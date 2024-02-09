<?php

namespace app\models;

use Yii;
use yii\base\Model;
use linslin\yii2\curl;
use app\models\PageScanResult;
use app\models\SiteUser;
use app\models\XSSAttack;
use app\helpers\XMLHelper;
use app\helpers\URL;

class GatekeepProject extends Model {
	protected $curl, $cookies_file, $site_url, $login_url, $login_form_url, $login_data_type, $logout_url, $error_page_text,
				 $name, $title, $login_data, $csrf_form_name, $attacks, $responseCode, $verbose, $xml;
	protected array $results, $usernames, $pages, $users, $urls;
	protected array $user_params;

	public function __construct($verbose) {
		$this->curl = null;
		$this->cookies_file = null;
		$this->results = [];
		$this->usernames = [];
		$this->users = [];
		$this->pages = [];
		$this->urls = [];
		$this->user_params = [];
		$this->title = 'Gatekeeper Test Results';
		$this->attacks = new XSSAttack();
		$this->verbose = $verbose;
	}

	public function getTitle() : string { return $this->title; }

	public function loadProject(string $xml_filename) : bool {
		if (!file_exists($xml_filename)) {
			if ($this->verbose) {
				echo "Project xml file $xml_filename not found." . PHP_EOL;
			}
			return false;
		}

		$xml_data = file_get_contents($xml_filename);
		if ($xml_data == null) {
			if ($this->verbose) {
				echo "Project xml file $xml_filename found but looks empty." . PHP_EOL;
			}
			return false;
		}
		if ($xml_data) {
			try {
				$this->xml = new \SimpleXMLElement($xml_data);
				if ($this->xml) {
					$this->name = $this->xml->name;
					$this->title = $this->xml->title;
					$this->site_url = $this->xml->site_url;
					$this->error_page_text = (string)$this->xml->error_page_text;
					$this->csrf_form_name = (string)$this->xml->csrf_form_name;

					$this->buildLoginInfo($this->xml);
					$this->buildPagesArray($this->xml);

					return true;
				} else {
					// xml not loaded

					if ($this->verbose) {
						echo "Valid xml not found in $xml_filename." . PHP_EOL;
					}
					return false;
				}
			}
			catch (Exception $e) {
				if ($this->verbose) {
					echo $e->getMessage() . PHP_EOL;
				}
				return false;
			}
		}
	}

	public function run($project_name) : int {
		echo "Gate/keeper scanning $project_name ... " . PHP_EOL;

		$xml_filename = Yii::getAlias("@app") . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . $project_name . ".xml";
		if ($this->loadProject($xml_filename)) {
			foreach ($this->xml->users->user as $user) {
				$this->checkUserPages($user);
			}
			$this->outputCSVResults();
			return $this->findFinalResult();
		} else {
			return 1;		// project failed to load
		}
	}

	protected function buildPagesArray(\SimpleXMLElement $xml) {
		foreach ($xml->users->user as $user) {
			$this->buildUserPagesArray($user);
		}
	}

	protected function buildUserPagesArray(\SimpleXMLElement $xml) {
		$user = $this->addSiteUser($xml);
		$username = XMLHelper::findXMLAttribute($xml, 'username');
		if ($xml->pages) {
			foreach ($xml->pages->page as $page) {
				$url = XMLHelper::findXMLAttribute($page, 'url');
				$data_type = XMLHelper::findXMLAttribute($page, 'data_type');
				$expected_result = XMLHelper::findXMLAttribute($page, 'expected_result');
				if ($expected_result == '') {
					$expected_result = 'ok';
				}
				$other_users_expected_result = XMLHelper::findXMLAttribute($page, 'other_users_expected_result');
				if ($other_users_expected_result == '') {
					$other_users_expected_result = 'ok';
				}
				$url = $this->expandParamsInUrl($user, $url);		// so if we're viewing say another user page we expand user_id into the id we're using
				$this->addPage($url);
				$this->addUrl($url, $data_type, $username, $expected_result, $other_users_expected_result);
			}
		}
	}

	protected function findUserParam($username, $param_name) {
		if (array_key_exists($username, $this->user_params)) {
			$user_params = $this->user_params[$username];
			if (array_key_exists($param_name, $user_params)) {
				return $user_params[$param_name];
			}
		}
		return null;
	}

	protected function expandParamsInUrl(SiteUser $user, $url) {
		$params = $user->getParams();
		foreach ($params as $param_name => $value) {
			$url_param_name = '{' . $param_name . '}';
			$url = str_replace($url_param_name, $value, $url);
		}
		return $url;
	}

	protected function performXSSAttack(string $username, \SimpleXMLElement $xml) : bool {
		$attack_type = XMLHelper::findXMLAttribute($xml, 'attack_type');
		//$attack_type = (string)$xml->attack_type;
		if ($this->verbose) {
			echo "----=====>>>> XSS attack type '$attack_type'" . PHP_EOL;
		}
		if ($attack_type == '') {
			echo "No attack type found" . PHP_EOL;
			print_r($xml);
			exit;
		}
		$attack_types = $this->findXSSAttacks((string)$attack_type);
		// Perform an xss attack on each page for each attack type
		foreach ($attack_types as $attack_type) {
			foreach ($xml->page as $page) {
				$this->checkUserPage($username, $page, $attack_type);
			}
		}
		return true;
	}

	protected function addSiteUser(\SimpleXMLElement $xml) {
		$username = XMLHelper::findXMLAttribute($xml, 'username');
		$user = $this->findSiteUser($username);
		if ($user == null) {
			$user = new SiteUser($xml);
			$this->users[] = $user;
		}
		return $user;
	}

	protected function userExists(string $name) : bool {
		$user = $this->findSiteUser($name);
		if ($user) {
			return true;
		} else {
			return false;
		}
	}

	protected function findSiteUser(string $name) : ?SiteUser {
		foreach ($this->users as $user) {
			if ($user->getUsername() == $name) {
				return $user;
			}
		}
		return null;
	}

	/**
	 * @function checkUserPages
	 * Checks a series of pages for a specified user
	 * First logs out, then logs in as this user
	 * Then fetches each page and checks their validity
	 **/

	protected function checkUserPages(\SimpleXMLElement $xml) : bool {
		$username = XMLHelper::findXMLAttribute($xml, 'username');
		$user = $this->findSiteUser($username);
		if ($user == null) {
			echo "User $username not found, bailing." . PHP_EOL;
			exit;
		}
		$params = $user->getParams();
		$level = $user->getLevel();
		$password = $user->getPassword();
		$user_is_guest = $user->isGuest();
		if ($this->verbose) {
			echo "checkUserPages for $username password $password level $level" . PHP_EOL;
		}

		// add params for this user - nb these should be more flexible
		if ($username) {
			if ($username == '' || $password == '') {
				echo "User credentials not found." . PHP_EOL;
				return false;
			}
		} else {
			$user_is_guest = true;
			$username = '';	// string is required
		}
		$this->logout();

		// Now login as this user
		if (!$user_is_guest) {
			$ok = $this->login($username, $password);
		} else {
			if ($this->verbose) {
				echo "Guest found ($username) so no login required." . PHP_EOL;
			}
			$ok = true;
		}

		if ($ok) {
			if ($this->verbose) {
				echo "Logged in ok, now checking user pages for $level..." . PHP_EOL;
			}
			$this->usernames[$username] = $level;

			// Check pages for this user
			$user_pages = [];
			if ($xml->pages) {
				foreach ($xml->pages->page as $page) {
					$this->checkUserPage($username, $page);
					$url = XMLHelper::findXMLAttribute($page, 'url');
					$user_pages []= $url;
				}
			}

			// Now check pages for other users (which often we won't have access to)
			if ($this->urls && count($this->urls)) {
				foreach ($this->urls as $url_object) {
					if (!in_array($url_object->getUrl(), $user_pages)) {
						$this->checkUserPage($username, $url_object->getUrl());
					}
				}
			}

			// Xss attacks
			if (isset($xml->xss_attacks)) {
				foreach ($xml->xss_attacks->xss_attack as $xss_attack) {
					$this->performXSSAttack($username, $xss_attack);
				}
			}

			return true;
		} else {
			echo "Login failed for user '$username' pw '$password'" . PHP_EOL;
			return false;
		}
	}

	protected function findXSSAttacks($attack_name) {
		$attacks = [];
		if ($attack_name == 'all') {
			$all_attacks = $this->attacks->getAllPayloads();
			foreach ($all_attacks as $attack => $payload) {
				$attacks []= $attack;
			}

		} else {
			$attacks []= $attack_name;
		}
		return $attacks;
	}

	protected function checkUserPage($username, $page, $attack_type = '') {
		$user = $this->findSiteUser($username);
		$use_default_data_type = true;
		$url_object = null;

		if (is_string($page)) {
			$url = $page;
			$name = $url;
			$url_object = $this->findUrl($page);
			if ($url_object) {
				$data_type = $url_object->getDataType();
				if ($data_type) {
					$use_default_data_type = false;
				}
			} else {
				$data_type = 'get';
			}
		} else {
			$url = XMLHelper::findXMLAttribute($page, 'url');
			$url = $this->expandParamsInUrl($user, $url);		// so if we're viewing say another user page we expand user_id into the id we're using
			$name = XMLHelper::findXMLAttribute($page, 'name');
			$data_type = XMLHelper::findXMLAttribute($page, 'data_type');
			if ($data_type) {
				$use_default_data_type = false;
			}
		}

		if ($url_object) {
			$expected_result = $url_object->getExpectedResultForUser($username);
			$other_users_expected_result = $url_object->getOtherUsersExpectedResult($username);
		} else {
			$expected_result = XMLHelper::findXMLAttribute($page, 'expected_result');
			if ($expected_result == '') {
				$expected_result = 'ok';
			}
			$other_users_expected_result = XMLHelper::findXMLAttribute($page, 'other_users_expected_result');
			if ($other_users_expected_result == '') {
				$other_users_expected_result = 'ok';
			}
		}

		$this->addPage($url);
		$this->addUrl($url, $data_type, $username, $expected_result, $other_users_expected_result);
		$result = $this->getResult($url, $username, $expected_result);
		$url = $this->site_url . DIRECTORY_SEPARATOR . $url;

		if ($this->verbose) {
			echo "Checking page $name at $url for $username with data_type $data_type." . PHP_EOL;
		}
		$data = $this->readXMLFormData($page, $attack_type);
		$this->results []= $result;
		if ($data && count($data) > 0) {
			$csrf_required = true;
		} else {
			$csrf_required = false;
		}

		$submit_to = '';

		if ($use_default_data_type) {		// default to get for a plain page, or post if we have data to submit
			$data_type = 'get';
			if ($data && count($data) > 0) {
				$data_type = 'post';
			}
		}
		$page = $this->fetchPage($url, $data, $csrf_required, $submit_to, $data_type);
		if ($page) {
			if ($this->verbose) {
				echo "Fetched page $url for user $username with response " . $page->getResponseCode() . PHP_EOL;
			}

			$html = $page->getHTML();
			if ($page->getResponseCode() == 302 || $page->getResponseCode() == 301) {
				$result->setIsRedirect($page->getResponseCode());
				if ($this->verbose) {
					echo "$url redirects to " . $page->findRedirectLocation() . " for user $username" . PHP_EOL;
				}
			}
			if ($page->getResponseCode() == 403 || $page->getResponseCode() == 400) {
				$result->setIsUnauthorized();
			} else {

				if ($page->notFound()) {
					$result->setNotFound();
				} else {

					if ($page->isError() || $this->checkIsErrorPage($html)) {
						$result->setIsError();
						if ($this->verbose) {
							echo "++++++ Page has errors +++++ - $url response was " . $page->getResponseCode() . PHP_EOL;
						}
					} else {
						//echo "+===== Fetched page $url ok ====+" . PHP_EOL;
					}
				}
			}
			//echo $html . PHP_EOL;
			if ($attack_type) {
				if ($this->verbose) {
					echo "Performing XSS attack $attack_type on page $url" . PHP_EOL;
				}
				// for a simple injection attack, fetch the page again and search for the content we injected
				// if its there in its raw state, we have injected it into the page
				$post_attack_html = $html; //$this->fetchPage($url, []);
				if ($post_attack_html) {
					//echo $post_attack_html . PHP_EOL; exit;
					$payload =  $this->attacks->findPayload($attack_type);
					if ($payload) {
						$injection_content_position = strpos($post_attack_html, $payload);
						if ($injection_content_position !== false) {
							$attack_result = "fail";
						} else {
							$attack_result = "pass";
						}
						$result->storeAttackResult($attack_type, $attack_result);
						if ($this->verbose) {
							echo "Stored xss $attack_type result $attack_result" . PHP_EOL;
						}
					}
				}
			}
		} else {
			$result->setNotFound();
			echo "Failed to fetch page $url for user $username" . PHP_EOL;
		}
	}

	protected function addPage($pagename) {
		$pagename = str_replace($this->site_url, '', $pagename);
		if (!in_array($pagename, $this->pages)) {
			$this->pages []= $pagename;
		}
	}

	protected function addUrl(string $url, string $data_type, string $user_name = '', string $expected_result = '', string $other_users_expected_result = '') {
		$url = str_replace($this->site_url, '', $url);
		if ($this->findUrl($url) == null) {
			$this->urls []= new URL($url, $data_type, $user_name, $expected_result, $other_users_expected_result);
		}
	}

	protected function findUrl(string $url) : ?URL {
		foreach ($this->urls as $url_object) {
			if ($url_object->getURL() == $url) {
				return $url_object;
			}
		}
		return null;
	}

	/**
	 * @function checkIsErrorPage
	 * @return boolean true if this an error page
	 * Currently we check if it contains a custom error text from the xml definition
	 **/

	protected function checkIsErrorPage($html) {
		if ($this->error_page_text == '') {
			return false;
		}
		$pos = strpos($html, $this->error_page_text);
		//echo "Checking for error pos = $pos '" . $this->error_page_text. "'" . PHP_EOL;
		//echo $html . PHP_EOL;
		return strpos($html, $this->error_page_text) !== false;
	}

	/**
	 * @function readXMLFormData
	 * Prepares and array of values for submission to an html form
	 * Variables in curly brackets are expanded according to their name
	 * @return array of strings
	 **/

	protected function readXMLFormData($xml_page,string $attack_type = '') : array {
		$data = [];
		if (isset($xml_page->form_data)) {
			foreach ($xml_page->form_data->data as $form_data) {
				$name = XMLHelper::findXMLAttribute($form_data, 'name');
				$value = XMLHelper::findXMLAttribute($form_data, 'value');
				$value = $this->expandFormVariables($value, $attack_type);
				$data[$name] = $value;
				//echo "Form data $name = $value" . PHP_EOL;
			}
		}
		return $data;
	}

	protected function expandFormVariables(string $form_value,string $attack_type) : string {
		$variables = [
			'time'		=> time(),
			'rnd'			=> rand(1, 99),
			'payload'	=> $this->attacks->findPayload($attack_type)
		];
		foreach ($variables as $vname => $vvalue) {
			$var_name = '{' . $vname . '}';
			$form_value = str_replace($var_name, $vvalue, $form_value);
		}
		// now expand xss attacks
		$xss_attacks = $this->attacks->getAllPayloads();
		foreach ($xss_attacks as $vname => $vvalue) {
			$var_name = '{' . $vname . '}';
			$form_value = str_replace($var_name, $vvalue, $form_value);
		}
		return $form_value;
	}

	protected function buildLoginInfo(\SimpleXMLElement $xml) {
		/*if (isset($xml->login_url)) {
			$this->login_url = $this->getXMLURL($xml, 'login_url');
		}
		if ($this->login_url == null) {*/
			if (isset($xml->login)) {
				$login_page = $xml->login;
				// detailed login info here
				$this->login_url = $this->site_url . DIRECTORY_SEPARATOR . (string)$login_page->login_url;
				if (isset($login_page->login_form_url)) {
					$this->login_form_url = (string)$login_page->login_form_url;
				} else {
					$this->login_form_url = $this->login_url;
				}
				$this->login_data = $this->readXMLFormData($login_page);
				if (isset($login_page->login_form_url)) {
					$this->login_data_type = $login_page->data_type;
				} else {
					$this->login_data_type = 'post';
				}
			}
		//}
		if (isset($xml->logout)) {
			$this->logout_url = XMLHelper::findXMLAttribute($xml->logout, 'url');
		} else {
			$this->logout_url = 'logout';
		}
	}

	protected function login($username, $password) : bool {
		if ($username == '') {
			//echo "Login ignored - this is a guest account" . PHP_EOL;
			return true;		// just a guest account
		}

		if ($this->login_data == []) {
			if ($this->verbose) {
				echo "No login page provided, login skipped..." . PHP_EOL;
			}
			return false;
		}

		$this->getCurl()->setOptions([
			CURLOPT_COOKIEFILE => $this->cookies_file,
			CURLOPT_COOKIEJAR => $this->cookies_file,
			CURLOPT_RETURNTRANSFER => true
		]);

		$login_field_username = $this->login_data['username'];
		$login_field_password = $this->login_data['password'];

		$page = $this->fetchPage($this->login_form_url, [
			$login_field_username		=> $username,
			$login_field_password		=> $password,
		], true, $this->login_url, $this->login_data_type);

		if ($page == null) {
			echo "Login page not found, login failed for $username" . PHP_EOL;
			return false;
		}

		// the login is considered successful if it didn't have a 302 to the same login page
		if ($page->getResponseCode() == 302) {
			if ($page->findRedirectLocation() == $this->login_url) {
				echo "Login failed - redirct to login." . PHP_EOL;
				return false;
			} else {
				echo "Login succeeded for $username." . PHP_EOL;
				return true;
			}
		}

		if ($page->getResponseCode() != 200) {
			if ($this->verbose) {
				echo "Login response not recognized - " . $page->getResponseCode() . PHP_EOL;
			}
		}
		return true;
	}

	protected function getXMLURL($xml_element, string $param_name) : string {
		$local_url = $xml_element->$param_name;
		return $this->site_url . DIRECTORY_SEPARATOR . $local_url;
	}

 	protected function getCurl() {
		if ($this->curl == null) {
			$this->curl = new curl\Curl();
			$this->cookies_file = null;
		}

		if ($this->cookies_file == null) {
			$this->cookies_file = tempnam("/tmp", "cookies");
		}

		$this->curl->setOptions([
			CURLOPT_COOKIEFILE => $this->cookies_file,
			CURLOPT_COOKIEJAR => $this->cookies_file,
			CURLOPT_RETURNTRANSFER => true
		]);
		return $this->curl;
	}

	protected function logout() {
		if ($this->curl) {
			//echo "--- Logout ---" . PHP_EOL;
			if ($this->cookies_file && file_exists($this->cookies_file)) {
				unlink($this->cookies_file);
			}
			$this->cookies_file = null;
			$this->curl = null;
			$this->fetchPage($this->site_url . DIRECTORY_SEPARATOR . $this->logout_url, [], false);
		}
	}

	protected function getCSRFFormName() {
		if ($this->csrf_form_name) {
			return $this->csrf_form_name;
		} else {
			return null;
		}
	}

 	/**
 	 * @function fetchPage
 	 **/

	protected function fetchPage(string $url, $post_data, $add_csrf = true, $submit_to = '', $data_type = 'post') : HTMLPage {
		$pos = strpos($url, (string)$this->site_url);
		if (strpos($url, (string)$this->site_url) === false) {
			$url = $this->site_url . DIRECTORY_SEPARATOR . $url;
		}
		//echo "fetchPage($url):" . PHP_EOL;
		$curl = $this->getCurl();

		$page = new HTMLPage($curl, $this->site_url, $this->getCSRFFormName());
		$page->fetchPage($url, $post_data, $add_csrf, $submit_to, $data_type);
		return $page;
	}

 	/**
 	 * @function outputCSVResults
 	 * Builds a csv file of the results and saves in the output directory
 	 * using the current date as a guide for the filename
 	 **/

 	protected function outputCSVResults() {
		$this->createOutputFolder();
		$filename = $this->buildCSVFilename();
		$csv = ',' . $this->title . PHP_EOL;
		$csv .= $this->buildUsersCSVHeader();
		$csv .= $this->buildCSVResults();
		file_put_contents($filename, $csv);
		echo "CSV saved to " . basename($filename) . PHP_EOL;
 	}

 	protected function buildCSVFilename() {
		$folder = $this->getOutputFolder();
		return $folder . DIRECTORY_SEPARATOR . date('Y-m-d H_i') . '_' . $this->name . '.csv';
 	}

 	protected function createOutputFolder() {
		$folder = $this->getOutputFolder();
		if (!file_exists($folder)) {
			mkdir($folder);
		}
	}

	protected function getOutputFolder() {
		return dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'output';
	}

 	protected function buildCSVResults() : string {
		$output = '';
		foreach ($this->pages as $page) {
			if ($page == '') {
				$page_name = '/';
			} else {
				$page_name = $page;
			}
			$output .= '"' . $page_name . '",';
			$comma_required = false;
			foreach ($this->usernames as $username => $level) {
				$result = $this->findResult($username, $page);
				if ($comma_required) {
					$output .= ", ";
				} else {
					$comma_required = true;
				}
				if ($result) {
					$output .= $result->getSummary();
				} else {
					$output .= 'n/a';		// really this has not been fetched, not a 404
				}
			}
			$output .= PHP_EOL;
		}
		// finish by showing the results for each user
		$output .= $this->buildUserResultsCSVRow();
		return $output;
 	}

 	protected function buildUserResultsCSVRow() : string  {
		$output = PHP_EOL;
		$output .= 'Result,';
		foreach ($this->usernames as $username => $level) {
			$output .= $this->findResultForUser($username);
			$output .= ',';
		}
		$output .= PHP_EOL;
		return $output;
 	}

 	protected function buildUsersCSVHeader()  : string {
		// columns are users
		$comma_required = false;
		$output = ",";

		foreach ($this->usernames as $username => $level) {
			if ($comma_required) {
				$output .= ", ";
			} else {
				$comma_required = true;
			}
			$output .= $username;
		}
		$output .= PHP_EOL;

		// add the user levels on the line below
		$comma_required = false;
		$output .= "Page,";

		foreach ($this->usernames as $username => $level) {
			if ($comma_required) {
				$output .= ", ";
			} else {
				$comma_required = true;
			}
			$output .= $level;
		}
		$output .= PHP_EOL;
		$output .= PHP_EOL;
		return $output;
 	}

 	protected function findResult($username, $page) {
		$page = str_replace($this->site_url, '', $page);

		foreach ($this->results as $result) {
			if ($result->getUsername() == $username && $result->getPage() == $page) {
				return $result;
			}
		}
		return null;
 	}

 	protected function findResultForUser(string $username) {
		foreach ($this->pages as $page) {
			$result = $this->findResult($username, $page);
			if ($result) {
				if (strpos($result->getSummary(), '(expected)') === false) {
					return 'fail';		// not the expected result, so its a fail for this user
				}
			}
		}
		return 'ok';
 	}

 	protected function findFinalResult() {
		foreach ($this->usernames as $username => $level) {
			$result = $this->findResultForUser($username);
			if ($result != 'ok') {
				return 1;		// fail
			}
		}
		return 0;	// success
	}

	/**
	 * @function getResult
	 * find a result for a page or create a new one
	 **/

	protected function getResult(string $url, string $username, string $expected_result) : PageScanResult {
		$result = $this->findResult($username, $url);
		if ($result) {
			return $result;
		} else {
			return new PageScanResult($url, $username, $expected_result);
		}
	}
}

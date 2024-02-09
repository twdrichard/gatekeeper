<?php

namespace app\models;

use Yii;
use yii\base\Model;

class HTMLPage extends Model {
	protected $response_code, $error_code, $html, $curl, $url, $csrf_name, $site_url, $verbose;
	protected array $post_data, $response_headers;

	public function __construct($curl, $site_url, $csrf_name = '') {
		$this->curl = $curl;
		$this->site_url = $site_url;
		$this->csrf_name = $csrf_name;
		$this->response_code = 0;
		$this->error_code = 0;
		$this->html = '';
		$this->verbose = false;
	}

	public function getResponseCode() : ?int {
		return $this->response_code;
	}

	public function getErrorCode() : int {
		return $this->error_code;
	}

	public function getHTML() : string {
		return $this->html;
	}

	public function getURL() : string {
		return $this->url;
	}

	public function getResponseHeaders() : array {
		return $this->response_headers;
	}

	public function notFound() : bool {
		return $this->response_code == 404;
	}

	public function isError() : bool {
		return $this->response_code == 500;
	}

	public function fetchPage(string $url, $post_data, $add_csrf = true, $submit_to = '', $data_type = 'post') {
		$this->url = $url;

		$pos = strpos($url, (string)$this->site_url);
		if (strpos($url, (string)$this->site_url) === false) {
			$url = $this->site_url . DIRECTORY_SEPARATOR . $url;
		}
		$curl = $this->curl;

		if ($add_csrf) {
			$csrf = $this->findCsrf($url, $curl);
			if ($csrf) {
				$csrf_field = $this->csrf_name;
				if ($csrf_field) {
					$post_data[$csrf_field] = $csrf;
					if ($submit_to) {
						// now we have the csrf from the original url, we submit the form to the new url
						$url = $submit_to;
					}
				}
			}
		}
		//echo "Fetching $url type $data_type" . PHP_EOL;
		switch ($data_type) {
			case 'get':
				$response = $curl->get($url);
			break;

			case 'json':
				$curl->setHeaders([ 'X-Requested-With' => 'XMLHttpRequest' ]);
				// drop through to post

			default:
			case 'post':
				if ($post_data && count($post_data)) {
					$curl->setPostParams($post_data);
					if ($this->verbose) {
						echo "post $url with " . print_r($post_data, true) . PHP_EOL;
					}
				}
				$response = $curl->post($url);

				//echo "fetchPage($url," . print_r($post_data,true) . ") returns with errorCode " . $curl->errorCode . " and responseCode " . $curl->responseCode . PHP_EOL;
				if ($curl->responseCode == 302) {
					//print_r($curl->responseHeaders);
					$forward_location = $this->fetchResponseHeaderValue($curl->responseHeaders, "Location");
					//echo "302 response - forwarding to a new page -  $forward_location - from $url " . PHP_EOL;
					return true;
				}
			break;
		}

		$this->response_code = $curl->responseCode;
		$this->error_code = $curl->errorCode;

		if ($curl->responseCode == 404 || $curl->responseCode == 400) {
			if ($this->verbose) {
				echo "$url failed with response " . $curl->responseCode . PHP_EOL;
			}
			return false;
		}

		if ($curl->errorCode != null) {
			if ($this->verbose) {
				echo "fetchPage($url) failed with errorCode " . $curl->errorCode . " and responseCode " . $curl->responseCode . PHP_EOL;
			}
			return null;
		}
		$this->response_headers = $curl->responseHeaders;
		$this->html = $response;
		return $response;
	}

	public function findRedirectLocation() {
		if ($this->responseCode == 302) {
			return $this->fetchResponseHeaderValue($this->responseHeaders, "Location");
		} else {
			return '';
		}
	}

	protected function fetchResponseHeaderValue(array $headers_ar, string $name) {
		if (array_key_exists($name, $headers_ar)) {
			return $headers_ar[$name];
		} else {
			return null;
		}
	}

	/**
	 * @function findCsrf
	 * To find the csrf form value we fetch the page initially
	 * and look for the csrf value in the form
	 **/

 	protected function findCsrf($url, $curl) {
		$response = $curl->get($url);
		if ($response == '') {
			return null;
		}
		$search_name =  ' name="' . $this->csrf_name . '"';
		$csrf_pos = strpos($response, $search_name);
		if ($csrf_pos !== false) {
			$value_id = ' value="';
			$value_pos = strpos($response, $value_id, $csrf_pos);
			$end_quote_pos = strpos($response, '"', $value_pos + strlen($value_id));
			if ($value_pos !== false && $end_quote_pos > $value_pos) {
				$value_pos += strlen($value_id);
				$csrf_length = $end_quote_pos - $value_pos;
				$csrf = substr($response, $value_pos, $csrf_length);
				return $csrf;
			}
		}
		return null;
 	}


}

<?php

namespace app\helpers;

use Yii;
use yii\base\Model;
use app\helpers\XMLHelper;

class ProjectCreator {
	public function __construct() {
		$name = \yii\helpers\BaseConsole::input("Project name: ");
		echo "Creating project $name" . PHP_EOL;
		$url = \yii\helpers\BaseConsole::input("Site url: ");
		$title = \yii\helpers\BaseConsole::input("Project descriptive title: ");
		echo "Creating project $url" . PHP_EOL;
		$login_url = \yii\helpers\BaseConsole::input("Login page: ");
		$logout_url = \yii\helpers\BaseConsole::input("Logout page: ");
		$username = \yii\helpers\BaseConsole::input("Username: ");
		$password = \yii\helpers\BaseConsole::input("Password: ");

		$login_ar = $this->buildLoginAr($login_url);
		$users = [
			'user level=guest' => [
				'pages' => [
					'page url= name=home' => ''
				]
			],
			'user level=member username=' . $username . ' password=' . $password => [
			]
		];

		$title = "Gatekeeper project $name";
		$project_ar = [
			'name' 			=> $name,
			'site_url' 		=> $url,
			'title' 			=> $title,
			'login'			=> $login_ar,
			'users' 			=> $users,
		];
		$project_name = $name;
		$xml_filename = Yii::getAlias("@app") . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . $name . ".xml";
		if (file_exists($xml_filename)) {
			echo "File $xml_filename already exists. Removing it..." . PHP_EOL;
			unlink($xml_filename);
		}
		$xml = XMLHelper::array_to_xml($project_ar, new \SimpleXMLElement('<project />'));
		$length_written = file_put_contents($xml_filename, $this->formatXML($xml));
		if ($length_written) {
			echo "Created project $name.xml" . PHP_EOL;
		} else {
			echo "Problem creating project $xml_filename." . PHP_EOL;
		}

		echo "To run this site:" . PHP_EOL;
		echo "php yii gate/keeper $name" . PHP_EOL;
	}

	protected function buildLoginAr(string $login_url) : array {
		$ar = //[ 'login usr=' . $login_url =>
		 [
			'login_url' => $login_url
			//]
		];
		return $ar;
	/*<login url="user/login">
		<login_url>user/login</login_url>
		<form_data>
			<data name="username" value="login-form[login]" />
			<data name="password" value="login-form[password]" />
		</form_data>
	</login>*/
	}

	/**
	 * @function formatXML
	 * Returns a string of formatted xml including line returns
	 **/

	protected function formatXML(\SimpleXMLElement $xml) : string {
		$doc = new \DOMDocument();
		$doc->preserveWhiteSpace = false;
		$doc->formatOutput = true;
		$doc->loadXML($xml->asXML());
		return $doc->saveXML();
	}
}

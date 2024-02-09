<?php

namespace app\models;

use Yii;
use yii\base\Model;
use app\helpers\XMLHelper;

class SiteUser extends Model {
	protected string $username, $password, $level;
	protected bool $is_guest;
	protected array $params;

	public function __construct(\SimpleXMLElement $xml) {
		$this->username = XMLHelper::findXMLAttribute($xml, 'username');
		$this->level = XMLHelper::findXMLAttribute($xml, 'level');
		$this->password = XMLHelper::findXMLAttribute($xml, 'password');
		if ($this->username == '') {
			$this->is_guest = true;
		} else {
			$this->is_guest = false;
		}
		$this->params = [];
		$this->readParams($xml);
	}

	protected function readParams(\SimpleXMLElement $xml) {
		if (isset($xml->params)) {
			foreach ($xml->params->param as $param) {
				$name = XMLHelper::findXMLAttribute($param, 'name');
				$value = XMLHelper::findXMLAttribute($param, 'value');
				$this->params[$name] = $value;
			}
		}
	}

	public function getParams() : array {
		return $this->params;
	}

	public function getParam(string $name) : string {
		if (array_key_exists($name, $this->params)) {
			return $this->params[$name];
		} else {
			return '';
		}
	}

	public function getUsername() : string {
		return $this->username;
	}
	public function getLevel() : string {
		return $this->level;
	}
	public function getPassword() : string {
		return $this->password;
	}
	public function isGuest() : bool {
		return $this->is_guest;
	}




}
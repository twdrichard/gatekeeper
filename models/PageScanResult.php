<?php

namespace app\models;

use Yii;
use yii\base\Model;

class PageScanResult extends Model {
	protected $url, $user, $is_error, $not_found, $expected_result, $is_redirect, $not_authorized, $response_code;
	protected array $attacks;

	public function __construct($_url, $_user, $_expected_result) {
		$this->url = $_url;
		$this->user = $_user;
		$this->expected_result = $_expected_result;

		$this->is_error = false;
		$this->not_found = false;
		$this->is_redirect = false;
		$this->not_authorized = false;

		$this->attacks = [];
	}

	public function getUsername() { return $this->user; }
	public function getPage() { return $this->url; }

	public function setIsError() {
		$this->is_error = true;
	}

	public function setIsRedirect($response_code = 302) {
		$this->response_code = $response_code;
		$this->is_redirect = true;
	}

	public function setNotFound() {
		$this->not_found = true;
	}

	public function setIsUnauthorized() {
		$this->not_authorized = true;
	}

	public function isError() : bool {
		return $this->error;
	}

	public function wasNotFound() : bool {
		return $this->not_found;
	}

	public function storeAttackResult($attack_type, $attack_result) {
		$this->attacks[$attack_type] = $attack_result;
	}

	public function getSummary() : string {
		if ($this->expected_result) {
			$expected_result = $this->expected_result;
		} else {
			$expected_result = 'ok';	// by default
		}

		if ($this->not_authorized) {
			$s = "not authorized";
			if ($expected_result == $s) {
				$s .= ' (expected)';
			}
			return $s;
		}

		if ($this->is_error) {
			if ($expected_result == "error") {
				return "error (expected)";
			} else {
				return "error";
			}
		} else {
			if ($this->is_redirect) {
				$s = $this->response_code; //"302";
				if ($expected_result == $this->response_code) { //"302") {
					$s .= ' (expected)';
				}
				return $s;
			}
			if ($this->not_found) {
				$s = "404";
				if ($expected_result == "404") {
					$s .= ' (expected)';
				}
				return $s;
			}
			$summary = "ok";
			if ($expected_result == "ok" || $expected_result == '') {		// default to ok if no result specified
				$summary .= ' (expected)';
			} else {
				$summary .= ' [expected_result=' . print_r($expected_result,true) . ']';
			}
			foreach ($this->attacks as $attack_type => $result) {
				$summary .= ". Attack $attack_type : $result";
			}
			return $summary;
		}
	}
}
<?php

namespace app\helpers;

use Yii;

class URL {
	protected $url, $data_type, $user_name, $expected_result, $other_users_expected_result;

	public function __construct($url, $data_type = 'get', $user_name = '', $expected_result = '', $other_users_expected_result = '') {
		$this->url = $url;
		$this->data_type = $data_type;
		$this->user_name = $user_name;
		if ($expected_result == '') {
			$this->expected_result = 'ok';
		} else {
			$this->expected_result = $expected_result;
		}
		if ($other_users_expected_result == '') {
			$this->other_users_expected_result = 'ok';
		} else {
			$this->other_users_expected_result = $other_users_expected_result;
		}
	}

	public function getUrl() { return $this->url; }
	public function getUser() { return $this->user_name; }
	public function getDataType() { return $this->data_type; }
	public function getExpectedResultForUser($user_name = '') {
		if ($user_name == $this->user_name) {
			return $this->expected_result;
		} else {
			return $this->other_users_expected_result;
		}
	}
	public function getExpectedResult() { return $this->expected_result; }
	public function getOtherUsersExpectedResult() { return $this->other_users_expected_result; }
}
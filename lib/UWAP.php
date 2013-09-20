<?php

/**
 * @package UWAP
 * @author Andreas Åkre Solberg <andreas.solberg@uninett.no>
 *
 * This
 */



/*
 * Examples of UWAP HTTP Headers
 * [UWAP-X-Auth] => 9c1d4ac1-588b-484a-83ca-7fa599c497ed
 * [UWAP-UserID] => andreas@uninett.no
 * [UWAP-Groups] => 5e0daec3-5b71-4e8a-8d5d-3a9894ff00d9,d6ac7d23-fb8e-4e00-92ec-11e58ee80d97,95e4e349-2b16-4f73-b060-c5fb371635a7,7ea1c555-583c-4a1f-9ae2-1273b0c66ebc,a48a713d-d1bb-4d63-84fd-2825ce518776,da5832bf-453f-4333-abc8-36b83579d9df,uwap:realm:uninett_no,uwap:orgunit:uninett_no:c3b7199f7a3c27249a6356d6de5337b9f79614bc,uwap:orgunit:uninett_no:9e463fc39897f07998e9aee50c4c1968369b6c52
 * [UWAP-Client] => app_connectwidget
 * [UWAP-Scopes] => 
 */



class UWAP {

	protected $config;
	protected $headers = array();
	protected $groups = array();

	function __construct($config) {
		$this->config = $config;
		$headers = apache_request_headers();
		foreach($headers AS $k => $v) {
			if (preg_match('/^UWAP-/', $k)) {
				$this->headers[$k] = $v;
			}
		}

		if (!empty($this->headers['UWAP-Groups'])) {
			$gr = explode(',', $this->headers['UWAP-Groups']);
			foreach($gr AS $g) {
				$this->groups[$g] = 1;
			}
		}

	}

	public function getUser() {
		$user = array('userid' => $this->headers['UWAP-UserID']);
		return $user;
	}

	public function requireMemberOf($group) {
		if (!isset($this->groups[$group])) {
			throw new Exception('Access denied: User is not a member of the group ' . $group);
		}
	}

	public function requireUWAPAuth() {
		if (empty($this->headers['UWAP-X-Auth'])) {
			throw new Exception('Missing required UWAP credentials');
		}
		if ($this->headers['UWAP-X-Auth'] !== $this->config['UWAP-X-Auth']) {
			throw new Exception('Invalid UWAP credentials');
		}
	}

	public function debug() {
		return array(
			'headers' => $this->headers,
			'groups' => $this->groups
		);

	}


}
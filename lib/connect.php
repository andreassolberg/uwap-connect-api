<?php
/**
 * @package Adobe Connect API connector
 * @author Simon Skrødal <simon.skrodal@uninett.no>
 * 
 */



class AConnect {

	protected $config, $apiurl, $sessioncookie;
	function __construct($config) {
		$this->config = $config;
		$this->sessioncookie = null;
		$this->apiurl = $this->config['connect-api-base'] . '/api/xml?';
	}


	public static function group2room($group) {
		return 'uwap-6-' . sha1($group);
	}


	/**
	 * Simplest call to get the current version of Connect running on connect.uninett.no.
	 *
	 * @return  array
	 */
	public function getConnectVersion() {
		$apiCommonInfo = $this->callConnectApi( array( 'action' => 'common-info', ), false);
		return array( 'status' => true, 'version' => (String)$apiCommonInfo->common->version );
	}

	public function getMeetingRoomParticipants($meetingroom) {

		$scoid = $this->findMeetingRoomID($meetingroom);
		// echo '<pre>scoid:';
		// print_r($scoid);

		if ($scoid === null) return null;

		// echo('<pre>SCOID IS '); print_r($scoid); exit;		

		$result = $this->callConnectApi( array( 'action' => 'meeting-usermanager-user-list', 'sco-id' => $scoid) );

		// echo('<pre>'); print_r($result); exit;
		// 
		
		return $result->{"meeting-usermanager-user-list"};

		if (empty($result->{"meeting-usermanager-user-list"} )) return null;


		
		if (is_array($result->{"meeting-usermanager-user-list"}->{"userdetails"})) {
			return $result->{"meeting-usermanager-user-list"}->{"userdetails"};
		} else {
			return array($result->{"meeting-usermanager-user-list"}->{"userdetails"});
		}

		
	}


	/**
	 * Public entry function ( ?method=getConnectMeetingRoom )
	 *
	 * When a user requests a URL to meeting room $uwapAccountInfo MUST be defined.
	 *
	 * 1. Authenticate as API user on AC service
	 * 2. Ensure that UWAP meeting folder exists on AC service
	 * 3. Check if user's home institution subscribes to the AC service (by using Feide API)
	 * 4. Check/create user account
	 * 5. Check/create group meeting room
	 * 6. Add user as host in room
	 *
	 * Finally the function returns status which includes room details and user details.
	 */
	public function getConnectMeetingRoom($user, $meetingroom, $roomname) {


		// Double check that the UWAP container for meetings actually exist. This is a REQUIREMENT. If it does not exist, everything BREAKS!
		if( !$this->isUWAPFolderPresent( $this->config['connect-folder-id']) ) {
			throw new Exception('Error: Shared Meetings folder UWAP not found.');
		}

		// Check AC subscription for user's home institution
		if ( !$this->isOrgSubscriber( $user['userid'] ) ) {
			throw new Exception('User institution does not subscribe to AC service.');
		}

		// Check/create user account
		$userAccountDetails = $this->getConnectUserExists( 
			'Firstname', // firstname
			'Lastname',  // lastname
			$user['userid']  // userid
		);

		// ...all good. Check/create group meeting room
		$uwapMeetingRoomDetails = $this->getUWAPGroupMeetingRoom( $meetingroom, $roomname );

		// Set permission as host for user in group room
		$permissionUpdateDetails = $this->setUWAPUserAsHost( (String)$uwapMeetingRoomDetails->sco['sco-id'], (String)$userAccountDetails->principal['principal-id'] );

		$path = (String)$uwapMeetingRoomDetails->sco->{'url-path'};
		$target = 'https://connect.uninett.no/system/login-content?' .
			'account-id=7&set-lang=en&' .
			'next=' . urlencode($path) . '&' .
			'path=' . urlencode($path);

		// Build a nice return structure for data
		return array(
			'status'    => true,
			'meeting'	=> array(
				'name'        => (String)$uwapMeetingRoomDetails->sco->name,
				'url-path'	  => $path,
				'loginurl' => 'https://connect.uninett.no/Shibboleth.sso/Login/feide',
				'target'	  => $target,
				'url'         => $this->config['connect-api-base'] . $path,
				'created'     => (String)$uwapMeetingRoomDetails->sco->{'date-created'},
			),
			'connect-meeting' 		=> $uwapMeetingRoomDetails,
			'connect-user'       	=> $userAccountDetails,
			'connect-permission' 	=> $permissionUpdateDetails,
		);
	}




	/**
	 * @param $apiCookie
	 * @param $uwapUserID
	 *
	 * @return array
	 */
	function getConnectUserExists($uwapFirstName, $uwapLastName, $uwapUserID ) {

		// Lookup account info for requested user
		$apiUserInfo = $this->callConnectApi( array( 'action' => 'principal-list', 'filter-login' => $uwapUserID) );
		// Response sanity check (TODO: Some better logging here would probably be useful)
		if ( $apiUserInfo->status['code'] != 'ok' ) {
			throw new Exception('Unexpected response from Adobe Connect.');
		}

		// Check response to see if user exists
		if ( isset( $apiUserInfo->{'principal-list'}->principal ) ) {
			return $apiUserInfo->{'principal-list'};
		} 

		return $this->createUWAPConnectAccount( $uwapFirstName, $uwapLastName, $uwapUserID );
	}

	/**
	 * Create the AC account only if: 1) It does not already exist, and, 2) User's Org subscribes to the service.
	 *
	 * Account is 'bare', i.e. no email, groups. All missing attribs are auto-populated when user logs on.
	 *
	 * @param $apiCookie
	 * @param $firstName
	 * @param $lastName
	 * @param $userID
	 *
	 * @return array
	 */
	function createUWAPConnectAccount( $firstName, $lastName, $userID ) {
		// Generates a random 20 char passwd for new users (Feide Auth on AC service disregards this, but API insists on a PW)
		$randomPassword = bin2hex( openssl_random_pseudo_bytes( 10 ) );
		// Note:
		//  - Combined max length of firstname/lastname is 60 chars.
		//    See http://help.adobe.com/en_US/connect/8.0/webservices/WS5b3ccc516d4fbf351e63e3d11a171dd0f3-7ff8_SP1.html
		$apiCreateUserResponse = $this->callConnectApi( array( 'action' => 'principal-update', 'first-name' => $firstName, 'last-name' => $lastName, 'login' => $userID, 'password' => $randomPassword, 'type' => 'user', 'send-email' => 'false', 'has-children' => '0') );
		// Check for errors
		if ( $apiCreateUserResponse->status['code'] != 'ok' ) {
			$e = new Exception('Failed to create new user');
			// $e->details = $apiCreateUserResponse;
			throw $e;
		}

		// If all ok, get the user's newly created Principal ID
		$userPrincipalID = (String)$apiCreateUserResponse->principal['principal-id'];
		// Another sanity check...
		if ( !$userPrincipalID ) {
			throw new Exception('Error: Failed to create user; principal-id not found.');
		}
		return $apiCreateUserResponse;
		// return array( 'status' => true, 'details' => $apiCreateUserResponse );
	}

	/**
	 * Check if meeting room for group exists, if not, create it.
	 *
	 * @param $apiCookie
	 * @param $uwapGroupID
	 *
	 * @return SimpleXMLElement
	 */
	function getUWAPGroupMeetingRoom( $uwapGroupID, $roomname ) {
		// Check to see if room already exists
		// $querypath = '/' . $uwapGroupID . '/';
		$uwapGroupMeetingFolder = $this->callConnectApi( array( 'action' => 'sco-search-by-field', 'query' => $uwapGroupID, 'field' => 'description') );
		// Return on error
		if ( (string)$uwapGroupMeetingFolder->status['code'] !== 'ok' ) {
			// echo '<pre>'; print_r($uwapGroupMeetingFolder); return;
			throw new Exception('Unexpected response from Adobe Connect: Search for meeting room failed');
		}
		// Ok search, but room does not exists (judged by missing sco)
		if ( !isset( $uwapGroupMeetingFolder->{'sco-search-by-field-info'}->sco['sco-id'] ) ) {
			return $this->createUWAPGroupMeetingRoom( $this->config['connect-folder-id'], $uwapGroupID, $roomname );
		}
		return $uwapGroupMeetingFolder->{'sco-search-by-field-info'};
	}


	/**
	 * Check if meeting room for group exists, if not, create it.
	 *
	 * @param $uwapGroupID
	 *
	 * @return SimpleXMLElement
	 */
	function findMeetingRoomID( $uwapGroupID ) {
		// Check to see if room already exists
		// $querypath = '/' . $uwapGroupID . '/';
		$uwapGroupMeetingFolder = $this->callConnectApi( array( 'action' => 'sco-search-by-field', 'query' => $uwapGroupID, 'field' => 'description') );

		// print_r($uwapGroupMeetingFolder);

		// echo '<pre>'; print_r($uwapGroupMeetingFolder->{'sco-search-by-field-info'}); exit;

		if (isset($uwapGroupMeetingFolder->{'sco-search-by-field-info'}->sco['sco-id'])) {
			return (string) $uwapGroupMeetingFolder->{'sco-search-by-field-info'}->sco['sco-id'];
		}

		return null;
	}


	/**
	 * Create a meeting room in the UWAP folder.
	 *
	 * @param $apiCookie
	 * @param $uwapMeetingsFolderID
	 * @param $uwapGroupID
	 *
	 * @return array
	 */
	function createUWAPGroupMeetingRoom( $uwapMeetingsFolderID, $uwapGroupID, $roomname ) {
		$apiCreateMeetingRoom = $this->callConnectApi( 
			array( 
				'action' => 'sco-update', 'type' => 'meeting', 
				'name' => $roomname . ' [_UWAP_]', 
				'description' => $uwapGroupID,
				'folder-id' => $uwapMeetingsFolderID, 
				'date-begin' => '2012-11-28T09:00', 
				'date-end' => '2012-11-28T17:00', 
				'url-path' => $uwapGroupID,
			) 
		);

		if ( (string) $apiCreateMeetingRoom->status['code'] != 'ok' ) {
			// $msg = 
			// print_r($apiCreateMeetingRoom);
			// 'api-msg' => $apiCreateMeetingRoom
			throw new Exception('Failed to create room');
		}
		return $apiCreateMeetingRoom;
	}

	function setUWAPUserAsHost( $uwapMeetingRoomSco, $uwapUserSco ) {
		$apiUserHost = $this->callConnectApi( array( 'action' => 'permissions-update', 'permission-id' => 'host', 'acl-id' => $uwapMeetingRoomSco, 'principal-id' => $uwapUserSco) );
		// TODO: Sanity check response!
		return $apiUserHost;
	}

	/**
	 * On AC server there is a (manually) created Shared Meeting folder named 'UWAP'. This is the container for all
	 * meeting rooms auto-created via UWAP.
	 *
	 * "For the folders, the difference between the User Meetings folders and the Shared Meetings folder is a
	 * matter of management. The access to and function of the room is not changed by where it lives on the server.
	 * What is affected is who has access to the server side functions of that meeting room. So if multiple people
	 * need to have access to the room (say you use it for weekly staff meetings) and there are a variety of
	 * individuals who may be hosting the room, then it should live in the Shared Meetings folder (preferably in
	 * a sub folder for organization) where all the individuals who may need access to the settings behind the scenes
	 * can get to it."
	 * http://forums.adobe.com/message/4325107
	 *
	 * Persistent sco-id for UWAP folder is 4223359
	 *
	 * @param $apiCookie
	 *
	 * @return SimpleXMLElement
	 *
	 */
	function isUWAPFolderPresent( $uwapMeetingsFolderID ) {
		$uwapMeetingFolder = $this->callConnectApi( array( 'action' => 'sco-info', 'sco-id' => $uwapMeetingsFolderID ) );
		//
		if ( $uwapMeetingFolder->status['code'] != 'ok' ) {
			return false;
		}
		// If folder was found return status with folder details directly from AC
		return true;
	}

	/**
	 * Use Feide API to check if user's home org subscribes to the AC service.
	 *
	 * @author Simon Skrodal
	 * @since  23.11.2012
	 */
	function isOrgSubscriber( $uwapUserID ) {

		$entityID = 'https://connect.uninett.no/shibboleth';
		$feideApiUser = $this->config['feide-api-userid']; 
		$feideApiPass = $this->config['feide-api-passwd'];

		$feideApiURL  = 'https://tjenester.uninett.no/feide/api/get_sp_subscriptions?entityID=' . urlencode($entityID);

		// Set up the API call (noticed very slow performance here...)
		$context = stream_context_create( 
			array( 'http' => 
				array( 'header' => "Authorization: Basic " . base64_encode( $feideApiUser . ':' . $feideApiPass ) )
			) 
		);

		// Make the call and store returned JSON (...,"hihm.no": true, "bibsys.no": false, ...)
		$orgSubscriptionInfo = json_decode( file_get_contents( $feideApiURL, false, $context ) );

		// Grab user's organisation from Feide username ('inst.no')
		$userOrg = explode( '@', $uwapUserID );
		$userOrg = $userOrg[1];

		// Get true/false value from JSON (can also be NULL!)
		$isOrgSubscriber = $orgSubscriptionInfo->$userOrg;

		// If institution is not in the subscriber list at all...
		if ( is_null( $isOrgSubscriber ) ) {
			$isOrgSubscriber = false;
		}

		// True if present, false otherwise
		return $isOrgSubscriber;
	}


	/**
	 * Utility function for AC API calls.
	 */
	protected function callConnectApi( $params = array(), $requireSession = true ) {

		if ($requireSession) {
			$params['session'] = $this->getSessionAuthCookie();	
		}

		$url = $this->apiurl . http_build_query( $params );
		return simplexml_load_file( $url );
	}


	/**
	 * Authenticate API user on AC service and grab returned cookie. If auth already in place, return cookie.
	 *
	 * @return array
	 */
	protected function getSessionAuthCookie() {

		if ($this->sessioncookie !== null) {
			return $this->sessioncookie;
		}


		$url = $this->apiurl . 'action=login&login=' . $this->config['connect-api-userid'] . '&password=' . $this->config['connect-api-passwd'];
		$auth = get_headers( $url, 1);

		if (!isset($auth['Set-Cookie'])) {
			throw new Exception('Error when authenticating to the Connect API using client API credentials. Set-Cookie not present in response.');
		}

		// Extract session cookie
		$acSessionCookie = substr( $auth['Set-Cookie'], strpos( $auth['Set-Cookie'], '=' ) + 1 );
		$acSessionCookie = substr( $acSessionCookie, 0, strpos( $acSessionCookie, ';' ) );

		$this->sessioncookie = $acSessionCookie;

		return $this->sessioncookie;
	}


}




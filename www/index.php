<?php


$BASE = dirname(dirname(__FILE__));
require_once($BASE . '/lib/UWAP.php');
require_once($BASE . '/lib/REST.php');
require_once($BASE . '/lib/connect.php');


$config = json_decode(file_get_contents($BASE . '/etc/config.js'), true);



$uwap = new UWAP($config);


try {


	/*
	 * Request / return status
	 */
	if (REST::route('get', '^/$', &$parameters, &$body)) {

		REST::result($parameters);


	} else if (REST::route('get', '^/debug$', &$parameters, &$body)) {

		$uwap->requireUWAPAuth();
		REST::result($uwap->debug());


	} else if (REST::route('get', '^/test$', &$parameters, &$body)) {

		$connect = new AConnect($config);

		REST::result($connect->getMeetingRoomParticipants('uwap-5-195165500aa3ff5b07ee19d3757a4adddad0b3dc'));
		// REST::result($connect->getMeetingRoomParticipants(array('userid' => 'andreas@uninett.no'), 'uwap-5-195165500aa3ff5b07ee19d3757a4adddad0b3dc', 'FOO'));
		// REST::result($connect->findMeetingRoomID('uwap-3-195165500aa3ff5b07ee19d3757a4adddad0b3dc', 'NAME'));

		// REST::result($connect->getConnectMeetingRoom(array('userid' => 'andreas@uninett.no'), 'uwap-3-195165500aa3ff5b07ee19d3757a4adddad0b3dc', '2SDFKJSDKFJSDKJF'));


	/**
	 * All requests that is relevant to the Adobe Connect API goes here...
	 */
	} else if (REST::route(false, '^/connect/.*$', &$parameters, &$body)) {

		$connect = new AConnect($config);


		if (REST::route('get', '^/connect/version$', &$parameters, &$body)) {

			REST::result($connect->getConnectVersion());

		} else if (REST::route('post', '^/connect/connect$', &$parameters, &$body)) {

			// echo "Parameters: "; print_r($parameters); echo "body:"; print_r($body); exit;

			$group = $body['groupid'];
			$roomname = $body['name'];

			$uwap->requireUWAPAuth();
			$uwap->requireMemberOf($group);
			$user = $uwap->getUser();
			$roomid = 'uwap-5-' . sha1($group);

			REST::result($connect->getConnectMeetingRoom($user, $roomid, $roomname));


		// } else if (REST::route('get', '^/connect/([@:._A-Za-z0-9\-]+)/connect$', &$parameters, &$body)) {

		// 	$group = $parameters[1];
		// 	$uwap->requireUWAPAuth();
		// 	$uwap->requireMemberOf($group);
		// 	$user = $uwap->getUser();
		// 	$roomid = 'uwap-' . sha1($group);
		// 	REST::result($connect->getConnectMeetingRoom($user, $roomid));


		} else if (REST::route('get', '^/connect/([@:._A-Za-z0-9\-]+)/participants$', &$parameters, &$body)) {

			
			$group = $parameters[1];
			$uwap->requireUWAPAuth();
			$uwap->requireMemberOf($group);
			$roomid = 'uwap-5-' . sha1($group);
			REST::result($connect->getMeetingRoomParticipants($roomid));

		} else {
			REST::error(400, 'Bad request', new Exception('Invalid request [connect], does not reckonize parameters. '));
		}





	} else {
		REST::error(400, 'Bad request', new Exception('Invalid request [generic], does not reckonize parameters.'));
	}
	// ------ END OF Connect API handling.


} catch(Exception $e) {

	REST::error(500, 'Internal Error', $e);

}



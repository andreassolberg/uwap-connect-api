<?php

/**
 * @package UWAP
 * @author Andreas Åkre Solberg <andreas.solberg@uninett.no>
 *
 * Helper library that deals with REST request routing and response JSON wrapping.
 */


class REST {


	public static function result($result) {

		header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
		header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

		header("Access-Control-Allow-Origin: *");
		header("Access-Control-Allow-Credentials: true");
		header("Access-Control-Allow-Methods: HEAD, GET, OPTIONS, POST, DELETE, PATCH");
		header("Access-Control-Allow-Headers: Authorization, X-Requested-With, Origin, Accept, Content-Type");

		header('Content-Type: application/json; charset=utf-8');

		if ( isset( $_GET['callback'] ) ) {
			$callback = $_GET['callback'];
			if ( !preg_match( '/^[a-zA-Z0-9_\-]*$/', $callback ) ) {
				return array( 'status' => false, 'details' => 'Invalid characters in callback' );
			}
		}

		// Return response JSON/JSONP
		if ( isset( $callback ) ) {
			echo $callback . '(' . json_encode( $result ) . ')';
		} else {
			echo json_encode( $result );
		}

	}

	public static function error($code, $text, $error) {

		header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
		header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

		header("Access-Control-Allow-Origin: *");
		header("Access-Control-Allow-Credentials: true");
		header("Access-Control-Allow-Methods: HEAD, GET, OPTIONS, POST, DELETE, PATCH");
		header("Access-Control-Allow-Headers: Authorization, X-Requested-With, Origin, Accept, Content-Type");

		header('Content-Type: application/json; charset=utf-8');

		// header('HTTP/1.1 ' . $code . ' ' . $text);

		echo json_encode(
			array(
				'status' => false,
				'message' => $error->getMessage()
			)
		);

	}

	public static function route($method = false, $match, $parameters, $object = null) {
		if (empty($_SERVER['PATH_INFO']) || strlen($_SERVER['PATH_INFO']) < 2) return false;

		$inputraw = file_get_contents("php://input");
		if ($inputraw) {
			$object = json_decode($inputraw, true);
		}
		

		$path = $_SERVER['PATH_INFO'];
		$realmethod = strtolower($_SERVER['REQUEST_METHOD']);

		if ($method !== false) {
			if (strtolower($method) !== $realmethod) return false;
		}
		if (!preg_match('|^' . $match . '|', $path, &$parameters)) return false;
		return true;
	}


}
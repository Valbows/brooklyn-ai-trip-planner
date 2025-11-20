<?php
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';

\BrooklynAI\Autoloader::register();

use Brain\Monkey;

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['batp_test_transients'] = array();

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		global $batp_test_transients;
		return isset( $batp_test_transients[ $key ] ) ? $batp_test_transients[ $key ]['value'] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration ) {
		global $batp_test_transients;
		$batp_test_transients[ $key ] = array(
			'value'      => $value,
			'expiration' => time() + (int) $expiration,
		);
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		global $batp_test_transients;
		unset( $batp_test_transients[ $key ] );
	}
}

if ( ! function_exists( 'batp_test_reset_transients' ) ) {
	function batp_test_reset_transients() {
		$GLOBALS['batp_test_transients'] = array();
	}
}

if ( ! function_exists( 'batp_test_get_transients' ) ) {
	function batp_test_get_transients() {
		return $GLOBALS['batp_test_transients'];
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ) {
		return hash_hmac( 'sha256', (string) $action, 'nonce-secret' );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action ) {
		return hash_equals( wp_create_nonce( $action ), $nonce );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_scalar( $str ) ? filter_var( $str, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH ) : '';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( $key );
		return preg_replace( '/[^a-z0-9_]/', '', $key );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'map_deep' ) ) {
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			return array_map(
				function ( $item ) use ( $callback ) {
					return map_deep( $item, $callback );
				},
				$value
			);
		}

		if ( is_object( $value ) ) {
			foreach ( $value as $key => $sub_value ) {
				$value->{$key} = map_deep( $sub_value, $callback );
			}

			return $value;
		}

		return call_user_func( $callback, $value );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		return 'test-salt';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = array();

		public function __construct( $code = '', $message = '', $data = array() ) {
			if ( $code ) {
				$this->errors[ $code ] = array( $message, $data );
			}
		}

		public function get_error_code() {
			return key( $this->errors );
		}
	}
}

Monkey\setUp();
register_shutdown_function(
	static function () {
		Monkey\tearDown();
	}
);

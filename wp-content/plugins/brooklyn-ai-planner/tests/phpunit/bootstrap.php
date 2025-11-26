<?php
echo "Bootstrap loaded\n";
// phpcs:ignoreFile WordPress.Files.FileName.InvalidClassFileName
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
echo "Vendor autoloaded\n";

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

if ( ! defined( 'BATP_PLUGIN_PATH' ) ) {
	define( 'BATP_PLUGIN_PATH', dirname( __DIR__, 2 ) . '/' );
}

require dirname( __DIR__, 2 ) . '/includes/class-autoloader.php';
echo "Class autoloader included\n";

\BrooklynAI\Autoloader::register();
echo "Autoloader registered\n";

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

/*
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
*/

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

		public function get_error_message( $code = '' ) {
			if ( empty( $code ) ) {
				$code = $this->get_error_code();
			}
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ] = array( $message, $data );
		}
	}
}

echo "Starting Monkey setup\n";
if ( function_exists( 'wp_verify_nonce' ) ) {
	echo "wp_verify_nonce ALREADY EXISTS\n";
} else {
	echo "wp_verify_nonce DOES NOT EXIST\n";
}
Monkey\setUp();
echo "Monkey setup complete\n";
register_shutdown_function(
	static function () {
		Monkey\tearDown();
	}
);

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title, $fallback_title = '', $context = 'save' ) {
		return strtolower( preg_replace( '/[^a-z0-9-]/', '-', $title ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		return strip_tags( $string );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( $email, FILTER_SANITIZE_EMAIL );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		return $url;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return $data;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $target ) {
		return mkdir( $target, 0755, true );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		// Simple mock store
		global $batp_test_options;
		return isset( $batp_test_options[ $option ] ) ? $batp_test_options[ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		global $batp_test_options;
		$batp_test_options[ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

Monkey\setUp();
echo "Monkey setup complete\n";
register_shutdown_function(
	static function () {
		Monkey\tearDown();
	}
);

echo "Bootstrap complete\n";

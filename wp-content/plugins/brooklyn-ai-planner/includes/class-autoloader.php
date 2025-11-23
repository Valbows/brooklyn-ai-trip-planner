<?php
/**
 * Simple PSR-4 autoloader fallback for BrooklynAI namespace.
 *
 * @package BrooklynAI
 */

namespace BrooklynAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Autoloader {
	/**
	 * Namespace prefixes mapped to base directories.
	 */
	private const PREFIXES = array(
		'BrooklynAI\\Admin\\' => 'admin/',
		'BrooklynAI\\'        => 'includes/',
	);

	/**
	 * Registers the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register(
			array(
				new self(),
				'autoload',
			)
		);
	}

	/**
	 * Loads the class file for the given class name.
	 */
	private static function autoload( string $class_name ): void {
		foreach ( self::PREFIXES as $prefix => $dir ) {
			if ( 0 !== strpos( $class_name, $prefix ) ) {
				continue;
			}

			$relative_class = substr( $class_name, strlen( $prefix ) );
			$relative_class = str_replace( '\\', '/', strtolower( $relative_class ) );
			$segments       = explode( '/', $relative_class );
			$filename       = array_pop( $segments );
			$filename       = 'class-' . str_replace( '_', '-', $filename ) . '.php';
			$sub_path       = $segments ? implode( '/', $segments ) . '/' : '';
			$file           = BATP_PLUGIN_PATH . $dir . $sub_path . $filename;

			if ( file_exists( $file ) ) {
				require $file;
			}
		}
	}
}

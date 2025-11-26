<?php
/**
 * REST API Controller.
 *
 * @package BrooklynAI\API
 */

namespace BrooklynAI\API;

use BrooklynAI\Plugin;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Controller extends WP_REST_Controller {
	/**
	 * Namespace for this controller.
	 *
	 * @var string
	 */
	protected $namespace = 'brooklyn-ai/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'itinerary';

	/**
	 * Register the routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/events',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'track_event' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => array(
						'action_type' => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => function ( $param ) {
								return in_array( $param, array( 'website_click', 'phone_click', 'directions_click' ), true );
							},
						),
						'venue_id'    => array(
							'required' => false,
							'type'     => 'string',
						),
						'metadata'    => array(
							'required' => false,
							'type'     => 'object',
						),
						'nonce'       => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Track user interaction event.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function track_event( $request ) {
		$params = $request->get_json_params();
		$nonce  = $params['nonce'];

		// Reuse the itinerary nonce for now as it proves the user is on the page
		if ( ! wp_verify_nonce( $nonce, 'batp_generate_itinerary' ) ) {
			return new WP_Error( 'batp_invalid_nonce', 'Security check failed.', array( 'status' => 403 ) );
		}

		$logger = Plugin::instance()->analytics();
		$result = $logger->log(
			$params['action_type'],
			array(
				'venue_id' => $params['venue_id'] ?? null,
				'metadata' => $params['metadata'] ?? array(),
			)
		);

		if ( is_wp_error( $result ) ) {
			// Log error but don't fail the request to client (fire and forget from client perspective)
			error_log( 'BATP Analytics Error: ' . $result->get_error_message() );
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Check if a given request has access to create items.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		// Public endpoint, but protected by nonce and rate limiting in the Engine.
		// We allow the request to proceed to the Engine which does the heavy security checks.
		// However, basic nonce check for REST API is handled by WP if logged in,
		// but for public facing blocks, we usually rely on a specific nonce or just the rate limiter.
		// Engine::stage_guardrails checks 'batp_generate_itinerary' nonce.
		return true;
	}

	/**
	 * Create one item from the collection.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$params = $request->get_json_params();

		// Pass headers/IP context if needed by Security Manager (handled inside Engine usually via $_SERVER)
		// But here we just pass the body params.

		// Ensure nonce is passed from headers or body
		if ( ! isset( $params['nonce'] ) && $request->get_header( 'X-WP-Nonce' ) ) {
			// Actually Engine expects 'nonce' in the $request array.
			// Frontend should send it in the body for the custom check,
			// or we map X-WP-Nonce to it.
			// But Engine checks `wp_verify_nonce( $request['nonce'], 'batp_generate_itinerary' )`.
			// X-WP-Nonce is usually for 'wp_rest' action.
			// Let's expect the specific nonce in the body.
		}

		$engine = Plugin::instance()->engine();
		$result = $engine->generate_itinerary( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get the endpoint args for creating an item.
	 *
	 * @param string $method HTTP method.
	 * @return array
	 */
	public function get_endpoint_args_for_item_schema( $method = WP_REST_Server::CREATABLE ) {
		return array(
			'nonce'        => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && ! empty( $param );
				},
			),
			'interests'    => array(
				'required' => true,
				'type'     => 'array',
				'items'    => array(
					'type' => 'string',
				),
			),
			'time_window'  => array(
				'required' => false,
				'type'     => 'integer',
				'default'  => 240,
			),
			'budget'       => array(
				'required' => false,
				'type'     => 'string',
				'enum'     => array( 'low', 'medium', 'high' ),
				'default'  => 'medium',
			),
			'neighborhood' => array(
				'required' => false, // Used for geocoding if lat/lng missing
				'type'     => 'string',
			),
			'latitude'     => array(
				'required' => false,
				'type'     => 'number',
			),
			'longitude'    => array(
				'required' => false,
				'type'     => 'number',
			),
		);
	}
}

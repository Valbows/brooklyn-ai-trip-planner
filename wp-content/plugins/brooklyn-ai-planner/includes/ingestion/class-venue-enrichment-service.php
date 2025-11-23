<?php
/**
 * Venue enrichment workflow (Gemini summaries + embeddings).
 *
 * @package BrooklynAI
 */

namespace BrooklynAI\Ingestion;

use BrooklynAI\Clients\Gemini_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Venue_Enrichment_Service {
	private const SUMMARY_MAX_WORDS   = 60;
	private const EMBEDDING_DIMENSION = 768;
	private const MOCK_EMBEDDING_SALT = 'batp_mock_embedding';
	private Gemini_Client $gemini;
	private string $embedding_model;

	public function __construct( Gemini_Client $gemini, string $embedding_model = 'text-embedding-004' ) {
		$this->gemini          = $gemini;
		$this->embedding_model = $embedding_model;
	}

	/**
	 * Adds Gemini-generated summary + embedding to normalized venue row.
	 *
	 * @param array<string, mixed> $venue
	 * @return array{supabase:array<string, mixed>,pinecone:array<string, mixed>}|WP_Error
	 */
	public function enrich( array $venue, bool $dry_run = false ): array|WP_Error {
		$vibe_summary = $dry_run ? $this->mock_summary( $venue ) : $this->generate_vibe_summary( $venue );
		if ( is_wp_error( $vibe_summary ) ) {
			return $vibe_summary;
		}

		$embedding_text = $this->build_embedding_corpus( $venue, $vibe_summary );
		$embedding      = $dry_run ? $this->mock_embedding( $venue ) : $this->generate_embedding( $embedding_text );
		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		$supabase_payload = $this->build_supabase_payload( $venue, $vibe_summary, $embedding );
		$pinecone_payload = $this->build_pinecone_payload( $venue, $vibe_summary, $embedding );

		return array(
			'supabase' => $supabase_payload,
			'pinecone' => $pinecone_payload,
		);
	}

	/**
	 * @param array<string, mixed> $venue
	 * @return string|WP_Error
	 */
	private function generate_vibe_summary( array $venue ): string|WP_Error {
		$name         = isset( $venue['name'] ) ? sanitize_text_field( $venue['name'] ) : '';
		$neighborhood = isset( $venue['neighborhood'] ) ? sanitize_text_field( $venue['neighborhood'] ) : '';
		$borough      = isset( $venue['borough'] ) ? sanitize_text_field( $venue['borough'] ) : '';
		$categories   = isset( $venue['categories'] ) ? implode( ', ', (array) $venue['categories'] ) : '';

		$prompt = sprintf(
			'You are writing a concise tourism blurb. In %d words or fewer, describe %s located in %s, %s. Highlight what makes it unique using a friendly tone. Do not mention prices or contact info.',
			self::SUMMARY_MAX_WORDS,
			$name,
			$neighborhood,
			$borough
		);

		if ( '' !== $categories ) {
			$prompt .= ' Categories: ' . $categories . '.';
		}

		$payload  = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array(
						array(
							'text' => $prompt,
						),
					),
				),
			),
			'generationConfig' => array(
				'maxOutputTokens' => 256,
				'temperature'     => 0.4,
			),
		);
		$response = $this->gemini->generate_content( $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_text_from_response( $response );
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return wp_strip_all_tags( $text );
	}

	/**
	 * @return array<int, float>|WP_Error
	 */
	private function generate_embedding( string $text ): array|WP_Error {
		$payload = array(
			'content' => array(
				'parts' => array(
					array(
						'text' => $text,
					),
				),
			),
		);

		$response = $this->gemini->embed_content( $payload, $this->embedding_model );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['embedding']['values'] ) && is_array( $response['embedding']['values'] ) ) {
			return array_map( 'floatval', $response['embedding']['values'] );
		}

		if ( isset( $response['embeddings'][0]['values'] ) && is_array( $response['embeddings'][0]['values'] ) ) {
			return array_map( 'floatval', $response['embeddings'][0]['values'] );
		}

		return new WP_Error( 'batp_gemini_embedding_missing', __( 'Gemini did not return embedding values.', 'brooklyn-ai-planner' ) );
	}

	/**
	 * @param array<string, mixed> $venue
	 * @return array<string, mixed>
	 */
	private function build_supabase_payload( array $venue, string $summary, array $embedding ): array {
		$payload = array(
			'slug'                => $this->resolve_slug( $venue ),
			'name'                => sanitize_text_field( (string) ( $venue['name'] ?? '' ) ),
			'description'         => isset( $venue['description'] ) ? wp_kses_post( $venue['description'] ) : null,
			'borough'             => sanitize_text_field( (string) ( $venue['borough'] ?? '' ) ),
			'neighborhood'        => sanitize_text_field( (string) ( $venue['neighborhood'] ?? '' ) ),
			'categories'          => $this->format_pg_array( (array) ( $venue['categories'] ?? array() ) ),
			'tags'                => $this->format_pg_array( (array) ( $venue['tags'] ?? array() ) ),
			'website'             => isset( $venue['website'] ) ? esc_url_raw( $venue['website'] ) : null,
			'phone'               => isset( $venue['phone'] ) ? sanitize_text_field( $venue['phone'] ) : null,
			'email'               => isset( $venue['email'] ) ? sanitize_email( $venue['email'] ) : null,
			'address_line1'       => sanitize_text_field( (string) ( $venue['address_line1'] ?? '' ) ),
			'address_line2'       => sanitize_text_field( (string) ( $venue['address_line2'] ?? '' ) ),
			'city'                => sanitize_text_field( (string) ( $venue['city'] ?? '' ) ),
			'state'               => sanitize_text_field( (string) ( $venue['state'] ?? 'NY' ) ),
			'postal_code'         => sanitize_text_field( (string) ( $venue['postal_code'] ?? '' ) ),
			'latitude'            => $this->maybe_float( $venue['latitude'] ?? null ),
			'longitude'           => $this->maybe_float( $venue['longitude'] ?? null ),
			'vibe_summary'        => $summary,
			'embedding'           => $this->format_embedding_for_supabase( $embedding ),
			'accessibility_notes' => isset( $venue['accessibility_notes'] ) ? wp_kses_post( $venue['accessibility_notes'] ) : null,
			'media'               => $this->encode_json_or_null( $venue['media'] ?? null ),
			'hours'               => $this->encode_json_or_null( $venue['hours'] ?? null ),
		);

		return array_filter(
			$payload,
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	/**
	 * @param array<string, mixed> $venue
	 * @return array<string, mixed>
	 */
	private function build_pinecone_payload( array $venue, string $summary, array $embedding ): array {
		return array(
			'id'       => $this->resolve_slug( $venue ),
			'values'   => $embedding,
			'metadata' => array(
				'name'         => sanitize_text_field( (string) ( $venue['name'] ?? '' ) ),
				'borough'      => sanitize_text_field( (string) ( $venue['borough'] ?? '' ) ),
				'neighborhood' => sanitize_text_field( (string) ( $venue['neighborhood'] ?? '' ) ),
				'categories'   => array_values( array_map( 'sanitize_text_field', (array) ( $venue['categories'] ?? array() ) ) ),
				'tags'         => array_values( array_map( 'sanitize_text_field', (array) ( $venue['tags'] ?? array() ) ) ),
				'latitude'     => $this->maybe_float( $venue['latitude'] ?? null ),
				'longitude'    => $this->maybe_float( $venue['longitude'] ?? null ),
				'vibe_summary' => $summary,
			),
		);
	}

	/**
	 * @param array<string, mixed> $response
	 * @return string|WP_Error
	 */
	private function extract_text_from_response( array $response ): string|WP_Error {
		if ( empty( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return new WP_Error( 'batp_gemini_missing_text', __( 'Gemini response missing text.', 'brooklyn-ai-planner' ) );
		}

		return (string) $response['candidates'][0]['content']['parts'][0]['text'];
	}

	/**
	 * @param array<string, mixed> $venue
	 */
	private function build_embedding_corpus( array $venue, string $summary ): string {
		$sections = array(
			$venue['name'] ?? '',
			$summary,
			$venue['description'] ?? '',
			implode( ', ', (array) ( $venue['categories'] ?? array() ) ),
			implode( ', ', (array) ( $venue['tags'] ?? array() ) ),
		);

		return implode( PHP_EOL, array_filter( $sections ) );
	}

	/**
	 * @param array<int, string> $values
	 */
	private function format_pg_array( array $values ): string {
		if ( empty( $values ) ) {
			return '{}';
		}

		$escaped = array_map(
			static function ( string $value ): string {
				$value = str_replace( '"', '\\"', $value );
				return '"' . $value . '"';
			},
			array_values( $values )
		);

		return '{' . implode( ',', $escaped ) . '}';
	}

	/**
	 * @param array<int, float> $embedding
	 */
	private function format_embedding_for_supabase( array $embedding ): string {
		return '[' . implode( ',', $embedding ) . ']';
	}

	/**
	 * @param array<string, mixed> $venue
	 */
	private function mock_summary( array $venue ): string {
		$name    = isset( $venue['name'] ) ? $venue['name'] : 'Venue';
		$borough = isset( $venue['borough'] ) ? $venue['borough'] : 'Brooklyn';
		return sprintf( '%s in %s offers a preview of Brooklyn vibes (dry-run).', $name, $borough );
	}

	/**
	 * @param array<string, mixed> $venue
	 * @return array<int, float>
	 */
	private function mock_embedding( array $venue ): array {
		$slug   = $this->resolve_slug( $venue );
		$seed   = $slug ? $slug : (string) ( $venue['name'] ?? 'batp_venue' );
		$values = array();

		for ( $i = 0; $i < self::EMBEDDING_DIMENSION; $i++ ) {
			$digest   = hash( 'sha256', self::MOCK_EMBEDDING_SALT . '|' . $seed . '|' . $i );
			$segment  = substr( $digest, 0, 8 );
			$values[] = round( hexdec( $segment ) / 0xFFFFFFFF, 6 );
		}

		return $values;
	}

	private function resolve_slug( array $venue ): string {
		$slug_source = $venue['slug'] ?? $venue['name'] ?? uniqid( 'venue_', true );
		return sanitize_title( $slug_source );
	}

	private function encode_json_or_null( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$encoded = wp_json_encode( $value );
		return false === $encoded ? null : $encoded;
	}

	private function maybe_float( mixed $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return is_numeric( $value ) ? (float) $value : null;
	}
}

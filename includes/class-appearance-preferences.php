<?php
/**
 * Per-user DataViews appearance preferences.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Stores one sparse appearance document per user and post type.
 */
class Appearance_Preferences {
	public const META_KEY_PREFIX = 'prc_wp_admin_dataview_appearance_';
	public const VERSION         = 1;

	private const LAYOUTS = array(
		'table' => array(
			'persist_preview_size' => false,
		),
		'grid'  => array(
			'persist_preview_size' => true,
		),
		'list'  => array(
			'persist_preview_size' => false,
		),
	);

	/**
	 * List registry.
	 *
	 * @var List_Registry
	 */
	private List_Registry $lists;

	/**
	 * Constructor.
	 *
	 * @param Loader        $loader Loader.
	 * @param List_Registry $lists  List registry.
	 */
	public function __construct( $loader, List_Registry $lists ) {
		$this->lists = $lists;
		$loader->add_action( 'rest_api_init', $this, 'register_rest_endpoints' );
	}

	/**
	 * Register the appearance update route.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_endpoints(): void {
		register_rest_route(
			'prc-api/v3',
			'wp-admin-dataview/appearance',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'put_item' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'post_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'document'  => array(
							'required' => false,
						),
						'issued_at' => array(
							'type'     => 'integer',
							'required' => false,
							'minimum'  => 0,
						),
					),
				),
			)
		);
	}

	/**
	 * Require a logged-in user with access to the registered list.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function permission( WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( null === $this->lists->get( $post_type ) ) {
			return false;
		}

		return current_user_can( REST_Controller::get_capability( $post_type ) );
	}

	/**
	 * Store or reset the current user's appearance document.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function put_item( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$resolved  = $this->resolve_post_type( $post_type );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$json = $request->get_json_params();
		if ( ! is_array( $json ) || ! array_key_exists( 'document', $json ) ) {
			return self::invalid_document_error();
		}

		$raw = $json['document'];
		if ( null !== $raw ) {
			$valid = self::validate_document_structure( $raw );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		$user_id   = (int) get_current_user_id();
		$meta_key  = self::meta_key( $post_type );
		$document  = self::normalize_document( $raw );
		$issued_at = 0;
		if ( isset( $json['issued_at'] ) && is_numeric( $json['issued_at'] ) ) {
			$issued_at = (int) $json['issued_at'];
		}
		if ( $issued_at < 0 ) {
			$issued_at = 0;
		}

		// Pair the document with issued_at in one compare-and-swap write so
		// overlapping keepalive PUTs cannot pass a stale snapshot and then
		// let an older request overwrite a newer document. Failed swaps drop
		// the per-request user_meta cache before the next get_user_meta().
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$raw_stored    = get_user_meta( $user_id, $meta_key, true );
			$stored        = self::split_stored( $raw_stored );
			$current       = $stored['document'];
			$stored_issued = $stored['issued_at'];
			if ( $stored_issued <= 0 ) {
				$stored_issued = (int) get_user_meta( $user_id, self::issued_at_meta_key( $post_type ), true );
			}

			if ( $issued_at > 0 && $stored_issued > 0 && $issued_at < $stored_issued ) {
				return rest_ensure_response( array( 'document' => $current ) );
			}

			$next_issued = $issued_at > $stored_issued ? $issued_at : $stored_issued;
			if ( $issued_at <= 0 ) {
				$next_issued = 0;
			}
			if ( $document === $current && $next_issued === $stored_issued ) {
				return rest_ensure_response( array( 'document' => $document ) );
			}

			$to_store = self::join_stored( $document, $next_issued );
			if ( self::compare_and_swap_user_meta( $user_id, $meta_key, $to_store, $raw_stored ) ) {
				return rest_ensure_response( array( 'document' => $document ) );
			}
		}

		return rest_ensure_response(
			array(
				'document' => self::get_for_post_type( $user_id, $post_type ),
			)
		);
	}

	/**
	 * Read and re-sanitize one user's appearance document.
	 *
	 * @param int    $user_id   User id.
	 * @param string $post_type Post type.
	 * @return array<string, mixed>|null
	 */
	public static function get_for_post_type( int $user_id, string $post_type ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		$stored = self::split_stored( get_user_meta( $user_id, self::meta_key( $post_type ), true ) );
		return $stored['document'];
	}

	/**
	 * Normalize stored data into the sparse versioned document.
	 *
	 * Invalid stored data is equivalent to no customization.
	 *
	 * @param mixed $raw Raw document.
	 * @return array<string, mixed>|null
	 */
	public static function normalize_document( $raw ): ?array {
		if (
			! is_array( $raw ) ||
			self::VERSION !== ( $raw['version'] ?? null ) ||
			! isset( $raw['overrides'] ) ||
			! is_array( $raw['overrides'] )
		) {
			return null;
		}

		$raw_overrides = $raw['overrides'];
		$overrides     = array();

		if ( isset( self::LAYOUTS[ $raw_overrides['mode'] ?? '' ] ) ) {
			$overrides['mode'] = $raw_overrides['mode'];
		}

		if ( in_array( $raw_overrides['rowsPerPage'] ?? null, array( 10, 20, 50, 100 ), true ) ) {
			$overrides['rowsPerPage'] = $raw_overrides['rowsPerPage'];
		}

		$ordering = self::normalize_ordering( $raw_overrides['ordering'] ?? null );
		if ( null !== $ordering ) {
			$overrides['ordering'] = $ordering;
		}

		if ( isset( $raw_overrides['visibleFieldIds'] ) && is_array( $raw_overrides['visibleFieldIds'] ) ) {
			$field_ids = self::normalize_field_ids( $raw_overrides['visibleFieldIds'] );
			if ( array() === $raw_overrides['visibleFieldIds'] || array() !== $field_ids ) {
				$overrides['visibleFieldIds'] = $field_ids;
			}
		}

		if ( isset( $raw_overrides['showLevels'] ) && is_bool( $raw_overrides['showLevels'] ) ) {
			$overrides['showLevels'] = $raw_overrides['showLevels'];
		}

		foreach ( self::LAYOUTS as $mode => $config ) {
			$layout = self::normalize_layout(
				$raw_overrides[ $mode ] ?? null,
				$config['persist_preview_size']
			);
			if ( array() !== $layout ) {
				$overrides[ $mode ] = $layout;
			}
		}

		if ( isset( $raw_overrides['filters'] ) && is_array( $raw_overrides['filters'] ) ) {
			$filters = Saved_Filters::sanitize_filters( $raw_overrides['filters'] );
			if ( ! is_wp_error( $filters ) ) {
				$overrides['filters'] = $filters;
			}
		}

		if ( array() === $overrides ) {
			return null;
		}

		return array(
			'version'   => self::VERSION,
			'overrides' => $overrides,
		);
	}

	/**
	 * Build the post-type-specific user-meta key.
	 *
	 * @param string $post_type Post type.
	 */
	public static function meta_key( string $post_type ): string {
		return self::META_KEY_PREFIX . sanitize_key( $post_type );
	}

	/**
	 * Build the post-type-specific issued-at user-meta key.
	 *
	 * @param string $post_type Post type.
	 */
	public static function issued_at_meta_key( string $post_type ): string {
		return self::meta_key( $post_type ) . '_issued_at';
	}

	/**
	 * Detect the stored envelope that pairs a document with issued_at.
	 *
	 * @param mixed $raw Raw user meta.
	 * @return bool
	 */
	private static function is_stored_envelope( $raw ): bool {
		return is_array( $raw )
			&& array_key_exists( 'issued_at', $raw )
			&& array_key_exists( 'document', $raw )
			&& ! array_key_exists( 'version', $raw );
	}

	/**
	 * Split stored user meta into a document and issued-at watermark.
	 *
	 * @param mixed $raw Raw user meta.
	 * @return array{document: array<string, mixed>|null, issued_at: int}
	 */
	private static function split_stored( $raw ): array {
		if ( self::is_stored_envelope( $raw ) ) {
			return array(
				'document'  => self::normalize_document( $raw['document'] ),
				'issued_at' => (int) $raw['issued_at'],
			);
		}

		return array(
			'document'  => self::normalize_document( $raw ),
			'issued_at' => 0,
		);
	}

	/**
	 * Pair a document with its issued-at watermark for a single meta write.
	 *
	 * @param array<string, mixed>|null $document  Normalized document.
	 * @param int                       $issued_at Watermark.
	 * @return array<string, mixed>|null
	 */
	private static function join_stored( ?array $document, int $issued_at ): ?array {
		if ( $issued_at <= 0 ) {
			return $document;
		}

		return array(
			'issued_at' => $issued_at,
			'document'  => $document,
		);
	}

	/**
	 * Compare-and-swap one user-meta key.
	 *
	 * A failed add/update/delete does not refresh WordPress's per-request
	 * `user_meta` object cache. Drop that cache so the next get_user_meta()
	 * reads the database instead of retrying with the same stale snapshot.
	 *
	 * @param int    $user_id    User id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $new_value  New value, or null to delete.
	 * @param mixed  $raw_stored Value last read from get_user_meta().
	 * @return bool
	 */
	private static function compare_and_swap_user_meta( int $user_id, string $meta_key, $new_value, $raw_stored ): bool {
		$exists  = ! ( '' === $raw_stored || false === $raw_stored );
		$swapped = false;

		if ( ! $exists ) {
			if ( null === $new_value ) {
				return true;
			}

			$swapped = false !== add_user_meta( $user_id, $meta_key, $new_value, true );
		} elseif ( null === $new_value ) {
			$swapped = (bool) delete_user_meta( $user_id, $meta_key, $raw_stored );
		} else {
			$swapped = false !== update_user_meta( $user_id, $meta_key, $new_value, $raw_stored );
		}

		if ( ! $swapped ) {
			wp_cache_delete( $user_id, 'user_meta' );
		}

		return $swapped;
	}

	/**
	 * Reject documents whose structure can smuggle query state.
	 *
	 * @param mixed $raw Raw document.
	 * @return true|WP_Error
	 */
	private static function validate_document_structure( $raw ) {
		if (
			! is_array( $raw ) ||
			self::VERSION !== ( $raw['version'] ?? null ) ||
			! isset( $raw['overrides'] ) ||
			! is_array( $raw['overrides'] )
		) {
			return self::invalid_document_error();
		}

		foreach ( array( 'page', 'search' ) as $query_key ) {
			if (
				array_key_exists( $query_key, $raw ) ||
				array_key_exists( $query_key, $raw['overrides'] )
			) {
				return self::invalid_document_error();
			}
		}

		return true;
	}

	/**
	 * Normalize an ordering override.
	 *
	 * @param mixed $raw Raw ordering.
	 * @return array{fieldId: string, direction: string}|null
	 */
	private static function normalize_ordering( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$field_id  = self::sanitize_field_id( $raw['fieldId'] ?? '' );
		$direction = $raw['direction'] ?? null;
		if ( '' === $field_id || ! in_array( $direction, array( 'asc', 'desc' ), true ) ) {
			return null;
		}

		return array(
			'fieldId'   => $field_id,
			'direction' => $direction,
		);
	}

	/**
	 * Normalize an array of DataViews field ids.
	 *
	 * @param array<int, mixed> $raw Raw field ids.
	 * @return string[]
	 */
	private static function normalize_field_ids( array $raw ): array {
		$field_ids = array();
		foreach ( $raw as $field_id ) {
			if ( ! is_string( $field_id ) && ! is_int( $field_id ) ) {
				continue;
			}

			$field_id = self::sanitize_field_id( $field_id );
			if ( '' !== $field_id && ! in_array( $field_id, $field_ids, true ) ) {
				$field_ids[] = $field_id;
			}
		}

		return $field_ids;
	}

	/**
	 * Normalize layout-specific appearance.
	 *
	 * @param mixed $raw                  Raw layout.
	 * @param bool  $allow_preview_size   Whether previewSize is valid.
	 * @return array<string, mixed>
	 */
	private static function normalize_layout( $raw, bool $allow_preview_size ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$layout = array();
		if ( in_array( $raw['density'] ?? null, array( 'compact', 'balanced', 'comfortable' ), true ) ) {
			$layout['density'] = $raw['density'];
		}

		$preview_size = $raw['previewSize'] ?? null;
		if (
			$allow_preview_size &&
			( is_int( $preview_size ) || is_float( $preview_size ) ) &&
			is_finite( (float) $preview_size ) &&
			$preview_size > 0
		) {
			$layout['previewSize'] = $preview_size;
		}

		return $layout;
	}

	/**
	 * Sanitize a DataViews field id without lowercasing camelCase.
	 *
	 * @param mixed $value Raw field id.
	 */
	private static function sanitize_field_id( $value ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
	}

	/**
	 * Create the invalid-document REST error.
	 */
	private static function invalid_document_error(): WP_Error {
		return new WP_Error(
			'prc_wp_admin_dataview_appearance_document',
			__( 'Invalid appearance document.', 'prc-wp-admin-dataview' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Reject unknown list post types.
	 *
	 * @param string $post_type Post type.
	 * @return true|WP_Error
	 */
	private function resolve_post_type( string $post_type ) {
		if ( null === $this->lists->get( $post_type ) ) {
			return new WP_Error(
				'prc_wp_admin_dataview_unknown_type',
				__( 'Unknown list post type.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}
}

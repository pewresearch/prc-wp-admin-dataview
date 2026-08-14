<?php
/**
 * Per-user saved DataViews filter presets.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * User-meta storage and REST CRUD for named filter sets.
 */
class Saved_Filters {
	public const META_KEY = 'prc_wp_admin_dataview_saved_filters';

	public const MAX_PER_POST_TYPE = 25;

	/**
	 * DataViews operators accepted for stored filters.
	 *
	 * @var string[]
	 */
	public const ALLOWED_OPERATORS = array(
		'is',
		'isNot',
		'isAny',
		'isNone',
		'isAll',
		'isNotAll',
		'lessThan',
		'greaterThan',
		'lessThanOrEqual',
		'greaterThanOrEqual',
		'contains',
		'notContains',
		'startsWith',
		'before',
		'after',
		'beforeInc',
		'afterInc',
		'on',
		'notOn',
		'between',
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
	 * Register saved-filters routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_endpoints(): void {
		register_rest_route(
			'prc-api/v3',
			'wp-admin-dataview/saved-filters',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'post_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'post_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'name'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'filters'   => array(
							'type'     => 'array',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			'prc-api/v3',
			'wp-admin-dataview/saved-filters/(?P<id>[a-zA-Z0-9\-]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'post_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'id'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'name'      => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'filters'   => array(
							'type'     => 'array',
							'required' => false,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'post_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'id'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission: logged-in user with list capability for a registered post type.
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
	 * List saved filter sets for the current user and post type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$resolved  = $this->resolve_post_type( $post_type );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return rest_ensure_response( self::get_for_post_type( get_current_user_id(), $post_type ) );
	}

	/**
	 * Create a saved filter set.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$resolved  = $this->resolve_post_type( $post_type );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$name = trim( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return new WP_Error(
				'prc_wp_admin_dataview_saved_filters_name',
				__( 'A name is required.', 'prc-wp-admin-dataview' ),
				array( 'status' => 400 )
			);
		}

		$filters = self::sanitize_filters( $request->get_param( 'filters' ) );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		$user_id = get_current_user_id();
		$all     = self::get_all_for_user( $user_id );
		$sets    = $all[ $post_type ] ?? array();

		if ( count( $sets ) >= self::MAX_PER_POST_TYPE ) {
			return new WP_Error(
				'prc_wp_admin_dataview_saved_filters_cap',
				__( 'You have reached the maximum number of saved filters for this list.', 'prc-wp-admin-dataview' ),
				array( 'status' => 400 )
			);
		}

		$now = gmdate( 'c' );
		$set = array(
			'id'        => self::generate_id(),
			'name'      => $name,
			'filters'   => $filters,
			'createdAt' => $now,
			'updatedAt' => $now,
		);

		$sets[]            = $set;
		$all[ $post_type ] = $sets;
		self::write_all_for_user( $user_id, $all );

		return rest_ensure_response( $set );
	}

	/**
	 * Update a saved filter set name and/or filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$resolved  = $this->resolve_post_type( $post_type );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$id      = (string) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$all     = self::get_all_for_user( $user_id );
		$sets    = $all[ $post_type ] ?? array();
		$index   = self::find_index( $sets, $id );

		if ( null === $index ) {
			return new WP_Error(
				'prc_wp_admin_dataview_saved_filters_not_found',
				__( 'Saved filter not found.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		$set         = $sets[ $index ];
		$has_name    = null !== $request->get_param( 'name' );
		$has_filters = null !== $request->get_param( 'filters' );

		if ( ! $has_name && ! $has_filters ) {
			return new WP_Error(
				'prc_wp_admin_dataview_saved_filters_empty',
				__( 'Provide a name and/or filters to update.', 'prc-wp-admin-dataview' ),
				array( 'status' => 400 )
			);
		}

		if ( $has_name ) {
			$name = trim( (string) $request->get_param( 'name' ) );
			if ( '' === $name ) {
				return new WP_Error(
					'prc_wp_admin_dataview_saved_filters_name',
					__( 'A name is required.', 'prc-wp-admin-dataview' ),
					array( 'status' => 400 )
				);
			}
			$set['name'] = $name;
		}

		if ( $has_filters ) {
			$filters = self::sanitize_filters( $request->get_param( 'filters' ) );
			if ( is_wp_error( $filters ) ) {
				return $filters;
			}
			$set['filters'] = $filters;
		}

		$set['updatedAt']  = gmdate( 'c' );
		$sets[ $index ]    = $set;
		$all[ $post_type ] = array_values( $sets );
		self::write_all_for_user( $user_id, $all );

		return rest_ensure_response( $set );
	}

	/**
	 * Delete a saved filter set.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$resolved  = $this->resolve_post_type( $post_type );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$id      = (string) $request->get_param( 'id' );
		$user_id = get_current_user_id();
		$all     = self::get_all_for_user( $user_id );
		$sets    = $all[ $post_type ] ?? array();
		$index   = self::find_index( $sets, $id );

		if ( null === $index ) {
			return new WP_Error(
				'prc_wp_admin_dataview_saved_filters_not_found',
				__( 'Saved filter not found.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		array_splice( $sets, $index, 1 );
		$all[ $post_type ] = array_values( $sets );
		self::write_all_for_user( $user_id, $all );

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Saved sets for one post type (boot + REST).
	 *
	 * @param int    $user_id   User id.
	 * @param string $post_type Post type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_post_type( int $user_id, string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		$all       = self::get_all_for_user( $user_id );
		return array_values( $all[ $post_type ] ?? array() );
	}

	/**
	 * Full user map, sanitized.
	 *
	 * @param int $user_id User id.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function get_all_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$raw = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $post_type => $sets ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( '' === $post_type || ! is_array( $sets ) ) {
				continue;
			}
			$clean = array();
			foreach ( $sets as $set ) {
				$normalized = self::normalize_set( $set );
				if ( null !== $normalized ) {
					$clean[] = $normalized;
				}
			}
			$out[ $post_type ] = array_slice( $clean, 0, self::MAX_PER_POST_TYPE );
		}

		return $out;
	}

	/**
	 * Persist the full map for a user.
	 *
	 * @param int                                              $user_id User id.
	 * @param array<string, array<int, array<string, mixed>>> $all     Map.
	 */
	public static function write_all_for_user( int $user_id, array $all ): void {
		update_user_meta( $user_id, self::META_KEY, $all );
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

	/**
	 * Sanitize a filters array from the client.
	 *
	 * @param mixed $filters Raw filters.
	 * @return array<int, array{field: string, operator: string, value: mixed}>|WP_Error
	 */
	public static function sanitize_filters( $filters ) {
		if ( ! is_array( $filters ) ) {
			return new WP_Error(
				'prc_wp_admin_dataview_saved_filters_filters',
				__( 'Filters must be an array.', 'prc-wp-admin-dataview' ),
				array( 'status' => 400 )
			);
		}

		$clean = array();
		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}
			$field    = self::sanitize_field_id( $filter['field'] ?? '' );
			$operator = (string) ( $filter['operator'] ?? '' );
			if ( '' === $field || ! in_array( $operator, self::ALLOWED_OPERATORS, true ) ) {
				continue;
			}
			if ( ! array_key_exists( 'value', $filter ) ) {
				continue;
			}
			$clean[] = array(
				'field'    => $field,
				'operator' => $operator,
				'value'    => self::sanitize_filter_value( $filter['value'] ),
			);
		}

		return $clean;
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
	 * Recursively sanitize filter values to scalars / lists of scalars.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private static function sanitize_filter_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					continue;
				}
				if ( is_bool( $item ) || is_int( $item ) || is_float( $item ) ) {
					$out[] = $item;
				} else {
					$out[] = sanitize_text_field( (string) $item );
				}
			}
			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}
		return sanitize_text_field( (string) $value );
	}

	/**
	 * Normalize a stored set or return null if invalid.
	 *
	 * @param mixed $set Raw set.
	 * @return array<string, mixed>|null
	 */
	private static function normalize_set( $set ): ?array {
		if ( ! is_array( $set ) ) {
			return null;
		}
		$id   = sanitize_text_field( (string) ( $set['id'] ?? '' ) );
		$name = sanitize_text_field( (string) ( $set['name'] ?? '' ) );
		if ( '' === $id || '' === $name ) {
			return null;
		}
		$filters = self::sanitize_filters( $set['filters'] ?? array() );
		if ( is_wp_error( $filters ) ) {
			return null;
		}
		return array(
			'id'        => $id,
			'name'      => $name,
			'filters'   => $filters,
			'createdAt' => sanitize_text_field( (string) ( $set['createdAt'] ?? '' ) ),
			'updatedAt' => sanitize_text_field( (string) ( $set['updatedAt'] ?? '' ) ),
		);
	}

	/**
	 * Find set index by id.
	 *
	 * @param array<int, array<string, mixed>> $sets Sets.
	 * @param string                           $id   Id.
	 */
	private static function find_index( array $sets, string $id ): ?int {
		foreach ( $sets as $index => $set ) {
			if ( ( $set['id'] ?? '' ) === $id ) {
				return (int) $index;
			}
		}
		return null;
	}

	/**
	 * Generate a uuid-like id without requiring ramsey/uuid.
	 */
	private static function generate_id(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
		$hex     = bin2hex( $data );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}

<?php
/**
 * REST routes for shared DataViews admin lists.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

/**
 * List + field-update endpoints owned by the shell.
 */
class REST_Controller {
	/**
	 * Default list query statuses. Trash is opt-in via the status filter.
	 *
	 * @var string[]
	 */
	public const DEFAULT_LIST_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * List registry.
	 *
	 * @var List_Registry
	 */
	private List_Registry $lists;

	/**
	 * Duplicator.
	 *
	 * @var Post_Duplicator
	 */
	private Post_Duplicator $duplicator;

	/**
	 * Constructor.
	 *
	 * @param Loader          $loader     Loader.
	 * @param List_Registry   $lists      List registry.
	 * @param Post_Duplicator $duplicator Duplicator.
	 */
	public function __construct( $loader, List_Registry $lists, Post_Duplicator $duplicator ) {
		$this->lists      = $lists;
		$this->duplicator = $duplicator;
		$loader->add_action( 'rest_api_init', $this, 'register_rest_endpoints' );
	}

	/**
	 * Register routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_endpoints(): void {
		register_rest_route(
			'prc-api/v3',
			'wp-admin-dataview/list',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_list' ),
				'permission_callback' => array( $this, 'list_permission' ),
				'args'                => array(
					'post_type' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'search'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'    => array(
						'type'              => 'string',
						'default'           => implode( ',', self::DEFAULT_LIST_STATUSES ),
						'sanitize_callback' => 'sanitize_text_field',
					),
					'orderby'   => array(
						'type'    => 'string',
						'default' => 'date',
					),
					'order'     => array(
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => array( 'asc', 'desc', 'ASC', 'DESC' ),
					),
					'parentFamily' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'author'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'author_exclude' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			'prc-api/v3',
			'wp-admin-dataview/terms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_terms' ),
				'permission_callback' => array( $this, 'list_permission' ),
				'args'                => array(
					'post_type' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'taxonomy'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'prc-api/v3',
			'wp-admin-dataview/field',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_field' ),
				'permission_callback' => array( $this, 'update_permission' ),
				'args'                => array(
					'postId'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'field'    => array(
						'type'              => 'string',
						'required'          => true,
						// Keep camelCase ids (seoTitle). sanitize_key lowercases them.
						'sanitize_callback' => array( $this, 'sanitize_field_id' ),
					),
					'value'    => array(
						'required' => false,
					),
					'postType' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			'prc-api/v3',
			'wp-admin-dataview/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'duplicate_post' ),
				'permission_callback' => array( $this, 'duplicate_permission' ),
				'args'                => array(
					'postId'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'postType' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Sanitize a DataViews field id without lowercasing camelCase.
	 *
	 * @param mixed $value Raw field id.
	 * @return string
	 */
	public function sanitize_field_id( $value ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
	}

	/**
	 * Capability for a registered list post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function get_capability( string $post_type ): string {
		$post_type_object = get_post_type_object( $post_type );
		if ( $post_type_object && isset( $post_type_object->cap->edit_posts ) ) {
			return (string) $post_type_object->cap->edit_posts;
		}
		return 'edit_posts';
	}

	/**
	 * List permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function list_permission( WP_REST_Request $request ): bool {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( null === $this->lists->get( $post_type ) ) {
			return false;
		}
		return current_user_can( self::get_capability( $post_type ) );
	}

	/**
	 * Update permission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function update_permission( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'postId' );
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Paginated list for DataViews.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_list( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( null === $this->lists->get( $post_type ) ) {
			return new WP_Error( 'prc_wp_admin_dataview_unknown_type', __( 'Unknown list post type.', 'prc-wp-admin-dataview' ), array( 'status' => 404 ) );
		}

		$query_args = self::build_list_query_args( $request, $post_type );
		$query      = new WP_Query( $query_args );
		$rows       = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$rows[] = $this->shape_row( $post, $post_type );
			}
		}

		$response = rest_ensure_response( $rows );
		$response->header( 'X-WP-Total', (string) (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) (int) $query->max_num_pages );
		return $response;
	}

	/**
	 * Build WP_Query args for a DataViews list request.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param string          $post_type Post type.
	 * @return array<string, mixed>
	 */
	public static function build_list_query_args( WP_REST_Request $request, string $post_type ): array {
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$search   = (string) $request->get_param( 'search' );
		$status   = (string) $request->get_param( 'status' );
		$orderby  = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$order    = strtoupper( (string) $request->get_param( 'order' ) );
		$order    = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

		$statuses = array_values(
			array_filter(
				array_map( 'sanitize_key', explode( ',', $status ) )
			)
		);
		if ( empty( $statuses ) ) {
			$statuses = self::DEFAULT_LIST_STATUSES;
		}

		// sanitize_key() lowercases; WP_Query expects uppercase ID.
		$allowed_orderby = array( 'date', 'modified', 'title', 'author', 'id' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'date';
		} elseif ( 'id' === $orderby ) {
			$orderby = 'ID';
		}

		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => $statuses,
			'perm'                   => 'editable',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => $orderby,
			'order'                  => $order,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
		);

		$author_ids = self::parse_author_ids( (string) $request->get_param( 'author' ) );
		if ( 1 === count( $author_ids ) ) {
			$query_args['author'] = $author_ids[0];
		} elseif ( count( $author_ids ) > 1 ) {
			$query_args['author__in'] = $author_ids;
		}

		$author_exclude_ids = self::parse_author_ids( (string) $request->get_param( 'author_exclude' ) );
		if ( ! empty( $author_exclude_ids ) ) {
			$query_args['author__not_in'] = $author_exclude_ids;
		}

		$query_args = apply_filters( Provider_Registry::FILTER_QUERY_ARGS, $query_args, $request, $post_type );

		return Search_Query::apply( $query_args, $search, $statuses );
	}

	/**
	 * Parse comma-separated author IDs from a list query param.
	 *
	 * @param string $raw Comma-separated author IDs.
	 * @return int[]
	 */
	private static function parse_author_ids( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'absint', explode( ',', $raw ) )
			)
		);
	}

	/**
	 * Term options for a registry taxonomy on a registered list type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_terms( WP_REST_Request $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$taxonomy  = sanitize_key( (string) $request->get_param( 'taxonomy' ) );

		if ( ! Taxonomy_Fields_Provider::is_registered_taxonomy( $taxonomy ) ) {
			return new WP_Error(
				'prc_wp_admin_dataview_unknown_taxonomy',
				__( 'Unknown taxonomy.', 'prc-wp-admin-dataview' ),
				array( 'status' => 400 )
			);
		}

		if ( ! Taxonomy_Fields_Provider::supports_taxonomy( $post_type, $taxonomy ) ) {
			return rest_ensure_response( array() );
		}

		return rest_ensure_response( Taxonomy_Fields_Provider::get_term_options( $taxonomy ) );
	}

	/**
	 * Shape a list row and let providers enrich it.
	 *
	 * @param WP_Post $post      Post.
	 * @param string  $post_type Post type.
	 * @return array<string, mixed>
	 */
	public function shape_row( WP_Post $post, string $post_type ): array {
		$author = get_userdata( (int) $post->post_author );
		$row    = array(
			'id'             => (int) $post->ID,
			'title'          => get_the_title( $post ),
			'status'         => $post->post_status,
			'previousStatus' => (string) get_post_meta( $post->ID, '_wp_trash_meta_status', true ),
			'author'         => $author ? plain_text( (string) $author->display_name ) : '',
			'authorId'       => (int) $post->post_author,
			'date'           => get_post_time( 'c', true, $post ),
			'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
		);

		if ( post_type_supports( $post_type, 'thumbnail' ) ) {
			$thumb = get_the_post_thumbnail_url( $post, 'medium' );
			$row['featuredImage'] = $thumb ? (string) $thumb : '';
		} else {
			$row['featuredImage'] = '';
		}

		$row = apply_filters( Provider_Registry::FILTER_SHAPE_ROW, $row, $post, $post_type );

		$decode_keys = array( 'title', 'parentPostTitle', 'author' );
		if ( class_exists( Taxonomy_Fields_Provider::class ) ) {
			foreach ( Taxonomy_Fields_Provider::registry() as $entry ) {
				if ( ! empty( $entry['fieldId'] ) ) {
					$decode_keys[] = (string) $entry['fieldId'];
				}
			}
		}
		foreach ( array_unique( $decode_keys ) as $key ) {
			if ( isset( $row[ $key ] ) && is_string( $row[ $key ] ) ) {
				$row[ $key ] = plain_text( $row[ $key ] );
			}
		}

		return $row;
	}

	/**
	 * Inline field update via provider filter.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_field( WP_REST_Request $request ) {
		$post_id   = (int) $request->get_param( 'postId' );
		$field     = $this->sanitize_field_id( $request->get_param( 'field' ) );
		$value     = $request->get_param( 'value' );
		$post_type = sanitize_key( (string) $request->get_param( 'postType' ) );

		if ( '' === $field ) {
			return new WP_Error( 'prc_wp_admin_dataview_bad_field', __( 'Invalid field.', 'prc-wp-admin-dataview' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || $post->post_type !== $post_type ) {
			return new WP_Error( 'prc_wp_admin_dataview_bad_post', __( 'Post not found.', 'prc-wp-admin-dataview' ), array( 'status' => 404 ) );
		}

		$result = apply_filters( Provider_Registry::FILTER_UPDATE_FIELD, null, $post_id, $field, $value, $post_type );

		if ( $result instanceof WP_Error ) {
			return $result;
		}
		if ( true !== $result ) {
			return new WP_Error( 'prc_wp_admin_dataview_unknown_field', __( 'No provider handled this field.', 'prc-wp-admin-dataview' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'row'     => $this->shape_row( get_post( $post_id ), $post_type ),
			)
		);
	}

	/**
	 * Duplicate permission.
	 *
	 * Unknown type or disabled duplicate is 404. Cap failure is 403.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function duplicate_permission( WP_REST_Request $request ) {
		$post_id   = (int) $request->get_param( 'postId' );
		$post_type = sanitize_key( (string) $request->get_param( 'postType' ) );
		$post      = get_post( $post_id );

		if ( ! $post instanceof WP_Post || $post->post_type !== $post_type ) {
			return new WP_Error(
				'prc_wp_admin_dataview_bad_post',
				__( 'Post not found.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		$config = $this->lists->get( $post_type );
		if ( null === $config ) {
			return new WP_Error(
				'prc_wp_admin_dataview_unknown_type',
				__( 'Unknown list post type.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		$args = Duplicate_Args::resolve( $config, $post_type, $post );
		if ( empty( $args['enabled'] ) ) {
			return new WP_Error(
				'prc_wp_admin_dataview_duplicate_disabled',
				__( 'Duplicate is disabled for this post type.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		return $this->duplicator->can_duplicate( $post );
	}

	/**
	 * Duplicate a registered list post into a new draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_post( WP_REST_Request $request ) {
		$post_id   = (int) $request->get_param( 'postId' );
		$post_type = sanitize_key( (string) $request->get_param( 'postType' ) );
		$post      = get_post( $post_id );

		if ( ! $post instanceof WP_Post || $post->post_type !== $post_type ) {
			return new WP_Error(
				'prc_wp_admin_dataview_bad_post',
				__( 'Post not found.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		$config = $this->lists->get( $post_type );
		if ( null === $config ) {
			return new WP_Error(
				'prc_wp_admin_dataview_unknown_type',
				__( 'Unknown list post type.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		$args = Duplicate_Args::resolve( $config, $post_type, $post );
		if ( empty( $args['enabled'] ) ) {
			return new WP_Error(
				'prc_wp_admin_dataview_duplicate_disabled',
				__( 'Duplicate is disabled for this post type.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		$new_id = $this->duplicator->duplicate( $post );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$edit_url = get_edit_post_link( $new_id, 'raw' );

		return rest_ensure_response(
			array(
				'id'       => $new_id,
				'edit_url' => is_string( $edit_url ) ? $edit_url : '',
			)
		);
	}
}

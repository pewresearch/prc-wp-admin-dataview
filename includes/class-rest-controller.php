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
						'default'           => 'publish,draft,pending,private,future',
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
			$statuses = array( 'publish', 'draft', 'pending', 'private', 'future' );
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

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$query_args = apply_filters( Provider_Registry::FILTER_QUERY_ARGS, $query_args, $request, $post_type );

		$query = new WP_Query( $query_args );
		$rows  = array();
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
	 * Shape a list row and let providers enrich it.
	 *
	 * @param WP_Post $post      Post.
	 * @param string  $post_type Post type.
	 * @return array<string, mixed>
	 */
	public function shape_row( WP_Post $post, string $post_type ): array {
		$author = get_userdata( (int) $post->post_author );
		$row    = array(
			'id'       => (int) $post->ID,
			'title'    => get_the_title( $post ),
			'status'   => $post->post_status,
			'author'   => $author ? $author->display_name : '',
			'date'     => get_post_time( 'c', true, $post ),
			'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
		);

		if ( post_type_supports( $post_type, 'thumbnail' ) ) {
			$thumb = get_the_post_thumbnail_url( $post, 'medium' );
			$row['featuredImage'] = $thumb ? (string) $thumb : '';
		} else {
			$row['featuredImage'] = '';
		}

		return apply_filters( Provider_Registry::FILTER_SHAPE_ROW, $row, $post, $post_type );
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
}

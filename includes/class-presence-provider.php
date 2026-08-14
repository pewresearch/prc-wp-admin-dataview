<?php
/**
 * Presence filter provider for shared DataViews lists.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Filters lists to posts with active editors.
 */
class Presence_Provider {
	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_filter( Provider_Registry::FILTER_LOCALIZE, $this, 'localize', 10, 2 );
		$loader->add_filter( Provider_Registry::FILTER_QUERY_ARGS, $this, 'query_args', 10, 3 );
	}

	/**
	 * Tell the client when Presence is available for a post type.
	 *
	 * @param array  $localize Localized shell data.
	 * @param string $post_type Current post type.
	 * @return array
	 */
	public function localize( $localize, $post_type ) {
		if ( ! self::supports_post_type( (string) $post_type ) ) {
			return $localize;
		}

		$localize['presence'] = array(
			'enabled' => true,
		);

		return $localize;
	}

	/**
	 * Filter the list to posts that have active Presence entries.
	 *
	 * @param array            $query_args Query arguments.
	 * @param \WP_REST_Request $request    REST request.
	 * @param string           $post_type  Current post type.
	 * @return array
	 */
	public function query_args( $query_args, $request, $post_type ) {
		if (
			! self::supports_post_type( (string) $post_type )
			|| ! $request instanceof \WP_REST_Request
			|| 'active' !== sanitize_key( (string) $request->get_param( 'activeEditors' ) )
		) {
			return $query_args;
		}

		$active_ids = self::get_active_post_ids( (string) $post_type );
		if ( isset( $query_args['post__in'] ) && is_array( $query_args['post__in'] ) ) {
			$active_ids = array_values(
				array_intersect(
					array_map( 'intval', $query_args['post__in'] ),
					$active_ids
				)
			);
		}

		$query_args['post__in'] = empty( $active_ids ) ? array( 0 ) : $active_ids;
		return $query_args;
	}

	/**
	 * Check whether a post type has Presence rooms.
	 *
	 * @param string $post_type Post type.
	 */
	public static function supports_post_type( string $post_type ): bool {
		return function_exists( 'wp_get_presence_by_room_prefix' )
			&& post_type_supports( $post_type, 'presence' );
	}

	/**
	 * Get unique post IDs from active rooms for one post type.
	 *
	 * @param string $post_type Post type.
	 * @return int[]
	 */
	public static function get_active_post_ids( string $post_type ): array {
		if ( ! function_exists( 'wp_get_presence_by_room_prefix' ) ) {
			return array();
		}

		$prefix = 'postType/' . sanitize_key( $post_type ) . ':';
		$ids    = array();

		foreach ( wp_get_presence_by_room_prefix( $prefix ) as $entry ) {
			$room = isset( $entry->room ) ? (string) $entry->room : '';
			if ( ! str_starts_with( $room, $prefix ) ) {
				continue;
			}

			$post_id = (int) substr( $room, strlen( $prefix ) );
			if ( $post_id > 0 ) {
				$ids[ $post_id ] = $post_id;
			}
		}

		return array_values( $ids );
	}
}

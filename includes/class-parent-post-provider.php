<?php
/**
 * Parent Post field + filter for DataViews post lists.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Post;
use WP_Query;
use WP_REST_Request;

/**
 * Ports the mu-plugin Parent Post ACP column onto the shared shell.
 */
class Parent_Post_Provider {
	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( $loader ) {
		$loader->add_filter( 'prc_wp_admin_dataview_shape_row', $this, 'shape_row', 10, 3 );
		$loader->add_filter( 'prc_wp_admin_dataview_query_args', $this, 'query_args', 10, 3 );
	}

	/**
	 * Whether the post type supports parent-family filters and parent column data.
	 *
	 * Core posts (report packages / chapters) and hierarchical types (e.g. page).
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function supports_parent_family( string $post_type ): bool {
		return 'post' === $post_type || is_post_type_hierarchical( $post_type );
	}

	/**
	 * Resolve a parentFamily query value.
	 *
	 * Returns null when the filter should be ignored (missing / invalid N).
	 * Returns 0 when N is positive but the parent is missing or wrong type
	 * (fail closed). Returns a positive parent ID when valid.
	 *
	 * @param mixed  $raw       Raw request value.
	 * @param string $post_type List post type.
	 * @return int|null
	 */
	public static function resolve_parent_family( $raw, string $post_type ): ?int {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		$parent_id = (int) $raw;
		if ( $parent_id <= 0 ) {
			return null;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post || $parent->post_type !== $post_type ) {
			return 0;
		}

		return $parent_id;
	}

	/**
	 * Apply parent-family constraint to query args.
	 *
	 * @param array $query_args Query args.
	 * @param int   $parent_id  Resolved parent ID (0 = empty result).
	 * @return array
	 */
	public function apply_parent_family( array $query_args, int $parent_id ): array {
		if ( $parent_id <= 0 ) {
			$query_args['post__in'] = array( 0 );
			return $query_args;
		}

		$query_args['prc_wp_admin_dataview_parent_family'] = $parent_id;
		add_filter( 'posts_where', array( $this, 'where_parent_family' ), 10, 2 );
		return $query_args;
	}

	/**
	 * Enrich rows with parent post data.
	 *
	 * @param array   $row       Row.
	 * @param WP_Post $post      Post.
	 * @param string  $post_type Post type.
	 * @return array
	 */
	public function shape_row( $row, $post, $post_type ) {
		if ( ! $post instanceof WP_Post || ! self::supports_parent_family( (string) $post_type ) ) {
			return $row;
		}

		$parent_id                = (int) $post->post_parent;
		$row['parentPostId']      = $parent_id;
		$row['parentPostTitle']   = '';
		$row['parentPostEditUrl'] = '';

		if ( 'post' === $post_type ) {
			$row['isReportPackageChapter'] = false;
		}

		if ( $parent_id > 0 ) {
			$parent = get_post( $parent_id );
			if ( $parent instanceof WP_Post ) {
				$row['parentPostTitle']   = get_the_title( $parent );
				$row['parentPostEditUrl'] = (string) get_edit_post_link( $parent_id, 'raw' );
			}

			if (
				'post' === $post_type
				&& function_exists( '\\PRC\\Platform\\Report_Package\\is_chapter_part_of_report_package' )
			) {
				$row['isReportPackageChapter'] = (bool) \PRC\Platform\Report_Package\is_chapter_part_of_report_package( $parent_id );
			}
		}

		return $row;
	}

	/**
	 * Map parentPost / parentFamily filters to WP_Query args.
	 *
	 * @param array           $query_args Query args.
	 * @param WP_REST_Request $request    Request.
	 * @param string          $post_type  Post type.
	 * @return array
	 */
	public function query_args( $query_args, $request, $post_type ) {
		if ( ! is_array( $query_args ) || ! $request instanceof WP_REST_Request ) {
			return $query_args;
		}

		$post_type = (string) $post_type;

		if ( 'post' === $post_type ) {
			$filter = sanitize_key( (string) $request->get_param( 'parentPost' ) );
			if ( 'parent_posts' === $filter ) {
				$query_args['post_parent'] = 0;
			} elseif ( 'child_posts' === $filter ) {
				$query_args['prc_wp_admin_dataview_child_posts'] = true;
				add_filter( 'posts_where', array( $this, 'where_child_posts' ), 10, 2 );
			}
		}

		if ( self::supports_parent_family( $post_type ) ) {
			$resolved = self::resolve_parent_family( $request->get_param( 'parentFamily' ), $post_type );
			if ( null !== $resolved ) {
				$query_args = $this->apply_parent_family( $query_args, $resolved );
			}
		}

		return $query_args;
	}

	/**
	 * Restrict the query to posts that have a parent.
	 *
	 * @param string   $where SQL WHERE.
	 * @param WP_Query $query Query.
	 * @return string
	 */
	public function where_child_posts( $where, $query ) {
		if ( ! $query instanceof WP_Query || empty( $query->get( 'prc_wp_admin_dataview_child_posts' ) ) ) {
			return $where;
		}

		remove_filter( 'posts_where', array( $this, 'where_child_posts' ), 10 );

		global $wpdb;
		$where .= " AND {$wpdb->posts}.post_parent != 0";
		return $where;
	}

	/**
	 * Restrict the query to a parent row and its direct children.
	 *
	 * @param string   $where SQL WHERE.
	 * @param WP_Query $query Query.
	 * @return string
	 */
	public function where_parent_family( $where, $query ) {
		if ( ! $query instanceof WP_Query ) {
			return $where;
		}

		$parent_id = (int) $query->get( 'prc_wp_admin_dataview_parent_family' );
		if ( $parent_id <= 0 ) {
			return $where;
		}

		remove_filter( 'posts_where', array( $this, 'where_parent_family' ), 10 );

		global $wpdb;
		$where .= $wpdb->prepare(
			" AND ( {$wpdb->posts}.ID = %d OR {$wpdb->posts}.post_parent = %d )",
			$parent_id,
			$parent_id
		);
		return $where;
	}
}

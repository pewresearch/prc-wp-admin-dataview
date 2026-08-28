<?php
/**
 * Copies a post into a new draft.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare(strict_types=1);

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Error;
use WP_Post;

/**
 * New-draft post copy owned by the DataViews shell.
 */
class Post_Duplicator {
	/**
	 * List registry.
	 *
	 * @var List_Registry
	 */
	private List_Registry $lists;

	/**
	 * Constructor.
	 *
	 * @param List_Registry $lists List registry.
	 */
	public function __construct( List_Registry $lists ) {
		$this->lists = $lists;
	}

	/**
	 * Whether the current user may duplicate this post.
	 *
	 * @param WP_Post $post Source post.
	 */
	public function can_duplicate( WP_Post $post ): bool {
		if ( 'trash' === $post->post_status || 'auto-draft' === $post->post_status ) {
			return false;
		}

		$config = $this->lists->get( $post->post_type );
		if ( null === $config ) {
			return false;
		}

		$args = Duplicate_Args::resolve( $config, $post->post_type, $post );
		if ( empty( $args['enabled'] ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		return current_user_can( self::create_capability( $post->post_type ) );
	}

	/**
	 * Duplicate a post into a draft owned by the current user.
	 *
	 * @param WP_Post $post Source post.
	 * @return int|WP_Error New post ID or error.
	 */
	public function duplicate( WP_Post $post ) {
		if ( ! $this->can_duplicate( $post ) ) {
			return new WP_Error(
				'prc_wp_admin_dataview_cannot_duplicate',
				__( 'You cannot duplicate this item.', 'prc-wp-admin-dataview' ),
				array( 'status' => 403 )
			);
		}

		$args  = Duplicate_Args::resolve(
			$this->lists->get( $post->post_type ),
			$post->post_type,
			$post
		);
		$title = (string) $post->post_title;
		$suffix = trim( (string) $args['titleSuffix'] );
		if ( '' !== $suffix ) {
			$title = trim( $title . ' ' . $suffix );
		}

		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_author'           => get_current_user_id(),
					'post_content'          => $post->post_content,
					'post_content_filtered' => $post->post_content_filtered,
					'post_title'            => $title,
					'post_excerpt'          => $post->post_excerpt,
					'post_status'           => 'draft',
					'post_type'             => $post->post_type,
					'comment_status'        => $post->comment_status,
					'ping_status'           => $post->ping_status,
					'post_password'         => '',
					'post_name'             => '',
					'post_parent'           => $this->resolve_post_parent( $post ),
					'menu_order'            => $post->menu_order,
					'post_mime_type'        => $post->post_mime_type,
				)
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		$new_id = (int) $new_id;
		$this->copy_taxonomies( $new_id, $post, $args );
		$this->copy_meta( $new_id, $post, $args );

		if ( is_callable( $args['callback'] ) ) {
			call_user_func( $args['callback'], $new_id, $post );
		}

		do_action( Provider_Registry::ACTION_DUPLICATED, $new_id, $post, $args );

		return $new_id;
	}

	/**
	 * Create-posts capability for a type.
	 *
	 * @param string $post_type Post type.
	 */
	public static function create_capability( string $post_type ): string {
		$post_type_object = get_post_type_object( $post_type );
		if ( $post_type_object && isset( $post_type_object->cap->create_posts ) ) {
			return (string) $post_type_object->cap->create_posts;
		}
		return 'edit_posts';
	}

	/**
	 * Copy taxonomies, skipping excluded ones.
	 *
	 * @param int                  $new_id New post ID.
	 * @param WP_Post              $post   Source post.
	 * @param array<string, mixed> $args   Resolved args.
	 */
	private function copy_taxonomies( int $new_id, WP_Post $post, array $args ): void {
		$taxonomies = get_object_taxonomies( $post->post_type );
		if ( ! is_array( $taxonomies ) ) {
			return;
		}

		$excluded = $args['excludeTaxonomies'] ?? array();
		if ( ! is_array( $excluded ) ) {
			$excluded = array();
		}

		if ( in_array( 'category', $taxonomies, true ) && ! in_array( 'category', $excluded, true ) ) {
			wp_set_object_terms( $new_id, array(), 'category' );
		}

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_string( $taxonomy ) || in_array( $taxonomy, $excluded, true ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			wp_set_object_terms( $new_id, $terms, $taxonomy );
		}
	}

	/**
	 * Parent for the copy. Report chapters detach from the live package.
	 *
	 * @param WP_Post $post Source post.
	 */
	private function resolve_post_parent( WP_Post $post ): int {
		$post_parent = (int) $post->post_parent;
		if ( $post_parent <= 0 ) {
			return 0;
		}

		if ( ! function_exists( 'PRC\Platform\Report_Package\is_chapter_part_of_report_package' ) ) {
			return $post_parent;
		}

		if ( \PRC\Platform\Report_Package\is_chapter_part_of_report_package( (int) $post->ID ) ) {
			return 0;
		}

		return $post_parent;
	}

	/**
	 * Copy post meta that is on the include list and not excluded.
	 *
	 * An empty include list copies no meta.
	 *
	 * @param int                  $new_id New post ID.
	 * @param WP_Post              $post   Source post.
	 * @param array<string, mixed> $args   Resolved args.
	 */
	private function copy_meta( int $new_id, WP_Post $post, array $args ): void {
		$keys = get_post_custom_keys( $post->ID );
		if ( empty( $keys ) || ! is_array( $keys ) ) {
			return;
		}

		$included = $args['includeMeta'] ?? array();
		if ( ! is_array( $included ) ) {
			$included = array();
		}

		$excluded = $args['excludeMeta'] ?? array();
		if ( ! is_array( $excluded ) ) {
			$excluded = array();
		}

		foreach ( $keys as $meta_key ) {
			if ( ! is_string( $meta_key ) ) {
				continue;
			}
			if ( ! Duplicate_Args::meta_is_included( $meta_key, $included ) ) {
				continue;
			}
			if ( Duplicate_Args::meta_is_excluded( $meta_key, $excluded ) ) {
				continue;
			}

			$values = get_post_custom_values( $meta_key, $post->ID );
			if ( ! is_array( $values ) ) {
				continue;
			}

			delete_post_meta( $new_id, $meta_key );
			foreach ( $values as $value ) {
				add_post_meta( $new_id, $meta_key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}
}

<?php
/**
 * Admin-bar Tools item and admin.php?action= duplicate handler.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare(strict_types=1);

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Post;

/**
 * Non-DataViews entry point for the same duplicator.
 */
class Duplicate_UI {
	/**
	 * Admin action name.
	 */
	public const ACTION = 'prc_wp_admin_dataview_duplicate';

	/**
	 * Running instance for the Tools node helper.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

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
	 * @param Post_Duplicator $duplicator Duplicator.
	 */
	public function __construct( $loader, Post_Duplicator $duplicator ) {
		$this->duplicator = $duplicator;
		self::$instance   = $this;
		$loader->add_action( 'admin_action_' . self::ACTION, $this, 'handle_admin_action' );
	}

	/**
	 * Tools admin-bar node, or null when the current screen has no copyable post.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function tools_node(): ?array {
		if ( ! self::$instance ) {
			return null;
		}
		return self::$instance->build_tools_node();
	}

	/**
	 * Handle admin.php?action=prc_wp_admin_dataview_duplicate.
	 */
	public function handle_admin_action(): void {
		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( self::ACTION . '_' . $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			wp_die( esc_html__( 'Post not found.', 'prc-wp-admin-dataview' ) );
		}

		$result = $this->duplicator->duplicate( $post );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$edit_url = get_edit_post_link( $result, 'raw' );
		if ( ! is_string( $edit_url ) || '' === $edit_url ) {
			wp_die( esc_html__( 'Copy created, but the editor URL is missing.', 'prc-wp-admin-dataview' ) );
		}

		wp_safe_redirect( $edit_url );
		exit;
	}

	/**
	 * Build the Tools child node.
	 *
	 * @return array<string, mixed>|null
	 */
	private function build_tools_node(): ?array {
		$post = $this->current_post();
		if ( ! $post instanceof WP_Post || ! $this->duplicator->can_duplicate( $post ) ) {
			return null;
		}

		$confirm_message = __( 'Are you sure you want to duplicate this item?', 'prc-wp-admin-dataview' );

		return array(
			'id'     => 'prc-wp-admin-dataview-duplicate',
			'title'  => __( 'Duplicate', 'prc-wp-admin-dataview' ),
			'href'   => wp_nonce_url(
				admin_url( 'admin.php?action=' . self::ACTION . '&post=' . $post->ID ),
				self::ACTION . '_' . $post->ID
			),
			'parent' => 'tools',
			'meta'   => array(
				'title'   => __( 'Copy this item to a new draft', 'prc-wp-admin-dataview' ),
				'onclick' => 'return confirm(' . wp_json_encode( $confirm_message ) . ');',
			),
		);
	}

	/**
	 * Post for the current admin or singular screen.
	 */
	private function current_post(): ?WP_Post {
		$post = get_post();
		if ( $post instanceof WP_Post && $post->ID > 0 ) {
			return $post;
		}

		if ( is_admin() && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$from_query = get_post( absint( wp_unslash( $_GET['post'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $from_query instanceof WP_Post ) {
				return $from_query;
			}
		}

		return null;
	}
}

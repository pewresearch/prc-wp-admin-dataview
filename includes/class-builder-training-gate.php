<?php
/**
 * Add New gate for builders with training modules.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Dataview;

use PRC\Platform\Wp_Admin_Tours\Training_Gate;
use PRC\Platform\Wp_Admin_Tours\Training_Module_Registry;
use PRC\Platform\Wp_Admin_Tours\Training_Progress;
use PRC\Platform\Wp_Admin_Tours\Training_Sandbox;
use function PRC\Platform\Wp_Admin_Tours\is_builder_unlocked;

/**
 * Soft-depends on prc-wp-admin-tours training modules. A list id (the post
 * type) is the builder key. A locked user sees a training CTA instead of
 * Add New, and `post-new.php` for that post type starts the training.
 */
class Builder_Training_Gate {
	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( $loader ) {
		$loader->add_filter( Provider_Registry::FILTER_HIDE_NEW_BUTTON, $this, 'filter_hide_new_button', 10, 3 );
		$loader->add_filter( Provider_Registry::FILTER_LOCALIZE, $this, 'filter_localize', 10, 2 );
		$loader->add_action( 'load-post-new.php', $this, 'redirect_new_post_to_training' );
	}

	/**
	 * Whether the prc-wp-admin-tours training API is loaded.
	 */
	public static function is_available(): bool {
		return function_exists( '\PRC\Platform\Wp_Admin_Tours\is_builder_unlocked' )
			&& class_exists( Training_Gate::class )
			&& class_exists( Training_Module_Registry::class )
			&& class_exists( Training_Progress::class )
			&& class_exists( Training_Sandbox::class );
	}

	/**
	 * Whether a user must finish training before creating content in a builder.
	 *
	 * False when training is missing or not rolled out to the user, or the
	 * builder has no modules.
	 *
	 * @param int    $user_id User id.
	 * @param string $builder Builder key (post type).
	 */
	public static function is_locked( int $user_id, string $builder ): bool {
		if ( ! self::is_available() || ! Training_Gate::is_enabled( $user_id ) ) {
			return false;
		}
		return ! is_builder_unlocked( $user_id, $builder );
	}

	/**
	 * The module a locked user should take next: the first unfinished module
	 * whose required modules are complete.
	 *
	 * @param int    $user_id User id.
	 * @param string $builder Builder key (post type).
	 * @return array<string, mixed>|null
	 */
	public static function next_module( int $user_id, string $builder ): ?array {
		$registry = self::is_available() ? Training_Module_Registry::instance() : null;
		if ( null === $registry ) {
			return null;
		}

		foreach ( $registry->for_builder( $builder ) as $module ) {
			if ( Training_Progress::is_complete( $user_id, (string) $module['id'] ) ) {
				continue;
			}
			$blocked = array_filter(
				(array) $module['requires'],
				static fn( $required ): bool => ! Training_Progress::is_complete( $user_id, (string) $required )
			);
			if ( array() === $blocked ) {
				return $module;
			}
		}

		return null;
	}

	/**
	 * Training CTA for the current user, or null when the builder is not locked.
	 *
	 * `startUrl` carries a nonce for the current user, so never build this for another user.
	 *
	 * @param string $builder Builder key (post type).
	 * @return array{moduleId: string, startUrl: string, label: string, description: string}|null
	 */
	public static function cta_for_current_user( string $builder ): ?array {
		$user_id = (int) get_current_user_id();
		if ( ! self::is_locked( $user_id, $builder ) ) {
			return null;
		}

		$module = self::next_module( $user_id, $builder );
		if ( null === $module ) {
			return null;
		}

		$pto      = get_post_type_object( $builder );
		$singular = $pto && ! empty( $pto->labels->singular_name ) ? strtolower( (string) $pto->labels->singular_name ) : $builder;
		$plural   = $pto && ! empty( $pto->labels->name ) ? strtolower( (string) $pto->labels->name ) : $builder;
		$started  = 'in_progress' === Training_Progress::get( $user_id, (string) $module['id'] )['status'];

		return array(
			'moduleId'    => (string) $module['id'],
			'startUrl'    => Training_Sandbox::start_url( (string) $module['id'] ),
			'label'       => $started
				/* translators: %s: lowercase post type singular name, e.g. "chart". */
				? sprintf( __( 'Continue %s training', 'prc-wp-admin-dataview' ), $singular )
				/* translators: %s: lowercase post type singular name, e.g. "chart". */
				: sprintf( __( 'Start %s training', 'prc-wp-admin-dataview' ), $singular ),
			'description' => sprintf(
				/* translators: 1: training module title, 2: lowercase post type plural name, e.g. "charts". */
				__( 'Finish “%1$s” to add new %2$s.', 'prc-wp-admin-dataview' ),
				(string) $module['title'],
				$plural
			),
		);
	}

	/**
	 * Hide Add New on a builder's list until the user finishes its training.
	 *
	 * @hook prc_wp_admin_dataview_hide_new_button
	 *
	 * @param bool   $hide    Whether the button is already hidden.
	 * @param string $list_id List id (post type).
	 * @param int    $user_id User id.
	 */
	public function filter_hide_new_button( $hide, $list_id, $user_id ): bool {
		return (bool) $hide || self::is_locked( (int) $user_id, (string) $list_id );
	}

	/**
	 * Add the training CTA to the list boot data as `training`.
	 *
	 * @hook prc_wp_admin_dataview_localize
	 *
	 * @param array<string, mixed> $data    Boot data.
	 * @param string               $list_id List id (post type).
	 * @return array<string, mixed>
	 */
	public function filter_localize( $data, $list_id ): array {
		$data = (array) $data;
		if ( List_Registry::KIND_COLLECTION !== ( $data['kind'] ?? '' ) ) {
			$data['training'] = self::cta_for_current_user( (string) $list_id );
		}
		return $data;
	}

	/**
	 * Send a locked user from `post-new.php` into the builder's training.
	 *
	 * @hook load-post-new.php
	 */
	public function redirect_new_post_to_training(): void {
		$post_type = isset( $_GET['post_type'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'post';

		$cta = self::cta_for_current_user( $post_type );
		if ( null !== $cta ) {
			wp_safe_redirect( $cta['startUrl'] );
			exit;
		}

		if ( self::is_locked( (int) get_current_user_id(), $post_type ) ) {
			wp_die(
				esc_html__( 'Finish this builder’s training before you add new content.', 'prc-wp-admin-dataview' ),
				'',
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}
}

<?php
/**
 * Registry of post types that use the shared DataViews admin shell.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Holds list screen configs keyed by post type.
 */
class List_Registry {
	/**
	 * Registered configs.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $lists = array();

	/**
	 * Register a list config.
	 *
	 * @param array<string, mixed> $config List config.
	 */
	public function register( array $config ): void {
		$post_type = isset( $config['postType'] ) ? sanitize_key( (string) $config['postType'] ) : '';
		$page_slug = isset( $config['pageSlug'] ) ? sanitize_key( (string) $config['pageSlug'] ) : '';

		if ( '' === $post_type || '' === $page_slug ) {
			return;
		}

		$merged = array_merge(
			array(
				'postType'             => $post_type,
				'pageSlug'             => $page_slug,
				'menuTitle'            => '',
				'pageTitle'            => '',
				'restPath'             => '/prc-api/v3/wp-admin-dataview/list',
				'hideDefaultNewButton' => false,
				'menuParent'           => '',
				'newUrl'               => null,
			),
			$config,
			array(
				'postType' => $post_type,
				'pageSlug' => $page_slug,
			)
		);
		$merged['duplicate'] = Duplicate_Args::normalize( $config['duplicate'] ?? array() );

		$this->lists[ $post_type ] = $merged;

		if ( function_exists( 'add_post_type_support' ) ) {
			add_post_type_support( $post_type, 'prc-wp-admin-dataview' );
		}
	}

	/**
	 * Get a list config by post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<string, mixed>|null
	 */
	public function get( string $post_type ): ?array {
		return $this->lists[ sanitize_key( $post_type ) ] ?? null;
	}

	/**
	 * All registered configs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		return $this->lists;
	}

	/**
	 * Find a config by admin page slug.
	 *
	 * @param string $page_slug Admin page slug.
	 * @return array<string, mixed>|null
	 */
	public function get_by_page_slug( string $page_slug ): ?array {
		$page_slug = sanitize_key( $page_slug );
		foreach ( $this->lists as $config ) {
			if ( ( $config['pageSlug'] ?? '' ) === $page_slug ) {
				return $config;
			}
		}
		return null;
	}
}

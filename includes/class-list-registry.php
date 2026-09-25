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
	public const KIND_POST_TYPE  = 'post-type';
	public const KIND_COLLECTION = 'collection';

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

		$kind = self::KIND_COLLECTION === ( $config['kind'] ?? '' ) ? self::KIND_COLLECTION : self::KIND_POST_TYPE;
		// A collection has no posts behind it, so the shared list endpoint cannot serve it.
		if ( self::KIND_COLLECTION === $kind && empty( $config['restPath'] ) ) {
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
				'kind'     => $kind,
			)
		);

		if ( self::KIND_COLLECTION === $kind ) {
			$merged['duplicate']            = Duplicate_Args::normalize( array( 'enabled' => false ) );
			$merged['newUrl']               = '';
			$merged['hideDefaultNewButton'] = true;
			$this->lists[ $post_type ]      = $merged;
			return;
		}

		$merged['duplicate'] = Duplicate_Args::normalize( $config['duplicate'] ?? array() );

		$this->lists[ $post_type ] = $merged;

		if ( function_exists( 'add_post_type_support' ) ) {
			add_post_type_support( $post_type, 'prc-wp-admin-dataview' );
		}
	}

	/**
	 * Whether a list config is a non-post collection.
	 *
	 * @param array<string, mixed>|null $config List config.
	 * @return bool
	 */
	public static function is_collection( ?array $config ): bool {
		return self::KIND_COLLECTION === ( $config['kind'] ?? '' );
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

<?php
/**
 * Resolved duplicate-post arguments for a list post type.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare(strict_types=1);

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Post;

/**
 * Normalizes and resolves per-type duplicate args.
 */
class Duplicate_Args {
	/**
	 * Default title suffix appended to the copy.
	 */
	public const DEFAULT_TITLE_SUFFIX = '(Copy)';

	/**
	 * Meta keys that must never copy, for every type.
	 *
	 * Trailing `*` matches a prefix.
	 *
	 * @return array<int, string>
	 */
	public static function platform_exclude_meta(): array {
		return array(
			'_edit_lock',
			'_edit_last',
			'_dp_*',
			'_prc_fork_*',
			'_prc_active_fork',
			'_prc_public_revisions',
			'apple_news_*',
			'_apple_news_*',
		);
	}

	/**
	 * Shared editorial meta that post, page, and like-types may copy.
	 *
	 * Does not include report-package graph keys (`multiSectionReport`, `package_parts`).
	 *
	 * @return array<int, string>
	 */
	public static function content_include_meta(): array {
		return array(
			'bylines',
			'acknowledgements',
			'displayBylines',
			'relatedPosts',
			'reportMaterials',
			'_prc_seo_data',
			'artDirection',
			'_thumbnail_id',
			'_wp_page_template',
		);
	}

	/**
	 * List-config defaults before platform excludes and filters.
	 *
	 * Empty `includeMeta` copies no meta.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'           => true,
			'titleSuffix'       => self::DEFAULT_TITLE_SUFFIX,
			'includeMeta'       => array(),
			'excludeMeta'       => array(),
			'excludeTaxonomies' => array(),
			'callback'          => null,
		);
	}

	/**
	 * Normalize a raw `duplicate` list-config value.
	 *
	 * @param mixed $duplicate Raw config.
	 * @return array<string, mixed>
	 */
	public static function normalize( mixed $duplicate ): array {
		$input      = is_array( $duplicate ) ? $duplicate : array();
		$defaults   = self::defaults();
		$normalized = array_merge( $defaults, $input );

		$normalized['enabled']     = (bool) $normalized['enabled'];
		$normalized['titleSuffix'] = is_string( $normalized['titleSuffix'] )
			? $normalized['titleSuffix']
			: $defaults['titleSuffix'];
		$normalized['includeMeta']       = self::string_list( $normalized['includeMeta'] ?? array() );
		$normalized['excludeMeta']       = self::string_list( $normalized['excludeMeta'] ?? array() );
		$normalized['excludeTaxonomies'] = self::string_list( $normalized['excludeTaxonomies'] ?? array() );
		$callback                        = $normalized['callback'] ?? null;
		$normalized['callback']          = is_callable( $callback ) ? $callback : null;

		return $normalized;
	}

	/**
	 * Resolve args for a post type.
	 * Platform excludes re-apply after the filter.
	 *
	 * @param array<string, mixed>|null $list_config Registered list config.
	 * @param string                    $post_type   Post type.
	 * @param WP_Post|null              $source      Source post when copying.
	 * @return array<string, mixed>
	 */
	public static function resolve( ?array $list_config, string $post_type, ?WP_Post $source = null ): array {
		if ( null === $list_config ) {
			$normalized = self::normalize( array( 'enabled' => false ) );
		} else {
			$normalized = self::normalize( $list_config['duplicate'] ?? array() );
		}
		$normalized['excludeMeta'] = self::merge_platform_excludes( $normalized['excludeMeta'] );

		$resolved = apply_filters(
			Provider_Registry::FILTER_DUPLICATE_ARGS,
			$normalized,
			$post_type,
			$source
		);
		if ( ! is_array( $resolved ) ) {
			$resolved = $normalized;
		}

		$resolved['includeMeta'] = self::string_list( $resolved['includeMeta'] ?? array() );
		$resolved['excludeMeta'] = self::merge_platform_excludes(
			is_array( $resolved['excludeMeta'] ?? null ) ? $resolved['excludeMeta'] : array()
		);
		if ( null === $list_config ) {
			$resolved['enabled'] = false;
		}

		return $resolved;
	}

	/**
	 * Public duplicate config for `wp_localize_script`. Resolves args and drops the PHP callback.
	 *
	 * @param array<string, mixed>|null $list_config Registered list config.
	 * @param string                    $post_type   Post type.
	 * @return array<string, mixed>
	 */
	public static function for_client( ?array $list_config, string $post_type ): array {
		$resolved = self::resolve( $list_config, $post_type );
		unset( $resolved['callback'] );
		return $resolved;
	}

	/**
	 * Whether a meta key is on the include list.
	 *
	 * An empty list includes nothing. Pattern `*` includes every key.
	 *
	 * @param string             $key      Meta key.
	 * @param array<int, string> $patterns Exact keys or trailing `*`.
	 */
	public static function meta_is_included( string $key, array $patterns ): bool {
		if ( empty( $patterns ) ) {
			return false;
		}
		return self::meta_matches( $key, $patterns );
	}

	/**
	 * Whether a meta key matches an exclude pattern.
	 *
	 * @param string             $key      Meta key.
	 * @param array<int, string> $patterns Exact keys or trailing `*`.
	 */
	public static function meta_is_excluded( string $key, array $patterns ): bool {
		return self::meta_matches( $key, $patterns );
	}

	/**
	 * Whether a meta key matches any pattern.
	 *
	 * @param string             $key      Meta key.
	 * @param array<int, string> $patterns Exact keys or trailing `*`.
	 */
	public static function meta_matches( string $key, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( ! is_string( $pattern ) || '' === $pattern ) {
				continue;
			}
			if ( str_ends_with( $pattern, '*' ) ) {
				$prefix = substr( $pattern, 0, -1 );
				if ( '' === $prefix || str_starts_with( $key, $prefix ) ) {
					return true;
				}
				continue;
			}
			if ( $key === $pattern ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Merge platform never-copy keys onto a list of excludes.
	 *
	 * @param array<int, string> $exclude_meta Type or filter excludes.
	 * @return array<int, string>
	 */
	private static function merge_platform_excludes( array $exclude_meta ): array {
		return self::string_list(
			array_merge(
				self::platform_exclude_meta(),
				$exclude_meta
			)
		);
	}

	/**
	 * Sanitize a list of strings.
	 *
	 * @param mixed $value Raw list.
	 * @return array<int, string>
	 */
	private static function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$item = trim( $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return array_values( array_unique( $out ) );
	}
}

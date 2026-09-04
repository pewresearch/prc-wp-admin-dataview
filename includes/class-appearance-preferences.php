<?php
/**
 * Appearance document seed for DataViews lists.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Reads and normalizes one sparse appearance document per user and post type.
 */
class Appearance_Preferences {
	public const META_KEY_PREFIX = 'prc_wp_admin_dataview_appearance_';
	public const VERSION         = 1;

	private const LAYOUTS = array(
		'table' => array(
			'persist_preview_size' => false,
		),
		'grid'  => array(
			'persist_preview_size' => true,
		),
		'list'  => array(
			'persist_preview_size' => false,
		),
	);

	/**
	 * Read and re-sanitize one user's appearance document.
	 *
	 * @param int    $user_id   User id.
	 * @param string $post_type Post type.
	 * @return array<string, mixed>|null
	 */
	public static function get_for_post_type( int $user_id, string $post_type ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		$stored = self::split_stored( get_user_meta( $user_id, self::meta_key( $post_type ), true ) );
		return $stored['document'];
	}

	/**
	 * Normalize stored data into the sparse versioned document.
	 *
	 * Invalid stored data is equivalent to no customization.
	 *
	 * @param mixed $raw Raw document.
	 * @return array<string, mixed>|null
	 */
	public static function normalize_document( $raw ): ?array {
		if (
			! is_array( $raw ) ||
			self::VERSION !== ( $raw['version'] ?? null ) ||
			! isset( $raw['overrides'] ) ||
			! is_array( $raw['overrides'] )
		) {
			return null;
		}

		$raw_overrides = $raw['overrides'];
		$overrides     = array();

		if ( isset( self::LAYOUTS[ $raw_overrides['mode'] ?? '' ] ) ) {
			$overrides['mode'] = $raw_overrides['mode'];
		}

		if ( in_array( $raw_overrides['rowsPerPage'] ?? null, array( 10, 20, 50, 100 ), true ) ) {
			$overrides['rowsPerPage'] = $raw_overrides['rowsPerPage'];
		}

		$ordering = self::normalize_ordering( $raw_overrides['ordering'] ?? null );
		if ( null !== $ordering ) {
			$overrides['ordering'] = $ordering;
		}

		if ( isset( $raw_overrides['visibleFieldIds'] ) && is_array( $raw_overrides['visibleFieldIds'] ) ) {
			$field_ids = self::normalize_field_ids( $raw_overrides['visibleFieldIds'] );
			if ( array() === $raw_overrides['visibleFieldIds'] || array() !== $field_ids ) {
				$overrides['visibleFieldIds'] = $field_ids;
			}
		}

		if ( isset( $raw_overrides['showLevels'] ) && is_bool( $raw_overrides['showLevels'] ) ) {
			$overrides['showLevels'] = $raw_overrides['showLevels'];
		}

		foreach ( self::LAYOUTS as $mode => $config ) {
			$layout = self::normalize_layout(
				$raw_overrides[ $mode ] ?? null,
				$config['persist_preview_size']
			);
			if ( array() !== $layout ) {
				$overrides[ $mode ] = $layout;
			}
		}

		if ( isset( $raw_overrides['filters'] ) && is_array( $raw_overrides['filters'] ) ) {
			$filters = Saved_Filters::sanitize_filters( $raw_overrides['filters'] );
			if ( ! is_wp_error( $filters ) ) {
				$overrides['filters'] = $filters;
			}
		}

		if ( array() === $overrides ) {
			return null;
		}

		return array(
			'version'   => self::VERSION,
			'overrides' => $overrides,
		);
	}

	/**
	 * Build the post-type-specific user-meta key.
	 *
	 * @param string $post_type Post type.
	 */
	public static function meta_key( string $post_type ): string {
		return self::META_KEY_PREFIX . sanitize_key( $post_type );
	}

	/**
	 * Detect the stored envelope that pairs a document with issued_at.
	 *
	 * @param mixed $raw Raw user meta.
	 * @return bool
	 */
	private static function is_stored_envelope( $raw ): bool {
		return is_array( $raw )
			&& array_key_exists( 'issued_at', $raw )
			&& array_key_exists( 'document', $raw )
			&& ! array_key_exists( 'version', $raw );
	}

	/**
	 * Split stored user meta into a document and issued-at watermark.
	 *
	 * @param mixed $raw Raw user meta.
	 * @return array{document: array<string, mixed>|null, issued_at: int}
	 */
	private static function split_stored( $raw ): array {
		if ( self::is_stored_envelope( $raw ) ) {
			return array(
				'document'  => self::normalize_document( $raw['document'] ),
				'issued_at' => (int) $raw['issued_at'],
			);
		}

		return array(
			'document'  => self::normalize_document( $raw ),
			'issued_at' => 0,
		);
	}

	/**
	 * Normalize an ordering override.
	 *
	 * @param mixed $raw Raw ordering.
	 * @return array{fieldId: string, direction: string}|null
	 */
	private static function normalize_ordering( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$field_id  = self::sanitize_field_id( $raw['fieldId'] ?? '' );
		$direction = $raw['direction'] ?? null;
		if ( '' === $field_id || ! in_array( $direction, array( 'asc', 'desc' ), true ) ) {
			return null;
		}

		return array(
			'fieldId'   => $field_id,
			'direction' => $direction,
		);
	}

	/**
	 * Normalize an array of DataViews field ids.
	 *
	 * @param array<int, mixed> $raw Raw field ids.
	 * @return string[]
	 */
	private static function normalize_field_ids( array $raw ): array {
		$field_ids = array();
		foreach ( $raw as $field_id ) {
			if ( ! is_string( $field_id ) && ! is_int( $field_id ) ) {
				continue;
			}

			$field_id = self::sanitize_field_id( $field_id );
			if ( '' !== $field_id && ! in_array( $field_id, $field_ids, true ) ) {
				$field_ids[] = $field_id;
			}
		}

		return $field_ids;
	}

	/**
	 * Normalize layout-specific appearance.
	 *
	 * @param mixed $raw                  Raw layout.
	 * @param bool  $allow_preview_size   Whether previewSize is valid.
	 * @return array<string, mixed>
	 */
	private static function normalize_layout( $raw, bool $allow_preview_size ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$layout = array();
		if ( in_array( $raw['density'] ?? null, array( 'compact', 'balanced', 'comfortable' ), true ) ) {
			$layout['density'] = $raw['density'];
		}

		$preview_size = $raw['previewSize'] ?? null;
		if (
			$allow_preview_size &&
			( is_int( $preview_size ) || is_float( $preview_size ) ) &&
			is_finite( (float) $preview_size ) &&
			$preview_size > 0
		) {
			$layout['previewSize'] = $preview_size;
		}

		return $layout;
	}

	/**
	 * Sanitize a DataViews field id without lowercasing camelCase.
	 *
	 * @param mixed $value Raw field id.
	 */
	private static function sanitize_field_id( $value ): string {
		return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
	}
}

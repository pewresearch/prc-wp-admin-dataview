<?php
/**
 * Cross-type taxonomy fields + filters for DataViews lists.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Post;
use WP_REST_Request;

/**
 * Registry-driven taxonomy columns, filter elements, tax_query mapping, and row labels.
 */
class Taxonomy_Fields_Provider {
	/**
	 * Soft cap for term options embedded in list boot data.
	 *
	 * Taxonomies above this count (e.g. Topics, Bylines) omit elements so
	 * every DataViews list load does not serialize thousands of terms.
	 */
	public const MAX_BOOT_TERM_ELEMENTS = 250;

	/**
	 * Taxonomy field definitions.
	 *
	 * @return array<int, array{taxonomy: string, fieldId: string, label: string, defaultVisible: bool}>
	 */
	public static function registry(): array {
		return array(
			array(
				'taxonomy'       => 'formats',
				'fieldId'        => 'formats',
				'label'          => __( 'Format', 'prc-wp-admin-dataview' ),
				'defaultVisible' => true,
			),
			array(
				'taxonomy'       => 'research-teams',
				'fieldId'        => 'researchTeams',
				'label'          => __( 'Research Team', 'prc-wp-admin-dataview' ),
				'defaultVisible' => false,
			),
			array(
				'taxonomy'       => 'regions-countries',
				'fieldId'        => 'regionsCountries',
				'label'          => __( 'Regions & Countries', 'prc-wp-admin-dataview' ),
				'defaultVisible' => false,
			),
			array(
				'taxonomy'       => 'languages',
				'fieldId'        => 'languages',
				'label'          => __( 'Languages', 'prc-wp-admin-dataview' ),
				'defaultVisible' => false,
			),
			array(
				'taxonomy'       => 'category',
				'fieldId'        => 'topics',
				'label'          => __( 'Topics', 'prc-wp-admin-dataview' ),
				'defaultVisible' => false,
			),
			array(
				'taxonomy'       => 'mode-of-analysis',
				'fieldId'        => 'modeOfAnalysis',
				'label'          => __( 'Mode of Analysis', 'prc-wp-admin-dataview' ),
				'defaultVisible' => false,
			),
			array(
				'taxonomy'       => 'bylines',
				'fieldId'        => 'bylines',
				'label'          => __( 'Bylines', 'prc-wp-admin-dataview' ),
				'defaultVisible' => false,
			),
		);
	}

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( $loader ) {
		$loader->add_filter( Provider_Registry::FILTER_LOCALIZE, $this, 'localize', 10, 2 );
		$loader->add_filter( Provider_Registry::FILTER_QUERY_ARGS, $this, 'query_args', 10, 3 );
		$loader->add_filter( Provider_Registry::FILTER_SHAPE_ROW, $this, 'shape_row', 10, 3 );
	}

	/**
	 * Whether the post type can use a taxonomy field.
	 *
	 * @param string $post_type Post type.
	 * @param string $taxonomy  Taxonomy slug.
	 * @return bool
	 */
	public static function supports_taxonomy( string $post_type, string $taxonomy ): bool {
		return taxonomy_exists( $taxonomy )
			&& is_object_in_taxonomy( $post_type, $taxonomy );
	}

	/**
	 * Registry rows supported by the post type.
	 *
	 * @param string $post_type Post type.
	 * @return array<int, array{taxonomy: string, fieldId: string, label: string, defaultVisible: bool}>
	 */
	public static function supported_entries( string $post_type ): array {
		return array_values(
			array_filter(
				self::registry(),
				static function ( array $entry ) use ( $post_type ): bool {
					return self::supports_taxonomy( $post_type, $entry['taxonomy'] );
				}
			)
		);
	}

	/**
	 * Term options for DataViews filter elements.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function get_term_options( string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $count ) || (int) $count > self::MAX_BOOT_TERM_ELEMENTS ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => self::MAX_BOOT_TERM_ELEMENTS,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_values(
			array_map(
				static function ( $term ) {
					return array(
						'value' => (string) $term->slug,
						'label' => (string) $term->name,
					);
				},
				$terms
			)
		);
	}

	/**
	 * Pass taxonomy field boot data to the shell.
	 *
	 * @param array  $localize  Localized shell data.
	 * @param string $post_type Current post type.
	 * @return array
	 */
	public function localize( $localize, $post_type ) {
		if ( ! is_array( $localize ) ) {
			return $localize;
		}

		$taxonomies = array();
		foreach ( self::supported_entries( (string) $post_type ) as $entry ) {
			$taxonomies[ $entry['fieldId'] ] = array(
				'taxonomy'       => $entry['taxonomy'],
				'label'          => $entry['label'],
				'elements'       => self::get_term_options( $entry['taxonomy'] ),
				'defaultVisible' => (bool) $entry['defaultVisible'],
			);
		}

		if ( empty( $taxonomies ) ) {
			return $localize;
		}

		$localize['taxonomies'] = $taxonomies;
		return $localize;
	}

	/**
	 * Map taxonomy filter params to a merged tax_query.
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

		$clauses = array();
		if ( isset( $query_args['tax_query'] ) && is_array( $query_args['tax_query'] ) ) {
			foreach ( $query_args['tax_query'] as $key => $clause ) {
				if ( 'relation' === $key ) {
					continue;
				}
				if ( is_array( $clause ) ) {
					$clauses[] = $clause;
				}
			}
		}

		foreach ( self::supported_entries( (string) $post_type ) as $entry ) {
			$raw = (string) $request->get_param( $entry['fieldId'] );
			if ( '' === $raw ) {
				continue;
			}

			$slugs = array_values(
				array_filter( array_map( 'sanitize_title', explode( ',', $raw ) ) )
			);
			if ( empty( $slugs ) ) {
				continue;
			}

			$clauses[] = array(
				'taxonomy' => $entry['taxonomy'],
				'field'    => 'slug',
				'terms'    => $slugs,
			);
		}

		if ( empty( $clauses ) ) {
			return $query_args;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		$query_args['tax_query'] = $clauses;
		if ( count( $clauses ) > 1 ) {
			$query_args['tax_query']['relation'] = 'AND';
		}

		return $query_args;
	}

	/**
	 * Enrich rows with comma-joined taxonomy label strings.
	 *
	 * @param array   $row       Row.
	 * @param WP_Post $post      Post.
	 * @param string  $post_type Post type.
	 * @return array
	 */
	public function shape_row( $row, $post, $post_type ) {
		if ( ! is_array( $row ) || ! $post instanceof WP_Post ) {
			return $row;
		}

		foreach ( self::supported_entries( (string) $post_type ) as $entry ) {
			$terms = get_the_terms( $post, $entry['taxonomy'] );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				$row[ $entry['fieldId'] ] = '';
				continue;
			}

			$labels = array_map(
				static function ( $term ) {
					return is_object( $term ) && isset( $term->name ) ? (string) $term->name : '';
				},
				$terms
			);
			$labels = array_values( array_filter( $labels ) );

			$row[ $entry['fieldId'] ] = implode( ', ', $labels );
		}

		return $row;
	}
}

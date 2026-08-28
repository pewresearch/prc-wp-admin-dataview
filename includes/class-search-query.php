<?php
/**
 * ElasticPress-aware search args for DataViews list queries.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare(strict_types=1);

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Maps a DataViews search term onto WP_Query args.
 *
 * Soft-depends on ElasticPress: `ep_integrate` is ignored when EP is not loaded.
 * Trash-only lists, chart lists, and `meta_query` filters stay on MySQL.
 * Chart search uses title (plus design_slug via Chart_List), not post_content.
 */
class Search_Query {
	/**
	 * WP_Query flag so ElasticPress can target admin list searches.
	 */
	public const QUERY_FLAG = 'prc_wp_admin_dataview';

	/**
	 * Max characters in a search term (matches prc-elasticpress).
	 */
	public const TERM_MAX_LENGTH = 100;

	/**
	 * Apply search to query args. Call after provider `query_args` filters
	 * so numeric ID `post__in` intersects Presence / Parent constraints.
	 *
	 * @param array<string, mixed> $query_args Query args.
	 * @param string               $search     Raw search term.
	 * @param string[]             $statuses   Post statuses for this list query.
	 * @return array<string, mixed>
	 */
	public static function apply( array $query_args, string $search, array $statuses ): array {
		$search = self::sanitize_term( $search );
		if ( '' === $search ) {
			unset( $query_args['s'] );
			return $query_args;
		}

		$query_args['s']                = $search;
		$query_args[ self::QUERY_FLAG ] = true;

		if ( self::is_mysql_search( $query_args, $statuses ) ) {
			$query_args['ep_integrate'] = false;
		} else {
			$query_args['ep_integrate'] = true;
		}

		$orderby = $query_args['orderby'] ?? 'date';
		if ( 'date' === $orderby ) {
			$query_args['orderby'] = 'relevance';
			unset( $query_args['order'] );
		}

		if ( self::is_chart_query( $query_args ) ) {
			// Chart post_content is block JSON + table data. LIKE on it matches
			// common words ("U.S.", "Democrats") in almost every chart and
			// hides the title hits editors can see on the unfiltered list.
			$query_args['search_columns'] = array( 'post_title' );
		}

		return self::apply_numeric_id( $query_args, $search );
	}

	/**
	 * Truncate and sanitize a search string.
	 *
	 * @param string $term Raw term.
	 * @return string
	 */
	public static function sanitize_term( string $term ): string {
		return substr( sanitize_text_field( $term ), 0, self::TERM_MAX_LENGTH );
	}

	/**
	 * Whether the status set is trash only.
	 *
	 * @param string[] $statuses Statuses.
	 * @return bool
	 */
	public static function is_trash_only( array $statuses ): bool {
		$statuses = array_values(
			array_filter(
				array_map( 'strval', $statuses )
			)
		);
		return 1 === count( $statuses ) && 'trash' === $statuses[0];
	}

	/**
	 * Whether this search must stay on MySQL.
	 *
	 * Chart documents in the current ES index omit `chart_type` terms, and
	 * VIP Search does not index most DataViews meta keys. Those searches
	 * return zero hits when ElasticPress integrates.
	 *
	 * @param array<string, mixed> $query_args Query args.
	 * @param string[]             $statuses   Post statuses.
	 * @return bool
	 */
	public static function is_mysql_search( array $query_args, array $statuses ): bool {
		return self::is_trash_only( $statuses )
			|| self::has_meta_query( $query_args )
			|| self::is_chart_query( $query_args );
	}

	/**
	 * Whether the query is limited to the chart post type.
	 *
	 * @param array<string, mixed> $query_args Query args.
	 * @return bool
	 */
	public static function is_chart_query( array $query_args ): bool {
		$post_type = $query_args['post_type'] ?? '';
		if ( is_array( $post_type ) ) {
			$post_type = array_values( array_map( 'strval', $post_type ) );
			return 1 === count( $post_type ) && 'chart' === $post_type[0];
		}
		return 'chart' === $post_type;
	}

	/**
	 * Whether the query includes a `meta_query`.
	 *
	 * VIP Search only indexes allow-listed meta. Library and shell filters
	 * (watchers, dataset ZIP, quiz type, form action, email status) are not
	 * listed, so search stays on MySQL.
	 *
	 * @param array<string, mixed> $query_args Query args.
	 * @return bool
	 */
	public static function has_meta_query( array $query_args ): bool {
		$meta_query = $query_args['meta_query'] ?? null;
		return is_array( $meta_query ) && array() !== $meta_query;
	}

	/**
	 * Restrict `post__in` when the term is a post ID.
	 *
	 * Drop `s` and ElasticPress integration. `s` plus `post__in` is an
	 * intersection, so a digit-only lookup would miss when the ID is not
	 * in title or body.
	 *
	 * @param array<string, mixed> $query_args Query args.
	 * @param string               $search     Sanitized search term.
	 * @return array<string, mixed>
	 */
	public static function apply_numeric_id( array $query_args, string $search ): array {
		if ( ! ctype_digit( $search ) ) {
			return $query_args;
		}

		$id = (int) $search;
		if ( $id < 1 ) {
			return $query_args;
		}

		if ( isset( $query_args['post__in'] ) && is_array( $query_args['post__in'] ) ) {
			$intersected            = array_values(
				array_intersect(
					array_map( 'intval', $query_args['post__in'] ),
					array( $id )
				)
			);
			$query_args['post__in'] = empty( $intersected ) ? array( 0 ) : $intersected;
		} else {
			$query_args['post__in'] = array( $id );
		}

		unset( $query_args['s'] );
		$query_args['ep_integrate'] = false;

		return $query_args;
	}
}

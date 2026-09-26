<?php
/**
 * Map DataViews date filter REST params onto WP_Query date_query.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare(strict_types=1);

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_REST_Request;

/**
 * Applies `after`, `before`, `date_on`, and `date_from`/`date_to` list query args.
 *
 * Custom list REST routes that run `prc_wp_admin_dataview_query_args`
 * pick this up without a second mapper.
 */
class Date_Query {
	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_filter( Provider_Registry::FILTER_QUERY_ARGS, $this, 'query_args', 5, 3 );
	}

	/**
	 * Filter callback.
	 *
	 * @param array           $query_args Query arguments.
	 * @param WP_REST_Request $request    REST request.
	 * @param string          $post_type  Current post type.
	 * @return array<string, mixed>
	 */
	public function query_args( $query_args, $request, $post_type ) {
		unset( $post_type );
		if ( ! is_array( $query_args ) || ! $request instanceof WP_REST_Request ) {
			return $query_args;
		}
		return self::apply( $query_args, $request );
	}

	/**
	 * Merge request date bounds into WP_Query args.
	 *
	 * @param array           $query_args Query arguments.
	 * @param WP_REST_Request $request    REST request.
	 * @return array<string, mixed>
	 */
	public static function apply( array $query_args, WP_REST_Request $request ): array {
		$clauses = array();

		$on = self::parse_day( (string) $request->get_param( 'date_on' ) );
		if ( null !== $on ) {
			$clauses[] = array(
				'column' => 'post_date',
				'year'   => $on['year'],
				'month'  => $on['month'],
				'day'    => $on['day'],
			);
		}

		$after  = self::parse_datetime( (string) $request->get_param( 'after' ) );
		$before = self::parse_datetime( (string) $request->get_param( 'before' ) );
		if ( null !== $after || null !== $before ) {
			$clause = array(
				'column' => 'post_date',
			);
			if ( null !== $after ) {
				$clause['after'] = $after;
			}
			if ( null !== $before ) {
				$clause['before'] = $before;
			}
			$clauses[] = $clause;
		}

		$range = self::range_clause(
			(string) $request->get_param( 'date_from' ),
			(string) $request->get_param( 'date_to' )
		);
		if ( null !== $range ) {
			$clauses[] = $range;
		}

		if ( empty( $clauses ) ) {
			return $query_args;
		}

		$existing = $query_args['date_query'] ?? array();
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$query_args['date_query'] = array_merge( $existing, $clauses );
		return $query_args;
	}

	/**
	 * Build an inclusive whole-day clause for the DataViews `between` operator.
	 *
	 * Day-only bounds with `inclusive` make WP_Date_Query expand `after` to
	 * 00:00:00 and `before` to 23:59:59.
	 *
	 * @param string $from_raw Raw `date_from` value.
	 * @param string $to_raw   Raw `date_to` value.
	 * @return array<string, mixed>|null
	 */
	private static function range_clause( string $from_raw, string $to_raw ): ?array {
		$from = self::parse_day( $from_raw );
		$to   = self::parse_day( $to_raw );
		if ( null === $from && null === $to ) {
			return null;
		}

		$clause = array(
			'column'    => 'post_date',
			'inclusive' => true,
		);
		if ( null !== $from ) {
			$clause['after'] = sprintf( '%04d-%02d-%02d', $from['year'], $from['month'], $from['day'] );
		}
		if ( null !== $to ) {
			$clause['before'] = sprintf( '%04d-%02d-%02d', $to['year'], $to['month'], $to['day'] );
		}
		return $clause;
	}

	/**
	 * Parse a calendar day from an ISO date or datetime.
	 *
	 * @param string $raw Raw query value.
	 * @return array{year: int, month: int, day: int}|null
	 */
	private static function parse_day( string $raw ): ?array {
		if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $raw, $matches ) ) {
			return null;
		}

		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		$day   = (int) $matches[3];
		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return array(
			'year'  => $year,
			'month' => $month,
			'day'   => $day,
		);
	}

	/**
	 * Parse an ISO date or datetime for after/before bounds.
	 *
	 * @param string $raw Raw query value.
	 * @return string|null
	 */
	private static function parse_datetime( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( null === self::parse_day( $raw ) ) {
			return null;
		}
		return $raw;
	}
}

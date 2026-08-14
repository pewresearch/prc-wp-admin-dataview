<?php
/**
 * Field-provider filter contract for DataViews admin lists.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Names the PHP filters domain plugins use to enrich list rows, queries, and
 * inline field updates. WordPress filters are the registration API.
 */
class Provider_Registry {
	public const FILTER_SHAPE_ROW    = 'prc_wp_admin_dataview_shape_row';
	public const FILTER_QUERY_ARGS   = 'prc_wp_admin_dataview_query_args';
	public const FILTER_LOCALIZE     = 'prc_wp_admin_dataview_localize';
	public const FILTER_UPDATE_FIELD = 'prc_wp_admin_dataview_update_field';

	/**
	 * Filter names consumed by the shell.
	 *
	 * @return array<string, string>
	 */
	public static function filters(): array {
		return array(
			'shape_row'    => self::FILTER_SHAPE_ROW,
			'query_args'   => self::FILTER_QUERY_ARGS,
			'localize'     => self::FILTER_LOCALIZE,
			'update_field' => self::FILTER_UPDATE_FIELD,
		);
	}
}

<?php
/**
 * Utility functions.
 *
 * @package    PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * This is a place for "utility" functions.
 * These are functions meant to be consumed both by this plugin and others, easily.
 */

/**
 * Example utility function.
 *
 * @param string $in Input string.
 * @return string Processed string.
 */
function do_some_utility( $in ) {
	$out = 'Utility processed: ' . $in;
	return $out;
}
/**
 * Then to call this:
 * - In the class, you can use:
 *   $result = do_some_utility( $input );
 * - Outside in other plugins, you can use:
 *   $result = \PRC\Platform\Wp_Admin_Dataview\do_some_utility( $input );
 */

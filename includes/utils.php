<?php
/**
 * Utility functions.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Convert HTML-flavored text to Unicode plain text.
 *
 * `get_the_title()` runs `wptexturize`, which emits character references.
 * DataViews interpolates row strings as React text nodes, so decode them
 * before JSON leaves the REST boundary.
 *
 * @param string $value Title or other HTML-flavored string.
 * @return string Plain text.
 */
function plain_text( string $value ): string {
	return html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}

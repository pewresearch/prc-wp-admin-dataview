<?php
/**
 * Core post field writes for DataViews bulk edit.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

use WP_Error;
use WP_Post;

/**
 * Handles title and status updates after domain providers decline a field.
 */
class Field_Updates {
	/**
	 * Post statuses this list may set.
	 *
	 * Trash stays a dedicated action. `future` needs a schedule date, which
	 * this writer does not collect — WordPress would publish immediately.
	 *
	 * @var string[]
	 */
	public const EDITABLE_STATUSES = array( 'publish', 'draft', 'pending', 'private' );

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( $loader ) {
		$loader->add_filter( Provider_Registry::FILTER_UPDATE_FIELD, $this, 'update_field', 99, 5 );
	}

	/**
	 * Persist a core list field.
	 *
	 * @param true|WP_Error|null $result    Prior result.
	 * @param int                $post_id   Post ID.
	 * @param string             $field     Field id.
	 * @param mixed              $value     Value.
	 * @param string             $post_type Post type.
	 * @return true|WP_Error|null
	 */
	public function update_field( $result, $post_id, $field, $value, $post_type ) {
		if ( null !== $result ) {
			return $result;
		}

		unset( $post_type );

		if ( 'title' === $field ) {
			return $this->update_title( (int) $post_id, $value );
		}

		if ( 'status' === $field ) {
			return $this->update_status( (int) $post_id, $value );
		}

		return null;
	}

	/**
	 * Update post title.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $value   Title.
	 * @return true|WP_Error
	 */
	private function update_title( int $post_id, $value ) {
		$updated = wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => sanitize_text_field( (string) $value ),
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return true;
	}

	/**
	 * Update post status.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $value   Status slug.
	 * @return true|WP_Error
	 */
	private function update_status( int $post_id, $value ) {
		$status = sanitize_key( (string) $value );
		if ( ! in_array( $status, self::EDITABLE_STATUSES, true ) ) {
			return new WP_Error(
				'prc_wp_admin_dataview_bad_status',
				__( 'That status cannot be set from this list.', 'prc-wp-admin-dataview' ),
				array( 'status' => 400 )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'prc_wp_admin_dataview_bad_post',
				__( 'Post not found.', 'prc-wp-admin-dataview' ),
				array( 'status' => 404 )
			);
		}

		$post_type_object = get_post_type_object( $post->post_type );
		$can_publish      = $post_type_object && current_user_can( $post_type_object->cap->publish_posts );
		if ( in_array( $status, array( 'publish', 'private' ), true ) && ! $can_publish ) {
			return new WP_Error(
				'prc_wp_admin_dataview_cannot_publish',
				__( 'Sorry, you are not allowed to publish posts in this post type.', 'prc-wp-admin-dataview' ),
				array( 'status' => 403 )
			);
		}

		$update = array(
			'ID'          => $post_id,
			'post_status' => $status,
		);

		// Classic bulk edit resets the date so wp_insert_post does not keep a
		// future post_date and force status back to `future`.
		if ( 'publish' === $status && in_array( $post->post_status, array( 'future', 'draft' ), true ) ) {
			$update['post_date']     = current_time( 'mysql' );
			$update['post_date_gmt'] = '';
		}

		$updated = wp_update_post( $update, true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		return true;
	}
}

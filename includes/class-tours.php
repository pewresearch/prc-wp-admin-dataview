<?php
/**
 * DataViews first-visit tour.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Soft-depends on prc-wp-admin-tours via the register action.
 */
class Tours {
	public const TOUR_ID = 'prc-wp-admin-dataview/first-visit';

	/**
	 * List registry.
	 *
	 * @var List_Registry
	 */
	private List_Registry $lists;

	/**
	 * Constructor.
	 *
	 * @param Loader        $loader Loader.
	 * @param List_Registry $lists  List registry.
	 */
	public function __construct( $loader, List_Registry $lists ) {
		$this->lists = $lists;
		$loader->add_action( 'prc_wp_admin_tours_register', $this, 'register_tours' );
	}

	/**
	 * Register the DataViews first-visit tour.
	 *
	 * @param object $registry Tour registry.
	 */
	public function register_tours( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}

		$registry->register( $this->tour_config() );
	}

	/**
	 * Tour definition owned by this plugin.
	 *
	 * @return array<string, mixed>
	 */
	public function tour_config(): array {
		return array(
			'id'         => self::TOUR_ID,
			'title'      => __( 'Using the new lists', 'prc-wp-admin-dataview' ),
			'version'    => 1,
			'autoStart'  => true,
			'capability' => 'edit_posts',
			'screens'    => $this->screens(),
			'steps'      => array(
				array(
					'id'          => 'dataviews-header',
					'title'       => __( 'This is the new list', 'prc-wp-admin-dataview' ),
					'description' => __( 'This list replaced the classic table. Switch between table, grid, and list in View options.', 'prc-wp-admin-dataview' ),
					'selector'    => '[data-prc-tour="dataviews-header"], .prc-wp-admin-dataview__header',
					'side'        => 'bottom',
				),
				array(
					'id'             => 'dataviews-view-options',
					'title'          => __( 'Open View options', 'prc-wp-admin-dataview' ),
					'description'    => __( 'Open View options to add or remove columns and change the layout.', 'prc-wp-admin-dataview' ),
					'selector'       => 'button[aria-label="View options"]',
					'waitMs'         => 8000,
					'advanceOnClick' => true,
					'side'           => 'bottom',
				),
				array(
					'id'          => 'dataviews-columns',
					'title'       => __( 'Choose columns', 'prc-wp-admin-dataview' ),
					'description' => __( 'Use Properties to show or hide columns. Your choices stay with your account.', 'prc-wp-admin-dataview' ),
					'selector'    => '.dataviews-view-config',
					'clickOnNext' => '.dataviews-view-config__toggle-wrapper button[aria-expanded="true"]',
					'side'        => 'left',
				),
				array(
					'id'             => 'dataviews-add-filter',
					'title'          => __( 'Filter the list', 'prc-wp-admin-dataview' ),
					'description'    => __( 'Add a filter to narrow the list by status, author, or other fields.', 'prc-wp-admin-dataview' ),
					'selector'       => 'button[aria-label="Add filter"], button[aria-label="Filter"]',
					'advanceOnClick' => true,
					'side'           => 'bottom',
				),
				array(
					'id'          => 'dataviews-filters',
					'title'       => __( 'Active editors and Working on', 'prc-wp-admin-dataview' ),
					'description' => __( 'Active editors shows people in a post now. Working on shows posts you watch in publish workflows.', 'prc-wp-admin-dataview' ),
					'selector'    => '.dataviews-filters__container, .dataviews-filters',
					'side'        => 'bottom',
				),
				array(
					'id'          => 'dataviews-presence',
					'title'       => __( 'Who is editing', 'prc-wp-admin-dataview' ),
					'description' => __( 'Avatars show who is in the post now. An empty stack means no one else is editing.', 'prc-wp-admin-dataview' ),
					'selector'    => '[data-prc-tour="dataviews-presence"], .prc-wp-admin-dataview__presence-stack',
					'side'        => 'left',
				),
				array(
					'id'          => 'dataviews-saved-filters',
					'title'       => __( 'Save a filter set', 'prc-wp-admin-dataview' ),
					'description' => __( 'Save the current filters and reuse that set later from this control.', 'prc-wp-admin-dataview' ),
					'selector'    => '[data-prc-tour="dataviews-saved-filters"], .prc-wp-admin-dataview__saved-filters-toggle',
					'side'        => 'bottom',
				),
			),
		);
	}

	/**
	 * Page slugs for every registered list.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function screens(): array {
		$screens = array();
		foreach ( $this->lists->all() as $config ) {
			$page_slug = isset( $config['pageSlug'] ) ? sanitize_key( (string) $config['pageSlug'] ) : '';
			if ( '' === $page_slug ) {
				continue;
			}
			$screens[] = array(
				'kind'     => 'pageSlug',
				'pageSlug' => $page_slug,
			);
		}

		if ( array() === $screens ) {
			$screens[] = array(
				'kind'     => 'pageSlug',
				'pageSlug' => 'prc-wp-admin-dataview-post',
			);
		}

		return $screens;
	}
}

<?php
/**
 * Settings page for the shared DataViews admin lists.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Dataview;

use PRC\Platform\Settings_Page_Boot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Settings > Admin DataViews and its REST API.
 */
class Settings {
	const OPTION_KEY      = 'prc_wp_admin_dataview_settings';
	const REST_NAMESPACE  = 'prc-wp-admin-dataview/v1';
	const ADMIN_PAGE_SLUG = 'prc-wp-admin-dataview-settings';

	/**
	 * Default settings.
	 *
	 * Missing post type keys are enabled.
	 *
	 * @var array{enabled: array<string, bool>}
	 */
	private static array $defaults = array(
		'enabled' => array(),
	);

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
	public function __construct( Loader $loader, List_Registry $lists ) {
		$this->lists = $lists;

		$loader->add_action( 'admin_menu', $this, 'register_admin_page' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Get stored settings merged with defaults.
	 *
	 * @return array{enabled: array<string, bool>}
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$stored_enabled = $stored['enabled'] ?? array();
		if ( ! is_array( $stored_enabled ) ) {
			$stored_enabled = array();
		}

		return array(
			'enabled' => array_replace_recursive( self::$defaults['enabled'], $stored_enabled ),
		);
	}

	/**
	 * Check whether DataViews is enabled for a post type.
	 *
	 * Post types are enabled unless their setting is explicitly false.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function is_enabled( string $post_type ): bool {
		$enabled   = self::get_settings()['enabled'];
		$post_type = sanitize_key( $post_type );

		return ! array_key_exists( $post_type, $enabled ) || false !== $enabled[ $post_type ];
	}

	/**
	 * Register Settings > Admin DataViews.
	 *
	 * @hook admin_menu
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'options-general.php',
			__( 'Admin DataViews Settings', 'prc-wp-admin-dataview' ),
			__( 'Admin DataViews', 'prc-wp-admin-dataview' ),
			'manage_options',
			self::ADMIN_PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the settings app mount point.
	 */
	public function render_admin_page(): void {
		Settings_Page_Boot::render( 'prc-wp-admin-dataview-settings-admin' );
	}

	/**
	 * Enqueue the settings app.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @hook admin_enqueue_scripts
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = PRC_WP_ADMIN_DATAVIEW_DIR . '/build/settings/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset  = require $asset_file;
		$handle = 'prc-wp-admin-dataview-settings';

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/settings/index.js', PRC_WP_ADMIN_DATAVIEW_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style_deps = array( 'wp-components' );
		if ( wp_style_is( 'wp-theme', 'registered' ) ) {
			$style_deps[] = 'wp-theme';
		}
		if ( in_array( 'prc-components', $asset['dependencies'], true ) ) {
			wp_enqueue_style( 'prc-components' );
			$style_deps[] = 'prc-components';
		}

		$style_path = PRC_WP_ADMIN_DATAVIEW_DIR . '/build/settings/style-index.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				$handle,
				plugins_url( 'build/settings/style-index.css', PRC_WP_ADMIN_DATAVIEW_FILE ),
				$style_deps,
				$asset['version']
			);
		}

		Settings_Page_Boot::enqueue(
			$handle,
			(string) $asset['version'],
			'prc-wp-admin-dataview-settings-admin'
		);
	}

	/**
	 * Register the settings REST routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings_endpoint' ),
					'permission_callback' => fn(): bool => current_user_can( 'manage_options' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings_endpoint' ),
					'permission_callback' => fn(): bool => current_user_can( 'manage_options' ),
				),
			)
		);
	}

	/**
	 * Get settings and registered post types.
	 */
	public function get_settings_endpoint(): \WP_REST_Response {
		return rest_ensure_response( $this->get_response_data() );
	}

	/**
	 * Save settings.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public function save_settings_endpoint( \WP_REST_Request $request ): \WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid payload.' ), 400 );
		}

		update_option( self::OPTION_KEY, $this->sanitize_settings( $body ) );

		return rest_ensure_response( $this->get_response_data() );
	}

	/**
	 * Sanitize settings for registered post types only.
	 *
	 * @param array<string, mixed> $input Raw request body.
	 * @return array{enabled: array<string, bool>}
	 */
	private function sanitize_settings( array $input ): array {
		$sanitized = self::$defaults;
		if ( empty( $input['enabled'] ) || ! is_array( $input['enabled'] ) ) {
			return $sanitized;
		}

		foreach ( $this->lists->all() as $post_type => $config ) {
			if ( array_key_exists( $post_type, $input['enabled'] ) ) {
				$sanitized['enabled'][ $post_type ] = rest_sanitize_boolean( $input['enabled'][ $post_type ] );
			}
		}

		return $sanitized;
	}

	/**
	 * Build the REST response.
	 *
	 * @return array{
	 *     settings: array{enabled: array<string, bool>},
	 *     postTypes: array<int, array{postType: string, label: string}>
	 * }
	 */
	private function get_response_data(): array {
		$post_types = array();

		foreach ( $this->lists->all() as $post_type => $config ) {
			$post_type_object = get_post_type_object( $post_type );
			$label            = '';
			if ( $post_type_object && isset( $post_type_object->labels->name ) ) {
				$label = (string) $post_type_object->labels->name;
			}
			if ( '' === $label ) {
				$label = (string) ( $config['menuTitle'] ?? $post_type );
			}

			$post_types[] = array(
				'postType' => $post_type,
				'label'    => $label,
			);
		}

		return array(
			'settings'  => self::get_settings(),
			'postTypes' => $post_types,
		);
	}
}

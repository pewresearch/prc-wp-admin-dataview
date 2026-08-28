<?php
/**
 * Shared DataViews admin list screens for registered post types.
 *
 * @package PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Registers submenu pages, rewrites classic "All X" destinations, redirects
 * bare edit.php list URLs (with ?classic=1 escape), and mounts the React app.
 */
class Post_List {
	public const SCRIPT_HANDLE      = 'prc-wp-admin-dataview';
	public const MOUNT_ID           = 'prc-wp-admin-dataview';
	public const LIST_MODULE_ID     = '@prc/wp-admin-dataview/list';
	public const PAGE_MODULE_ID     = '@prc/wp-admin-dataview/page';
	public const MAX_AUTHOR_OPTIONS = 500;

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
		$loader->add_action( 'admin_menu', $this, 'register_admin_pages' );
		$loader->add_action( 'admin_menu', $this, 'rewrite_menu_destinations', 1000 );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$loader->add_action( 'load-edit.php', $this, 'redirect_classic_list_to_dataviews' );
		$loader->add_filter( 'parent_file', $this, 'filter_parent_file' );
		$loader->add_filter( 'submenu_file', $this, 'filter_submenu_file', 10, 2 );
	}

	/**
	 * Parent menu slug for a post type.
	 *
	 * @param string                    $post_type Post type.
	 * @param array<string, mixed>|null $config    Optional list config.
	 * @return string
	 */
	public static function get_parent_slug( string $post_type, ?array $config = null ): string {
		if ( ! empty( $config['menuParent'] ) ) {
			return (string) $config['menuParent'];
		}
		return 'post' === $post_type ? 'edit.php' : 'edit.php?post_type=' . $post_type;
	}

	/**
	 * Admin URL for a registered list.
	 *
	 * @param string $post_type Post type.
	 * @param string $page_slug Page slug.
	 * @return string
	 */
	public static function get_page_url( string $post_type, string $page_slug ): string {
		return self::get_page_url_for_config(
			array(
				'postType' => $post_type,
				'pageSlug' => $page_slug,
			)
		);
	}

	/**
	 * Admin URL for a registered list config.
	 *
	 * @param array<string, mixed> $config List config.
	 * @return string
	 */
	public static function get_page_url_for_config( array $config ): string {
		$post_type = (string) ( $config['postType'] ?? 'post' );
		$page_slug = (string) ( $config['pageSlug'] ?? '' );
		$parent    = self::get_parent_slug( $post_type, $config );
		$separator = str_contains( $parent, '?' ) ? '&' : '?';

		return admin_url( $parent . $separator . 'page=' . $page_slug );
	}

	/**
	 * Capability for a post type list.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function get_capability( string $post_type ): string {
		return REST_Controller::get_capability( $post_type );
	}

	/**
	 * Classic list URL with escape hatch.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function get_classic_url( string $post_type ): string {
		if ( 'post' === $post_type ) {
			return admin_url( 'edit.php?classic=1' );
		}
		return admin_url( 'edit.php?post_type=' . $post_type . '&classic=1' );
	}

	/**
	 * Register DataViews submenu pages.
	 *
	 * @hook admin_menu
	 */
	public function register_admin_pages(): void {
		foreach ( $this->lists->all() as $config ) {
			$post_type  = (string) $config['postType'];
			if ( ! Settings::is_enabled( $post_type ) ) {
				continue;
			}

			$page_slug  = (string) $config['pageSlug'];
			$page_title = (string) ( ! empty( $config['pageTitle'] ) ? $config['pageTitle'] : $config['menuTitle'] );
			$menu_title = (string) ( ! empty( $config['menuTitle'] ) ? $config['menuTitle'] : $page_title );

			add_submenu_page(
				self::get_parent_slug( $post_type, $config ),
				$page_title,
				$menu_title,
				self::get_capability( $post_type ),
				$page_slug,
				array( $this, 'render_admin_page' )
			);
		}
	}

	/**
	 * Point the auto "All X" menu item at the DataViews page and dedupe.
	 *
	 * @hook admin_menu
	 */
	public function rewrite_menu_destinations(): void {
		global $submenu;

		foreach ( $this->lists->all() as $config ) {
			$post_type = (string) $config['postType'];
			if ( ! Settings::is_enabled( $post_type ) ) {
				continue;
			}

			$page_slug = (string) $config['pageSlug'];
			$parent    = self::get_parent_slug( $post_type, $config );
			$source    = self::get_parent_slug( $post_type );

			if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
				continue;
			}

			$seen      = array();
			$reordered = array();
			foreach ( $submenu[ $parent ] as $item ) {
				if ( $source === $item[2] ) {
					$item[2] = $page_slug;
					$item[0] = (string) ( ! empty( $config['menuTitle'] ) ? $config['menuTitle'] : $item[0] );
					$item[3] = (string) ( ! empty( $config['pageTitle'] ) ? $config['pageTitle'] : $item[3] );
				}
				if ( isset( $seen[ $item[2] ] ) ) {
					continue;
				}
				$seen[ $item[2] ] = true;
				$reordered[]      = $item;
			}
			$submenu[ $parent ] = array_values( $reordered ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- rewrite the All X dest for this registered list.
		}
	}

	/**
	 * Redirect classic list tables to DataViews.
	 *
	 * Guard order: POST, page=, classic=1, then registered post type.
	 *
	 * @hook load-edit.php
	 */
	public function redirect_classic_list_to_dataviews(): void {
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return;
		}
		if ( ! empty( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( isset( $_GET['classic'] ) && '1' === (string) $_GET['classic'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$post_type = isset( $_GET['post_type'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) )
			: 'post';

		if ( ! Settings::is_enabled( $post_type ) ) {
			return;
		}

		$config = $this->lists->get( $post_type );
		if ( null === $config ) {
			return;
		}

		$redirect_url = self::get_page_url_for_config( $config );
		if ( ! empty( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_status = sanitize_key( wp_unslash( (string) $_GET['post_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redirect_url = add_query_arg( 'post_status', $post_status, $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Keep the CPT (or Posts) menu open on the DataViews screen.
	 *
	 * @param string $parent_file Parent file.
	 * @return string
	 */
	public function filter_parent_file( $parent_file ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page ) {
			return $parent_file;
		}
		$config = $this->lists->get_by_page_slug( $page );
		if ( null === $config ) {
			return $parent_file;
		}
		if ( ! Settings::is_enabled( (string) $config['postType'] ) ) {
			return $parent_file;
		}

		return self::get_parent_slug( (string) $config['postType'], $config );
	}

	/**
	 * Highlight the rewritten All X submenu item.
	 *
	 * @param string $submenu_file Submenu file.
	 * @param string $parent_file  Parent file.
	 * @return string
	 */
	public function filter_submenu_file( $submenu_file, $parent_file ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page ) {
			return $submenu_file;
		}
		$config = $this->lists->get_by_page_slug( $page );
		if ( null === $config ) {
			return $submenu_file;
		}
		if ( ! Settings::is_enabled( (string) $config['postType'] ) ) {
			return $submenu_file;
		}

		return (string) $config['pageSlug'];
	}

	/**
	 * Mount node for the React app.
	 *
	 * Prints a centered loading heading inside the mount until createRoot
	 * replaces this markup when the React app mounts.
	 */
	public function render_admin_page(): void {
		$config      = $this->current_list_config();
		$placeholder = self::get_mount_placeholder_html( $config ?? array() );

		self::print_placeholder_styles();

		if ( self::is_boot_available() ) {
			self::print_boot_layout_styles();
			printf(
				'<div id="%s" class="boot-layout-container">%s</div>',
				esc_attr( self::MOUNT_ID ),
				$placeholder // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped pieces.
			);
			return;
		}

		printf(
			'<div id="%s">%s</div>',
			esc_attr( self::MOUNT_ID ),
			$placeholder // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaped pieces.
		);
	}

	/**
	 * List config for the current admin page, if registered.
	 *
	 * @return array<string, mixed>|null
	 */
	private function current_list_config(): ?array {
		global $plugin_page;

		$page = is_string( $plugin_page ) ? sanitize_key( $plugin_page ) : '';
		if ( '' === $page ) {
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' === $page ) {
			return null;
		}

		return $this->lists->get_by_page_slug( $page );
	}

	/**
	 * Add New URL for a list config.
	 *
	 * @param array<string, mixed> $config List config.
	 * @return string
	 */
	private static function get_new_url( array $config ): string {
		if ( array_key_exists( 'newUrl', $config ) && null !== $config['newUrl'] ) {
			return (string) $config['newUrl'];
		}
		$post_type = (string) ( $config['postType'] ?? 'post' );
		$query     = 'post' === $post_type ? '' : '?post_type=' . $post_type;
		return admin_url( 'post-new.php' . $query );
	}

	/**
	 * Centered loading heading shown until React replaces the mount node.
	 *
	 * @param array<string, mixed> $config List config.
	 * @return string
	 */
	private static function get_mount_placeholder_html( array $config ): string {
		$page_title = '';
		if ( ! empty( $config['pageTitle'] ) ) {
			$page_title = (string) $config['pageTitle'];
		} elseif ( ! empty( $config['menuTitle'] ) ) {
			$page_title = (string) $config['menuTitle'];
		}

		if ( '' === $page_title ) {
			$heading = __( 'Loading…', 'prc-wp-admin-dataview' );
		} else {
			$heading = sprintf(
				/* translators: %s: DataViews page title, e.g. All Posts */
				__( 'Loading %s…', 'prc-wp-admin-dataview' ),
				$page_title
			);
		}

		return sprintf(
			'<div class="prc-wp-admin-dataview prc-wp-admin-dataview--placeholder" role="status" aria-busy="true" aria-live="polite"><h1 class="prc-wp-admin-dataview__placeholder-heading">%s</h1></div>',
			esc_html( $heading )
		);
	}

	/**
	 * Enqueue the list app on registered list screens only.
	 *
	 * @param string $hook_suffix Current admin hook.
	 * @hook admin_enqueue_scripts
	 */
	public function enqueue_admin_assets( $hook_suffix ): void {
		$config = $this->config_for_hook( (string) $hook_suffix );
		if ( null === $config ) {
			return;
		}
		if ( ! Settings::is_enabled( (string) $config['postType'] ) ) {
			return;
		}

		$asset_file = PRC_WP_ADMIN_DATAVIEW_DIR . '/build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$dependencies = array_values( array_unique( (array) ( $asset['dependencies'] ?? array() ) ) );
		$version      = (string) ( $asset['version'] ?? PRC_WP_ADMIN_DATAVIEW_VERSION );
		$use_boot     = self::is_boot_available();
		$boot_asset   = $use_boot ? self::get_gutenberg_boot_asset() : null;

		if ( is_array( $boot_asset ) && ! empty( $boot_asset['dependencies'] ) ) {
			$dependencies = array_values(
				array_unique(
					array_merge(
						$dependencies,
						(array) $boot_asset['dependencies']
					)
				)
			);
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/index.js', PRC_WP_ADMIN_DATAVIEW_FILE ),
			$dependencies,
			$version,
			true
		);

		$style_path = PRC_WP_ADMIN_DATAVIEW_DIR . '/build/style-index.css';
		if ( file_exists( $style_path ) ) {
			$style_deps = array( 'wp-components' );
			if ( wp_style_is( 'wp-theme', 'registered' ) ) {
				$style_deps[] = 'wp-theme';
			}
			if ( is_array( $boot_asset ) && ! empty( $boot_asset['dependencies'] ) ) {
				foreach ( (array) $boot_asset['dependencies'] as $handle ) {
					if ( wp_style_is( $handle, 'registered' ) ) {
						$style_deps[] = $handle;
					}
				}
				$style_deps = array_values( array_unique( $style_deps ) );
			}
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				plugins_url( 'build/style-index.css', PRC_WP_ADMIN_DATAVIEW_FILE ),
				$style_deps,
				$version
			);
		}

		$post_type = (string) $config['postType'];
		$pto       = get_post_type_object( $post_type );
		$rest_base = '';
		if ( $pto && ! empty( $pto->rest_base ) ) {
			$rest_base = (string) $pto->rest_base;
		} elseif ( 'post' === $post_type ) {
			$rest_base = 'posts';
		} elseif ( 'page' === $post_type ) {
			$rest_base = 'pages';
		} else {
			$rest_base = $post_type;
		}

		$singular_label = $post_type;
		if ( $pto && ! empty( $pto->labels->singular_name ) ) {
			$singular_label = strtolower( (string) $pto->labels->singular_name );
		}

		$can_publish = false;
		if ( $pto && isset( $pto->cap->publish_posts ) ) {
			$can_publish = current_user_can( $pto->cap->publish_posts );
		}

		$localize = array(
			'postType'           => $post_type,
			'pageSlug'           => (string) $config['pageSlug'],
			'singularLabel'      => $singular_label,
			'classicUrl'         => self::get_classic_url( $post_type ),
			'newUrl'             => self::get_new_url( $config ),
			'hideDefaultNewButton' => ! empty( $config['hideDefaultNewButton'] ),
			'restPath'           => (string) ( $config['restPath'] ?? '/prc-api/v3/wp-admin-dataview/list' ),
			'restBase'           => $rest_base,
			'savedFilters'       => Saved_Filters::get_for_post_type( (int) get_current_user_id(), $post_type ),
			'appearance'         => Appearance_Preferences::get_for_post_type( (int) get_current_user_id(), $post_type ),
			'supportsParentFamily' => Parent_Post_Provider::supports_parent_family( $post_type ),
			'canPublish'         => $can_publish,
			'authors'            => self::get_author_options( $post_type ),
			// Status filter options for DataViews; domain providers may override via FILTER_LOCALIZE.
			'statuses'           => array(
				array(
					'value' => 'publish',
					'label' => __( 'Published', 'prc-wp-admin-dataview' ),
				),
				array(
					'value' => 'draft',
					'label' => __( 'Draft', 'prc-wp-admin-dataview' ),
				),
				array(
					'value' => 'pending',
					'label' => __( 'Pending', 'prc-wp-admin-dataview' ),
				),
				array(
					'value' => 'private',
					'label' => __( 'Private', 'prc-wp-admin-dataview' ),
				),
				array(
					'value' => 'future',
					'label' => __( 'Scheduled', 'prc-wp-admin-dataview' ),
				),
			),
			'config'             => array_merge(
				$config,
				array(
					'pageTitle'            => (string) ( $config['pageTitle'] ?? '' ),
					'restBase'             => $rest_base,
					'hideDefaultNewButton' => ! empty( $config['hideDefaultNewButton'] ),
					'duplicate'            => Duplicate_Args::for_client( $config, $post_type ),
				)
			),
		);

		$localize = apply_filters( Provider_Registry::FILTER_LOCALIZE, $localize, $post_type );
		$localize['statuses'] = self::ensure_trash_status_option( $localize['statuses'] ?? array() );

		wp_localize_script( self::SCRIPT_HANDLE, 'prcWpAdminDataview', $localize );

		if ( $use_boot ) {
			$this->enqueue_boot_runtime( $version );
		}

		// WordPress 7.0 already hooks this on admin_enqueue_scripts. Calling it
		// again appends a second initializeCommandPalette() and the two CommandMenus
		// close each other. Only enqueue when Core (or Gutenberg) did not.
		if (
			function_exists( 'wp_enqueue_command_palette_assets' ) &&
			! has_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' )
		) {
			wp_enqueue_command_palette_assets();
		}
	}

	/**
	 * Author select options for DataViews filters.
	 *
	 * @param string $post_type Post type.
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function get_author_options( string $post_type ): array {
		$users = get_users(
			array(
				'capability' => self::get_capability( $post_type ),
				'orderby'    => 'display_name',
				'order'      => 'ASC',
				'number'     => self::MAX_AUTHOR_OPTIONS,
				'fields'     => array( 'ID', 'display_name' ),
			)
		);

		if ( ! is_array( $users ) ) {
			return array();
		}

		$options = array();
		foreach ( $users as $user ) {
			$id = isset( $user->ID ) ? (int) $user->ID : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$label     = isset( $user->display_name ) ? (string) $user->display_name : '';
			$options[] = array(
				'value' => (string) $id,
				'label' => plain_text( $label ),
			);
		}

		return $options;
	}

	/**
	 * Append Trash to status filter options when a provider omitted it.
	 *
	 * Trash stays off the default list query. Editors must select it.
	 *
	 * @param mixed $statuses Localized status options.
	 * @return array<int, array{value: string, label: string}>
	 */
	public static function ensure_trash_status_option( $statuses ): array {
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}

		foreach ( $statuses as $option ) {
			if ( is_array( $option ) && 'trash' === ( $option['value'] ?? '' ) ) {
				return array_values( $statuses );
			}
		}

		$statuses[] = array(
			'value' => 'trash',
			'label' => __( 'Trash', 'prc-wp-admin-dataview' ),
		);

		return $statuses;
	}

	/**
	 * Path to Gutenberg's generated @wordpress/boot asset file.
	 *
	 * @return string
	 */
	public static function get_gutenberg_boot_asset_path(): string {
		return dirname( PRC_WP_ADMIN_DATAVIEW_DIR ) . '/gutenberg/build/modules/boot/index.min.asset.php';
	}

	/**
	 * Whether Gutenberg's @wordpress/boot script module can be consumed.
	 *
	 * @return bool
	 */
	public static function is_boot_available(): bool {
		if ( ! function_exists( 'wp_register_script_module' ) || ! function_exists( 'wp_enqueue_script_module' ) ) {
			return false;
		}
		$content = PRC_WP_ADMIN_DATAVIEW_DIR . '/build/routes/list/content.js';
		$loader  = PRC_WP_ADMIN_DATAVIEW_DIR . '/build/routes/loader.js';
		if ( ! file_exists( $content ) || ! file_exists( $loader ) ) {
			return false;
		}
		return is_array( self::get_gutenberg_boot_asset() );
	}

	/**
	 * Gutenberg boot asset.php payload, or null when missing.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_gutenberg_boot_asset(): ?array {
		$path = self::get_gutenberg_boot_asset_path();
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$asset = include $path;
		return is_array( $asset ) ? $asset : null;
	}

	/**
	 * Register the list content module and start initSinglePage.
	 *
	 * @param string $version Asset version shared with the classic bundle.
	 */
	private function enqueue_boot_runtime( string $version ): void {
		wp_register_script_module(
			self::LIST_MODULE_ID,
			plugins_url( 'build/routes/list/content.js', PRC_WP_ADMIN_DATAVIEW_FILE ),
			array(),
			$version
		);

		$routes = array(
			array(
				'path'           => '/',
				'content_module' => self::LIST_MODULE_ID,
			),
		);

		$init_js_function = <<<'JS'
		( mountId, routes, initModules ) => {
			const run = async () => {
				const mod = await import( "@wordpress/boot" );
				mod.initSinglePage( { mountId, routes, initModules } );
			};
			if ( document.readyState === "loading" ) {
				document.addEventListener( "DOMContentLoaded", run );
			} else {
				run();
			}
		}
		JS;

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			sprintf(
				'( %s )( %s, %s, %s );',
				$init_js_function,
				wp_json_encode( self::MOUNT_ID, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
				wp_json_encode( $routes, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ),
				wp_json_encode( array(), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES )
			)
		);

		$boot_dependencies = array(
			array(
				'import' => 'static',
				'id'     => '@wordpress/boot',
			),
			array(
				'import' => 'dynamic',
				'id'     => self::LIST_MODULE_ID,
			),
		);

		wp_register_script_module(
			self::PAGE_MODULE_ID,
			plugins_url( 'build/routes/loader.js', PRC_WP_ADMIN_DATAVIEW_FILE ),
			$boot_dependencies,
			$version
		);
		wp_enqueue_script_module( self::PAGE_MODULE_ID );
	}

	/**
	 * Critical first-paint CSS for the centered loading heading.
	 *
	 * Inlined so the heading is styled before style-index.css arrives.
	 */
	private static function print_placeholder_styles(): void {
		echo '<style>
			.prc-wp-admin-dataview--placeholder {
				box-sizing: border-box;
				display: flex;
				align-items: center;
				justify-content: center;
				height: 100%;
				padding: 16px;
			}
			.prc-wp-admin-dataview__placeholder-heading {
				margin: 0;
				color: #1d2327;
				font-size: 1.5rem;
				font-weight: 600;
			}
		</style>';
	}

	/**
	 * Critical Boot layout CSS.
	 *
	 * Starts from Gutenberg's generated page-wp-admin.php. On desktop the
	 * content column is pinned under the admin bar. The admin menu stays
	 * in normal flow so hover flyouts can paint and a long menu can
	 * scroll with the page.
	 */
	private static function print_boot_layout_styles(): void {
		echo '<style>
			#wpwrap { overflow-y: auto; }
			body { background: #fff; }
			#wpcontent { padding-inline-start: 0; }
			#wpbody-content { padding-bottom: 0; }
			#wpbody-content > div:not(.boot-layout-container):not(#screen-meta) { display: none; }
			#wpfooter { display: none; }
			.boot-layout-container {
				box-sizing: border-box;
				position: relative;
			}
			.a11y-speak-region { inset-inline-start: -1px; top: -1px; }
			ul#adminmenu a.wp-has-current-submenu::after,
			ul#adminmenu > li.current > a.current::after { border-inline-end-color: #fff; }
			.media-frame select.attachment-filters:last-of-type { width: auto; max-width: 100%; }
			@media (min-width: 782px) {
				#wpwrap { overflow-y: initial; }
				#wpcontent {
					position: fixed;
					top: var(--wp-admin--admin-bar--height, 32px);
					bottom: 0;
					inset-inline-start: 0;
					inset-inline-end: 0;
					width: auto;
					height: auto;
					overflow: hidden;
					z-index: 1;
				}
				#wpbody,
				#wpbody-content,
				.boot-layout-container {
					height: 100%;
					min-height: 0;
					overflow: hidden;
				}
				#prc-wp-admin-dataview.boot-layout-container .boot-layout {
					height: 100%;
					min-height: 0;
					overflow: hidden;
				}
				#adminmenuwrap {
					position: relative;
					z-index: 10000;
				}
			}
			@media (max-width: 781px) {
				#prc-wp-admin-dataview.boot-layout-container > .boot-layout {
					position: relative;
					inset: auto;
					height: auto;
					min-height: 0;
					overflow: visible;
				}
			}
		</style>';
	}

	/**
	 * Resolve list config from an admin hook suffix.
	 *
	 * Uses core get_plugin_page_hookname() so built-in Pages map correctly
	 * (`page` → `pages_page_…`, not `page_page_…`).
	 *
	 * @param string $hook_suffix Hook suffix.
	 * @return array<string, mixed>|null
	 */
	private function config_for_hook( string $hook_suffix ): ?array {
		foreach ( $this->lists->all() as $config ) {
			$post_type = (string) $config['postType'];
			$page_slug = (string) $config['pageSlug'];
			$parent    = self::get_parent_slug( $post_type, $config );
			$expected  = get_plugin_page_hookname( $page_slug, $parent );
			if ( $expected === $hook_suffix ) {
				return $config;
			}
		}
		return null;
	}
}

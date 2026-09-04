<?php
/**
 * Bootstrap class.
 *
 * @package    PRC\Platform\Wp_Admin_Dataview
 */

namespace PRC\Platform\Wp_Admin_Dataview;

/**
 * Bootstrap class.
 *
 * @package    PRC\Platform\Wp_Admin_Dataview
 */
class Bootstrap {
	/**
	 * Loader.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * List registry.
	 *
	 * @var List_Registry
	 */
	protected List_Registry $lists;

	/**
	 * Plugin name.
	 *
	 * @var string
	 */
	protected $plugin_name;

	/**
	 * Version.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->version     = '1.0.0';
		$this->plugin_name = 'prc-wp-admin-dataview';

		$this->load_dependencies();
		$this->init_dependencies();
	}

	/**
	 * Load dependencies.
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';
		$this->loader = new Loader();

		require_once plugin_dir_path( __DIR__ ) . '/includes/class-provider-registry.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-duplicate-args.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-list-registry.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-post-duplicator.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-duplicate-ui.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-settings.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-search-query.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-rest-controller.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-saved-filters.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-appearance-preferences.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-post-list.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-parent-post-provider.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-presence-provider.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-taxonomy-fields-provider.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-field-updates.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-tours.php';

		$this->lists = new List_Registry();
	}

	/**
	 * Initialize dependencies.
	 */
	private function init_dependencies() {
		foreach ( self::default_list_configs() as $config ) {
			$this->lists->register( $config );
		}

		// Register after post types and translations are available.
		$this->loader->add_action( 'init', $this, 'register_domain_lists', 20 );

		$duplicator = new Post_Duplicator( $this->lists );

		new Settings( $this->get_loader(), $this->lists );
		new REST_Controller( $this->get_loader(), $this->lists, $duplicator );
		new Duplicate_UI( $this->get_loader(), $duplicator );
		new Saved_Filters( $this->get_loader(), $this->lists );
		new Post_List( $this->get_loader(), $this->lists );
		new Parent_Post_Provider( $this->get_loader() );
		new Presence_Provider( $this->get_loader() );
		new Taxonomy_Fields_Provider( $this->get_loader() );
		new Field_Updates( $this->get_loader() );
		new Tours( $this->get_loader(), $this->lists );
	}

	/**
	 * Let domain plugins register additional list screens.
	 *
	 * @hook plugins_loaded
	 */
	public function register_domain_lists(): void {
		/**
		 * Register additional shared DataViews list screens.
		 *
		 * @param List_Registry $lists List registry.
		 */
		do_action( 'prc_wp_admin_dataview_register_lists', $this->lists );
	}

	/**
	 * Built-in list configs for core post types owned by this shell.
	 *
	 * Domain CPTs register via `prc_wp_admin_dataview_register_lists`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function default_list_configs(): array {
		$duplicate = array(
			'includeMeta' => Duplicate_Args::content_include_meta(),
		);

		return array(
			array(
				'postType'  => 'post',
				'pageSlug'  => 'prc-wp-admin-dataview-post',
				'menuTitle' => __( 'All Posts', 'prc-wp-admin-dataview' ),
				'pageTitle' => __( 'All Posts', 'prc-wp-admin-dataview' ),
				'duplicate' => $duplicate,
			),
			array(
				'postType'  => 'page',
				'pageSlug'  => 'prc-wp-admin-dataview-page',
				'menuTitle' => __( 'All Pages', 'prc-wp-admin-dataview' ),
				'pageTitle' => __( 'All Pages', 'prc-wp-admin-dataview' ),
				'duplicate' => $duplicate,
			),
		);
	}

	/**
	 * Run loader.
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * Plugin name.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Loader.
	 *
	 * @return Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Version.
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Lists.
	 *
	 * @return List_Registry
	 */
	public function get_lists(): List_Registry {
		return $this->lists;
	}
}

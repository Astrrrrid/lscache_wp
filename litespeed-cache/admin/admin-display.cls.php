<?php
/**
 * The admin-panel specific functionality of the plugin.
 *
 *
 * @since      1.0.0
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/admin
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */

if ( ! defined( 'WPINC' ) ) {
	die ;
}

class LiteSpeed_Cache_Admin_Display
{
	private static $_instance ;

	const NOTICE_BLUE = 'notice notice-info' ;
	const NOTICE_GREEN = 'notice notice-success' ;
	const NOTICE_RED = 'notice notice-error' ;
	const NOTICE_YELLOW = 'notice notice-warning' ;
	const LITESPEED_MSG = 'litespeed_messages' ;

	const PURGEBY_CAT = '0' ;
	const PURGEBY_PID = '1' ;
	const PURGEBY_TAG = '2' ;
	const PURGEBY_URL = '3' ;

	const PURGEBYOPT_SELECT = 'purgeby' ;
	const PURGEBYOPT_LIST = 'purgebylist' ;

	const DISMISS_MSG = 'litespeed-cache-dismiss' ;
	const RULECONFLICT_ON = 'ExpiresDefault_1' ;
	const RULECONFLICT_DISMISSED = 'ExpiresDefault_0' ;

	private $__cfg ;
	private $__options ;
	private $messages = array() ;
	private $default_settings = array() ;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.7
	 * @access   private
	 */
	private function __construct()
	{
		// load assets
		if( ! empty( $_GET[ 'page' ] ) && ( strpos( $_GET[ 'page' ], 'lscache-' ) === 0 || $_GET[ 'page' ] == 'litespeedcache' ) ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'load_assets' ) ) ;
		}

		// main css
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_style' ) ) ;
		// Main js
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) ) ;

		$is_network_admin = is_network_admin() ;

		// Quick access menu
		if ( is_multisite() && $is_network_admin ) {
			$manage = 'manage_network_options' ;
		}
		else {
			$manage = 'manage_options' ;
		}
		if ( current_user_can( $manage ) ) {
			add_action( 'wp_before_admin_bar_render', array( LiteSpeed_Cache_GUI::get_instance(), 'backend_shortcut' ) ) ;

			// `admin_notices` is after `admin_enqueue_scripts`
			// @see wp-admin/admin-header.php
			add_action( is_network_admin() ? 'network_admin_notices' : 'admin_notices', array( $this, 'display_messages' ) ) ;
		}

		/**
		 * In case this is called outside the admin page
		 * @see  https://codex.wordpress.org/Function_Reference/is_plugin_active_for_network
		 * @since  2.0
		 */
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' ) ;
		}

		// add menus ( Also check for mu-plugins)
		if ( $is_network_admin && ( is_plugin_active_for_network( LSCWP_BASENAME ) || defined( 'LSCWP_MU_PLUGIN' ) ) ) {
			add_action( 'network_admin_menu', array( $this, 'register_admin_menu' ) ) ;
		}
		else {
			add_action( 'admin_menu', array( $this, 'register_admin_menu' ) ) ;
		}

		$this->__cfg = LiteSpeed_Cache_Config::get_instance() ;
		$this->__options = $this->__cfg->get_options() ;
	}

	/**
	 * Load LiteSpeed assets
	 *
	 * @since    1.1.0
	 * @access public
	 * @param  array $hook WP hook
	 */
	public function load_assets($hook)
	{
		// Admin footer
		add_filter('admin_footer_text', array($this, 'admin_footer_text'), 1) ;

		if( defined( 'LITESPEED_ON' ) ) {
			// Help tab
			$this->add_help_tabs() ;

			global $pagenow ;
			if ( $pagenow === 'plugins.php' ) {//todo: check if work
				add_action('wp_default_scripts', array($this, 'set_update_text'), 0) ;
				add_action('wp_default_scripts', array($this, 'unset_update_text'), 20) ;
			}
		}
	}

	/**
	 * Show the title of one line
	 *
	 * @since  3.0
	 * @access public
	 */
	public function title( $id )
	{
		echo LiteSpeed_Lang::title( $id ) ;
	}

	/**
	 * Register the admin menu display.
	 *
	 * @since    1.0.0
	 * @access public
	 */
	public function register_admin_menu()
	{
		$capability = is_network_admin() ? 'manage_network_options' : 'manage_options' ;
		if ( current_user_can( $capability ) ) {
			// root menu
			add_menu_page( 'LiteSpeed Cache', 'LiteSpeed Cache', 'manage_options', 'litespeed' ) ;

			// sub menus
			$this->_add_submenu( __( 'Dashboard', 'litespeed-cache' ), 'litespeed', 'show_menu_dash' ) ;

			$this->_add_submenu( __( 'Settings', 'litespeed-cache' ), 'lscache-settings', 'show_menu_settings' ) ;

			$this->_add_submenu( __( 'CDN', 'litespeed-cache' ), 'lscache-cdn', 'show_menu_cdn' ) ;

			$this->_add_submenu( __( 'Manage', 'litespeed-cache' ), 'lscache-manage', 'show_menu_manage' ) ;

			if ( ! is_multisite() || is_network_admin() ) {
				$this->_add_submenu(__('Edit .htaccess', 'litespeed-cache'), LiteSpeed_Cache::PAGE_EDIT_HTACCESS, 'show_menu_edit_htaccess') ;
			}

			if ( ! is_network_admin() ) {
				$this->_add_submenu(__('Image Optimization', 'litespeed-cache'), 'lscache-optimization', 'show_optimization') ;
				$this->_add_submenu(__('Crawler', 'litespeed-cache'), 'lscache-crawler', 'show_crawler') ;
				$this->_add_submenu(__('Report', 'litespeed-cache'), 'lscache-report', 'show_report') ;
				$this->_add_submenu(__('Import / Export', 'litespeed-cache'), 'lscache-import', 'show_import_export') ;
			}

			defined( 'LSCWP_LOG' ) && $this->_add_submenu(__('Debug Log', 'litespeed-cache'), 'lscache-debug', 'show_debug_log') ;

			// sub menus under options
			add_options_page('LiteSpeed Cache', 'LiteSpeed Cache', $capability, 'litespeedcache', array($this, 'show_menu_settings')) ;
		}
	}

	/**
	 * Helper function to set up a submenu page.
	 *
	 * @since 1.0.4
	 * @access private
	 * @param string $menu_title The title that appears on the menu.
	 * @param string $menu_slug The slug of the page.
	 * @param string $callback The callback to call if selected.
	 */
	private function _add_submenu( $menu_title, $menu_slug, $callback )
	{
		add_submenu_page( 'litespeed', $menu_title, $menu_title, 'manage_options', $menu_slug, array( $this, $callback ) ) ;
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.14
	 * @access public
	 */
	public function enqueue_style()
	{
		wp_enqueue_style(LiteSpeed_Cache::PLUGIN_NAME, LSWCP_PLUGIN_URL . 'assets/css/litespeed.css', array(), LiteSpeed_Cache::PLUGIN_VERSION, 'all') ;
	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 * @access public
	 */
	public function enqueue_scripts()
	{
		wp_register_script( LiteSpeed_Cache::PLUGIN_NAME, LSWCP_PLUGIN_URL . 'assets/js/litespeed-cache-admin.js', array(), LiteSpeed_Cache::PLUGIN_VERSION, false ) ;

		$localize_data = array() ;
		if ( LiteSpeed_Cache_GUI::has_whm_msg() ) {
			$ajax_url_dismiss_whm = LiteSpeed_Cache_Utility::build_url( LiteSpeed_Cache::ACTION_DISMISS, LiteSpeed_Cache_GUI::TYPE_DISMISS_WHM, true ) ;
			$localize_data[ 'ajax_url_dismiss_whm' ] = $ajax_url_dismiss_whm ;
		}

		if ( LiteSpeed_Cache_GUI::has_msg_ruleconflict() ) {
			$ajax_url = LiteSpeed_Cache_Utility::build_url( LiteSpeed_Cache::ACTION_DISMISS, LiteSpeed_Cache_GUI::TYPE_DISMISS_EXPIRESDEFAULT, true ) ;
			$localize_data[ 'ajax_url_dismiss_ruleconflict' ] = $ajax_url ;
		}

		$promo_tag = LiteSpeed_Cache_GUI::get_instance()->show_promo( true ) ;
		if ( $promo_tag ) {
			$ajax_url_promo = LiteSpeed_Cache_Utility::build_url( LiteSpeed_Cache::ACTION_DISMISS, LiteSpeed_Cache_GUI::TYPE_DISMISS_PROMO, true, null, array( 'promo_tag' => $promo_tag ) ) ;
			$localize_data[ 'ajax_url_promo' ] = $ajax_url_promo ;
		}

		if ( $localize_data ) {
			wp_localize_script(LiteSpeed_Cache::PLUGIN_NAME, 'litespeed_data', $localize_data ) ;
		}

		wp_enqueue_script( LiteSpeed_Cache::PLUGIN_NAME ) ;
	}

	/**
	 * Callback that adds LiteSpeed Cache's action links.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param array $links Previously added links from other plugins.
	 * @return array Links array with the litespeed cache one appended.
	 */
	public function add_plugin_links($links)
	{
		//$links[] = '<a href="' . admin_url('admin.php?page=litespeedcache') .'">Settings</a>';
		$links[] = '<a href="' . admin_url('options-general.php?page=litespeedcache') . '">' . __('Settings', 'litespeed-cache') . '</a>' ;

		return $links ;
	}

	/**
	 * Add text to recommend updating upon update success.
	 *
	 * @since 1.0.8.1
	 * @access public
	 * @param string $translations
	 * @param string $text
	 * @return string
	 */
	public function add_update_text($translations, $text)
	{
		if ( $text !== 'Updated!' ) {
			return $translations ;
		}

		return $translations . ' ' . __('It is recommended that LiteSpeed Cache be purged after updating a plugin.', 'litespeed-cache') ;
	}

	/**
	 * Add the filter to update plugin update text.
	 *
	 * @since 1.0.8.1
	 * @access public
	 */
	public function set_update_text()
	{
		add_filter('gettext', array($this, 'add_update_text'), 10, 2) ;
	}

	/**
	 * Remove the filter to update plugin update text.
	 *
	 * @since 1.0.8.1
	 * @access public
	 */
	public function unset_update_text()
	{
		remove_filter('gettext', array($this, 'add_update_text')) ;
	}

	/**
	 * Change the admin footer text on LiteSpeed Cache admin pages.
	 *
	 * @since  1.0.13
	 * @param  string $footer_text
	 * @return string
	 */
	public function admin_footer_text($footer_text)
	{
		require_once LSCWP_DIR . 'admin/tpl/inc/admin_footer.php' ;

		return $footer_text ;
	}

	/**
	 * Displays the help tab in the admin pages.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function add_help_tabs()
	{
		require_once LSCWP_DIR . 'admin/tpl/inc/help_tabs.php' ;
	}

	/**
	 * Builds the html for a single notice.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $color The color to use for the notice.
	 * @param string $str The notice message.
	 * @return string The built notice html.
	 */
	public static function build_notice($color, $str)
	{
		return '<div class="' . $color . ' is-dismissible"><p>'. $str . '</p></div>' ;
	}

	/**
	 * Display info notice
	 *
	 * @since 1.6.5
	 * @access public
	 */
	public static function info( $msg )
	{
		self::add_notice( self::NOTICE_BLUE, $msg ) ;
	}

	/**
	 * Display note notice
	 *
	 * @since 1.6.5
	 * @access public
	 */
	public static function note( $msg )
	{
		self::add_notice( self::NOTICE_YELLOW, $msg ) ;
	}

	/**
	 * Display success notice
	 *
	 * @since 1.6
	 * @access public
	 */
	public static function succeed( $msg )
	{
		self::add_notice( self::NOTICE_GREEN, $msg ) ;
	}

	/**
	 * Display error notice
	 *
	 * @since 1.6
	 * @access public
	 */
	public static function error( $msg )
	{
		self::add_notice( self::NOTICE_RED, $msg ) ;
	}

	/**
	 * Adds a notice to display on the admin page. Multiple messages of the
	 * same color may be added in a single call. If the list is empty, this
	 * method will add the action to display notices.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $color One of the available constants provided by this
	 *     class.
	 * @param mixed $msg May be a string for a single message or an array for
	 *     multiple.
	 */
	public static function add_notice($color, $msg)
	{
		// Bypass adding for CLI or cron
		if ( defined( 'LITESPEED_CLI' ) || defined( 'DOING_CRON' ) ) {
			// WP CLI will show the info directly
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				$msg = strip_tags( $msg ) ;
				if ( $color == self::NOTICE_RED ) {
					WP_CLI::error( $msg ) ;
				}
				else {
					WP_CLI::success( $msg ) ;
				}
			}
			return ;
		}

		$messages = (array)get_option( self::LITESPEED_MSG ) ;
		if( ! $messages ) {
			$messages = array() ;
		}
		if ( is_array($msg) ) {
			foreach ($msg as $str) {
				$messages[] = self::build_notice($color, $str) ;
			}
		}
		else {
			$messages[] = self::build_notice($color, $msg) ;
		}
		update_option( self::LITESPEED_MSG, $messages ) ;
	}

	/**
	 * Display notices and errors in dashboard
	 *
	 * @since 1.1.0
	 * @access public
	 */
	public function display_messages()
	{
		if ( LiteSpeed_Cache_GUI::has_whm_msg() ) {
			$this->show_display_installed() ;
		}

		// One time msg
		$messages = get_option( self::LITESPEED_MSG ) ;
		if( is_array($messages) ) {
			$messages = array_unique($messages) ;

			$added_thickbox = false ;
			foreach ($messages as $msg) {
				// Added for popup links
				if ( strpos( $msg, 'TB_iframe' ) && ! $added_thickbox ) {
					add_thickbox();
					$added_thickbox = true ;
				}
				echo $msg ;
			}
		}
		delete_option( self::LITESPEED_MSG ) ;

		/**
		 * Check promo msg first
		 * @since 2.9
		 */
		LiteSpeed_Cache_GUI::get_instance()->show_promo() ;

	}

	/**
	 * Hooked to the in_widget_form action.
	 * Appends LiteSpeed Cache settings to the widget edit settings screen.
	 * This will append the esi on/off selector and ttl text.
	 *
	 * @access public
	 * @since 1.1.0
	 * @param type $widget
	 * @param type $return
	 * @param type $instance
	 */
	public function show_widget_edit($widget, $return, $instance)
	{
		require LSCWP_DIR . 'admin/tpl/esi_widget_edit.php' ;
	}

	/**
	 * Displays the dashboard page.
	 *
	 * @since 3.0
	 * @access public
	 */
	public function show_menu_dash()
	{
		require_once LSCWP_DIR . 'admin/tpl/dash.php' ;
	}

	/**
	 * Displays the cache management page.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function show_menu_manage()
	{
		require_once LSCWP_DIR . 'admin/tpl/manage.php' ;
	}

	/**
	 * Outputs the LiteSpeed Cache settings page.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function show_menu_settings()
	{
		if ( is_network_admin() ) {
			require_once LSCWP_DIR . 'admin/tpl/network_settings.php' ;
		}
		else {
			if ( $_GET['page'] != 'litespeedcache' ) {// ls settings msg need to display manually
				settings_errors() ;
			}
			require_once LSCWP_DIR . 'admin/tpl/settings.php' ;
		}
	}

	/**
	 * Displays the edit_htaccess admin page.
	 *
	 * This function will try to load the .htaccess file contents.
	 * If it fails, it will echo the error message.
	 *
	 * @since 1.0.4
	 * @access public
	 */
	public function show_menu_edit_htaccess()
	{
		require_once LSCWP_DIR . 'admin/tpl/edit_htaccess.php' ;
	}

	/**
	 * Outputs the html for the Environment Report page.
	 *
	 * @since 1.0.12
	 * @access public
	 */
	public function show_report()
	{
		require_once LSCWP_DIR . 'admin/tpl/report.php' ;
	}

	/**
	 * Outputs the html for the Import/Export page.
	 *
	 * @since 1.8.2
	 * @access public
	 */
	public function show_import_export()
	{
		require_once LSCWP_DIR . 'admin/tpl/import_export.php' ;
	}

	/**
	 * Outputs the crawler operation page.
	 *
	 * @since 1.1.0
	 * @access public
	 */
	public function show_crawler()
	{
		require_once LSCWP_DIR . 'admin/tpl/crawler.php' ;
	}

	/**
	 * Outputs the optimization operation page.
	 *
	 * @since 1.6
	 * @access public
	 */
	public function show_optimization()
	{
		require_once LSCWP_DIR . 'admin/tpl/image_optimization.php' ;
	}

	/**
	 * Outputs the debug log.
	 *
	 * @since 1.1.5
	 * @access public
	 */
	public function show_debug_log()
	{
		require_once LSCWP_DIR . 'admin/tpl/debug_log.php' ;
	}

	/**
	 * Outputs a notice to the admin panel when the plugin is installed
	 * via the WHM plugin.
	 *
	 * @since 1.0.12
	 * @access public
	 */
	public function show_display_installed()
	{
		require_once LSCWP_DIR . 'admin/tpl/inc/show_display_installed.php' ;
	}

	/**
	 * Display error cookie msg.
	 *
	 * @since 1.0.12
	 * @access public
	 */
	public static function show_error_cookie()
	{
		require_once LSCWP_DIR . 'admin/tpl/inc/show_error_cookie.php' ;
	}

	/**
	 * Display warning if lscache is disabled
	 *
	 * @since 2.1
	 * @access public
	 */
	public function cache_disabled_warning()
	{
		include LSCWP_DIR . "admin/tpl/inc/check_cache_disabled.php" ;
	}

	/**
	 * Output litespeed form info
	 *
	 * @since    3.0
	 * @access public
	 */
	public function form_action( $action = LiteSpeed_Cache_Router::ACTION_SAVE_SETTINGS, $type = false )
	{
		echo '<form method="post" action="' . wp_unslash( $_SERVER[ 'REQUEST_URI' ] ) . '" class="litespeed-relative">' ;
		echo '<input type="hidden" name="' . LiteSpeed_Cache_Router::ACTION_KEY . '" value="' . $action . '" />' ;
		if ( $type ) {
			echo '<input type="hidden" name="' . LiteSpeed_Cache_Router::TYPE . '" value="' . $type . '" />' ;
		}
		wp_nonce_field( $action, LiteSpeed_Cache_Router::NONCE_NAME ) ;
	}

	/**
	 * Register this setting to save
	 *
	 * @since  3.0
	 * @access public
	 */
	public function enroll( $id )
	{
		echo '<input type="hidden" name="' . LiteSpeed_Cache_Admin_Settings::ENROLL . '[]" value="' . $id . '" />' ;
	}

	/**
	 * Build a textarea
	 *
	 * @since 1.1.0
	 * @access public
	 */
	public function build_textarea( $id, $cols = false, $val = null )
	{
		if ( $val === null ) {
			$val = $this->__options[ $id ] ;

			if ( is_array( $val ) ) {
				$val = implode( "\n", $val ) ;
			}
		}

		if ( ! $cols ) {
			$cols = 80 ;
		}

		$this->enroll( $id ) ;

		echo "<textarea name='$id' rows='5' cols='$cols'>" . esc_textarea( $val ) . "</textarea>" ;
	}

	/**
	 * Build a text input field
	 *
	 * @since 1.1.0
	 * @access public
	 * @param  string $id
	 * @param  string $cls     Appending styles
	 * @param  string $val       Field value
	 * @param  string $type      Input type
	 */
	public function build_input( $id, $cls = null, $val = null, $type = 'text' )
	{
		if ( $val === null ) {
			$val = $this->__options[ $id ] ;
		}

		$label_id = preg_replace( '|\W|', '', $id ) ;

		if ( $type == 'text' ) {
			$cls = "litespeed-regular-text $cls" ;
		}

		$this->enroll( $id ) ;

		echo "<input type='$type' class='$cls' name='$id' value='" . esc_textarea( $val ) ."' id='input_$label_id' /> " ;
	}

	/**
	 * Build a checkbox html snippet
	 *
	 * @since 1.1.0
	 * @access public
	 * @param  string $id
	 * @param  string $title
	 * @param  bool $checked
	 */
	public function build_checkbox( $id, $title, $checked = null, $value = 1 )
	{
		if ( $checked == null && ! empty( $this->__options[ $id ] ) ) {
			$checked = true ;
		}
		$checked = $checked ? ' checked ' : '' ;

		$label_id = preg_replace( '|\W|', '', $id ) ;

		if ( $value !== 1 ) {
			$label_id .= '_' . $value ;
		}

		$this->enroll( $id ) ;

		echo "<div class='litespeed-tick'>
				<label for='input_checkbox_$label_id'>$title</label>
				<input type='checkbox' name='$id' id='input_checkbox_$label_id' value='$value' $checked />
			</div>" ;
	}

	/**
	 * Build a toggle checkbox html snippet
	 *
	 * @since 1.7
	 */
	public function build_toggle( $id, $checked = null, $title_on = null, $title_off = null )
	{
		if ( $checked === null && $this->__options[ $id ] ) {
			$checked = true ;
		}

		if ( $title_on === null ) {
			$title_on = __( 'ON', 'litespeed-cache' ) ;
			$title_off = __( 'OFF', 'litespeed-cache' ) ;
		}

		$cls = $checked ? 'primary' : 'default litespeed-toggleoff' ;

		$this->enroll( $id ) ;

		echo "<div class='litespeed-toggle litespeed-toggle-btn litespeed-toggle-btn-$cls' data-litespeed-toggle-on='primary' data-litespeed-toggle-off='default'>
				<input name='$id' type='hidden' value='$checked' />
				<div class='litespeed-toggle-group'>
					<label class='litespeed-toggle-btn litespeed-toggle-btn-primary litespeed-toggle-on'>$title_on</label>
					<label class='litespeed-toggle-btn litespeed-toggle-btn-default litespeed-toggle-active litespeed-toggle-off'>$title_off</label>
					<span class='litespeed-toggle-handle litespeed-toggle-btn litespeed-toggle-btn-default'></span>
				</div>
			</div>" ;
	}

	/**
	 * Build a switch div html snippet
	 *
	 * @since 1.1.0
	 * @since 1.7 removed param $disable
	 * @access public
	 * @param  string $id
	 */
	public function build_switch( $id )
	{
		echo '<div class="litespeed-switch">' ;

		$this->build_radio( $id, LiteSpeed_Cache_Config::VAL_OFF ) ;
		$this->build_radio( $id, LiteSpeed_Cache_Config::VAL_ON ) ;

		echo '</div>' ;
	}

	/**
	 * Build a radio input html codes and output
	 *
	 * @since 1.1.0
	 * @access public
	 * @param  string $id
	 * @param  string $val     Default value of this input
	 * @param  string $txt     Title of this input
	 */
	public function build_radio( $id, $val, $txt = null )
	{
		$id_attr = 'input_radio_' . preg_replace( '|\W|', '', $id ) . '_' . $val ;

		if ( ! $txt ) {
			if ( $val ) {
				$txt = __( 'ON', 'litespeed-cache' ) ;
			}
			else {
				$txt = __( 'OFF', 'litespeed-cache' ) ;
			}
		}

		$checked = isset( $this->__options[ $id ] ) && $this->__options[ $id ] == $val ? ' checked ' : '' ;

		$this->enroll( $id ) ;

		echo "<input type='radio' name='$id' id='$id_attr' value='$val' $checked /> <label for='$id_attr'>$txt</label>" ;
	}

	/**
	 * Display default value
	 *
	 * @since  1.1.1
	 * @access public
	 * @param  string $id The setting tag
	 */
	public function recommended( $id )
	{
		if ( ! $this->default_settings ) {
			$this->default_settings = $this->__cfg->default_vals() ;
		}

		$val = $this->default_settings[ $id ] ;

		if ( $val ) {
			if ( is_array( $val ) ) {
				$val = implode( "\n", $val ) ;
				$val = esc_textarea( $val ) ;
				$val = "<textarea readonly rows='5' class='litespeed-left10'>$val</textarea>" ;
			}
			else {
				$val = "<code>$val</code>" ;
			}
			echo __( 'Recommended value', 'litespeed-cache' ) . ': ' . $val ;
		}
	}

	/**
	 * Validate rewrite rules regex syntax
	 *
	 * @since  3.0
	 */
	private function _validate_syntax( $id )
	{
		$val = $this->__options[ $id ] ;

		if ( ! $val ) {
			return ;
		}

		if ( ! is_array( $val ) ) {
			$val = array( $val ) ;
		}

		foreach ( $val as $v ) {
			if ( ! LiteSpeed_Cache_Utility::syntax_checker( $v ) ) {
				echo '<br /><font class="litespeed-warning"> ❌ ' . __( 'Invalid rewrite rule', 'litespeed-cache' ) . ': <code>' . $v . '</code></font>' ;
			}
		}
	}

	/**
	 * Check ttl instead of error when saving
	 *
	 * @since  3.0
	 */
	private function _validate_ttl( $id, $min = false, $max = false )
	{
		$val = $this->__options[ $id ] ;
		$tip = array() ;
		if ( $min && $val < $min ) {
			$tip[] = __( 'Minimum value', 'litespeed-cache' ) . ': <code>' . $min . '</code>.' ;
		}
		if ( $max && $val > $min ) {
			$tip[] = __( 'Maximum value', 'litespeed-cache' ) . ': <code>' . $max . '</code>.' ;
		}

		if ( $tip ) {
			echo '<br /><font class="litespeed-warning"> ❌ ' . implode( ' ', $tip ) . '</font>' ;
		}
	}

	/**
	 * Check if ip is valid
	 *
	 * @since  3.0
	 */
	private function _validate_ip( $id )
	{
		$val = $this->__options[ $id ] ;
		if ( ! $val ) {
			return ;
		}

		if ( ! is_array( $val ) ) {
			$val = array( $val ) ;
		}

		$tip = array() ;
		foreach ( $val as $v ) {
			if ( ! $v ) {
				continue ;
			}

			if ( ! WP_Http::is_ip_address( $v ) ) {
				$tip[] = __( 'Invalid IP', 'litespeed-cache' ) . ': <code>' . $v . '</code>.' ;
			}
		}

		if ( $tip ) {
			echo '<br /><font class="litespeed-warning"> ❌ ' . implode( ' ', $tip ) . '</font>' ;
		}
	}

	/**
	 * Display API environment variable support
	 *
	 * @since  1.8.3
	 * @access private
	 */
	private function _api_env_var()
	{
		$args = func_get_args() ;
		$s = '<code>' . implode( '</code>, <code>', $args ) . '</code>' ;

		echo '<font class="litespeed-success"> '
			. __( 'API', 'litespeed-cache' ) . ': '
			. sprintf( __( 'Server variable(s) %s available to override this setting.', 'litespeed-cache' ), $s ) ;

		$this->learn_more( 'https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:configuration:server_variables' ) ;
	}

	/**
	 * Display learn more link
	 *
	 * @since  2.6.1
	 * @access public
	 */
	public function learn_more( $link, $title = false, $class = false )
	{
		if ( $class ) {
			$class = " class='$class' " ;
		}

		if ( ! $title ) {
			$title = __( 'Learn More', 'litespeed-cache' ) ;
		}

		echo " <a href='$link' target='_blank' $class>$title</a>" ;
	}

	/**
	 * Display URI setting example
	 *
	 * @since  2.6.1
	 * @access private
	 */
	private function _uri_usage_example()
	{
		echo __( 'The URLs will be compared to the REQUEST_URI server variable.', 'litespeed-cache' ) ;
		echo ' ' . sprintf( __( 'For example, for %s, %s can be used here.', 'litespeed-cache' ), '<code>/mypath/mypage?aa=bb</code>', '<code>mypage?aa=</code>' ) ;
		echo '<br /><i>' ;
			echo sprintf( __( 'To match the beginning, add %s to the beginning of the item.', 'litespeed-cache' ), '<code>^</code>' ) ;
			echo ' ' . sprintf( __( 'To do an exact match, add %s to the end of the URL.', 'litespeed-cache' ), '<code>$</code>' ) ;
			echo ' ' . __( 'One per line.', 'litespeed-cache' ) ;
		echo '</i>' ;
	}

	/**
	 * Return groups string
	 *
	 * @since  2.0
	 * @access public
	 */
	public static function print_plural( $num, $kind = 'group' )
	{
		if ( $num > 1 ) {
			switch ( $kind ) {
				case 'group' :
					return sprintf( __( '%s groups', 'litespeed-cache' ), $num ) ;

				case 'image' :
					return sprintf( __( '%s images', 'litespeed-cache' ), $num ) ;

				default:
					return $num ;
			}

		}

		switch ( $kind ) {
			case 'group' :
				return sprintf( __( '%s group', 'litespeed-cache' ), $num ) ;

			case 'image' :
				return sprintf( __( '%s image', 'litespeed-cache' ), $num ) ;

			default:
				return $num ;
		}
	}

	/**
	 * Return guidance html
	 *
	 * @since  2.0
	 * @access public
	 */
	public static function guidance( $title, $steps, $current_step )
	{
		if ( $current_step === 'done' ) {
			$current_step = count( $steps ) + 1 ;
		}

		$percentage = ' (' . floor( ( $current_step - 1 ) * 100 / count( $steps ) ) . '%)' ;

		$html = '<div class="litespeed-guide">'
					. '<h2>' . $title . $percentage . '</h2>'
					. '<ol>' ;
		foreach ( $steps as $k => $v ) {
			$step = $k + 1 ;
			if ( $current_step > $step ) {
				$html .= '<li class="litespeed-guide-done">' ;
			}
			else {
				$html .= '<li>' ;
			}
			$html .= $v . '</li>' ;
		}

		$html .= '</ol></div>' ;

		return $html ;
	}

	/**
	 * Get the current instance object.
	 *
	 * @since 1.1.0
	 * @access public
	 * @return Current class instance.
	 */
	public static function get_instance()
	{
		if ( ! isset(self::$_instance) ) {
			self::$_instance = new self() ;
		}

		return self::$_instance ;
	}
}

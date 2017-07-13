<?php
/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/includes
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */
class LiteSpeed_Cache
{
	private static $_instance ;

	const PLUGIN_NAME = 'litespeed-cache' ;
	const PLUGIN_VERSION = '1.1.2.2' ;

	const PAGE_EDIT_HTACCESS = 'lscache-edit-htaccess' ;

	const NONCE_NAME = 'LSCWP_NONCE' ;
	const ACTION_KEY = 'LSCWP_CTRL' ;
	const ACTION_DISMISS_WHM = 'dismiss-whm' ;
	const ACTION_SAVE_HTACCESS = 'save-htaccess' ;
	const ACTION_SAVE_SETTINGS = 'save-settings' ;
	const ACTION_SAVE_SETTINGS_NETWORK = 'save-settings-network' ;
	const ACTION_PURGE = 'PURGE' ;
	const ACTION_PURGE_ERRORS = 'PURGE_ERRORS' ;
	const ACTION_PURGE_PAGES = 'PURGE_PAGES' ;
	const ACTION_PURGE_BY = 'PURGE_BY' ;
	const ACTION_PURGE_FRONT = 'PURGE_FRONT' ;
	const ACTION_PURGE_ALL = 'PURGE_ALL' ;
	const ACTION_PURGE_EMPTYCACHE = 'PURGE_EMPTYCACHE' ;
	const ACTION_PURGE_SINGLE = 'PURGESINGLE' ;
	const ACTION_SHOW_HEADERS = 'SHOWHEADERS' ;
	const ACTION_NOCACHE = 'NOCACHE' ;
	const ACTION_CRAWLER_GENERATE_FILE = 'crawler-generate-file' ;
	const ACTION_CRAWLER_RESET_POS = 'crawler-reset-pos' ;
	const ACTION_CRAWLER_CRON_ENABLE = 'crawler-cron-enable' ;
	const ACTION_DO_CRAWL = 'do-crawl' ;
	const ACTION_BLACKLIST_SAVE = 'blacklist-save' ;

	const WHM_TRANSIENT = 'lscwp_whm_install' ;
	const WHM_TRANSIENT_VAL = 'whm_install' ;

	const HEADER_DEBUG = 'X-LiteSpeed-Debug' ;

	protected static $_error_status = false ;
	protected static $_debug_show_header = false ;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	private function __construct()
	{
		// Check if debug is on
		if ( self::config(LiteSpeed_Cache_Config::OPID_ENABLED) ) {
			$should_debug = intval(self::config(LiteSpeed_Cache_Config::OPID_DEBUG)) ;
			if ( $should_debug == LiteSpeed_Cache_Config::VAL_ON || ($should_debug == LiteSpeed_Cache_Config::VAL_NOTSET && LiteSpeed_Cache_Router::is_admin_ip()) ) {
				LiteSpeed_Cache_Log::set_enabled() ;
			}

			// Load third party detection if lscache enabled.
			include_once LSWCP_DIR . 'thirdparty/lscwp-registry-3rd.php' ;
		}

		// Register plugin activate/deactivate/uninstall hooks
		// NOTE: this can't be moved under after_setup_theme, otherwise activation will be bypassed somehow
		if( is_admin() || LiteSpeed_Cache_Router::is_cli() ) {
			$plugin_file = LSWCP_DIR . 'litespeed-cache.php' ;
			register_activation_hook($plugin_file, array('LiteSpeed_Cache_Activation', 'register_activation' )) ;
			register_deactivation_hook($plugin_file, array('LiteSpeed_Cache_Activation', 'register_deactivation' )) ;
			register_uninstall_hook($plugin_file, 'LiteSpeed_Cache_Activation::uninstall_litespeed_cache') ;
		}

		add_action('after_setup_theme', array( $this, 'init' )) ;
	}

	/**
	 * The plugin initializer.
	 *
	 * This function checks if the cache is enabled and ready to use, then
	 * determines what actions need to be set up based on the type of user
	 * and page accessed. Output is buffered if the cache is enabled.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function init()
	{
		if( is_admin() ) {
			LiteSpeed_Cache_Admin::get_instance() ;
		}

		if ( !LiteSpeed_Cache_Config::get_instance()->is_plugin_enabled() || !defined('LSCACHE_ADV_CACHE') || !LSCACHE_ADV_CACHE ) {
			return ;
		}

		define('LITESPEED_CACHE_ENABLED', true) ;
		ob_start() ;
		add_action('shutdown', array($this, 'send_headers'), 0) ;
		add_action('wp_footer', 'LiteSpeed_Cache::litespeed_comment_info') ;

		$bad_cookies = LiteSpeed_Cache_Vary::setup_cookies() ;

		// if ( $this->check_esi_page()) {
		// 	return ;
		// }

		if ( ! $bad_cookies && ! LiteSpeed_Cache_Vary::check_user_logged_in() && ! LiteSpeed_Cache_Vary::check_cookies() ) {
			// user is not logged in
			add_action('login_init', array( $this, 'check_login_cacheable' ), 5) ;
			add_filter('status_header', 'LiteSpeed_Cache::check_error_codes', 10, 2) ;
		}
		else {
			if ( LITESPEED_SERVER_TYPE !== 'LITESPEED_SERVER_OLS' ) {
				add_action('wp_logout', 'LiteSpeed_Cache_Purge::purge_on_logout') ;
				if ( self::config(LiteSpeed_Cache_Config::OPID_ESI_ENABLE) ) {
					define('LSCACHE_ESI_LOGGEDIN', true) ;
					// user is not logged in
					add_action('login_init', array( $this, 'check_login_cacheable' ), 5) ;
					add_filter('status_header', 'LiteSpeed_Cache::check_error_codes', 10, 2) ;
				}
			}
		}

		// Load public hooks
		$this->load_public_actions() ;

		// load cron task for crawler
		if ( self::config(LiteSpeed_Cache_Config::CRWL_CRON_ACTIVE) && LiteSpeed_Cache_Router::can_crawl() ) {
			// keep cron intval filter
			LiteSpeed_Cache_Task::schedule_filter() ;

			// cron hook
			add_action(LiteSpeed_Cache_Task::CRON_ACTION_HOOK, 'LiteSpeed_Cache_Crawler::crawl_data') ;
		}

		if ( LiteSpeed_Cache_Router::is_ajax() ) {
			add_action('init', array($this, 'detect'), 4) ;
		}
		elseif ( is_admin() || is_network_admin() ) {
			add_action('admin_init', array($this, 'detect'), 0) ;
		}
		else {
			add_action('wp', array($this, 'detect'), 4) ;
		}

		// load litespeed actions
		if ( $action = LiteSpeed_Cache_Router::get_action() ) {
			$this->proceed_action($action) ;
		}
	}

	/**
	 * Run frontend actions
	 *
	 * @since 1.1.0
	 * @access public
	 */
	public function proceed_action($action)
	{
		$msg = false ;
		// handle actions
		switch ( $action ) {
			case LiteSpeed_Cache::ACTION_PURGE:
				LiteSpeed_Cache_Purge::set_purge_related() ;
				break;

			case self::ACTION_SHOW_HEADERS:
				self::$_debug_show_header = true ;
				break;

			case LiteSpeed_Cache::ACTION_PURGE_SINGLE:
				LiteSpeed_Cache_Purge::set_purge_single() ;
				break;

			case LiteSpeed_Cache::ACTION_CRAWLER_GENERATE_FILE:
				LiteSpeed_Cache_Crawler::get_instance()->generate_sitemap() ;
				LiteSpeed_Cache_Admin::redirect() ;
				break;

			case LiteSpeed_Cache::ACTION_CRAWLER_RESET_POS:
				LiteSpeed_Cache_Crawler::get_instance()->reset_pos() ;
				LiteSpeed_Cache_Admin::redirect() ;
				break;

			case LiteSpeed_Cache::ACTION_CRAWLER_CRON_ENABLE:
				LiteSpeed_Cache_Task::enable() ;
				break;

			// Handle the ajax request to proceed crawler manually by admin
			case LiteSpeed_Cache::ACTION_DO_CRAWL:
				LiteSpeed_Cache_Crawler::crawl_data(true) ;
				break ;

			case LiteSpeed_Cache::ACTION_BLACKLIST_SAVE:
				LiteSpeed_Cache_Crawler::get_instance()->save_blacklist() ;
				$msg = __('Crawler blacklist is saved.', 'litespeed-cache') ;
				break ;

			case LiteSpeed_Cache::ACTION_PURGE_FRONT:
				LiteSpeed_Cache_Purge::purge_front() ;
				$msg = __('Notified LiteSpeed Web Server to purge the front page.', 'litespeed-cache') ;
				break ;

			case LiteSpeed_Cache::ACTION_PURGE_PAGES:
				LiteSpeed_Cache_Purge::purge_pages() ;
				$msg = __('Notified LiteSpeed Web Server to purge pages.', 'litespeed-cache') ;
				break ;

			case LiteSpeed_Cache::ACTION_PURGE_ERRORS:
				LiteSpeed_Cache_Purge::purge_errors() ;
				$msg = __('Notified LiteSpeed Web Server to purge error pages.', 'litespeed-cache') ;
				break ;

			case LiteSpeed_Cache::ACTION_PURGE_ALL://todo: for cli, move this to ls->proceed_action()
				LiteSpeed_Cache_Purge::purge_all() ;
				$msg = __('Notified LiteSpeed Web Server to purge the public cache.', 'litespeed-cache') ;
				break;

			case LiteSpeed_Cache::ACTION_PURGE_EMPTYCACHE:
				LiteSpeed_Cache_Purge::purge_all() ;
				$msg = __('Notified LiteSpeed Web Server to purge everything.', 'litespeed-cache') ;
				break;

			case LiteSpeed_Cache::ACTION_PURGE_BY:
				LiteSpeed_Cache_Purge::get_instance()->purge_list() ;
				$msg = __('Notified LiteSpeed Web Server to purge the list.', 'litespeed-cache') ;
				break;

			case LiteSpeed_Cache::ACTION_DISMISS_WHM:// Even its from ajax, we don't need to register wp ajax callback function but directly use our action
				LiteSpeed_Cache_Activation::dismiss_whm() ;
				break ;

			default:
				break ;
		}
		if ( $msg && ! LiteSpeed_Cache_Router::is_ajax() ) {
			LiteSpeed_Cache_Admin_Display::add_notice(LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, $msg) ;
			LiteSpeed_Cache_Admin::redirect() ;
			return ;
		}
	}

	/**
	 * Callback used to call the detect third party action.
	 *
	 * The detect action is used by third party plugin integration classes
	 * to determine if they should add the rest of their hooks.
	 *
	 * @since 1.0.5
	 * @access public
	 */
	public function detect()
	{
		do_action('litespeed_cache_api_detect_thirdparty') ;
	}

	/**
	 * Register all of the hooks related to the all users
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_public_actions()
	{
		//register purge actions
		$purge_post_events = array(
			'edit_post',
			'save_post',
			'deleted_post',
			'trashed_post',
			'delete_attachment',
		) ;
		foreach ( $purge_post_events as $event ) {
			// this will purge all related tags
			add_action($event, 'LiteSpeed_Cache_Purge::purge_post', 10, 2) ;
		}

		// The ESI functionality is an enterprise feature.
		// Removing the openlitespeed check will simply break the page.
		//todo: make a constant for esiEnable included cfg esi eanbled
		if ( LITESPEED_SERVER_TYPE !== 'LITESPEED_SERVER_OLS' && !LiteSpeed_Cache_Router::is_ajax() && self::config(LiteSpeed_Cache_Config::OPID_ESI_ENABLE) ) {
			if ( LiteSpeed_Cache::config(LiteSpeed_Cache_Config::OPID_ESI_ENABLE) ) {
				add_action('template_include', 'LiteSpeed_Cache_ESI::esi_template', 100) ;
				add_action('load-widgets.php', 'LiteSpeed_Cache_Purge::purge_widget') ;
				add_action('wp_update_comment_count', 'LiteSpeed_Cache_Purge::purge_comment_widget') ;
			}
		}
		add_action('wp_update_comment_count', 'LiteSpeed_Cache_Purge::purge_feeds') ;

		// register recent posts widget tag before theme renders it to make it work
		add_filter('widget_posts_args', 'LiteSpeed_Cache_Tag::add_widget_recent_posts') ;
	}

	/**
	 * A shortcut to get the LiteSpeed_Cache_Config config value
	 *
	 * @since 1.0.0
	 * @access public
	 * @param string $opt_id An option ID if getting an option.
	 * @return the option value
	 */
	public static function config($opt_id)
	{
		return LiteSpeed_Cache_Config::get_instance()->get_option($opt_id) ;
	}

	/**
	 * Check if the page returns 403 and 500 errors.
	 *
	 * @since 1.0.13.1
	 * @access public
	 * @param $header
	 * @param $code
	 * @return $eror_status
	 */
	public static function check_error_codes($header, $code)
	{
		$ttl_403 = self::config(LiteSpeed_Cache_Config::OPID_403_TTL) ;
		$ttl_500 = self::config(LiteSpeed_Cache_Config::OPID_500_TTL) ;
		if ( $code == 403 ) {
			if ( $ttl_403 <= 30 ) {
				LiteSpeed_Cache_Control::set_nocache() ;
			}
			else {
				self::$_error_status = $code ;
			}
		}
		elseif ( $code >= 500 && $code < 600 ) {
			if ( $ttl_500 <= 30 ) {
				LiteSpeed_Cache_Control::set_nocache() ;
			}
		}
		elseif ( $code > 400 ) {
			self::$_error_status = $code ;
		}
		return self::$_error_status ;
	}

	/**
	 * Get error code.
	 *
	 * @since 1.2.0
	 * @access public
	 */
	public static function get_error_code()
	{
		return self::$_error_status ;
	}

	/**
	 * Check if the login page is cacheable.
	 * If not, unset the cacheable member variable.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public function check_login_cacheable()
	{
		if ( ! self::config(LiteSpeed_Cache_Config::OPID_CACHE_LOGIN) ) {
			return ;
		}
		// not sure if need to use LiteSpeed_Cache_Control::finalize()
		if ( ! LiteSpeed_Cache_Control::get_cacheable() ) {
			return ;
		}

		if ( !empty($_GET) ) {
			LiteSpeed_Cache_Log::debug('Do not cache - Not a get request') ;
			LiteSpeed_Cache_Control::set_nocache() ;
			return ;
		}

		LiteSpeed_Cache_Tag::add(LiteSpeed_Cache_Tag::TYPE_LOGIN) ;

		$list = headers_list() ;
		if ( empty($list) ) {
			return ;
		}
		foreach ($list as $hdr) {
			if ( strncasecmp($hdr, 'set-cookie:', 11) == 0 ) {
				$cookie = substr($hdr, 12) ;
				@header('lsc-cookie: ' . $cookie, false) ;
			}
		}
	}

	/**
	 * Tigger coment info display for wp_footer hook
	 *
	 * @since 1.1.1
	 * @access public
	 */
	public static function litespeed_comment_info()
	{
		// double check to make sure it is a html file
		$buffer = ob_get_contents() ;
		if ( strlen($buffer) > 300 ) {
			$buffer = substr($buffer, 0, 300) ;
		}
		if ( strstr($buffer, '<!--') !== false ) {
			$buffer = preg_replace('|<!--.*?-->|s', '', $buffer) ;
		}
		$is_html = stripos($buffer, '<html') === 0 || stripos($buffer, '<!DOCTYPE') === 0 ;
		if ( defined('DOING_AJAX') ) {
			return ;
		}
		if ( defined('DOING_CRON') ) {
			return ;
		}
		if ( ! $is_html ) {
			return ;
		}

		define('LITESPEED_COMMENT_INFO', true) ;
	}

	/**
	 * Sends the headers out at the end of processing the request.
	 *
	 * This will send out all LiteSpeed Cache related response headers
	 * needed for the post.
	 *
	 * @since 1.0.5
	 * @access public
	 */
	public function send_headers()
	{
		// NOTE: cache ctrl output needs to be done first, as currently some varies are added in 3rd party hook `litespeed_cache_api_control`.
		$control_header = LiteSpeed_Cache_Control::get_instance()->output() ;

		$vary_header = LiteSpeed_Cache_Vary::output() ;

		$tag_header = '' ;
		// If is not cacheable but Admin QS is `purge` or `purgesingle`, `tag` still needs to be generated
		$is_cacheable = LiteSpeed_Cache_Control::get_cacheable() ;
		if ( ! $is_cacheable && LiteSpeed_Cache_Purge::get_qs_purge() ) {
			LiteSpeed_Cache_Tag::finalize() ;
		}
		elseif ( $is_cacheable ) {
			$tag_header = LiteSpeed_Cache_Tag::output() ;
		}

		if ( empty($tag_header) ) {
			//$mode = self::CACHECTRL_NOCACHE ;xx
			// todo1
		}

		// NOTE: `purge` output needs to be after `tag` output as Admin QS may need to send `tag` header
		$purge_header = LiteSpeed_Cache_Purge::output() ;

		if ( $control_header ) {
			$hdr_content[] = $control_header ;
		}
		if ( $purge_header ) {
			$hdr_content[] = $purge_header ;
		}
		if ( $tag_header ) {
			$hdr_content[] = $tag_header ;
		}
		if ( $vary_header ) {
			$hdr_content[] = $vary_header ;
		}

		if ( ! empty($hdr_content) ) {
			if ( self::$_debug_show_header ) {
				@header(self::HEADER_DEBUG . ': ' . implode('; ', $hdr_content)) ;
			}
			else {
				foreach($hdr_content as $hdr) {
					@header($hdr) ;
				}
			}
		}

		$running_info_showing = defined('LITESPEED_COMMENT_INFO') ;
		if ( $running_info_showing ) {
			echo '<!-- Page generated by LiteSpeed Cache on '.date('Y-m-d H:i:s').' -->' ;
		}
		if (LiteSpeed_Cache_Log::get_enabled()) {
			if($tag_header){
				LiteSpeed_Cache_Log::push($tag_header) ;
				if( $running_info_showing ) {
					echo "\n<!-- ".$tag_header." -->" ;
				}
			}
			if($control_header) {
				LiteSpeed_Cache_Log::push($control_header) ;
				if( $running_info_showing ) {
					echo "\n<!-- ".$control_header." -->" ;
				}
			}
			if($purge_header) {
				LiteSpeed_Cache_Log::push($purge_header) ;
				if( $running_info_showing ) {
					echo "\n<!-- ".$purge_header." -->" ;
				}
			}

			LiteSpeed_Cache_Log::push("End response.\n--------------------------------------------------------------------------------\n") ;
		}
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
		$cls = get_called_class() ;
		if ( ! isset(self::$_instance) ) {
			self::$_instance = new $cls() ;
		}

		return self::$_instance ;
	}
}
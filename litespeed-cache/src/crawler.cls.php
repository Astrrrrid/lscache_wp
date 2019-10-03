<?php
/**
 * The crawler class
 *
 *
 * @since      	1.1.0
 * @since  		1.5 Moved into /inc
 * @since  		3.0 Moved into /src
 * @package    	LiteSpeed
 * @subpackage 	LiteSpeed/src
 * @author     	LiteSpeed Technologies <info@litespeedtech.com>
 */
namespace LiteSpeed ;

defined( 'WPINC' ) || exit ;

class Crawler extends Conf
{
	protected static $_instance;
	const DB_PREFIX = 'crawler' ; // DB record prefix name

	private $_sitemap_file ;
	private $_blacklist_file ;
	private $_home_url ;
	const CRWL_BLACKLIST = 'crawler_blacklist' ;

	private $_options ;

	/**
	 * Initialize crawler, assign sitemap path
	 *
	 * @since    1.1.0
	 * @access protected
	 */
	protected function __construct()
	{
		$sitemapPath = LSCWP_DIR . 'var' ;
		if ( is_multisite() ) {
			$blogID = get_current_blog_id() ;
			$this->_sitemap_file = $sitemapPath . '/crawlermap-' . $blogID . '.data' ;
			$this->_home_url = get_home_url( $blogID ) ;
		}
		else{
			$this->_sitemap_file = $sitemapPath . '/crawlermap.data' ;
			$this->_home_url = get_home_url() ;
		}
		$this->_blacklist_file = $this->_sitemap_file . '.blacklist' ;

		$this->_options = Config::get_instance()->get_options() ;

		Log::debug('Crawler: Initialized') ;
	}

	/**
	 * Return crawler meta file
	 *
	 * @since    1.1.0
	 * @access public
	 * @return string Json data file path
	 */
	public function get_crawler_json_path()
	{
		if ( ! file_exists($this->_sitemap_file . '.meta') ) {
			return false ;
		}
		$metaUrl = implode('/', array_slice(explode('/', $this->_sitemap_file . '.meta'), -5)) ;
		return $this->_home_url . '/' . $metaUrl ;
	}

	/**
	 * Return blacklist content
	 *
	 * @since    1.1.0
	 * @access public
	 * @return string
	 */
	public function get_blacklist()
	{
		return File::read($this->_blacklist_file) ;
	}

	/**
	 * Return blacklist count
	 *
	 * @since    1.1.0
	 * @access public
	 * @return string
	 */
	public function count_blacklist()
	{
		return File::count_lines($this->_blacklist_file) ;
	}

	/**
	 * Save blacklist to file
	 *
	 * @since    1.1.0
	 * @access public
	 * @return bool If saved successfully
	 */
	public function save_blacklist()
	{
		if ( ! isset( $_POST[ self::CRWL_BLACKLIST ] ) ) {
			$msg = __( 'Can not find any form data for blacklist', 'litespeed-cache' ) ;
			Admin_Display::add_notice( Admin_Display::NOTICE_RED, $msg ) ;
			return false ;
		}

		// save blacklist file
		$ret = File::save( $this->_blacklist_file, Utility::sanitize_lines( $_POST[ self::CRWL_BLACKLIST ], 'string' ), true, false, false ) ;
		if ( $ret !== true ) {
			Admin_Display::add_notice( Admin_Display::NOTICE_RED, $ret ) ;
		}
		else {
			$msg = sprintf(
				__( 'File saved successfully: %s', 'litespeed-cache' ),
				$this->_blacklist_file
			) ;
			Admin_Display::add_notice( Admin_Display::NOTICE_GREEN, $msg ) ;
		}

		return true ;
	}

	/**
	 * Append urls to current list
	 *
	 * @since    1.1.0
	 * @access public
	 * @param  array $list The url list needs to be appended
	 */
	public function append_blacklist( $list )
	{
		defined( 'LSCWP_LOG' ) && Log::debug( 'Crawler: append blacklist ' . count( $list ) ) ;

		$ori_list = File::read( $this->_blacklist_file ) ;
		$ori_list = explode( "\n", $ori_list ) ;
		$ori_list = array_merge( $ori_list, $list ) ;
		$ori_list = array_map( 'trim', $ori_list ) ;
		$ori_list = array_filter( $ori_list ) ;
		$ori_list = array_unique( $ori_list ) ;
		$content = implode( "\n", $ori_list ) ;

		// save blacklist
		$ret = File::save( $this->_blacklist_file, $content, true, false, false ) ;
		if ( $ret !== true ) {
			Log::debug( 'Crawler: append blacklist failed: ' . $ret ) ;
			return false ;
		}

		return true ;
	}

	/**
	 * Generate sitemap
	 *
	 * @since    1.1.0
	 * @access public
	 */
	public function generate_sitemap()
	{
		$ret = $this->_generate_sitemap() ;
		if ( $ret !== true ) {
			Admin_Display::add_notice(Admin_Display::NOTICE_RED, $ret) ;
		}
		else {
			$msg = sprintf(
				__('File created successfully: %s', 'litespeed-cache'),
				$this->_sitemap_file
			) ;
			Admin_Display::add_notice(Admin_Display::NOTICE_GREEN, $msg) ;
		}
	}

	/**
	 * Parse custom sitemap and return urls
	 *
	 * @since    1.1.1
	 * @access public
	 * @param  string  $sitemap       The url set map address
	 * @param  boolean $return_detail If return url list
	 * @return array          Url list
	 */
	public function parse_custom_sitemap($sitemap, $return_detail = true)
	{
		/**
		 * Read via wp func to avoid allow_url_fopen = off
		 * @since  2.2.7
		 */
		$response = wp_remote_get( $sitemap, array( 'timeout' => 15 ) ) ;
		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message() ;
			Log::debug( '[Crawler] failed to read sitemap: ' . $error_message ) ;

			throw new \Exception( 'Failed to remote read' ) ;
		}

		$xml_object = simplexml_load_string( $response[ 'body' ] ) ;
		if ( ! $xml_object ) {
			throw new \Exception( 'Failed to parse xml' ) ;
		}

		if ( ! $return_detail ) {
			return true ;
		}
		// start parsing
		$_urls = array() ;

		$xml_array = (array)$xml_object ;
		if ( !empty($xml_array['sitemap']) ) {// parse sitemap set
			if ( is_object($xml_array['sitemap']) ) {
				$xml_array['sitemap'] = (array)$xml_array['sitemap'] ;
			}
			if ( !empty($xml_array['sitemap']['loc']) ) {// is single sitemap
				$urls = $this->parse_custom_sitemap( $xml_array[ 'sitemap' ][ 'loc' ] ) ;
				if ( is_array( $urls ) && ! empty( $urls ) ) {
					$_urls = array_merge($_urls, $urls) ;
				}
			}
			else {
				// parse multiple sitemaps
				foreach ($xml_array['sitemap'] as $val) {
					$val = (array)$val ;
					if ( !empty($val['loc']) ) {
						$urls = $this->parse_custom_sitemap( $val[ 'loc' ] ) ;// recursive parse sitemap
						if ( is_array( $urls ) && ! empty( $urls ) ) {
							$_urls = array_merge( $_urls, $urls ) ;
						}
					}
				}
			}
		}
		elseif ( !empty($xml_array['url']) ) {// parse url set
			if ( is_object($xml_array['url']) ) {
				$xml_array['url'] = (array)$xml_array['url'] ;
			}
			// if only 1 element
			if ( !empty($xml_array['url']['loc']) ) {
				$_urls[] = $xml_array['url']['loc'] ;
			}
			else {
				foreach ($xml_array['url'] as $val) {
					$val = (array)$val ;
					if ( !empty($val['loc']) ) {
						$_urls[] = $val['loc'] ;
					}
				}
			}
		}

		return $_urls ;
	}

	/**
	 * Generate the sitemap
	 *
	 * @since    1.1.0
	 * @access protected
	 * @return string|true
	 */
	protected function _generate_sitemap()
	{
		// use custom sitemap
		if ( $sitemap = $this->_options[ Conf::O_CRWL_CUSTOM_SITEMAP ] ) {
			$urls = array() ;
			$offset = strlen( $this->_home_url ) ;
			$sitemap_urls = false ;

			try {
				$sitemap_urls = $this->parse_custom_sitemap( $sitemap ) ;
			} catch( \Exception $e ) {
				Log::debug( '[Crawler] ❌ failed to prase custom sitemap: ' . $e->getMessage() ) ;
			}

			if ( is_array( $sitemap_urls ) && ! empty( $sitemap_urls ) ) {
				foreach ( $sitemap_urls as $val ) {
					if ( stripos( $val, $this->_home_url ) === 0 ) {
						$urls[] = substr( $val, $offset ) ;
					}
				}

				$urls = array_unique( $urls ) ;
			}
		}
		else {
			$urls = Crawler_Sitemap::get_instance()->generate_data() ;
		}

		// filter urls
		$blacklist = File::read( $this->_blacklist_file ) ;
		$blacklist = explode( "\n", $blacklist ) ;
		$urls = array_diff( $urls, $blacklist ) ;
		Log::debug( 'Crawler: Generate sitemap' ) ;

		$ret = File::save( $this->_sitemap_file, implode( "\n", $urls ), true, false, false ) ;

		clearstatcache() ;

		// refresh list size in meta
		$crawler = new Crawler_Engine( $this->_sitemap_file ) ;
		$crawler->refresh_list_size() ;

		return $ret ;
	}

	/**
	 * Get sitemap file info
	 *
	 * @since 1.1.0
	 * @access public
	 */
	public function sitemap_time()
	{
		if ( ! file_exists($this->_sitemap_file) ) {
			return false ;
		}

		$filetime = date('m/d/Y H:i:s', filemtime($this->_sitemap_file) + LITESPEED_TIME_OFFSET ) ;

		return $filetime ;
	}

	/**
	 * Create reset pos file
	 *
	 * @since    1.1.0
	 * @access public
	 * @return mixed True or error message
	 */
	public function reset_pos()
	{
		$crawler = new Crawler_Engine($this->_sitemap_file) ;
		$ret = $crawler->reset_pos() ;
		$log = 'Crawler: Reset pos. ' ;
		if ( $ret !== true ) {
			$log .= "Error: $ret" ;
			$msg = sprintf(__('Failed to send position reset notification: %s', 'litespeed-cache'), $ret) ;
			Admin_Display::add_notice(Admin_Display::NOTICE_RED, $msg) ;
		}
		else {
			$msg = __('Position reset notification sent successfully', 'litespeed-cache') ;
			// Admin_Display::add_notice(Admin_Display::NOTICE_GREEN, $msg) ;
		}
		Log::debug($log) ;
	}

	/**
	 * Proceed crawling
	 *
	 * @since    1.1.0
	 * @access public
	 * @param bool $force If ignore whole crawling interval
	 */
	public static function crawl_data($force = false)
	{
		if ( ! Router::can_crawl() ) {
			Log::debug('Crawler: ......crawler is NOT allowed by the server admin......') ;
			return false;
		}
		if ( $force ) {
			Log::debug('Crawler: ......crawler manually ran......') ;
		}
		return self::get_instance()->_crawl_data($force) ;
	}

	/**
	 * Receive meta info from crawler
	 *
	 * @since    1.9.1
	 * @access   public
	 */
	public function read_meta()
	{
		$crawler = new Crawler_Engine( $this->_sitemap_file ) ;
		return $crawler->read_meta() ;
	}

	/**
	 * Crawling start
	 *
	 * @since    1.1.0
	 * @access   protected
	 * @param bool $force If ignore whole crawling interval
	 */
	protected function _crawl_data($force)
	{
		Log::debug('Crawler: ......crawler started......') ;
		// for the first time running
		if ( ! file_exists($this->_sitemap_file) ) {
			$ret = $this->_generate_sitemap() ;
			if ( $ret !== true ) {
				Log::debug('Crawler: ' . $ret) ;
				return $this->output($ret) ;
			}
		}

		$crawler = new Crawler_Engine($this->_sitemap_file) ;
		// if finished last time, regenerate sitemap
		if ( $last_fnished_at = $crawler->get_done_status() ) {
			// check whole crawling interval
			if ( ! $force && time() - $last_fnished_at < $this->_options[Conf::O_CRWL_CRAWL_INTERVAL] ) {
				Log::debug('Crawler: Cron abort: cache warmed already.') ;
				// if not reach whole crawling interval, exit
				return;
			}
			Log::debug( 'Crawler: TouchedEnd. regenerate sitemap....' ) ;
			$this->_generate_sitemap() ;
		}
		$crawler->set_base_url($this->_home_url) ;
		$crawler->set_run_duration($this->_options[Conf::O_CRWL_RUN_DURATION]) ;

		/**
		 * Limit delay to use server setting
		 * @since 1.8.3
		 */
		$usleep = $this->_options[ Conf::O_CRWL_USLEEP ] ;
		if ( ! empty( $_SERVER[ Conf::ENV_CRAWLER_USLEEP ] ) && $_SERVER[ Conf::ENV_CRAWLER_USLEEP ] > $usleep ) {
			$usleep = $_SERVER[ Conf::ENV_CRAWLER_USLEEP ] ;
		}
		$crawler->set_run_delay( $usleep ) ;
		$crawler->set_threads_limit( $this->_options[ Conf::O_CRWL_THREADS ] ) ;
		/**
		 * Set timeout to avoid incorrect blacklist addition #900171
		 * @since  3.0
		 */
		$crawler->set_timeout( $this->_options[ Conf::O_CRWL_TIMEOUT ] ) ;

		$server_load_limit = $this->_options[ Conf::O_CRWL_LOAD_LIMIT ] ;
		if ( ! empty( $_SERVER[ Conf::ENV_CRAWLER_LOAD_LIMIT_ENFORCE ] ) ) {
			$server_load_limit = $_SERVER[ Conf::ENV_CRAWLER_LOAD_LIMIT_ENFORCE ] ;
		}
		elseif ( ! empty( $_SERVER[ Conf::ENV_CRAWLER_LOAD_LIMIT ] ) && $_SERVER[ Conf::ENV_CRAWLER_LOAD_LIMIT ] < $server_load_limit ) {
			$server_load_limit = $_SERVER[ Conf::ENV_CRAWLER_LOAD_LIMIT ] ;
		}
		$crawler->set_load_limit( $server_load_limit ) ;
		if ( $this->_options[Conf::O_SERVER_IP] ) {
			$crawler->set_domain_ip($this->_options[Conf::O_SERVER_IP]) ;
		}

		// Get current crawler
		$meta = $crawler->read_meta() ;
		$curr_crawler_pos = $meta[ 'curr_crawler' ] ;

		// Generate all crawlers
		$crawlers = $this->list_crawlers() ;

		// In case crawlers are all done but not reload, reload it
		if ( empty( $crawlers[ $curr_crawler_pos ] ) ) {
			$curr_crawler_pos = 0 ;
		}
		$current_crawler = $crawlers[ $curr_crawler_pos ] ;

		$cookies = array() ;
		/**
		 * Set role simulation
		 * @since 1.9.1
		 */
		if ( ! empty( $current_crawler[ 'uid' ] ) ) {
			// Get role simulation vary name
			$vary_inst = Vary::get_instance() ;
			$vary_name = $vary_inst->get_vary_name() ;
			$vary_val = $vary_inst->finalize_default_vary( $current_crawler[ 'uid' ] ) ;
			$cookies[ $vary_name ] = $vary_val ;
			$cookies[ 'litespeed_role' ] = $current_crawler[ 'uid' ] ;
		}

		/**
		 * Check cookie crawler
		 * @since  2.8
		 */
		foreach ( $current_crawler as $k => $v ) {
			if ( strpos( $k, 'cookie:') !== 0 ) {
				continue ;
			}

			$cookies[ substr( $k, 7 ) ] = $v ;
		}

		if ( $cookies ) {
			$crawler->set_cookies( $cookies ) ;
		}

		/**
		 * Set WebP simulation
		 * @since  1.9.1
		 */
		if ( ! empty( $current_crawler[ 'webp' ] ) ) {
			$crawler->set_headers( array( 'Accept: image/webp,*/*' ) ) ;
		}

		/**
		 * Set mobile crawler
		 * @since  2.8
		 */
		if ( ! empty( $current_crawler[ 'mobile' ] ) ) {
			$crawler->set_ua( 'Mobile' ) ;
		}

		$ret = $crawler->engine_start() ;

		// merge blacklist
		if ( $ret['blacklist'] ) {
			$this->append_blacklist($ret['blacklist']) ;
		}

		if ( ! empty($ret['crawled']) ) {
			defined( 'LSCWP_LOG' ) && Log::debug( 'Crawler: Last crawled ' . $ret[ 'crawled' ] . ' item(s)' ) ;
		}

		// return error
		if ( $ret['error'] !== false ) {
			Log::debug('Crawler: ' . $ret['error']) ;
			return $this->output($ret['error']) ;
		}
		else {
			$msg = 'Crawler #' . ( $curr_crawler_pos + 1 ) . ' reached end of sitemap file.' ;
			$msg_t = sprintf( __( 'Crawler %s reached end of sitemap file.', 'litespeed-cache' ), '#' . ( $curr_crawler_pos + 1 ) )  ;
			Log::debug('Crawler: ' . $msg) ;
			return $this->output($msg_t) ;
		}
	}

	/**
	 * List all crawlers
	 *
	 * @since    1.9.1
	 * @access   public
	 */
	public function list_crawlers( $count_only = false )
	{
		/**
		 * Data structure:
		 * 	[
		 * 		tagA => [
		 * 			valueA => titleA,
		 * 			valueB => titleB
		 * 			...
		 * 		],
		 * 		...
		 * 	]
		 */
		$crawler_factors = array() ;

		// Add default Guest crawler
		$crawler_factors[ 'uid' ] = array( 0 => __( 'Guest', 'litespeed-cache' ) ) ;

		// WebP on/off
		if ( Media::webp_enabled() ) {
			$crawler_factors[ 'webp' ] = array( 0 => '', 1 => 'WebP' ) ;
		}

		// Mobile crawler
		if ( $this->_options[ Conf::O_CACHE_MOBILE ] ) {
			$crawler_factors[ 'mobile' ] = array( 0 => '', 1 => '<font title="Mobile">📱</font>' ) ;
		}

		// Get roles set
		// List all roles
		foreach ( $this->_options[ Conf::O_CRWL_ROLES ] as $v ) {
			$role_title = '' ;
			$udata = get_userdata( $v ) ;
			if ( isset( $udata->roles ) && is_array( $udata->roles ) ) {
				$tmp = array_values( $udata->roles ) ;
				$role_title = array_shift( $tmp ) ;
			}
			if ( ! $role_title ) {
				continue ;
			}

			$crawler_factors[ 'uid' ][ $v ] = ucfirst( $role_title ) ;
		}

		// Cookie crawler
		foreach ( $this->_options[ Conf::O_CRWL_COOKIES ] as $v ) {
			if ( empty( $v[ 'name' ] ) ) {
				continue ;
			}

			$this_cookie_key = 'cookie:' . $v[ 'name' ] ;

			$crawler_factors[ $this_cookie_key ] = array() ;

			foreach ( $v[ 'vals' ] as $v2 ) {
				$crawler_factors[ $this_cookie_key ][ $v2 ] = '<font title="Cookie">🍪</font>' . $v[ 'name' ] . '=' . $v2 ;
			}
		}

		// Crossing generate the crawler list
		$crawler_list = $this->_recursive_build_crawler( $crawler_factors ) ;

		if ( $count_only ) {
			return count( $crawler_list ) ;
		}

		return $crawler_list ;
	}


	/**
	 * Build a crawler list recursively
	 *
	 * @since 2.8
	 * @access private
	 */
	private function _recursive_build_crawler( $crawler_factors, $group = array(), $i = 0 )
	{
		$current_factor = array_keys( $crawler_factors ) ;
		$current_factor = $current_factor[ $i ] ;

		$if_touch_end = $i + 1 >= count( $crawler_factors ) ;

		$final_list = array() ;

		foreach ( $crawler_factors[ $current_factor ] as $k => $v ) {

			// Don't alter $group bcos of loop usage
			$item = $group ;
			$item[ 'title' ] = ! empty( $group[ 'title' ] ) ? $group[ 'title' ] : '' ;
			if ( $v ) {
				if ( $item[ 'title' ] ) {
					$item[ 'title' ] .= ' - ' ;
				}
				$item[ 'title' ] .= $v ;
			}
			$item[ $current_factor ] = $k ;

			if ( $if_touch_end ) {
				$final_list[] = $item ;
			}
			else {
				// Inception: next layer
				$final_list = array_merge( $final_list, $this->_recursive_build_crawler( $crawler_factors, $item, $i + 1 ) ) ;
			}

		}

		return $final_list ;
	}

	/**
	 * Output info and exit
	 *
	 * @since    1.1.0
	 * @access protected
	 * @param  string $error Error info
	 */
	protected function output($msg)
	{
		if ( defined('DOING_CRON') ) {
			echo $msg ;
			// exit();
		}
		else {
			echo "<script>alert('" . htmlspecialchars($msg) . "');</script>" ;
			// exit;
		}
	}
}
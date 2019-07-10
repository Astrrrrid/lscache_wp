<?php
/**
 * The admin optimize tool
 *
 *
 * @since      1.2.1
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/admin
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */

if ( ! defined( 'WPINC' ) ) {
	die ;
}

class LiteSpeed_Cache_DB_Optm
{
	const TYPES = array( 'revision', 'auto_draft', 'trash_post', 'spam_comment', 'trash_comment', 'trackback-pingback', 'expired_transient', 'all_transients' ) ;
	const TYPE_CONV_TB = 'conv_innodb' ;

	private static $_instance ;

	/**
	 * Clean/Optimize WP tables
	 *
	 * @since  1.2.1
	 * @access public
	 * @param  string $type The type to clean
	 * @param  bool $ignore_multisite If ignore multisite check
	 * @return  int The rows that will be affected
	 */
	public static function db_count( $type, $ignore_multisite = false )
	{
		if ( $type === 'all' ) {
			$num = 0 ;
			foreach ( self::TYPES as $v ) {
				$num += self::db_count( $v ) ;
			}
			return $num ;
		}

		if ( ! $ignore_multisite ) {
			if ( is_multisite() && is_network_admin() ) {
				$num = 0 ;
				$blogs = LiteSpeed_Cache_Activation::get_network_ids() ;
				foreach ( $blogs as $blog_id ) {
					switch_to_blog( $blog_id ) ;
					$num += self::db_count( $type, true ) ;
					restore_current_blog() ;
				}
				return $num ;
			}
		}

		global $wpdb ;

		switch ( $type ) {
			case 'revision':
				return $wpdb->get_var( "SELECT COUNT(*) FROM `$wpdb->posts` WHERE post_type = 'revision'" ) ;

			case 'auto_draft':
				return $wpdb->get_var( "SELECT COUNT(*) FROM `$wpdb->posts` WHERE post_status = 'auto-draft'" ) ;

			case 'trash_post':
				return $wpdb->get_var( "SELECT COUNT(*) FROM `$wpdb->posts` WHERE post_status = 'trash'" ) ;

			case 'spam_comment':
				return $wpdb->get_var( "SELECT COUNT(*) FROM `$wpdb->comments` WHERE comment_approved = 'spam'" ) ;

			case 'trash_comment':
				return $wpdb->get_var( "SELECT COUNT(*) FROM `$wpdb->comments` WHERE comment_approved = 'trash'" ) ;

			case 'trackback-pingback':
				return $wpdb->get_var( "SELECT COUNT(*) FROM `$wpdb->comments` WHERE comment_type = 'trackback' OR comment_type = 'pingback'" ) ;

			case 'expired_transient':
				return $wpdb->get_var( "SELECT COUNT(*) FROM `$wpdb->options` WHERE option_name LIKE '_transient_timeout%' AND option_value < " . time() ) ;

			case 'all_transients':
				return $wpdb->get_var( "SELECT COUNT(*) FROM `$wpdb->options` WHERE option_name LIKE '%_transient_%'" ) ;

			case 'optimize_tables':
				return $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA = '" . DB_NAME . "' and ENGINE <> 'InnoDB' and DATA_FREE > 0" ) ;

			case 'all_cssjs' :
				return $wpdb->get_var( "SELECT COUNT(*) FROM `" . LiteSpeed_Cache_Data::get_optm_table() . "`" ) ;
		}

		return '-' ;
	}

	/**
	 * Clean/Optimize WP tables
	 *
	 * @since  1.2.1
	 * @since 3.0 changed to private
	 * @access private
	 */
	private function _db_clean( $type )
	{
		if ( $type === 'all' ) {
			foreach ( self::TYPES as $v ) {
				$this->_db_clean( $v ) ;
			}
			return __( 'Clean all successfully.', 'litespeed-cache' ) ;
		}

		global $wpdb ;
		switch ( $type ) {
			case 'revision':
				$wpdb->query( "DELETE FROM `$wpdb->posts` WHERE post_type = 'revision'" ) ;
				return __( 'Clean post revisions successfully.', 'litespeed-cache' ) ;

			case 'auto_draft':
				$wpdb->query( "DELETE FROM `$wpdb->posts` WHERE post_status = 'auto-draft'" ) ;
				return __( 'Clean auto drafts successfully.', 'litespeed-cache' ) ;

			case 'trash_post':
				$wpdb->query( "DELETE FROM `$wpdb->posts` WHERE post_status = 'trash'" ) ;
				return __( 'Clean trashed posts and pages successfully.', 'litespeed-cache' ) ;

			case 'spam_comment':
				$wpdb->query( "DELETE FROM `$wpdb->comments` WHERE comment_approved = 'spam'" ) ;
				return __( 'Clean spam comments successfully.', 'litespeed-cache' ) ;

			case 'trash_comment':
				$wpdb->query( "DELETE FROM `$wpdb->comments` WHERE comment_approved = 'trash'" ) ;
				return __( 'Clean trashed comments successfully.', 'litespeed-cache' ) ;

			case 'trackback-pingback':
				$wpdb->query( "DELETE FROM `$wpdb->comments` WHERE comment_type = 'trackback' OR comment_type = 'pingback'" ) ;
				return __( 'Clean trackbacks and pingbacks successfully.', 'litespeed-cache' ) ;

			case 'expired_transient':
				$wpdb->query( "DELETE FROM `$wpdb->options` WHERE option_name LIKE '_transient_timeout%' AND option_value < " . time() ) ;
				return __( 'Clean expired transients successfully.', 'litespeed-cache' ) ;

			case 'all_transients':
				$wpdb->query( "DELETE FROM `$wpdb->options` WHERE option_name LIKE '%_transient_%'" ) ;
				return __( 'Clean all transients successfully.', 'litespeed-cache' ) ;

			case 'optimize_tables':
				$sql = "SELECT table_name, DATA_FREE FROM information_schema.tables WHERE TABLE_SCHEMA = '" . DB_NAME . "' and ENGINE <> 'InnoDB' and DATA_FREE > 0" ;
				$result = $wpdb->get_results( $sql ) ;
				if ( $result ) {
					foreach ( $result as $row ) {
						$wpdb->query( 'OPTIMIZE TABLE ' . $row->table_name ) ;
					}
				}
				return __( 'Optimized all tables.', 'litespeed-cache' ) ;

			case 'all_cssjs' :
				LiteSpeed_Cache_Purge::purge_all() ;
				$wpdb->query( "TRUNCATE `" . LiteSpeed_Cache_Data::get_optm_table() . "`" ) ;
				return __( 'Clean all CSS/JS optimizer data successfully.', 'litespeed-cache' ) ;

		}

	}

	/**
	 * Get all myisam tables
	 *
	 * @since 3.0
	 * @access public
	 */
	public function list_myisam()
	{
		global $wpdb ;
		$q = "SELECT * FROM information_schema.tables WHERE TABLE_SCHEMA = '" . DB_NAME . "' and ENGINE = 'myisam' AND TABLE_NAME LIKE '{$wpdb->prefix}%'" ;
		return $wpdb->get_results( $q ) ;
	}

	/**
	 * Convert tables to InnoDB
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _conv_innodb()
	{
		global $wpdb ;

		if ( empty( $_GET[ 'tb' ] ) ) {
			LiteSpeed_Cache_Admin_Display::error( 'No table to convert' ) ;
			return ;
		}

		$tb = false ;

		$list = $this->list_myisam() ;
		foreach ( $list as $v ) {
			if ( $v->TABLE_NAME == $_GET[ 'tb' ] ) {
				$tb = $v->TABLE_NAME ;
				break ;
			}
		}

		if ( ! $tb ) {
			LiteSpeed_Cache_Admin_Display::error( 'No existing table' ) ;
			return ;
		}

		$q = 'ALTER TABLE ' . DB_NAME . '.' . $tb . ' ENGINE = InnoDB' ;
		$wpdb->query( $q ) ;

		LiteSpeed_Cache_Log::debug( "[DB] Converted $tb to InnoDB" ) ;

		$msg = __( 'Converted to InnoDB successfully.', 'litespeed-cache' ) ;
		LiteSpeed_Cache_Admin_Display::succeed( $msg ) ;

	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  3.0
	 * @access public
	 */
	public static function handler()
	{
		$instance = self::get_instance() ;

		$type = LiteSpeed_Cache_Router::verify_type() ;

		switch ( $type ) {
			case in_array( $type, self::TYPES ) :
				if ( is_multisite() && is_network_admin() ) {
					$blogs = LiteSpeed_Cache_Activation::get_network_ids() ;
					foreach ( $blogs as $blog_id ) {
						switch_to_blog( $blog_id ) ;
						$msg = $instance->_db_clean( $type ) ;
						restore_current_blog() ;
					}
				}
				else {
					$msg = $instance->_db_clean( $type ) ;
				}
				LiteSpeed_Cache_Admin_Display::succeed( $msg ) ;
				break ;

			case self::TYPE_CONV_TB :
				$instance->_conv_innodb() ;
				break ;

			default:
				break ;
		}

		LiteSpeed_Cache_Admin::redirect() ;
	}

	/**
	 * Get the current instance object.
	 *
	 * @since 3.0
	 * @access public
	 * @return Current class instance.
	 */
	public static function get_instance()
	{
		if ( ! isset( self::$_instance ) ) {
			self::$_instance = new self() ;
		}

		return self::$_instance ;
	}

}

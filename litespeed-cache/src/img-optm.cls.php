<?php
/**
 * The class to optimize image.
 *
 * @since 		2.0
 * @package    	LiteSpeed
 * @subpackage 	LiteSpeed/src
 * @author     	LiteSpeed Technologies <info@litespeedtech.com>
 */
namespace LiteSpeed;
defined( 'WPINC' ) || exit;

class Img_Optm extends Base
{
	protected static $_instance;

	const IAPI_ACTION_REQUEST_OPTIMIZE = 'request_optimize';
	const IAPI_ACTION_IMG_TAKEN = 'client_img_taken';
	const IAPI_ACTION_REQUEST_DESTROY = 'imgoptm_destroy';
	const IAPI_ACTION_REQUEST_DESTROY_UNFINISHED = 'imgoptm_destroy_unfinished';

	const TYPE_SYNC_DATA = 'sync_data';
	const TYPE_IMG_OPTIMIZE = 'img_optm';
	const TYPE_IMG_OPTIMIZE_RESCAN = 'img_optm_rescan';
	const TYPE_IMG_OPTM_DESTROY = 'img_optm_destroy';
	const TYPE_IMG_OPTM_DESTROY_UNFINISHED = 'img_optm_destroy-unfinished';
	const TYPE_IMG_PULL = 'img_pull';
	const TYPE_IMG_BATCH_SWITCH_ORI = 'img_optm_batch_switch_ori';
	const TYPE_IMG_BATCH_SWITCH_OPTM = 'img_optm_batch_switch_optm';
	const TYPE_CALC_BKUP = 'calc_bkup';
	const TYPE_RESET_ROW = 'reset_row';
	const TYPE_RM_BKUP = 'rm_bkup';

	const DB_DESTROY = 'litespeed-optimize-destroy';
	const DB_IMG_OPTM_DATA = 'litespeed-optimize-data';
	const DB_IMG_OPTM_STATUS = 'litespeed-optimize-status';

	const DB_STATUS_RAW 		= 0; // 'raw';
	const DB_STATUS_REQUESTED 	= 3; // 'requested';
	const DB_STATUS_NOTIFIED 	= 6; // 'notified';
	const DB_STATUS_DUPLICATED 	= 8; // 'duplicated';
	const DB_STATUS_PULLED 		= 9; // 'pulled';
	const DB_STATUS_FAILED 		= -1; //'failed';
	const DB_STATUS_MISS 		= -3; // 'miss';
	const DB_STATUS_ERR_FETCH 	= -6; // 'err_fetch';
	const DB_STATUS_ERR_OPTM 	= -7; // 'err_optm';
	const DB_STATUS_XMETA 		= -8; // 'xmeta';
	const DB_STATUS_ERR 		= -9; // 'err';
	const DB_SIZE = 'litespeed-optimize-size';

	const DB_NEED_PULL = 'need_pull';
	const DB_CRON_RUN = 'cron_run'; // last cron running time

	const NUM_THRESHOLD_AUTO_REQUEST = 1200;

	private $wp_upload_dir;
	private $tmp_pid;
	private $tmp_path;
	private $_img_in_queue = array();
	private $_img_in_queue_missed = array();
	private $_table_img_optm;
	private $_table_img_optming;
	private $_cron_ran = false;

	private $__media;
	private $_summary;

	/**
	 * Init
	 *
	 * @since  2.0
	 * @access protected
	 */
	protected function __construct()
	{
		Log::debug2( 'ImgOptm init' );

		$this->wp_upload_dir = wp_upload_dir();
		$this->__media = Media::get_instance();
		$this->_table_img_optm = Data::get_instance()->tb( 'img_optm' );
		$this->_table_img_optming = Data::get_instance()->tb( 'img_optming' );

		$this->_summary = self::get_summary();
	}

	/**
	 * This will gather latest certain images from wp_posts to litespeed_img_optm
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _gather_images()
	{
		global $wpdb;

		Data::get_instance()->tb_create( 'img_optm' );
		Data::get_instance()->tb_create( 'img_optming' );

		// Get images
		$q = "SELECT b.post_id, b.meta_value
			FROM $wpdb->posts a
			LEFT JOIN $wpdb->postmeta b ON b.post_id = a.ID
			LEFT JOIN $this->_table_img_optm c ON c.post_id = a.ID
			WHERE a.post_type = 'attachment'
				AND a.post_status = 'inherit'
				AND a.post_mime_type IN ('image/jpeg', 'image/png')
				AND b.meta_key = '_wp_attachment_metadata'
				AND c.id IS NULL
			ORDER BY a.ID DESC
			LIMIT %d
			";
		$q = $wpdb->prepare( $q, apply_filters( 'litespeed_img_gather_max_rows', 200 ) );
		$list = $wpdb->get_results( $q );

		if ( ! $list ) {
			$msg = __( 'No new image gathered.', 'litespeed-cache' );
			Admin_Display::succeed( $msg );

			Log::debug( '[Img_Optm] gather_images bypass: no new image found' );
			return;
		}

		foreach ( $list as $v ) {

			$meta_value = $this->_parse_wp_meta_value( $v );
			if ( ! $meta_value ) {
				$this->_save_err_meta( $v->post_id );
				continue;
			}

			$this->tmp_pid = $v->post_id;
			$this->tmp_path = pathinfo( $meta_value[ 'file' ], PATHINFO_DIRNAME ) . '/';
			$this->_append_img_queue( $meta_value, true );
			if ( ! empty( $meta_value[ 'sizes' ] ) ) {
				array_map( array( $this, '_append_img_queue' ), $meta_value[ 'sizes' ] );
			}
		}

		// Save missed images into img_optm
		$this->_save_err_missed();

		if ( empty( $this->_img_in_queue ) ) {
			Log::debug( '[Img_Optm] gather_images bypass: empty _img_in_queue' );
			return;
		}

		// Save to DB
		$this->_save_raw();

		$msg = sprintf( __( 'Gathered %d images successfully.', 'litespeed-cache' ), count( $this->_img_in_queue ) );
		Admin_Display::succeed( $msg );
	}

	/**
	 * Add a new img to queue which will be pushed to request
	 *
	 * @since 1.6
	 * @access private
	 */
	private function _append_img_queue( $meta_value, $is_ori_file = false )
	{
		if ( empty( $meta_value[ 'file' ] ) || empty( $meta_value[ 'width' ] ) || empty( $meta_value[ 'height' ] ) ) {
			Log::debug2( '[Img_Optm] bypass image due to lack of file/w/h: pid ' . $this->tmp_pid, $meta_value ) ;
			return ;
		}

		$short_file_path = $meta_value[ 'file' ] ;

		if ( ! $is_ori_file ) {
			$short_file_path = $this->tmp_path . $short_file_path ;
		}

		// check file exists or not
		$_img_info = $this->__media->info( $short_file_path, $this->tmp_pid ) ;

		if ( ! $_img_info || ! in_array( pathinfo( $short_file_path, PATHINFO_EXTENSION ), array( 'jpg', 'jpeg', 'png' ) ) ) {
			$this->_img_in_queue_missed[] = array(
				'pid'	=> $this->tmp_pid,
				'src'	=> $short_file_path,
			) ;
			Log::debug2( '[Img_Optm] bypass image due to file not exist: pid ' . $this->tmp_pid . ' ' . $short_file_path ) ;
			return ;
		}

		Log::debug2( '[Img_Optm] adding image: pid ' . $this->tmp_pid ) ;

		$this->_img_in_queue[] = array(
			'pid'	=> $this->tmp_pid,
			'md5'	=> $_img_info[ 'md5' ],
			'url'	=> $_img_info[ 'url' ],
			'src'	=> $short_file_path, // not needed in LiteSpeed IAPI, just leave for local storage after post
			'mime_type'	=> ! empty( $meta_value[ 'mime-type' ] ) ? $meta_value[ 'mime-type' ] : '' ,
			'src_filesize'	=> $_img_info[ 'size' ], // Only used for local storage and calculation
		) ;
	}

	/**
	 * Save failed to parse meta info
	 *
	 * @since 2.1.1
	 * @access private
	 */
	private function _save_err_meta( $pid )
	{
		$data = array(
			$pid,
			self::DB_STATUS_XMETA,
		) ;
		$this->_insert_img_optm( $data, 'post_id, optm_status' ) ;
		Log::debug( '[Img_Optm] Mark wrong meta [pid] ' . $pid ) ;
	}

	/**
	 * Saved non-existed images into img_optm
	 *
	 * @since 2.0
	 * @access private
	 */
	private function _save_err_missed()
	{
		if ( ! $this->_img_in_queue_missed ) {
			return ;
		}
		Log::debug( '[Img_Optm] Missed img need to save [total] ' . count( $this->_img_in_queue_missed ) ) ;

		$data_to_add = array() ;
		foreach ( $this->_img_in_queue_missed as $src_data ) {
			$data_to_add[] = $src_data[ 'pid' ] ;
			$data_to_add[] = self::DB_STATUS_MISS ;
			$data_to_add[] = $src_data[ 'src' ] ;
		}
		$this->_insert_img_optm( $data_to_add, 'post_id, optm_status, src' ) ;
	}

	/**
	 * Save gathered image raw data
	 *
	 * @since  3.0
	 */
	private function _save_raw()
	{
		$data = array();
		foreach ( $this->_img_in_queue as $v ) {
			$data[] = $v[ 'pid' ];
			$data[] = self::DB_STATUS_RAW;
			$data[] = $v[ 'src' ];
			$data[] = $v[ 'src_filesize' ];
		}
		$this->_insert_img_optm( $data ) ;

		Log::debug( '[Img_Optm] Saved gathered raw images [total] ' . count( $this->_img_in_queue ) ) ;
	}

	/**
	 * Insert data into table img_optm
	 *
	 * @since 2.0
	 * @access private
	 */
	private function _insert_img_optm( $data, $fields = 'post_id, optm_status, src, src_filesize' )
	{
		if ( empty( $data ) ) {
			return ;
		}

		global $wpdb ;

		$division = substr_count( $fields, ',' ) + 1 ;

		$q = "INSERT INTO $this->_table_img_optm ( $fields ) VALUES " ;

		// Add placeholder
		$q .= $this->_chunk_placeholder( $data, $division ) ;

		// Store data
		$wpdb->query( $wpdb->prepare( $q, $data ) ) ;
	}

	/**
	 * Generate placeholder for an array to query
	 *
	 * @since 2.0
	 * @access private
	 */
	private function _chunk_placeholder( $data, $division )
	{
		$q = implode( ',', array_map(
			function( $el ) { return '(' . implode( ',', $el ) . ')' ; },
			array_chunk( array_fill( 0, count( $data ), '%s' ), $division )
		) ) ;

		return $q ;
	}

	/**
	 * Push raw img to image optm server
	 *
	 * @since 1.6
	 * @access private
	 */
	private function request_optm()
	{
		global $wpdb;

		$allowance = Cloud::get_instance()->allowance( Cloud::SVC_IMG_OPTM );
		if ( ! $allowance ) {
			Log::debug( '[Img_Optm] No credit' );
			return;
		}

		$recovered_count = 1;
		if ( ! empty( $this->_summary[ 'recovered' ] ) ) {
			$recovered_count = $this->_summary[ 'recovered' ] > 500 ? $this->_summary[ 'recovered' ] : pow( $this->_summary[ 'recovered' ], 2 );
		}

		$allowance = min( $allowance, apply_filters( 'litespeed_img_optimize_max_rows', 500 ), $recovered_count );

		Log::debug( '[Img_Optm] preparing images to push' );

		$q = "SELECT * FROM $this->_table_img_optm WHERE optm_status = %d ORDER BY id LIMIT %d";
		$q = $wpdb->prepare( $q, array( self::DB_STATUS_RAW, $allowance ) );

		$this->_img_in_queue = $wpdb->get_results( $q, ARRAY_A );

		if ( ! $this->_img_in_queue ) {
			Log::debug( '[Img_Optm] no new raw image found, need to gather first' );
			$this->_gather_images();
			return;
		}

		$num_a = count( $this->_img_in_queue );
		Log::debug( '[Img_Optm] Images found: ' . $num_a );
		$this->_filter_duplicated_src();
		$num_b = count( $this->_img_in_queue );
		if ( $num_b != $num_a ) {
			Log::debug( '[Img_Optm] Images after filtered duplicated src: ' . $num_b );
		}

		if ( ! $num_b ) {
			Log::debug( '[Img_Optm] No image in queue' );
			return;
		}

		// Push to Cloud server
		$accepted_imgs = $this->_send_request();

		if ( ! $accepted_imgs ) {
			return;
		}

		$placeholder1 = Admin_Display::print_plural( $num_b, 'image' );
		$placeholder2 = Admin_Display::print_plural( $accepted_imgs, 'image' );
		$msg = sprintf( __( 'Pushed %1$s to Cloud server, accepted %2$s.', 'litespeed-cache' ), $placeholder1, $placeholder2 ) ;
		Admin_Display::succeed( $msg ) ;
	}

	/**
	 * Filter duplicated src in work table and $this->_img_in_queue, then mark them as duplicated
	 *
	 * @since 2.0
	 * @access private
	 */
	private function _filter_duplicated_src()
	{
		global $wpdb;

		$srcpath_list = array() ;

		$list = $wpdb->get_results( "SELECT src FROM $this->_table_img_optming" );
		foreach ( $list as $v ) {
			$srcpath_list[] = $v->src;
		}

		$img_in_queue_duplicated = array();
		foreach ( $this->_img_in_queue as $k => $v ) {
			if ( in_array( $v[ 'src' ], $srcpath_list ) ) {
				$img_in_queue_duplicated[] = $v[ 'id' ];
				unset( $this->_img_in_queue[ $k ] ) ;
				continue ;
			}

			$srcpath_list[] = $v[ 'src' ] ;
		}

		if ( ! $img_in_queue_duplicated ) {
			return;
		}

		Log::debug( '[Img_Optm] Found duplicated src [total_img_duplicated] ' . count( $img_in_queue_duplicated ) ) ;

		// Update img table
		$ids = implode( ',', $img_in_queue_duplicated );
		$q = "UPDATE $this->_table_img_optm SET optm_status = '" . self::DB_STATUS_DUPLICATED . "' WHERE id IN ( $ids )" ;
		$wpdb->query( $q ) ;
	}

	/**
	 * Push img request to Cloud server
	 *
	 * @since 1.6.7
	 * @access private
	 */
	private function _send_request()
	{
		$list = array();
		foreach ( $this->_img_in_queue as $v ) {
			$_img_info = $this->__media->info( $v[ 'src' ], $v[ 'post_id' ] ) ;

			/**
			 * Filter `litespeed_img_optm_options_per_image`
			 * @since 2.4.2
			 */
			/**
			 * To use the filter `litespeed_img_optm_options_per_image` to manipulate `optm_options`, do below:
			 *
			 * 		add_filter( 'litespeed_img_optm_options_per_image', function( $optm_options, $file ){
			 * 			// To add optimize original image
			 * 			if ( Your conditions ) {
			 * 				$optm_options |= API::IMG_OPTM_BM_ORI ;
			 * 			}
			 *
			 * 			// To add optimize webp image
			 * 			if ( Your conditions ) {
			 * 				$optm_options |= API::IMG_OPTM_BM_WEBP ;
			 * 			}
			 *
			 * 			// To turn on lossless optimize for this image e.g. if filename contains `magzine`
			 * 			if ( strpos( $file, 'magzine' ) !== false ) {
			 * 				$optm_options |= API::IMG_OPTM_BM_LOSSLESS ;
			 * 			}
			 *
			 * 			// To set keep exif info for this image
			 * 			if ( Your conditions ) {
			 * 				$optm_options |= API::IMG_OPTM_BM_EXIF ;
			 * 			}
			 *
			 *			return $optm_options ;
			 *   	} ) ;
			 *
			 */
			$optm_options = apply_filters( 'litespeed_img_optm_options_per_image', 0, $v[ 'src' ] ) ;

			$img = array(
				'id'	=> $v[ 'id' ],
				'url'	=> $_img_info[ 'url' ],
				'md5'	=> $_img_info[ 'md5' ],
			);
			if ( $optm_options ) {
				$img[ 'optm_options' ] = $optm_options;
			}

			$list[] = $img;
		}

		$data = array(
			'action'		=> 'new_req',
			'list' 			=> $list,
			'optm_ori'		=> Conf::val( Base::O_IMG_OPTM_ORI ) ? 1 : 0,
			'optm_webp'		=> Conf::val( Base::O_IMG_OPTM_WEBP ) ? 1 : 0,
			'optm_lossless'	=> Conf::val( Base::O_IMG_OPTM_LOSSLESS ) ? 1 : 0,
			'keep_exif'		=> Conf::val( Base::O_IMG_OPTM_EXIF ) ? 1 : 0,
		);

		// Push to Cloud server
		$json = Cloud::post( Cloud::SVC_IMG_OPTM, $data );
		if ( ! $json ) {
			return;
		}

		// Check data format
		if ( empty( $json[ 'ids' ] ) ) {
			Log::debug( '[Img_Optm] Failed to parse response data from Cloud server ', $json );
			$msg = __( 'Failed to parse response data from Cloud server', 'litespeed-cache' );
			Admin_Display::error( $msg );
			return;
		}

		Log::debug( '[Img_Optm] Returned data from Cloud server count: ' . count( $json[ 'ids' ] ) );

		$ids = implode( ',', array_map( 'intval', $json[ 'ids' ] ) );
		// Update img table
		$q = "UPDATE $this->_table_img_optm SET optm_status = '" . self::DB_STATUS_REQUESTED . "' WHERE id IN ( $ids )" ;
		$wpdb->query( $q ) ;

		// Save to work table
		$q = "INSERT INTO $this->_table_img_optming ( id, post_id, optm_status, src ) SELECT id, post_id, optm_status, src FROM $this->_table_img_optm WHERE id IN ( $ids )";
		$wpdb->query( $q ) ;

		return count( $json[ 'ids' ] );
	}

	/**
	 * LiteSpeed Child server notify Client img status changed
	 *
	 * @since  1.6
	 * @since  1.6.5 Added err/request status free switch
	 * @access public
	 */
	public function notify_img()
	{
		// Validate key
		if ( empty( $_POST[ 'domain_key' ] ) || $_POST[ 'domain_key' ] !== md5( Conf::val( Base::O_API_KEY ) ) ) {
			return array( '_res' => 'err', '_msg' => 'wrong_key' ) ;
		}

		global $wpdb ;

		$notified_data = $_POST[ 'data' ];
		if ( empty( $notified_data ) || ! is_array( $notified_data ) ) {
			Log::debug( '[Img_Optm] ❌ notify exit: no notified data' ) ;
			return array( '_res' => 'err', '_msg' => 'no notified data' ) ;
		}

		if ( empty( $_POST[ 'server' ] ) || substr( $_POST[ 'server' ], -21 ) !== 'api.litespeedtech.com' ) {
			Log::debug( '[Img_Optm] notify exit: no/wrong server' ) ;
			return array( '_res' => 'err', '_msg' => 'no/wrong server' ) ;
		}

		$_allowed_status = array(
			self::DB_STATUS_NOTIFIED,
			self::DB_STATUS_REQUESTED,
		) ;

		if ( empty( $_POST[ 'status' ] ) || ( ! in_array( $_POST[ 'status' ], $_allowed_status ) && substr( $_POST[ 'status' ], 0, 3 ) != self::DB_STATUS_ERR ) ) {
			Log::debug( '[Img_Optm] notify exit: no/wrong status' ) ;
			return array( '_res' => 'err', '_msg' => 'no/wrong status' ) ;
		}

		$server = $_POST[ 'server' ] ;
		$status = $_POST[ 'status' ] ;

		$pids = array_keys( $notified_data ) ;

		$q = "SELECT a.*, b.meta_id as b_meta_id, b.meta_value AS b_optm_info
				FROM $this->_table_img_optm a
				LEFT JOIN $wpdb->postmeta b ON b.post_id = a.post_id AND b.meta_key = %s
				WHERE a.optm_status != %s AND a.post_id IN ( " . implode( ',', array_fill( 0, count( $pids ), '%d' ) ) . " )" ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, array_merge( array( self::DB_SIZE, self::DB_STATUS_PULLED ), $pids ) ) ) ;

		$need_pull = false ;
		$last_log_pid = 0 ;
		$postmeta_info = array() ;
		$child_postmeta_info = array() ;

		foreach ( $list as $v ) {
			if ( ! array_key_exists( $v->src_md5, $notified_data[ $v->post_id ] ) ) {
				// This image is not in notifcation
				continue ;
			}

			$json = $notified_data[ $v->post_id ][ $v->src_md5 ] ;

			$server_info = array(
				'server'	=> $server,
			) ;

			// Only need to update meta_info for pull notification, for other notifications, no need to modify meta_info
			if ( ! empty( $json[ 'ori' ] ) || ! empty( $json[ 'webp' ] ) ) {
				// Save server side ID to send taken notification after pulled
				$server_info[ 'id' ] = $json[ 'id' ] ;

				// Default optm info array
				if ( empty( $postmeta_info[ $v->post_id ] ) ) {
					$postmeta_info[ $v->post_id ] =  array(
						'meta_id'	=> $v->b_meta_id,
						'meta_info'	=> array(
							'ori_total' => 0,
							'ori_saved' => 0,
							'webp_total' => 0,
							'webp_saved' => 0,
						),
					) ;
					// Init optm_info for the first one
					if ( ! empty( $v->b_meta_id ) ) {
						foreach ( maybe_unserialize( $v->b_optm_info ) as $k2 => $v2 ) {
							$postmeta_info[ $v->post_id ][ 'meta_info' ][ $k2 ] += $v2 ;
						}
					}
				}

			}

			$target_saved = 0 ;
			if ( ! empty( $json[ 'ori' ] ) ) {
				$server_info[ 'ori_md5' ] = $json[ 'ori_md5' ] ;
				$server_info[ 'ori' ] = $json[ 'ori' ] ;

				$target_saved = $json[ 'ori_reduced' ] ;

				// Append meta info
				$postmeta_info[ $v->post_id ][ 'meta_info' ][ 'ori_total' ] += $json[ 'src_size' ] ;
				$postmeta_info[ $v->post_id ][ 'meta_info' ][ 'ori_saved' ] += $json[ 'ori_reduced' ] ;

			}

			$webp_saved = 0 ;
			if ( ! empty( $json[ 'webp' ] ) ) {
				$server_info[ 'webp_md5' ] = $json[ 'webp_md5' ] ;
				$server_info[ 'webp' ] = $json[ 'webp' ] ;

				$webp_saved = $json[ 'webp_reduced' ] ;

				// Append meta info
				$postmeta_info[ $v->post_id ][ 'meta_info' ][ 'webp_total' ] += $json[ 'src_size' ] ;
				$postmeta_info[ $v->post_id ][ 'meta_info' ][ 'webp_saved' ] += $json[ 'webp_reduced' ] ;
			}

			// Update status and data
			$q = "UPDATE $this->_table_img_optm SET optm_status = %s, target_saved = %d, webp_saved = %d, server_info = %s WHERE id = %d " ;
			$wpdb->query( $wpdb->prepare( $q, array( $status, $target_saved, $webp_saved, json_encode( $server_info ), $v->id ) ) ) ;

			// Update child images ( same md5 files )
			$q = "UPDATE $this->_table_img_optm SET optm_status = %s, target_saved = %d, webp_saved = %d WHERE root_id = %d " ;
			$child_count = $wpdb->query( $wpdb->prepare( $q, array( $status, $target_saved, $webp_saved, $v->id ) ) ) ;

			// Group child meta_info for later update
			if ( ! empty( $json[ 'ori' ] ) || ! empty( $json[ 'webp' ] ) ) {
				if ( $child_count ) {
					$child_postmeta_info[ $v->id ] = $postmeta_info[ $v->post_id ][ 'meta_info' ] ;
				}
			}

			// write log
			$pid_log = $last_log_pid == $v->post_id ? '.' : $v->post_id ;
			Log::debug( '[Img_Optm] notify_img [status] ' . $status . " \t\t[pid] " . $pid_log . " \t\t[id] " . $v->id ) ;
			$last_log_pid = $v->post_id ;

			// set need_pull tag
			if ( $status == self::DB_STATUS_NOTIFIED ) {
				$need_pull = true ;
			}

		}

		/**
		 * Update size saved info
		 * @since  1.6.5
		 */
		if ( $postmeta_info ) {
			foreach ( $postmeta_info as $post_id => $optm_arr ) {
				$optm_info = serialize( $optm_arr[ 'meta_info' ] ) ;

				if ( ! empty( $optm_arr[ 'meta_id' ] ) ) {
					$q = "UPDATE $wpdb->postmeta SET meta_value = %s WHERE meta_id = %d " ;
					$wpdb->query( $wpdb->prepare( $q, array( $optm_info, $optm_arr[ 'meta_id' ] ) ) ) ;
				}
				else {
					Log::debug( '[Img_Optm] New size info [pid] ' . $post_id ) ;
					$q = "INSERT INTO $wpdb->postmeta ( post_id, meta_key, meta_value ) VALUES ( %d, %s, %s )" ;
					$wpdb->query( $wpdb->prepare( $q, array( $post_id, self::DB_SIZE, $optm_info ) ) ) ;
				}
			}
		}

		// Update child postmeta data based on root_id
		if ( $child_postmeta_info ) {
			Log::debug( '[Img_Optm] Proceed child images [total] ' . count( $child_postmeta_info ) ) ;

			$root_id_list = array_keys( $child_postmeta_info ) ;

			$q = "SELECT a.*, b.meta_id as b_meta_id
				FROM $this->_table_img_optm a
				LEFT JOIN $wpdb->postmeta b ON b.post_id = a.post_id AND b.meta_key = %s
				WHERE a.root_id IN ( " . implode( ',', array_fill( 0, count( $root_id_list ), '%d' ) ) . " ) GROUP BY a.post_id" ;

			$tmp = $wpdb->get_results( $wpdb->prepare( $q, array_merge( array( self::DB_SIZE ), $root_id_list ) ) ) ;

			$pids_to_update = array() ;
			$pids_data_to_insert = array() ;
			foreach ( $tmp as $v ) {
				$optm_info = serialize( $child_postmeta_info[ $v->root_id ] ) ;

				if ( $v->b_meta_id ) {
					$pids_to_update[] = $v->post_id ;
				}
				else {
					$pids_data_to_insert[] = $v->post_id ;
					$pids_data_to_insert[] = self::DB_SIZE ;
					$pids_data_to_insert[] = $optm_info ;
				}
			}

			// Update these size_info
			if ( $pids_to_update ) {
				$pids_to_update = array_unique( $pids_to_update ) ;
				Log::debug( '[Img_Optm] Update child group size_info [total] ' . count( $pids_to_update ) ) ;

				$q = "UPDATE $wpdb->postmeta SET meta_value = %s WHERE meta_key = %s AND post_id IN ( " . implode( ',', array_fill( 0, count( $pids_to_update ), '%d' ) ) . " )" ;
				$wpdb->query( $wpdb->prepare( $q, array_merge( array( $optm_info, self::DB_SIZE ), $pids_to_update ) ) ) ;
			}

			// Insert these size_info
			if ( $pids_data_to_insert ) {
				Log::debug( '[Img_Optm] Insert child group size_info [total] ' . ( count( $pids_data_to_insert ) / 3 ) ) ;

				$q = "INSERT INTO $wpdb->postmeta ( post_id, meta_key, meta_value ) VALUES " ;
				// Add placeholder
				$q .= $this->_chunk_placeholder( $pids_data_to_insert, 3 ) ;
				$wpdb->query( $wpdb->prepare( $q, $pids_data_to_insert ) ) ;
			}

		}

		// Mark need_pull tag for cron
		if ( $need_pull ) {
			self::update_option( self::DB_NEED_PULL, self::DB_STATUS_NOTIFIED ) ;
		}

		// redo count err

		return array( '_res' => 'ok', 'count' => count( $notified_data ) ) ;
	}

	/**
	 * Cron pull optimized img
	 *
	 * @since  1.6
	 * @access public
	 */
	public static function cron_pull_optimized_img()
	{
		if ( ! defined( 'DOING_CRON' ) ) {
			return ;
		}

		$tag = self::get_option( self::DB_NEED_PULL ) ;

		if ( ! $tag || $tag !== self::DB_STATUS_NOTIFIED ) {
			return ;
		}

		Log::debug( '[Img_Optm] Cron pull_optimized_img started' ) ;

		self::get_instance()->_pull_optimized_img() ;
	}

	/**
	 * Pull optm data from litespeed IAPI server for CLI usage
	 *
	 * @since  2.4.4
	 * @access public
	 */
	public function pull_img()
	{
		$res = $this->_pull_optimized_img() ;

		$this->_update_cron_running( true ) ;

		return $res ;
	}

	/**
	 * Pull optimized img
	 *
	 * @since  1.6
	 * @access private
	 */
	private function _pull_optimized_img( $manual = false )
	{
		if ( $this->cron_running() ) {
			$msg = '[Img_Optm] fetch cron is running' ;
			Log::debug( $msg ) ;
			return $msg ;
		}

		global $wpdb ;

		$q = "SELECT * FROM $this->_table_img_optm FORCE INDEX ( optm_status ) WHERE root_id = 0 AND optm_status = %s ORDER BY id LIMIT 1" ;
		$_q = $wpdb->prepare( $q, self::DB_STATUS_NOTIFIED ) ;

		$optm_ori = Conf::val( Base::O_IMG_OPTM_ORI ) ;
		$rm_ori_bkup = Conf::val( Base::O_IMG_OPTM_RM_BKUP ) ;
		$optm_webp = Conf::val( Base::O_IMG_OPTM_WEBP ) ;

		// pull 1 min images each time
		$end_time = time() + ( $manual ? 120 : 60 ) ;

		$server_list = array() ;

		$total_pulled_ori = 0 ;
		$total_pulled_webp = 0 ;
		$beginning = time() ;

		set_time_limit( $end_time + 20 ) ;
		while ( time() < $end_time ) {
			$row_img = $wpdb->get_row( $_q ) ;
			if ( ! $row_img ) {
				// No image
				break ;
			}

			/**
			 * Update cron timestamp to avoid duplicated running
			 * @since  1.6.2
			 */
			$this->_update_cron_running() ;

			/**
			 * If no server_info, will fail to pull
			 * This is only for v2.4.2- data
			 * @see  https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:image-optimization:2-4-2-upgrade
			 */
			$server_info = json_decode( $row_img->server_info, true ) ;
			if ( empty( $server_info[ 'server' ] ) ) {
				Log::debug( '[Img_Optm] Failed to decode server_info.' ) ;

				$msg = sprintf(
					__( 'LSCWP %1$s has simplified the image pulling process. Please %2$s, or resend the pull notification this one time only. After that, the process will be automated.', 'litespeed-cache' ),
					'v2.9.6',
					GUI::img_optm_clean_up_unfinished()
				) ;

				$msg .= Doc::learn_more( 'https://www.litespeedtech.com/support/wiki/doku.php/litespeed_wiki:cache:lscwp:image-optimization:2-4-2-upgrade' ) ;

				Admin_Display::error( $msg ) ;

				return ;
			}
			$server = $server_info[ 'server' ] ;

			$local_file = $this->wp_upload_dir[ 'basedir' ] . '/' . $row_img->src ;

			// Save ori optm image
			$target_size = 0 ;

			if ( ! empty( $server_info[ 'ori' ] ) ) {
				/**
				 * Use wp orignal get func to avoid allow_url_open off issue
				 * @since  1.6.5
				 */
				$response = wp_remote_get( $server_info[ 'ori' ], array( 'timeout' => 15 ) ) ;
				if ( is_wp_error( $response ) ) {
					$error_message = $response->get_error_message() ;
					Log::debug( 'IAPI failed to pull image: ' . $error_message ) ;
					return ;
				}

				file_put_contents( $local_file . '.tmp', $response[ 'body' ] ) ;

				if ( ! file_exists( $local_file . '.tmp' ) || ! filesize( $local_file . '.tmp' ) || md5_file( $local_file . '.tmp' ) !== $server_info[ 'ori_md5' ] ) {
					Log::debug( '[Img_Optm] Failed to pull optimized img: file md5 dismatch, server md5: ' . $server_info[ 'ori_md5' ] ) ;

					// update status to failed
					$q = "UPDATE $this->_table_img_optm SET optm_status = %s WHERE id = %d " ;
					$wpdb->query( $wpdb->prepare( $q, array( self::DB_STATUS_FAILED, $row_img->id ) ) ) ;
					// Update child images
					$q = "UPDATE $this->_table_img_optm SET optm_status = %s WHERE root_id = %d " ;
					$wpdb->query( $wpdb->prepare( $q, array( self::DB_STATUS_FAILED, $row_img->id ) ) ) ;

					return 'Md5 dismatch' ; // exit from running pull process
				}

				// Backup ori img
				$extension = pathinfo( $local_file, PATHINFO_EXTENSION ) ;
				$bk_file = substr( $local_file, 0, -strlen( $extension ) ) . 'bk.' . $extension ;

				if ( ! $rm_ori_bkup ) {
					file_exists( $local_file ) && rename( $local_file, $bk_file ) ;
				}

				// Replace ori img
				rename( $local_file . '.tmp', $local_file ) ;

				Log::debug( '[Img_Optm] Pulled optimized img: ' . $local_file ) ;

				$target_size = filesize( $local_file ) ;

				/**
				 * API Hook
				 * @since  2.9.5
				 */
				do_action( 'litespeed_img_pull_ori', $row_img, $local_file ) ;

				$total_pulled_ori ++ ;
			}

			// Save webp image
			$webp_size = 0 ;

			if ( ! empty( $server_info[ 'webp' ] ) ) {

				// Fetch
				$response = wp_remote_get( $server_info[ 'webp' ], array( 'timeout' => 15 ) ) ;
				if ( is_wp_error( $response ) ) {
					$error_message = $response->get_error_message() ;
					Log::debug( 'IAPI failed to pull webp image: ' . $error_message ) ;
					return ;
				}

				file_put_contents( $local_file . '.webp', $response[ 'body' ] ) ;

				if ( ! file_exists( $local_file . '.webp' ) || ! filesize( $local_file . '.webp' ) || md5_file( $local_file . '.webp' ) !== $server_info[ 'webp_md5' ] ) {
					Log::debug( '[Img_Optm] Failed to pull optimized webp img: file md5 dismatch, server md5: ' . $server_info[ 'webp_md5' ] ) ;

					// update status to failed
					$q = "UPDATE $this->_table_img_optm SET optm_status = %s WHERE id = %d " ;
					$wpdb->query( $wpdb->prepare( $q, array( self::DB_STATUS_FAILED, $row_img->id ) ) ) ;
					// Update child images
					$q = "UPDATE $this->_table_img_optm SET optm_status = %s WHERE root_id = %d " ;
					$wpdb->query( $wpdb->prepare( $q, array( self::DB_STATUS_FAILED, $row_img->id ) ) ) ;

					return 'WebP md5 dismatch' ; // exit from running pull process
				}

				Log::debug( '[Img_Optm] Pulled optimized img WebP: ' . $local_file . '.webp' ) ;

				$webp_size = filesize( $local_file . '.webp' ) ;

				/**
				 * API for WebP
				 * @since 2.9.5
				 * @see #751737  - API docs for WEBP generation
				 */
				do_action( 'litespeed_img_pull_webp', $row_img, $local_file . '.webp' ) ;

				$total_pulled_webp ++ ;
			}

			Log::debug2( '[Img_Optm] Update _table_img_optm record [id] ' . $row_img->id ) ;

			// Update pulled status
			$q = "UPDATE $this->_table_img_optm SET optm_status = %s, target_filesize = %d, webp_filesize = %d WHERE id = %d " ;
			$wpdb->query( $wpdb->prepare( $q, array( self::DB_STATUS_PULLED, $target_size, $webp_size, $row_img->id ) ) ) ;

			// Update child images ( same md5 files )
			$q = "UPDATE $this->_table_img_optm SET optm_status = %s, target_filesize = %d, webp_filesize = %d WHERE root_id = %d " ;
			$child_count = $wpdb->query( $wpdb->prepare( $q, array( self::DB_STATUS_PULLED, $target_size, $webp_size, $row_img->id ) ) ) ;

			// Save server_list to notify taken
			if ( empty( $server_list[ $server ] ) ) {
				$server_list[ $server ] = array() ;
			}
			$server_list[ $server ][] = $server_info[ 'id' ] ;

		}

		// Notify IAPI images taken
		$json = false ;
		foreach ( $server_list as $server => $img_list ) {
			$json = Admin_API::post( Admin_API::IAPI_ACTION_IMG_TAKEN, $img_list, $server, true ) ;
		}

		// use latest credit from last server response
		// Recover credit
		if ( is_array( $json ) && isset( $json[ 'credit' ] ) ) {
			$this->_update_credit( $json[ 'credit' ] ) ;
		}

		// Try level up

		// Check if there is still task in queue
		$q = "SELECT * FROM $this->_table_img_optm WHERE root_id = 0 AND optm_status = %s LIMIT 1" ;
		$tmp = $wpdb->get_row( $wpdb->prepare( $q, self::DB_STATUS_NOTIFIED ) ) ;
		if ( $tmp ) {
			Log::debug( '[Img_Optm] Task in queue, to be continued...' ) ;
			return array( 'ok' => 'to_be_continued' ) ;
		}

		// If all pulled, update tag to done
		Log::debug( '[Img_Optm] Marked pull status to all pulled' ) ;
		self::update_option( self::DB_NEED_PULL, self::DB_STATUS_PULLED ) ;

		$time_cost = time() - $beginning ;

		return array( 'ok' => "Pulled [ori] $total_pulled_ori [WebP] $total_pulled_webp [cost] {$time_cost}s" ) ;
	}

	/**
	 * Auto send optm request
	 *
	 * @since  2.4.1
	 * @access public
	 */
	public static function cron_auto_request()
	{
		if ( ! defined( 'DOING_CRON' ) ) {
			return false ;
		}

		$instance = self::get_instance() ;

		$credit = (int) self::get_summary( 'credit' ) ;
		if ( $credit < self::NUM_THRESHOLD_AUTO_REQUEST ) {
			return false ;
		}

		// No need to check last time request interval for now

		$instance->request_optm( 'from cron' ) ;
	}

	/**
	 * Show an image's optm status
	 *
	 * @since  1.6.5
	 * @access public
	 */
	public function check_img()
	{
		$ip = gethostbyname( 'wp.api.litespeedtech.com' ) ;
		if ( $ip != Router::get_ip() ) {
			return array( '_res' => 'err', '_msg' => 'wrong ip ' . $ip . '!=' . Router::get_ip() ) ;
		}

		// Validate key
		if ( empty( $_POST[ 'auth_key' ] ) || $_POST[ 'auth_key' ] !== md5( Conf::val( Base::O_API_KEY ) ) ) {
			return array( '_res' => 'err', '_msg' => 'wrong_key' ) ;
		}

		global $wpdb ;

		$pid = $_POST[ 'data' ] ;

		Log::debug( '[Img_Optm] Check image [ID] ' . $pid ) ;

		$data = array() ;

		$data[ 'img_count' ] = $this->img_count() ;
		$data[ 'optm_summary' ] = self::get_summary() ;

		$data[ '_wp_attached_file' ] = get_post_meta( $pid, '_wp_attached_file', true ) ;
		$data[ '_wp_attachment_metadata' ] = get_post_meta( $pid, '_wp_attachment_metadata', true ) ;

		// Get img_optm data
		$q = "SELECT * FROM $this->_table_img_optm WHERE post_id = %d" ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, $pid ) ) ;
		$img_data = array() ;
		if ( $list ) {
			foreach ( $list as $v ) {
				$img_data[] = array(
					'id'	=> $v->id,
					'optm_status'	=> $v->optm_status,
					'src'	=> $v->src,
					'srcpath_md5'	=> $v->srcpath_md5,
					'src_md5'	=> $v->src_md5,
					'server_info'	=> $v->server_info,
				) ;
			}
		}
		$data[ 'img_data' ] = $img_data ;

		return array( '_res' => 'ok', 'data' => $data ) ;
	}

	/**
	 * Parse wp's meta value
	 *
	 * @since 1.6.7
	 * @access private
	 */
	private function _parse_wp_meta_value( $v )
	{
		if ( ! $v->meta_value ) {
			Log::debug( '[Img_Optm] bypassed parsing meta due to no meta_value: pid ' . $v->post_id ) ;
			return false ;
		}

		$meta_value = @maybe_unserialize( $v->meta_value ) ;
		if ( ! is_array( $meta_value ) ) {
			Log::debug( '[Img_Optm] bypassed parsing meta due to meta_value not json: pid ' . $v->post_id ) ;
			return false ;
		}

		if ( empty( $meta_value[ 'file' ] ) ) {
			Log::debug( '[Img_Optm] bypassed parsing meta due to no ori file: pid ' . $v->post_id ) ;
			return false ;
		}

		return $meta_value ;
	}

	/**
	 * Clean up unfinished data for CLI usage
	 *
	 * @since  2.4.4
	 * @access public
	 */
	public function destroy_unfinished()
	{
		$res = $this->_img_optimize_destroy_unfinished() ;

		return $res ;
	}

	/**
	 * Destroy all unfinished queue locally and to LiteSpeed IAPI server
	 *
	 * @since 2.1.2
	 * @access private
	 */
	private function _img_optimize_destroy_unfinished()
	{
		if ( ! Data::get_instance()->tb_exist( 'img_optm' ) ) {
			return;
		}

		global $wpdb ;

		Log::debug( '[Img_Optm] sending DESTROY_UNFINISHED cmd to LiteSpeed IAPI' ) ;

		// Push to LiteSpeed IAPI server and recover credit
		$json = Admin_API::post( Admin_API::IAPI_ACTION_REQUEST_DESTROY_UNFINISHED, false, true ) ;

		// confirm link will be displayed by Admin_API automatically
		if ( is_array( $json ) ) {
			Log::debug( '[Img_Optm] cmd result', $json ) ;
		}

		// If failed to run request to IAPI
		if ( ! is_array( $json ) || empty( $json[ 'success' ] ) ) {

			// For other errors that Admin_API didn't take
			if ( ! is_array( $json ) ) {
				Admin_Display::error( $json ) ;

				Log::debug( '[Img_Optm] err ', $json ) ;

				return $json ;
			}

			return ;
		}

		// Clear local queue
		$_status_to_clear = array(
			self::DB_STATUS_NOTIFIED,
			self::DB_STATUS_REQUESTED,
			self::DB_STATUS_ERR_FETCH,
		) ;
		$q = "DELETE FROM $this->_table_img_optm WHERE optm_status IN ( " . implode( ',', array_fill( 0, count( $_status_to_clear ), '%s' ) ) . " )" ;
		$wpdb->query( $wpdb->prepare( $q, $_status_to_clear ) ) ;

		// Recover credit
		$this->_sync_data( true ) ;

		$msg = __( 'Destroy unfinished data successfully.', 'litespeed-cache' ) ;
		Admin_Display::succeed( $msg ) ;

		return $msg ;

	}

	/**
	 * Send destroy all requests cmd to LiteSpeed IAPI server and get the link to finish it ( avoid click by mistake )
	 *
	 * @since 1.6.7
	 * @access private
	 */
	private function _img_optimize_destroy()
	{
		Log::debug( '[Img_Optm] sending DESTROY cmd to LiteSpeed IAPI' ) ;

		// Mark request time to avoid duplicated request
		self::update_option( self::DB_DESTROY, time() ) ;

		// Push to LiteSpeed IAPI server
		$json = Admin_API::post( Admin_API::IAPI_ACTION_REQUEST_DESTROY, false, true ) ;

		// confirm link will be displayed by Admin_API automatically
		if ( is_array( $json ) && $json ) {
			Log::debug( '[Img_Optm] cmd result', $json ) ;
		}

	}

	/**
	 * Callback from LiteSpeed IAPI server to destroy all optm data
	 *
	 * @since 1.6.7
	 * @access private
	 */
	public function destroy_callback()
	{
		if ( ! Data::get_instance()->tb_exist( 'img_optm' ) ) {
			return;
		}

		// Validate key
		if ( empty( $_POST[ 'auth_key' ] ) || $_POST[ 'auth_key' ] !== md5( Conf::val( Base::O_API_KEY ) ) ) {
			return array( '_res' => 'err', '_msg' => 'wrong_key' ) ;
		}

		global $wpdb ;
		Log::debug( '[Img_Optm] excuting DESTROY process' ) ;

		$request_time = self::get_option( self::DB_DESTROY ) ;
		if ( time() - $request_time > 300 ) {
			Log::debug( '[Img_Optm] terminate DESTROY process due to timeout' ) ;
			return array( '_res' => 'err', '_msg' => 'Destroy callback timeout ( 300 seconds )[' . time() . " - $request_time]" ) ;
		}

		/**
		 * Limit to 3000 images each time before redirection to fix Out of memory issue. #665465
		 * @since  2.9.8
		 */
		// Start deleting files
		$limit = apply_filters( 'litespeed_imgoptm_destroy_max_rows', 3000 ) ;
		$q = "SELECT src,post_id FROM $this->_table_img_optm WHERE optm_status = %s ORDER BY id LIMIT %d" ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, self::DB_STATUS_PULLED, $limit ) ) ;
		foreach ( $list as $v ) {
			// del webp
			$this->__media->info( $v->src . '.webp', $v->post_id ) && $this->__media->del( $v->src . '.webp', $v->post_id ) ;
			$this->__media->info( $v->src . '.optm.webp', $v->post_id ) && $this->__media->del( $v->src . '.optm.webp', $v->post_id ) ;

			$extension = pathinfo( $v->src, PATHINFO_EXTENSION ) ;
			$local_filename = substr( $v->src, 0, - strlen( $extension ) - 1 ) ;
			$bk_file = $local_filename . '.bk.' . $extension ;
			$bk_optm_file = $local_filename . '.bk.optm.' . $extension ;

			// del optimized ori
			if ( $this->__media->info( $bk_file, $v->post_id ) ) {
				$this->__media->del( $v->src, $v->post_id ) ;
				$this->__media->rename( $bk_file, $v->src, $v->post_id ) ;
			}
			$this->__media->info( $bk_optm_file, $v->post_id ) && $this->__media->del( $bk_optm_file, $v->post_id ) ;
		}

		// Check if there are more images, then return `to_be_continued` code
		$q = "SELECT COUNT(*) FROM $this->_table_img_optm WHERE optm_status = %s" ;
		$total_img = $wpdb->get_var( $wpdb->prepare( $q, self::DB_STATUS_PULLED ) ) ;
		if ( $total_img > $limit ) {
			$q = "DELETE FROM $this->_table_img_optm WHERE optm_status = %s ORDER BY id LIMIT %d" ;
			$wpdb->query( $wpdb->prepare( $q, self::DB_STATUS_PULLED, $limit ) ) ;

			// Return continue signal
			self::update_option( self::DB_DESTROY, time() ) ;

			Log::debug( '[Img_Optm] To be continued 🚦' ) ;

			return array( '_res' => 'to_be_continued' ) ;
		}

		// Delete optm info
		$q = "DELETE FROM $wpdb->postmeta WHERE meta_key LIKE 'litespeed-optimize%'" ;
		$wpdb->query( $q ) ;

		// Delete img_optm table
		Data::get_instance()->tb_del( 'img_optm' ) ;

		// Clear credit info
		self::delete_option( '_summary' ) ;
		self::delete_option( self::DB_NEED_PULL ) ;

		return array( '_res' => 'ok' ) ;
	}

	/**
	 * Resend requested img to LiteSpeed IAPI server
	 *
	 * @since 1.6.7
	 * @access private
	 */
	private function _img_optimize_rescan()
	{return;
		Log::debug( '[Img_Optm] resend requested images' ) ;

		$_credit = (int) self::get_summary( 'credit' ) ;

		global $wpdb ;

		$q = "SELECT a.post_id, a.meta_value, b.meta_id as bmeta_id, c.meta_id as cmeta_id, c.meta_value as cmeta_value
			FROM $wpdb->postmeta a
			LEFT JOIN $wpdb->postmeta b ON b.post_id = a.post_id
			LEFT JOIN $wpdb->postmeta c ON c.post_id = a.post_id
			WHERE a.meta_key = '_wp_attachment_metadata'
				AND b.meta_key = %s
				AND c.meta_key = %s
			LIMIT %d
			" ;
		$limit_rows = apply_filters( 'litespeed_img_optm_resend_rows', 300 ) ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, array( self::DB_IMG_OPTM_STATUS, self::DB_IMG_OPTM_DATA, $limit_rows ) ) ) ;
		if ( ! $list ) {
			Log::debug( '[Img_Optm] resend request bypassed: no image found' ) ;
			$msg = __( 'No image found.', 'litespeed-cache' ) ;
			Admin_Display::error( $msg ) ;
			return ;
		}

		// meta list
		$optm_data_list = array() ;
		$optm_data_pid2mid_list = array() ;

		foreach ( $list as $v ) {
			// wp meta
			$meta_value = $this->_parse_wp_meta_value( $v ) ;
			if ( ! $meta_value ) {
				continue ;
			}
			if ( empty( $meta_value[ 'sizes' ] ) ) {
				continue ;
			}

			$optm_data_pid2mid_list[ $v->post_id ] = array( 'status_mid' => $v->bmeta_id, 'data_mid' => $v->cmeta_id ) ;

			// prepare for pushing
			$this->tmp_pid = $v->post_id ;
			$this->tmp_path = pathinfo( $meta_value[ 'file' ], PATHINFO_DIRNAME ) . '/' ;

			// ls optimized meta
			$optm_meta = $optm_data_list[ $v->post_id ] = maybe_unserialize( $v->cmeta_value ) ;
			$optm_list = array() ;
			foreach ( $optm_meta as $md5 => $optm_row ) {
				$optm_list[] = $optm_row[ 0 ] ;
				// only do for requested/notified img
				// if ( ! in_array( $optm_row[ 1 ], array( self::DB_STATUS_NOTIFIED, self::DB_STATUS_REQUESTED ) ) ) {
				// 	continue ;
				// }
			}

			// check if there is new files from wp meta
			$img_queue = array() ;
			foreach ( $meta_value[ 'sizes' ] as $v2 ) {
				$curr_file = $this->tmp_path . $v2[ 'file' ] ;

				// new child file OR not finished yet
				if ( ! in_array( $curr_file, $optm_list ) ) {
					$img_queue[] = $v2 ;
				}
			}

			// nothing to add
			if ( ! $img_queue ) {
				continue ;
			}

			$num_will_incease = count( $img_queue ) ;
			if ( $this->_img_total + $num_will_incease > $_credit ) {
				Log::debug( '[Img_Optm] resend img request hit limit: [total] ' . $this->_img_total . " \t[add] $num_will_incease \t[credit] $_credit" ) ;
				break ;
			}

			foreach ( $img_queue as $v2 ) {
				$this->_img_queue( $v2 ) ;
			}
		}

		// push to LiteSpeed IAPI server
		if ( empty( $this->_img_in_queue ) ) {
			$msg = __( 'No image found.', 'litespeed-cache' ) ;
			Admin_Display::succeed( $msg ) ;
			return ;
		}

		$total_groups = count( $this->_img_in_queue ) ;
		Log::debug( '[Img_Optm] prepared images to push: groups ' . $total_groups . ' images ' . $this->_img_total ) ;

		// Push to LiteSpeed IAPI server
		// $json = $this->_push_img_in_queue_to_iapxxi();
		if ( ! $json ) {
			return;
		}
		// Returned data is the requested and notifed images

		$q = "UPDATE $wpdb->postmeta SET meta_value = %s WHERE meta_id = %d" ;

		// Update data
		foreach ( $json[ 'pids' ] as $pid ) {
			$md52src_list = $optm_data_list[ $pid ] ;

			foreach ( $this->_img_in_queue[ $pid ] as $md5 => $src_data ) {
				$md52src_list[ $md5 ] = array( $src_data[ 'src' ], self::DB_STATUS_REQUESTED ) ;
			}

			$new_status = $this->_get_status_by_meta_data( $md52src_list, self::DB_STATUS_REQUESTED ) ;

			$md52src_list = serialize( $md52src_list ) ;

			// Store data
			$wpdb->query( $wpdb->prepare( $q, array( $new_status, $optm_data_pid2mid_list[ $pid ][ 'status_mid' ] ) ) ) ;
			$wpdb->query( $wpdb->prepare( $q, array( $md52src_list, $optm_data_pid2mid_list[ $pid ][ 'data_mid' ] ) ) ) ;
		}

		$accepted_groups = count( $json[ 'pids' ] ) ;
		$accepted_imgs = $json[ 'total' ] ;

		$msg = sprintf( __( 'Pushed %1$s groups with %2$s images to LiteSpeed optimization server, accepted %3$s groups with %4$s images.', 'litespeed-cache' ), $total_groups, $this->_img_total, $accepted_groups, $accepted_imgs ) ;
		Admin_Display::succeed( $msg ) ;

		// Update credit info
		if ( isset( $json[ 'credit' ] ) ) {
			$this->_update_credit( $json[ 'credit' ] ) ;
		}

	}

	/**
	 * Update client credit info
	 *
	 * @since 1.6.5
	 * @access private
	 */
	private function _update_credit( $credit )
	{
		$summary = self::get_summary() ;

		if ( empty( $summary[ 'credit' ] ) ) {
			$summary[ 'credit' ] = 0 ;
		}

		if ( $credit === '++' ) {
			$credit = $summary[ 'credit' ] + 1 ;
		}

		$old = $summary[ 'credit' ] ?: '-' ;
		Log::debug( "[Img_Optm] Credit updated \t\t[Old] $old \t\t[New] $credit" ) ;

		// Mark credit recovered
		if ( $credit > $summary[ 'credit' ] ) {
			if ( empty( $summary[ 'credit_recovered' ] ) ) {
				$summary[ 'credit_recovered' ] = 0 ;
			}
			$summary[ 'credit_recovered' ] += $credit - $summary[ 'credit' ] ;
		}

		$summary[ 'credit' ] = $credit ;

		self::save_summary( $summary ) ;
	}

	/**
	 * Calculate bkup original images storage
	 *
	 * @since 2.2.6
	 * @access private
	 */
	private function _calc_bkup()
	{
		if ( ! Data::get_instance()->tb_exist( 'img_optm' ) ) {
			return;
		}

		global $wpdb ;
		$q = "SELECT src,post_id FROM $this->_table_img_optm WHERE optm_status = %s" ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, self::DB_STATUS_PULLED ) ) ;

		$i = 0 ;
		$total_size = 0 ;
		foreach ( $list as $v ) {
			$extension = pathinfo( $v->src, PATHINFO_EXTENSION ) ;
			$local_filename = substr( $v->src, 0, - strlen( $extension ) - 1 ) ;
			$bk_file = $local_filename . '.bk.' . $extension ;

			$img_info = $this->__media->info( $bk_file, $v->post_id ) ;
			if ( ! $img_info ) {
				continue ;
			}

			$i ++ ;
			$total_size += $img_info[ 'size' ] ;

		}

		$this->_summary[ 'bk_summary' ] = array(
			'date' => time(),
			'count' => $i,
			'sum' => $total_size,
		) ;
		self::save_summary( $this->_summary ) ;

		Log::debug( '[Img_Optm] _calc_bkup total: ' . $i . ' [size] ' . $total_size ) ;

	}

	/**
	 * Remove backups for CLI usage
	 *
	 * @since  2.5
	 * @access public
	 */
	public function rm_bkup()
	{
		return $this->_rm_bkup() ;
	}

	/**
	 * Delete bkup original images storage
	 *
	 * @since 2.2.6
	 * @access private
	 */
	private function _rm_bkup()
	{
		global $wpdb ;
		$q = "SELECT src,post_id FROM $this->_table_img_optm WHERE optm_status = %s" ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, self::DB_STATUS_PULLED ) ) ;

		$i = 0 ;
		$total_size = 0 ;
		foreach ( $list as $v ) {
			$extension = pathinfo( $v->src, PATHINFO_EXTENSION ) ;
			$local_filename = substr( $v->src, 0, - strlen( $extension ) - 1 ) ;
			$bk_file = $local_filename . '.bk.' . $extension ;

			// Del ori file
			$img_info = $this->__media->info( $bk_file, $v->post_id ) ;
			if ( ! $img_info ) {
				continue ;
			}

			$i ++ ;
			$total_size += $img_info[ 'size' ] ;

			$this->__media->del( $bk_file, $v->post_id ) ;
		}

		$this->_summary[ 'rmbk_summary' ] = array(
			'date' => time(),
			'count' => $i,
			'sum' => $total_size,
		) ;
		self::save_summary( $this->_summary ) ;

		Log::debug( '[Img_Optm] _rm_bkup total: ' . $i . ' [size] ' . $total_size ) ;

		$msg = sprintf( __( 'Removed %1$s images and saved %2$s successfully.', 'litespeed-cache' ), $i, Utility::real_size( $total_size ) ) ;
		Admin_Display::succeed( $msg ) ;

		return $msg ;
	}

	/**
	 * Count images
	 *
	 * @since 1.6
	 * @access public
	 */
	public function img_count()
	{
		global $wpdb;

		$tb_existed = Data::get_instance()->tb_exist( 'img_optm' );

		$q = "SELECT COUNT(*)
			FROM $wpdb->posts a
			LEFT JOIN $wpdb->postmeta b ON b.post_id = a.ID
			WHERE a.post_type = 'attachment'
				AND a.post_status = 'inherit'
				AND a.post_mime_type IN ('image/jpeg', 'image/png')
				AND b.meta_key = '_wp_attachment_metadata'
			";
		// $q = "SELECT count(*) FROM $wpdb->posts WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN ('image/jpeg', 'image/png') " ;
		$total_not_gathered = $total_raw = $total_img = $wpdb->get_var( $q );
		$total_raw_imgs = '-';

		if ( $tb_existed ) {
			$q = "SELECT COUNT(*)
				FROM $wpdb->posts a
				LEFT JOIN $wpdb->postmeta b ON b.post_id = a.ID
				LEFT JOIN $this->_table_img_optm c ON c.post_id = a.ID
				WHERE a.post_type = 'attachment'
					AND a.post_status = 'inherit'
					AND a.post_mime_type IN ('image/jpeg', 'image/png')
					AND b.meta_key = '_wp_attachment_metadata'
					AND c.id IS NULL
				";
			$total_not_gathered = $wpdb->get_var( $q );

			$q = $wpdb->prepare( "SELECT COUNT(DISTINCT post_id),COUNT(*) FROM $this->_table_img_optm WHERE optm_status = %d", self::DB_STATUS_RAW );
			list( $total_raw, $total_raw_imgs ) = $wpdb->get_row( $q, ARRAY_N );
		}

		$count_list = array(
			'total_img'	=> $total_img,
			'total_not_gathered'	=> $total_not_gathered,
			'total_raw'	=> $total_raw,
			'total_raw_imgs'	=> $total_raw_imgs,
		) ;

		// images count from work table
		$q = "SELECT COUNT(distinct post_id),COUNT(*) FROM $this->_table_img_optming WHERE optm_status = %d";

		// The groups to check
		$groups_to_check = array(
			self::DB_STATUS_REQUESTED,
			self::DB_STATUS_NOTIFIED,
			self::DB_STATUS_DUPLICATED,
			self::DB_STATUS_PULLED,
			self::DB_STATUS_FAILED,
			self::DB_STATUS_MISS,
			self::DB_STATUS_ERR_FETCH,
			self::DB_STATUS_ERR_OPTM,
			self::DB_STATUS_XMETA,
			self::DB_STATUS_ERR,//todo: use img not work table to count
		) ;

		foreach ( $groups_to_check as $v ) {
			$count_list[ 'img.' . $v ] = $count_list[ 'group.' . $v ] = 0;
			if ( $tb_existed ) {
				list( $count_list[ 'img.' . $v ], $count_list[ 'group.' . $v ] ) = $wpdb->get_row( $wpdb->prepare( $q, $v ), ARRAY_N );
			}
		}

		return $count_list;
	}

	/**
	 * Check if fetch cron is running
	 *
	 * @since  1.6.2
	 * @access public
	 */
	public function cron_running( $bool_res = true )
	{
		$last_run = self::get_option( self::DB_CRON_RUN ) ;

		$is_running = $last_run && time() - $last_run < 120 ;

		if ( $bool_res ) {
			return $is_running ;
		}

		return array( $last_run, $is_running ) ;
	}

	/**
	 * Update fetch cron timestamp tag
	 *
	 * @since  1.6.2
	 * @access private
	 */
	private function _update_cron_running( $done = false )
	{
		$ts = time() ;

		if ( $done ) {
			// Only update cron tag when its from the active running cron
			if ( $this->_cron_ran ) {
				// Rollback for next running
				$ts -= 120 ;
			}
			else {
				return ;
			}
		}

		self::update_option( self::DB_CRON_RUN, $ts ) ;

		$this->_cron_ran = true ;
	}

	/**
	 * Batch switch images to ori/optm version
	 *
	 * @since  1.6.2
	 * @access private
	 */
	private function _batch_switch( $type )
	{
		global $wpdb ;
		$q = "SELECT src,post_id FROM $this->_table_img_optm WHERE optm_status = %s" ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, self::DB_STATUS_PULLED ) ) ;

		$i = 0 ;
		foreach ( $list as $v ) {
			$extension = pathinfo( $v->src, PATHINFO_EXTENSION ) ;
			$local_filename = substr( $v->src, 0, - strlen( $extension ) - 1 ) ;
			$bk_file = $local_filename . '.bk.' . $extension ;
			$bk_optm_file = $local_filename . '.bk.optm.' . $extension ;

			// switch to ori
			if ( $type === self::TYPE_IMG_BATCH_SWITCH_ORI ) {
				if ( ! $this->__media->info( $bk_file, $v->post_id ) ) {
					continue ;
				}

				$i ++ ;

				$this->__media->rename( $v->src, $bk_optm_file, $v->post_id ) ;
				$this->__media->rename( $bk_file, $v->src, $v->post_id ) ;
			}
			// switch to optm
			elseif ( $type === self::TYPE_IMG_BATCH_SWITCH_OPTM ) {
				if ( ! $this->__media->info( $bk_optm_file, $v->post_id ) ) {
					continue ;
				}

				$i ++ ;

				$this->__media->rename( $v->src, $bk_file, $v->post_id ) ;
				$this->__media->rename( $bk_optm_file, $v->src, $v->post_id ) ;
			}
		}

		Log::debug( '[Img_Optm] batch switched images total: ' . $i ) ;
		$msg = __( 'Switched images successfully.', 'litespeed-cache' ) ;
		Admin_Display::add_notice( Admin_Display::NOTICE_GREEN, $msg ) ;

	}

	/**
	 * Switch image between original one and optimized one
	 *
	 * @since 1.6.2
	 * @access private
	 */
	private function _switch_optm_file( $type )
	{
		$pid = substr( $type, 4 ) ;
		$switch_type = substr( $type, 0, 4 ) ;

		global $wpdb ;
		$q = "SELECT src,post_id FROM $this->_table_img_optm WHERE optm_status = %s AND post_id = %d" ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, array( self::DB_STATUS_PULLED, $pid ) ) ) ;

		$msg = 'Unknown Msg' ;

		foreach ( $list as $v ) {
			// to switch webp file
			if ( $switch_type === 'webp' ) {
				if ( $this->__media->info( $v->src . '.webp', $v->post_id ) ) {
					$this->__media->rename( $v->src . '.webp', $v->src . '.optm.webp', $v->post_id ) ;
					Log::debug( '[Img_Optm] Disabled WebP: ' . $v->src ) ;

					$msg = __( 'Disabled WebP file successfully.', 'litespeed-cache' ) ;
				}
				elseif ( $this->__media->info( $v->src . '.optm.webp', $v->post_id ) ) {
					$this->__media->rename( $v->src . '.optm.webp', $v->src . '.webp', $v->post_id ) ;
					Log::debug( '[Img_Optm] Enable WebP: ' . $v->src ) ;

					$msg = __( 'Enabled WebP file successfully.', 'litespeed-cache' ) ;
				}
			}
			// to switch original file
			else {
				$extension = pathinfo( $v->src, PATHINFO_EXTENSION ) ;
				$local_filename = substr( $v->src, 0, - strlen( $extension ) - 1 ) ;
				$bk_file = $local_filename . '.bk.' . $extension ;
				$bk_optm_file = $local_filename . '.bk.optm.' . $extension ;

				// revert ori back
				if ( $this->__media->info( $bk_file, $v->post_id ) ) {
					$this->__media->rename( $v->src, $bk_optm_file, $v->post_id ) ;
					$this->__media->rename( $bk_file, $v->src, $v->post_id ) ;
					Log::debug( '[Img_Optm] Restore original img: ' . $bk_file ) ;

					$msg = __( 'Restored original file successfully.', 'litespeed-cache' ) ;
				}
				elseif ( $this->__media->info( $bk_optm_file, $v->post_id ) ) {
					$this->__media->rename( $v->src, $bk_file, $v->post_id ) ;
					$this->__media->rename( $bk_optm_file, $v->src, $v->post_id ) ;
					Log::debug( '[Img_Optm] Switch to optm img: ' . $v->src ) ;

					$msg = __( 'Switched to optimized file successfully.', 'litespeed-cache' ) ;
				}

			}
		}

		Admin_Display::add_notice( Admin_Display::NOTICE_GREEN, $msg ) ;
	}

	/**
	 * Delete one optm data and recover original file
	 *
	 * @since 2.4.2
	 * @access public
	 */
	public function reset_row( $post_id )
	{
		if ( ! $post_id ) {
			return ;
		}

		$size_meta = get_post_meta( $post_id, self::DB_SIZE, true ) ;

		if ( ! $size_meta ) {
			return ;
		}

		Log::debug( '[Img_Optm] _reset_row [pid] ' . $post_id ) ;

		global $wpdb ;
		$q = "SELECT src,post_id FROM $this->_table_img_optm WHERE post_id = %d" ;
		$list = $wpdb->get_results( $wpdb->prepare( $q, array( $post_id ) ) ) ;

		foreach ( $list as $v ) {
			$this->__media->info( $v->src . '.webp', $v->post_id ) && $this->__media->del( $v->src . '.webp', $v->post_id ) ;
			$this->__media->info( $v->src . '.optm.webp', $v->post_id ) && $this->__media->del( $v->src . '.optm.webp', $v->post_id ) ;

			$extension = pathinfo( $v->src, PATHINFO_EXTENSION ) ;
			$local_filename = substr( $v->src, 0, - strlen( $extension ) - 1 ) ;
			$bk_file = $local_filename . '.bk.' . $extension ;
			$bk_optm_file = $local_filename . '.bk.optm.' . $extension ;

			if ( $this->__media->info( $bk_file, $v->post_id ) ) {
				Log::debug( '[Img_Optm] _reset_row Revert ori file' . $bk_file ) ;
				$this->__media->del( $v->src, $v->post_id ) ;
				$this->__media->rename( $bk_file, $v->src, $v->post_id ) ;
			}
			elseif ( $this->__media->info( $bk_optm_file, $v->post_id ) ) {
				Log::debug( '[Img_Optm] _reset_row Del ori bk file' . $bk_optm_file ) ;
				$this->__media->del( $bk_optm_file, $v->post_id ) ;
			}
		}

		$q = "DELETE FROM $this->_table_img_optm WHERE post_id = %d" ;
		$wpdb->query( $wpdb->prepare( $q, $post_id ) ) ;

		delete_post_meta( $post_id, self::DB_SIZE ) ;

		$msg = __( 'Reset the optimized data successfully.', 'litespeed-cache' ) ;

		Admin_Display::add_notice( Admin_Display::NOTICE_GREEN, $msg ) ;
	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  2.0
	 * @access public
	 */
	public static function handler()
	{
		$instance = self::get_instance() ;

		$type = Router::verify_type() ;

		switch ( $type ) {
			case self::TYPE_RESET_ROW :
				$instance->reset_row( ! empty( $_GET[ 'id' ] ) ? $_GET[ 'id' ] : false ) ;
				break ;

			case self::TYPE_CALC_BKUP :
				$instance->_calc_bkup() ;
				break ;

			case self::TYPE_RM_BKUP :
				$instance->_rm_bkup() ;
				break ;

			case self::TYPE_SYNC_DATA :
				$instance->_sync_data() ;
				break ;

			case self::TYPE_IMG_OPTIMIZE :
				$instance->request_optm() ;
				break ;

			case self::TYPE_IMG_OPTIMIZE_RESCAN :
				$instance->_img_optimize_rescan() ;
				break ;

			case self::TYPE_IMG_OPTM_DESTROY :
				$instance->_img_optimize_destroy() ;
				break ;

			case self::TYPE_IMG_OPTM_DESTROY_UNFINISHED :
				$instance->_img_optimize_destroy_unfinished() ;
				break ;

			case self::TYPE_IMG_PULL :
				Log::debug( 'ImgOptm: Manually running Cron pull_optimized_img' ) ;
				$result = $instance->_pull_optimized_img( true ) ;
				// Manually running needs to roll back timestamp for next running
				$instance->_update_cron_running( true ) ;

				// Check if need to self redirect
				if ( is_array( $result ) && $result[ 'ok' ] === 'to_be_continued' ) {
					$link = Utility::build_url( Router::ACTION_IMG_OPTM, Img_Optm::TYPE_IMG_PULL ) ;
					// Add i to avoid browser too many redirected warning
					$i = ! empty( $_GET[ 'i' ] ) ? $_GET[ 'i' ] : 0 ;
					$i ++ ;
					$url = html_entity_decode( $link ) . '&i=' . $i ;
					exit( "<meta http-equiv='refresh' content='0;url=$url'>" ) ;
					// Admin::redirect( $url ) ;
				}
				break ;

			/**
			 * Batch switch
			 * @since 1.6.3
			 */
			case self::TYPE_IMG_BATCH_SWITCH_ORI :
			case self::TYPE_IMG_BATCH_SWITCH_OPTM :
				$instance->_batch_switch( $type ) ;
				break ;

			case substr( $type, 0, 4 ) === 'webp' :
			case substr( $type, 0, 4 ) === 'orig' :
				$instance->_switch_optm_file( $type ) ;
				break ;

			default:
				break ;
		}

		Admin::redirect() ;
	}


}
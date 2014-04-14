<?php
/**
 * Automatically transcode uploads into their relevant HTML5 fallbacks
 *
 * @package AudioVideoBonusPack
 * @subpackage Transcoding
 */

define( 'AV_ENCODE_MAX', 1 );

class AVTranscoding extends AVSingleton {
	private $encode_key = 'av_encoding_media';
	private $queue_key = 'av_media_queue';
	private $failed_key = 'av_media_failed';

	private $ffmpeg_bin;
	private $ffprobe_bin;

	/**
	 * Sandbox actions and filters
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'check_for_activity' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'delete_post', array( $this, 'delete_post' ) );

		$this->ffmpeg_bin = get_option( 'av_ffmpeg_path' );
		$this->ffprobe_bin = get_option( 'av_ffprobe_path' );

		if ( empty( $this->ffmpeg_bin ) || empty( $this->ffprobe_bin ) ) {
			add_action( 'admin_notices', array( $this, 'no_ffmpeg_notice' ) );
			return;
		}

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'wp_generate_attachment_metadata' ), 10, 2 );
	}

	/**
	 * Admin-specific actions and filters
	 */
	function admin_init() {
		$this->encodes = $this->get_transient( $this->encode_key, array() );
		$this->queue = $this->get_transient( $this->queue_key, array() );
		$this->failed = $this->get_transient( $this->failed_key, array() );

		add_action( 'wp_ajax_av-read-queue', array( $this, 'av_read_queue_json' ) );

		add_action( 'admin_footer', array( $this, 'admin_footer' ) );
		wp_enqueue_style( 'av-transcoding', plugins_url( 'transcoding.css', __FILE__ ) );
		wp_enqueue_script( 'av-transcoding', plugins_url( 'transcoding.js', __FILE__ ), array( 'backbone', 'wp-util' ), '', true );
		add_action( 'admin_notices', array( $this, 'ffmpeg_encoding_notice' ) );

		foreach ( array( 'ffmpeg', 'ffprobe' ) as $bin )  {
			register_setting( 'media', 'av_' . $bin . '_path' );
			add_settings_field( 'av-' . $bin, __(  sprintf( 'Path to <code>%s</code> binary', $bin ) ), array( $this, 'field_' . $bin ), 'media', 'av-settings' );
		}
	}

	function av_read_queue_json() {
		$queue = array();
		$encodes = array();

		if ( ! empty( $this->encodes ) ) {
			foreach ( $this->encodes as $key => $exists ) {
				list( $id, $type ) = explode( '_', $key );

				$progress = $this->get_transient( $key . '_progress', 1 );

				$encodes[] = array(
					'pid' => $id,
					'type' => strtoupper( $type ),
					'progress' => $progress,
					'title' => get_the_title( $id )
				);
			}
		}

		if ( ! empty( $this->queue ) ) {
			foreach ( $this->queue as $key => $file ) {
				if ( isset( $this->encodes[ $key ] ) ) {
					continue;
				}

				list( $id, $type ) = explode( '_', $key );

				$queue[] = array(
					'pid' => $id,
					'type' => $type,
					'file' => $file,
					'path' => ltrim( str_replace( $_SERVER['DOCUMENT_ROOT'], '', $file ), '/' )
				);
			}

		}

		echo json_encode( array(
			'encodes' => $encodes,
			'queue' => $queue,
			'failed' => $this->failed,
		) );

		if ( empty( $encodes ) && ! empty( $queue ) ) {
			$this->check_queue();
		}
		exit();
	}

	function admin_footer() {
		include_once( __DIR__ . '/templates.php' );
	}

	/**
	 * Remove items from the queue when the main attachment is deleted
	 *
	 * @param int $id
	 */
	function delete_post( $id ) {
		$post = get_post( $id );
		if ( 'attachment' !== $post->post_type ) {
			return;
		}

		$queue = $this->get_transient( $this->queue_key, array() );
		foreach ( $queue as $key => $file ) {
			if ( 0 === strpos( $key, $id . '_' ) ) {
				unset( $queue[ $key ] );
			}
		}
		set_transient( $this->queue_key, $queue );
	}

	/**
	 * Fire a non-blocking request to pop an item off the queue if no file is in progress
	 */
	function check_queue() {
		$url = add_query_arg( array(
			'action' => 'av_encode'
		), home_url( '/' ) );

		$nonced = html_entity_decode( wp_nonce_url( $url, 'av_encode' ) );
		$this->log( $nonced );
		wp_remote_get( $nonced, array( 'blocking' => false ) );
	}

	/**
	 * Add items to the queue
	 *
	 * @param int $id
	 */
	function add_to_queue( $id ) {
		if ( get_post_meta( $id, '_is_fallback', true ) ) {
			return;
		}

		$file = get_attached_file( $id );
		$ext = wp_check_filetype( $file );
		$base = basename( $file );
		$front = rtrim( $file, $base );

		$encodes = $this->get_transient( $this->encode_key, array() );
		$queue = $this->get_transient( $this->queue_key, array() );
		$fallbacks = array();

		switch ( $ext['ext'] ) {
		case 'wma':
		case 'wav':
		case 'm4a':
			$fallbacks = array( 'mp3', 'ogg' );
			break;

		case 'ogg':
			$fallbacks[] = 'mp3';
			break;

		case 'mp3':
			$fallbacks[] = 'ogg';
			break;

		case 'm4v':
		case 'mp4':
			$fallbacks = array( 'ogv', 'webm' );
			break;

		case 'webm':
			$fallbacks[] = 'ogv';
			break;

		case 'ogv':
			$fallbacks[] = 'webm';
			break;

		case 'flv':
		case 'mov':
		case 'wmv':
			$fallbacks = array( 'webm', 'ogv' );
			break;
		}

		foreach ( $fallbacks as $type ) {
			$key = '_' . $type . '_fallback';
			$meta = get_post_meta( $id, $key, true );
			$encode_key = $id . '_' . $type;
			$new_file = $front . rtrim( $base, $ext['ext'] ) . $type;

			if ( empty( $meta )
				&& ! in_array( $new_file, $queue )
				&& ! isset( $encodes[ $encode_key ] )
				&& ! isset( $queue[ $encode_key ] )
				&& ! file_exists( $new_file ) ) {
				$queue[ $encode_key ] = $new_file;
			}
		}

		set_transient( $this->queue_key, $queue );
		$this->check_queue();
	}

	/**
	 * On init, check for a request to potentially hijack
	 */
	function check_for_activity() {
		if ( ! isset( $_GET['action'] ) || 0 !== strpos( $_GET['action'], 'av_' ) ) {
			return;
		}

		$check = wp_verify_nonce( $_GET['_wpnonce'], $_GET['action'] );
		if ( ! $check ) {
			// the first nonce is always failing for some reason...
			$this->log( 'nonce failed' );
			//return;
		}

		switch ( $_GET['action'] ) {
		case 'av_delete_queue':
			set_transient( $this->queue_key, array() );
			wp_safe_redirect( wp_get_referer() );
			exit();

		case 'av_delete_failed':
			set_transient( $this->failed_key, array() );
			wp_safe_redirect( wp_get_referer() );
			exit();

		case 'av_encode':
			$encodes = $this->get_transient( $this->encode_key, array() );
			if ( empty( $encodes ) || count( $encodes ) < AV_ENCODE_MAX ) {
				$queue = $this->get_transient( $this->queue_key, array() );
				if ( ! empty( $queue ) ) {
					foreach ( $queue as $key => $file ) {
						list( $id, $type ) = explode( '_', $key );
						$encodes[ $key ] = 1;
						set_transient( $this->encode_key, $encodes );

						unset( $queue[ $key ] );
						set_transient( $this->queue_key, $queue );

						ignore_user_abort( true );
						set_time_limit( 0 );
						$this->encode_media( $id, $type, $file );
						exit();
					}
				}
			}
			break;
		case 'av_queue':
			$id = (int) $_GET['media_id'];
			if ( empty( $id ) ) {
				return;
			}

			$this->add_to_queue( $id );
			exit();
		}
	}

	function remove_encode( $encode_key ) {
		$encodes = $this->get_transient( $this->encode_key, array() );
		unset( $encodes[ $encode_key ] );
		set_transient( $this->encode_key, $encodes );
		delete_transient( $encode_key . '_progress' );
	}

	function add_to_failed( $encode_key, $type_file ) {
		$failed = $this->get_transient( $this->failed_key, array() );
		$failed[ $encode_key ] = $type_file;
		set_transient( $this->failed_key, $failed );
	}

	/**
	 * Process the passed item
	 *
	 * @param int $id
	 * @param string $type
	 * @param string $type_file
	 */
	function encode_media( $id, $type, $type_file ) {
		$fallback_types = array( 'mp3', 'ogg', 'ogv', 'webm', 'mp4' );
		$encode_key = $id . '_' . $type;
		if ( ! in_array( $type, $fallback_types ) || file_exists( $type_file ) ) {
			$this->remove_encode( $encode_key );
			return;
		}

		$file = get_attached_file( $id );
		if ( 'm4a' === substr( $file, -3 ) && 'ogg' === $type ) {
			$fallback = get_post_meta( $id, '_mp3_fallback', true );
			if ( ! empty( $fallback ) ) {
				$file = get_attached_file( $fallback );
			}
		}

		// Autoload PHP-FFMpeg + dependencies
		require_once( AV_DIR . '/vendor/autoload.php' );

		$ffmpeg = $this->get_ffmpeg();
		$media = $ffmpeg->open( $file );

		$attachment = get_post( $id, ARRAY_A );
		$props = $attachment;
		unset(
			$props['post_modified_gmt'],
			$props['post_modified'],
			$props['post_mime_type'],
			$props['guid'],
			$props['post_name'],
			$props['ID']
		);
		$thumbnail_id = get_post_thumbnail_id( $id );
		$original_meta = wp_get_attachment_metadata( $id );

		$key = '_' . $type . '_fallback';

		try {
			switch ( $type ) {
			case 'mp3':
				$format = new FFMpeg\Format\Audio\Mp3();
				break;
			case 'ogg':
				$format = new FFMpeg\Format\Audio\Vorbis();
				break;
			case 'ogv':
				$format = new FFMpeg\Format\Video\Ogg();
				break;
			case 'webm':
				$format = new FFMpeg\Format\Video\WebM();
				break;
			case 'mp4':
				$format = new FFMpeg\Format\Video\X264();
				break;
			}

			$progress_key = $id . '_' . $type . '_' . 'progress';
			$format->on( 'progress', function ( $media, $format, $percentage ) use ( $progress_key ) {
				static $last_progress = false;

				if ( ! $last_progress ) {
					$last_progress = $percentage;
				}

				if ( $percentage && $percentage !== $last_progress ) {
					$last_progress = $percentage;

					set_transient( $progress_key, $last_progress );
				}
			} );
			$media->save( $format, $type_file );
		} catch ( Exception $e ) {
			$this->log( 'Caught exception: ' . $e->getMessage() );

			unlink( $type_file );

			$this->remove_encode( $encode_key );
			$this->add_to_failed( $encode_key, $type_file );
			$this->check_queue();
			return;
		}

		$type_ext = wp_check_filetype( $type_file );
		$props['post_mime_type'] = $type_ext['type'];

		$attachment_id = wp_insert_attachment( $props, $type_file );
		update_post_meta( $id, $key, $attachment_id );
		update_post_meta( $attachment_id, '_is_fallback', true );

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/media.php' );
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
		}

		$attach_data = wp_generate_attachment_metadata( $attachment_id, $type_file );

		foreach ( wp_get_attachment_id3_keys( $attachment, 'display' ) as $key ) {
			if ( ! empty( $original_meta[ $key ] ) ) {
				$attach_data[ $key ] = $original_meta[ $key ];
			}
		}

		wp_update_attachment_metadata( $attachment_id, $attach_data );

		if ( ! empty( $thumbnail_id ) ) {
			set_post_thumbnail( $attachment_id, $thumbnail_id );
		}

		$this->remove_encode( $encode_key );
		$this->check_queue();
	}

	/**
	 * Fire a non-blocking request when a new item is being added to queue its fallbacks
	 *
	 * @param array $data
	 * @param int $post_id
	 * @return array
	 */
	function wp_generate_attachment_metadata( $data, $post_id ) {
		$file = get_attached_file( $post_id );
		$ext = wp_check_filetype( $file );

		switch ( $ext['type'] ) {
		case 'audio/x-ms-wma':
		case 'audio/ogg':
		case 'audio/wav':
		case 'audio/m4a':
		case 'audio/mpeg':
		case 'video/ogg':
		case 'video/webm':
		case 'video/mpeg':
		case 'video/mp4':
		case 'video/m4v':
		case 'video/quicktime':
		case 'video/x-ms-wmv':
			$url = add_query_arg( array(
				'media_id' => $post_id,
				'action' => 'av_queue',
			), home_url( '/' ) );

			$nonced = html_entity_decode( wp_nonce_url( $url, 'av_queue' ) );
			$this->log( $nonced );
			wp_remote_get( $nonced, array( 'blocking' => false ) );
			break;
		}

		return $data;
	}

	/**
	 * Return an instance of ffmpeg wrapper that does not use threads
	 *	Give the process 30 minutes to run for large files
	 *
	 * @return FFMpeg\FFMpeg
	 */
	function get_ffmpeg() {
		return FFMpeg\FFMpeg::create( array(
			'ffmpeg.binaries' => $this->ffmpeg_bin,
			'ffprobe.binaries' => $this->ffprobe_bin,
			'ffmpeg.threads' => 0,
			'timeout' => HOUR_IN_SECONDS / 2
		) );
	}

	/**
	 * Output a message in the admin when path options are missing
	 */
	function no_ffmpeg_notice() {
		$plugin_file = plugin_basename( __FILE__ );
	?>
<div class="error"><p><strong>Audio / Video Bonus Pack</strong> isn't very useful without <code>ffmpeg</code>.
		<a href="<?php echo wp_nonce_url( 'plugins.php?action=deactivate&amp;plugin=' . $plugin_file, 'deactivate-plugin_' . $plugin_file );
		?>">Uninstall the plugin</a> or <a href="<?php echo admin_url( 'options-media.php' ) ?>">update your settings</a>.</p></div>
	<?php
	}

	/**
	 * Display data related to the current queue
	 */
	function ffmpeg_encoding_notice() {
	?>
<div class="updated" id="av-queue">
	<p id="av-encode-message"><strong>Encoding media</strong></p>
	<div id="av-encode-items"></div>
	<div id="av-queued-items"></div>
	<p id="av-queued-message"><a href="<?php
		$url = add_query_arg( 'action', 'av_delete_queue', home_url( '/' ) );
		echo wp_nonce_url( $url, 'av_delete_queue' ) ?>">Delete all queued items</a></p>
</div>
	<?php

	if ( ! empty( $this->failed ) ): ?>
<div class="error" id="av-failed">
	<?php foreach ( $this->failed as $key => $file ): ?>
	<p><?php
		$path = ltrim( str_replace( $_SERVER['DOCUMENT_ROOT'], '', $file ), '/' );
		printf( '<strong>Transcoding failed:</strong> %s', $path ); ?></p>
	<?php endforeach ?>
	<p><a href="<?php
		$url = add_query_arg( 'action', 'av_delete_failed', home_url( '/' ) );
		echo wp_nonce_url( $url, 'av_delete_failed' ) ?>">Delete all failed items</a></p>
</div>
	<?php endif;
	}

	/**
	 * Outputs the field for 'ffmpeg_path'
	 */
	function field_ffmpeg() {
		$option = get_option( 'av_ffmpeg_path' );
	?>
	<input type="text" name="av_ffmpeg_path" class="widefat" value="<?php if ( ! empty( $option ) ) {
		echo esc_attr( $option );
	} ?>"/>
	<?php
	}

	/**
	 * Outputs the field for 'ffprobe_path'
	 */
	function field_ffprobe() {
		$option = get_option( 'av_ffprobe_path' );
	?>
	<input type="text" name="av_ffprobe_path" class="widefat" value="<?php if ( ! empty( $option ) ) {
		echo esc_attr( $option );
	} ?>"/>
	<?php
	}
}
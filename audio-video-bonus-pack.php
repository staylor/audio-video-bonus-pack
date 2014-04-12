<?php
/*
Plugin Name: Audio/Video Bonus Pack
Description: Experimental/Supplemental features not included in WordPress core.
Author: Scott Taylor
Author URI: http://profiles.wordpress.org/wonderboymusic/
Version: 0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

require_once( __DIR__ . '/vendor/autoload.php' );

class AudioVideoBonusPack {
	private $encode_key = 'av_encoding_media';
	private $queue_key = 'av_media_queue';
	private $failed_key = 'av_media_failed';
	private $ffmpeg_bin;
	private $ffprobe_bin;

	private static $instance;

	public static function get_instance() {
		if ( ! self::$instance instanceof AudioVideoBonusPack ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Sandbox actions and filters
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'check_for_activity' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'delete_post', array( $this, 'delete_post' ) );

		$this->ffmpeg_bin = get_option( 'ffmpeg_path' );
		$this->ffprobe_bin = get_option( 'ffprobe_path' );

		if ( empty( $this->ffmpeg_bin ) || empty( $this->ffprobe_bin ) ) {
			add_action( 'admin_notices', array( $this, 'no_ffmpeg_notice' ) );
			return;
		}

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'wp_generate_attachment_metadata' ), 10, 2 );
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

		$queue = get_transient( $this->queue_key );
		foreach ( $queue as $key => $file ) {
			if ( 0 === strpos( $key, $id . '_' ) ) {
				unset( $queue[ $key ] );
			}
		}
		set_transient( $this->queue_key, $queue );
	}

	/**
	 * Fire a non-blocking request to pop an item off the queue if no file is is progress
	 */
	function check_queue() {
		$url = add_query_arg( array(
			'action' => 'av_encode'
		), home_url( '/' ) );

		$nonced = html_entity_decode( wp_nonce_url( $url, 'av_encode' ) );
		error_log( $nonced );
		wp_remote_get( $nonced, array( 'blocking' => false ) );
	}

	/**
	 * Add items to the queue
	 *
	 * @param int $id
	 */
	function add_to_queue( $id ) {
		$file = get_attached_file( $id );
		$ext = wp_check_filetype( $file );
		$base = basename( $file );
		$front = rtrim( $file, $base );

		$encodes = get_transient( $this->encode_key );
		$queue = get_transient( $this->queue_key );

		foreach ( array( 'ogv', 'webm' ) as $type ) {
			$key = '_' . $type . '_fallback';
			$meta = get_post_meta( $id, $key, true );
			$encode_key = $id . '_' . $type;

			if ( empty( $meta ) && ! isset( $encodes[ $encode_key ] ) && ! isset( $queue[ $encode_key ] ) ) {
				$queue[ $encode_key ] = $front . rtrim( $base, $ext['ext'] ) . $type;
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
			error_log( 'nonce failed' );
			//return;
		}

		switch ( $_GET['action'] ) {
		case 'av_delete_queue':
			delete_transient( $this->queue_key );
			break;
		case 'av_delete_failed':
			delete_transient( $this->failed_key );
			break;
		case 'av_encode':
			$encodes = get_transient( $this->encode_key );
			if ( empty( $encodes ) ) {
				$queue = get_transient( $this->queue_key );
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

	/**
	 * Update the progress of a processing item
	 *
	 * @param mixed $media
	 * @param mixed $format
	 * @param int $percentage
	 */
	function do_progress( $media, $format, $percentage ) {
		$this->progress = $percentage;
		if ( ! isset( $this->last_progress ) ) {
			$this->last_progress = $this->progress;
		}

		if ( $this->progress && $this->progress !== $this->last_progress ) {
			error_log( "$percentage% transcoded" );
			$this->last_progress = $this->progress;

			$encode_key = $this->id . '_' . $this->type;
			$encodes = get_transient( $this->encode_key );
			$encodes[ $encode_key ] = $percentage;
			set_transient( $this->encode_key, $encodes );
		}
	}

	/**
	 * Process the passed item
	 *
	 * @param int $id
	 * @param string $type
	 * @param string $type_file
	 */
	function encode_media( $id, $type, $type_file ) {
		$this->id = $id;
		$this->type = $type;

		$file = get_attached_file( $id );
		$ffmpeg = $this->get_ffmpeg();
		$video = $ffmpeg->open( $file );

		$attachment = get_post( $id, ARRAY_A );
		$props = $attachment;
		unset( $props['post_mime_type'], $props['guid'], $props['post_name'], $props['ID'] );
		$thumbnail_id = get_post_thumbnail_id( $id );

		$key = '_' . $type . '_fallback';
		$encode_key = $id . '_' . $type;

		try {
			switch ( $type ) {
			case 'ogv':
				$format = new FFMpeg\Format\Video\Ogg();
				$format->on( 'progress', array( $this, 'do_progress' ) );
				$video->save( $format, $type_file );
				break;
			case 'webm':
				$format = new FFMpeg\Format\Video\WebM();
				$format->on( 'progress', array( $this, 'do_progress' ) );
				$video->save( $format, $type_file );
				break;
			}
		} catch ( Exception $e ) {
			error_log( 'Caught exception: ' . $e->getMessage() );

			$encodes = get_transient( $this->encode_key );
			unset( $encodes[ $encode_key ] );
			set_transient( $this->encode_key, $encodes );

			$failed = get_transient( $this->failed_key );
			$failed[ $encode_key ] = $file;
			set_transient( $this->failed_key, $failed );

			$this->check_queue();
			return;
		}

		$type_ext = wp_check_filetype( $type_file );
		$props['post_mime_type'] = $type_ext['type'];

		$attachment_id = wp_insert_attachment( $props, $type_file );
		update_post_meta( $id, $key, $attachment_id );

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once( ABSPATH . 'wp-admin/includes/media.php' );
			require_once( ABSPATH . 'wp-admin/includes/image.php' );
		}

		$attach_data = wp_generate_attachment_metadata( $attachment_id, $type_file );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		if ( ! empty( $thumbnail_id ) ) {
			set_post_thumbnail( $attachment_id, $thumbnail_id );
		}

		$encodes = get_transient( $key );
		unset( $encodes[ $encode_key ] );
		set_transient( $this->encode_key, $encodes );
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
		case 'video/mp4':
			$url = add_query_arg( array(
				'action' => 'av_queue',
				'media_id' => $post_id
			), home_url( '/' ) );

			$nonced = html_entity_decode( wp_nonce_url( $url, 'av_queue' ) );
			error_log( $nonced );
			wp_remote_get( $nonced, array( 'blocking' => false ) );
			break;
		}

		return $data;
	}

	/**
	 * Return an instance of ffmpeg wrapper that does not use threads
	 *
	 * @return FFMpeg\FFMpeg
	 */
	function get_ffmpeg() {
		return FFMpeg\FFMpeg::create( array(
			'ffmpeg.binaries' => $this->ffmpeg_bin,
			'ffprobe.binaries' => $this->ffprobe_bin,
			'ffmpeg.threads' => 0
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
		$size = count( $this->encodes );
		$message = 1 === $size ? '1 media file that is' : "$size media files that are";
	?>
<div class="updated">
	<?php if ( ! empty( $this->encodes ) ): ?>
	<p><strong>Encoding media</strong> You have <?php echo $message ?> being encoded....</p>
	<?php foreach ( $this->encodes as $key => $progress ): ?>
		<p><?php
		list( $id, $type ) = explode( '_', $key );
		printf( '<strong>%s</strong> for "%s" is %d%% done.', strtoupper( $type ), get_the_title( $id ), $progress );
		?></p>
	<?php endforeach;
	endif; ?>

	<?php
	if ( ! empty( $this->queue ) ):
		foreach ( $this->queue as $key => $file ): ?>
		<p><?php printf( '<strong>Queued for creation:</strong> %s', $file ); ?></p>
	<?php endforeach; ?>
		<p><a href="<?php
			$url = add_query_arg( 'action', 'av_delete_queue', home_url( '/' ) );
			echo wp_nonce_url( $url, 'av_delete_queue' ) ?>">Delete all queued items</a></p>
	<?php endif; ?>
</div>
	<?php
	if ( ! empty( $this->failed ) ): ?>
<div class="error">
	<?php foreach ( $this->failed as $key => $file ): ?>
	<p><?php printf( '<strong>Transcoding failed:</strong> %s', $file ); ?></p>
	<?php endforeach ?>
	<p><a href="<?php
		$url = add_query_arg( 'action', 'av_delete_failed', home_url( '/' ) );
		echo wp_nonce_url( $url, 'av_delete_failed' ) ?>">Delete all failed items</a></p>
</div>
	<?php endif;
	}

	/**
	 * Admin-specific actions and filters
	 */
	function admin_init() {
		$this->encodes = get_transient( $this->encode_key );
		$this->queue = get_transient( $this->queue_key );
		$this->failed = get_transient( $this->failed_key );
		if ( ! empty( $this->encodes ) || ! empty( $this->queue ) || ! empty( $this->failed ) ) {
			add_action( 'admin_notices', array( $this, 'ffmpeg_encoding_notice' ) );
		}

		add_settings_section( 'av-settings', __( 'Audio/Video Settings' ), array( $this, 'settings' ), 'media' );

		foreach ( array( 'ffmpeg', 'ffprobe' ) as $bin )  {
			register_setting( 'media', $bin . '_path' );
			add_settings_field( 'av-' . $bin, __(  sprintf( 'Path to <code>%s</code> binary', $bin ) ), array( $this, 'field_' . $bin ), 'media', 'av-settings' );
		}
	}

	/**
	 * Outputs the field for 'ffmpeg_path'
	 */
	function field_ffmpeg() {
		$option = get_option( 'ffmpeg_path' );
	?>
	<input type="text" name="ffmpeg_path" class="widefat" value="<?php if ( ! empty( $option ) ) {
		echo esc_attr( $option );
	} ?>"/>
	<?php
	}

	/**
	 * Outputs the field for 'ffprobe_path'
	 */
	function field_ffprobe() {
		$option = get_option( 'ffprobe_path' );
	?>
	<input type="text" name="ffprobe_path" class="widefat" value="<?php if ( ! empty( $option ) ) {
		echo esc_attr( $option );
	} ?>"/>
	<?php
	}

	/**
	 * Introductory text before A/V settings fields
	 */
	function settings() {
	?>
	If you were redirected here, you need to specify the paths to these binaries: <em>(You can find them via</em> <code>which ffmpeg</code><em>)</em>
	<?php
	}
}
AudioVideoBonusPack::get_instance();

/**
 * Perform safe redirection to media settings page.
 *
 */
function av_redirect_activated_plugin() {
	wp_safe_redirect( admin_url( 'options-media.php' ) );
	exit();
}

/**
 * On activation, if binaries cannot be detected, redirect to the settings page for Media.
 *
 */
function av_detect_ffmpeg() {
	exec( "which ffmpeg", $exec_ffmpeg );
	if ( ! empty( $exec_ffmpeg ) ) {
		add_option( 'ffmpeg_path', reset( $exec_ffmpeg ) );
	}
	exec( "which ffprobe", $exec_ffprobe );
	if ( ! empty( $exec_ffprobe ) ) {
		add_option( 'ffprobe_path', reset( $exec_ffprobe ) );
	}
	if ( empty( $exec_ffmpeg ) || empty( $exec_ffprobe ) ) {
		add_action( 'activated_plugin', 'av_redirect_activated_plugin' );
	}
}
register_activation_hook( __FILE__, 'av_detect_ffmpeg' );
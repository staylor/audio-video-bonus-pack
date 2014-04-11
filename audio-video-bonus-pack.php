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
	private $ffmpeg_bin;
	private $ffprobe_bin;

	private static $instance;

	public static function get_instance() {
		if ( ! self::$instance instanceof AudioVideoBonusPack ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'check_for_activity' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		$this->ffmpeg_bin = get_option( 'ffmpeg_path' );
		$this->ffprobe_bin = get_option( 'ffprobe_path' );

		if ( empty( $this->ffmpeg_bin ) || empty( $this->ffprobe_bin ) ) {
			add_action( 'admin_notices', array( $this, 'no_ffmpeg_notice' ) );
			return;
		}

		if ( get_transient( 'av_encoding_media' ) ) {
			add_action( 'admin_notices', array( $this, 'ffmpeg_encoding_notice' ) );
		}

		add_filter( 'wp_generate_attachment_metadata', array( $this, 'wp_generate_attachment_metadata' ), 10, 2 );
	}

	function check_for_activity() {
		if ( isset( $_GET['action'] ) && 'encode' === $_GET['action'] ) {
			error_log( 'wants to encode' );
			$check = wp_verify_nonce( $_GET['_wpnonce'], 'encode' );
			if ( ! $check ) {
				error_log( 'nonce failed' );
				//return;
			}

			$id = (int) $_GET['media_id'];
			if ( empty( $id ) ) {
				error_log( 'no id' );
				return;
			}

			error_log( 'supposedly encoding' );
			$this->encode_media( $id );
		}
	}

	function encode_media( $id ) {
		$file = get_attached_file( $id );
		$ext = wp_check_filetype( $file );

		$ffmpeg = $this->get_ffmpeg();
		$video = $ffmpeg->open( $file );

		$base = basename( $file );
		$front = rtrim( $file, $base );

		$attachment = get_post( $id, ARRAY_A );
		$props = $attachment;
		unset( $props['post_mime_type'], $props['guid'], $props['post_name'], $props['ID'] );
		$thumbnail_id = get_post_thumbnail_id( $id );

		foreach ( array( 'webm', 'ogv' ) as $type ) {
			$key = '_' . $type . '_fallback';
			$meta = get_post_meta( $id, $key, true );
			if ( empty( $meta ) ) {
				$type_file = $front . rtrim( $base, $ext['ext'] ) . $type;

				try {
					set_transient( 'av_encoding_media', true );

					switch ( $type ) {
					case 'ogv':
						$video->save( new FFMpeg\Format\Video\Ogg(), $type_file );
						break;
					case 'webm':
						$video->save( new FFMpeg\Format\Video\WebM(), $type_file );
						break;
					}
				} catch ( Exception $e ) {
					delete_transient( 'av_encoding_media' );

					break;
				}

				$type_ext = wp_check_filetype( $type_file );
				$props['post_mime_type'] = $type_ext['type'];

				$attachment_id = wp_insert_attachment( $props, $type_file );
				update_post_meta( $id, $key, $attachment_id );

				$attach_data = wp_generate_attachment_metadata( $attachment_id, $type_file );
				wp_update_attachment_metadata( $attachment_id, $attach_data );

				if ( ! empty( $thumbnail_id ) ) {
					set_post_thumbnail( $attachment_id, $thumbnail_id );
				}
			}

			delete_transient( 'av_encoding_media' );
		}
	}

	function wp_generate_attachment_metadata( $data, $post_id ) {
		$file = get_attached_file( $post_id );
		$ext = wp_check_filetype( $file );

		switch ( $ext['type'] ) {
		case 'video/mp4':
			$url = add_query_arg( array(
				'action' => 'encode',
				'media_id' => $post_id
			), home_url( '/' ) );

			$nonced = html_entity_decode( wp_nonce_url( $url, 'encode' ) );
			error_log( $nonced );
			wp_remote_get( $nonced, array( 'blocking' => false ) );
			break;
		}

		return $data;
	}

	function get_ffmpeg() {
		return FFMpeg\FFMpeg::create( array(
			'ffmpeg.binaries' => $this->ffmpeg_bin,
			'ffprobe.binaries' => $this->ffprobe_bin,
			'ffmpeg.threads' => 60
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

	function ffmpeg_encoding_notice() {
	?>
<div class="updated"><p><strong>Encoding media</strong> You have media that is being encoded....</p></div>
	<?php
	}

	/**
	 * Admin-specific actions and filters
	 */
	function admin_init() {
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
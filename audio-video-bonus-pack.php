<?php
/**
 * @package AudioVideoBonusPack
 */
/*
Plugin Name: Audio/Video Bonus Pack
Description: Experimental/Supplemental features not included in WordPress core.
Author: Scott Taylor
Author URI: http://profiles.wordpress.org/wonderboymusic/
Version: 0.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

define( 'AV_LOG', false );
define( 'AV_DIR', __DIR__ );

abstract class AVSingleton {
	protected static $instance = array();
	public static function get_instance() {
		$c = get_called_class();
		if ( ! isset( self::$instance[ $c ] ) ) {
			self::$instance[ $c ] = new $c;
		}
		return self::$instance[ $c ];
	}
	protected function __construct() {}

	function log( $message ) {
		if ( AV_LOG ) {
			error_log( $message );
		}
	}

	function get_transient( $key, $default ) {
		$value = get_transient( $key );
		if ( empty( $value ) ) {
			return $default;
		}

		return $value;
	}
}

/**
 * The main plugin controller
 * - conditionally loads other plugins
 * - creates feature flags
 */
class AudioVideoBonusPack extends AVSingleton {
	/**
	 * Sandbox actions and filters
	 */
	protected function __construct() {
		parent::__construct();

		add_action( 'admin_init', array( $this, 'admin_init' ) );

		if ( get_option( 'av_transcoding_enabled', 1 ) ) {
			require( AV_DIR . '/features/transcoding/transcoding.php' );
			AVTranscoding::get_instance();
		}

		if ( get_option( 'av_soundcloud_manager_enabled', 1 ) ) {
			require( AV_DIR . '/features/soundcloud/soundcloud.php' );
			AVSoundCloud::get_instance();
		}
	}

	/**
	 * Register feature settings for the main plugin controller
	 */
	function admin_init() {
		add_settings_section( 'av-settings', __( 'Audio/Video Settings' ), array( $this, 'settings' ), 'media' );

		register_setting( 'media', 'av_soundcloud_manager_enabled' );
		add_settings_field( 'av-soundcloud_manager_enabled', __( 'Add Advanced SoundCloud support' ), array( $this, 'field_soundcloud_manager_enabled' ), 'media', 'av-settings' );
		register_setting( 'media', 'av_transcoding_enabled' );
		add_settings_field( 'av-transcoding-enabled', __( 'Automatically generate HTML5 fallbacks' ), array( $this, 'field_transcoding_enabled' ), 'media', 'av-settings' );
	}

	/**
	 * Output the 'av_transcoding_enabled' field
	 */
	function field_transcoding_enabled() {
		$option = get_option( 'av_transcoding_enabled', 1 );
	?>
	<input type="checkbox" name="av_transcoding_enabled" value="1" <?php checked( $option, 1 ) ?>/>
	<?php
	}

	/**
	 * Output the 'av_soundcloud_manager_enabled' field
	 */
	function field_soundcloud_manager_enabled() {
		$option = get_option( 'av_soundcloud_manager_enabled', 1 );
	?>
	<input type="checkbox" name="av_soundcloud_manager_enabled" value="1" <?php checked( $option, 1 ) ?>/>
	<?php
	}

	/**
	 * Introductory conditional text before A/V settings fields
	 */
	function settings() {
		if ( get_option( 'av_transcoding_enabled', 1 ) ): ?>
	If you were redirected here when activating the plugin, <code>ffmpeg</code> is not installed properly;
		or you need to specify the paths to these binaries: <em>(You can find them via</em> <code>which ffmpeg</code><em>)</em>
		<?php endif;
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
		add_option( 'av_ffmpeg_path', reset( $exec_ffmpeg ) );
	}
	exec( "which ffprobe", $exec_ffprobe );
	if ( ! empty( $exec_ffprobe ) ) {
		add_option( 'av_ffprobe_path', reset( $exec_ffprobe ) );
	}
	if ( empty( $exec_ffmpeg ) || empty( $exec_ffprobe ) ) {
		add_action( 'activated_plugin', 'av_redirect_activated_plugin' );
	}
}
register_activation_hook( __FILE__, 'av_detect_ffmpeg' );
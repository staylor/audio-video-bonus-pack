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
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		$this->ffmpeg_bin = get_option( 'ffmpeg_path' );
		$this->ffprobe_bin = get_option( 'ffprobe_path' );

		if ( empty( $this->ffmpeg_bin ) || empty( $this->ffprobe_bin ) ) {
			add_action( 'admin_notices', array( $this, 'no_ffmpeg_notice' ) );
			return;
		}
	}

	function get_ffmpeg() {
		return FFMpeg\FFMpeg::create( array(
			'ffmpeg.binaries' => $this->ffmpeg_bin,
			'ffprobe.binaries' => $this->ffprobe_bin
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
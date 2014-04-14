<?php
/**
 * Soundcloud manager
 *
 * @package AudioVideoBonusPack
 * @subpackage Soundcloud
 */

class AVSoundCloud extends AVSingleton {
	protected function __construct() {
		parent::__construct();

		add_shortcode( 'soundcloud', array( $this, 'shortcode' ) );
		add_action( 'load-post.php', array( $this, 'enqueue' ) );
		add_action( 'load-post-new.php', array( $this, 'enqueue' ) );
	}

	function shortcode( $atts ) {
		$defaults = array(
			'src' => ''
		);
		$params = wp_parse_args( $atts, $defaults );
		return $params['src'];
	}

	function enqueue() {
		add_action( 'admin_footer', array( $this, 'print_templates' ) );

		$css_src = plugins_url( 'soundcloud.css', __FILE__ );
		$js_src = plugins_url( 'soundcloud.js', __FILE__ );
		wp_enqueue_style( 'av-soundcloud', $css_src );
		wp_enqueue_script( 'av-soundcloud', $js_src, array( 'mce-view' ), '', true );
	}

	function print_templates() {
	?>
	<script type="text/html" id="tmpl-av-soundcloud-details">
		<div class="media-embed">
			<div class="embed-media-settings">
				{{{ wp.media.embedCache[ data.model.url ] }}}
			</div>
		</div>
	</script>

	<script type="text/html" id="tmpl-editor-av-soundcloud">
		<div class="toolbar">
			<div class="dashicons dashicons-edit edit"></div>
			<div class="dashicons dashicons-no-alt remove"></div>
		</div>
		<div class="av-replace-soundcloud">{{{ data.url }}}</div>
	</script>
	<?php
	}
}
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
		global $wp_embed;

		$defaults = array(
			'url' => '',
			'width' => '',
			'height' => ''
		);
		$params = wp_parse_args( $atts, $defaults );
		$url = $params['url'];
		unset( $params['url'] );
		return $wp_embed->shortcode( $params, $url );
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
				<# console.log( data ) #>
				{{{ wp.media.embedCache[ data.model.key ] }}}

				<label class="setting">
					<span><?php _e('URL'); ?></span>
					<input type="text" data-setting="url" value="{{ data.model.url }}" />
				</label>
				<label class="setting">
					<span><?php _e('WIDTH'); ?></span>
					<input type="text" data-setting="width" value="{{ data.model.width ? data.model.width : '' }}" />
				</label>
				<label class="setting">
					<span><?php _e('HEIGHT'); ?></span>
					<input type="text" data-setting="height" value="{{ data.model.height ? data.model.height : '' }}" />
				</label>
			</div>
		</div>
	</script>

	<script type="text/html" id="tmpl-editor-av-soundcloud">
		<div class="toolbar">
			<div class="dashicons dashicons-edit edit"></div>
			<div class="dashicons dashicons-no-alt remove"></div>
		</div>
		<div class="av-soundcloud-embed av-replace-soundcloud">{{{ data.url }}}</div>
	</script>
	<?php
	}
}
<?php
/**
 * Replace [embed]s with TinyMCE Previews
 *
 * @package AudioVideoBonusPack
 * @subpackage Embeds
 */

class AVEmbeds extends AVSingleton {
	protected function __construct() {
		parent::__construct();

		add_action( 'load-post.php', array( $this, 'enqueue' ) );
		add_action( 'load-post-new.php', array( $this, 'enqueue' ) );
	}

	function enqueue() {
		$src = plugins_url( 'embeds.js', __FILE__ );
		wp_enqueue_script( 'av-embeds', $src, array( 'mce-view' ), '', true );
	}
}
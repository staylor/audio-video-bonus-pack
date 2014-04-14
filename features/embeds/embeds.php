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

		add_action( 'wp_ajax_av-parse-content', array( $this, 'parse_content' ) );
	}

	function enqueue() {
		$src = plugins_url( 'embeds.js', __FILE__ );
		wp_enqueue_script( 'av-embeds', $src, array( 'mce-view' ) );
	}

	function parse_content() {
		global $post;

		if ( ! $post = get_post( (int) $_REQUEST['post_ID'] ) ) {
			wp_send_json_error();
		}

		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			wp_send_json_error();
		}

		setup_postdata( $post );

		$content = wp_unslash( $_POST['oembed_content'] );
		$parsed = apply_filters( 'the_content', $content );

		wp_send_json( array( 'content' => $parsed ) );
	}
}
<?php
/**
 * Soundcloud manager
 *
 * @package AudioVideoBonusPack
 * @subpackage Soundcloud
 */

define( 'SOUNDCLOUD_CLIENT_ID', '248c57e7f54fb15812a11f34afd88c92' );

class AVSoundCloud extends AVSingleton {
	protected function __construct() {
		parent::__construct();

		add_action( 'media_buttons', array( $this, 'media_buttons' ) );
		add_action( 'load-post.php', array( $this, 'enqueue' ) );
		add_action( 'load-post-new.php', array( $this, 'enqueue' ) );

		add_action( 'wp_ajax_soundcloud-save-asset', array( $this, 'soundcloud_save_asset' ) );
	}

	function resolve_asset( $track_id ) {
		$service = "https://api.soundcloud.com/tracks/{$track_id}.json";
		$endpoint = add_query_arg( array(
			'consumer_key' => SOUNDCLOUD_CLIENT_ID
		), $service );

		$response = wp_remote_get( $endpoint );
		if ( empty( $response['body'] ) ) {
			return;
		}

		$data = json_decode( $response['body'], true );
		if ( empty( $data['stream_url'] ) ) {
			return;
		}

		$stream_url = add_query_arg( array(
			'consumer_key' => SOUNDCLOUD_CLIENT_ID
		), $data['stream_url'] );

		$stream_response = wp_remote_get( $stream_url, array( 'redirection' => 0 ) );

		if ( ! empty( $stream_response['headers']['location'] ) ) {
			return array(
				'title' => $data['title'],
				'audio' => $stream_response['headers']['location'],
				'image' => empty( $data['artwork_url'] ) ? '' : $data['artwork_url']
			);
		}
	}

	function sideload_assets( $data, $post_id ) {
		$file_array = array();
		$file_array['name'] = sanitize_title( $data['title'] );
		$file_array['tmp_name'] = download_url( $data['audio'] );

		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			return;
		}

		$overrides = array(
			'test_form' => false,
			'test_type' => false
		);

		$time = current_time( 'mysql' );
		if ( $post = get_post( $post_id ) ) {
			if ( substr( $post->post_date, 0, 4 ) > 0 ) {
				$time = $post->post_date;
			}
		}

		$sideload = wp_handle_sideload( $file_array, $overrides, $time );

		var_dump( $sideload );
		if ( empty( $sideload['url'] ) ) {
			return;
		}

		$url = $sideload['url'];
		$type = $sideload['type'];
		$file = $sideload['file'];
		$title = preg_replace( '/\.[^.]+$/', '', basename( $file ) );
		$content = '';

		$post_data = array();

		// Construct the attachment array.
		$attachment = array_merge( array(
			'post_mime_type' => $type,
			'guid' => $url,
			'post_parent' => $post_id,
			'post_title' => $title,
			'post_content' => $content,
		), $post_data );


		// Save the attachment metadata
		$id = wp_insert_attachment( $attachment, $file, $post_id );
		if ( ! is_wp_error( $id ) ) {
			wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
		}
	}

	function soundcloud_save_asset() {
		$response = $this->resolve_asset( 'https://api.soundcloud.com/tracks/' + $_REQUEST['track'] + '/stream' );
		if ( empty( $response ) ) {
			wp_send_json_error();
		}

		$this->sideload_assets( $response, $_REQUEST['post_ID'] );

		wp_send_json_success();
	}

	function media_buttons() {
?>
<a href="#" class="button add-soundcloud" title="<?php
	esc_attr_e( 'Add Soundcloud' );
?>"><?php
	_e( 'Add Soundcloud' );
?></a>
<?php
	}

	function enqueue() {
		add_action( 'print_media_templates', array( $this, 'print_templates' ) );

		$css_src = plugins_url( 'soundcloud.css', __FILE__ );
		$js_src = plugins_url( 'soundcloud.js', __FILE__ );
		wp_enqueue_style( 'av-soundcloud', $css_src );
		wp_register_script( 'soundcloud-sdk', 'http://connect.soundcloud.com/sdk.js' );
		wp_enqueue_script( 'av-soundcloud', $js_src, array( 'soundcloud-sdk', 'mce-view' ), '', true );
		wp_localize_script( 'av-soundcloud', '_soundcloudSettings', array(
			'apiClientId' => SOUNDCLOUD_CLIENT_ID
		) );
	}

	function print_templates() {
?>
<script type="text/html" id="tmpl-soundcloud-credentials">
	<div>
		<# if ( data.api.clientId ) { #>
		Client ID: <span>{{{ data.api.clientId }}}</span>
		<# } else { #>
		<span>You need a SoundCloud API key,
		 <a href="http://soundcloud.com/you/apps" target="_blank">go here</a>.
		</span>
		<# } #>
	</div>
</script>
<script type="text/html" id="tmpl-soundcloud-query-results">
	<# _.each( data.tracks, function (track) {
		var seconds, minutes;

		seconds = Math.round( ( track.duration / 1000 ) % 60 );
		minutes = Math.round( ( ( track.duration / 1000 ) - seconds ) / 60 );
		if ( seconds < 10 ) {
			seconds = "0" + seconds;
		}
	#>
	<li class="sc-track" data-id="{{ track.id }}">
		<# if ( track.artwork_url ) { #>
		<img class="sc-artwork" src="{{{ track.artwork_url }}}"/>
		<# } else { #>
		<span class="sc-artwork"></span>
		<# } #>
		<span class="meta">
			<a class="sound" data-id="{{ track.id }}" href="{{ track.permalink_url }}">{{{ track.title }}}</a>
			<span><strong>Posted:</strong> {{ track.created_at.split(' ')[0] }}</span>
			<span><strong>Duration:</strong> {{ minutes }}:{{ seconds }}</span>
			<# if ( track.favoritings_count ) { #>
			<span><strong>Favorites:</strong> {{ track.favoritings_count.toLocaleString() }}</span>
			<# } #>
			<# if ( track.genre ) { #>
			<span><strong>Genre:</strong> {{ track.genre }}</span>
			<# } #>
		</span>
		<span class="user">
			<# if ( track.user.avatar_url ) { #>
			<img class="sc-avatar" src="{{ track.user.avatar_url }}"/>
			<# } #>
			Posted by: <a data-id="{{ track.user.id }}" class="soundcloud-user" href="{{ track.user.permalink_url }}">{{ track.user.username }}</a>
		</span>
	</li>
	<# } ) #>
</script>
<script type="text/html" id="tmpl-default-state-single-mode">
	<div class="spin-wrapper"></div>
	<div class="soundcloud-wrapper"></div>
	<a data-id="{{ data.id }}" class="button sc-add-to-library hidden" href="#">Add to Media Library</a>
</script>
<script type="text/html" id="tmpl-soundcloud-recent-searches">
<# if ( data.terms.length ) { #>
<span>Recent Searches:</span>
	<# _.each( data.terms, function( term ) { #>
	<a class="query" href="#" data-query="{{ term }}">{{ term }}</a>
	<# } ) #>
<# } #>
</script>
<?php
	}
}
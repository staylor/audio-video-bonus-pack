<?php
/**
 * SoundCloud manager
 *
 * @package AudioVideoBonusPack
 * @subpackage SoundCloud
 */

// 248c57e7f54fb15812a11f34afd88c92

class AVSoundCloud extends AVSingleton {
	protected function __construct() {
		parent::__construct();

		$this->client_id = get_option( 'soundcloud_client_id' );
		$this->errors = array();

		add_action( 'media_buttons', array( $this, 'media_buttons' ) );
		add_action( 'load-post.php', array( $this, 'enqueue' ) );
		add_action( 'load-post-new.php', array( $this, 'enqueue' ) );

		add_action( 'wp_ajax_soundcloud-save-asset', array( $this, 'soundcloud_save_asset' ) );
		add_action( 'wp_ajax_soundcloud-register-key', array( $this, 'soundcloud_register_key' ) );
	}

	function _wp_handle_upload( &$file, $time ) {
		// Courtesy of php.net, the strings that describe the error indicated in $_FILES[{form field}]['error'].
		$upload_error_strings = array(
			false,
			__( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.' ),
			__( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.' ),
			__( 'The uploaded file was only partially uploaded.' ),
			__( 'No file was uploaded.' ),
			'',
			__( 'Missing a temporary folder.' ),
			__( 'Failed to write file to disk.' ),
			__( 'File upload stopped by extension.' )
		);

		// A successful upload will pass this test. It makes no sense to override this one.
		if ( isset( $file['error'] ) && $file['error'] > 0 ) {
			wp_send_json_error( $upload_error_strings[ $file['error'] ] );
		}

		list( $clean_url ) = explode( '?', $file['url'], 2 );
		$wp_filetype = wp_check_filetype( $clean_url );
		$ext = $wp_filetype['ext'];
		$type = $wp_filetype['type'];

		if ( ( ! $type || ! $ext ) && ! current_user_can( 'unfiltered_upload' ) ) {
			wp_send_json_error( 'Sorry, this file type is not permitted for security reasons.' );
		}
		if ( ! $type ) {
			$type = $file['type'];
		}

		if ( ! ( ( $uploads = wp_upload_dir( $time ) ) && false === $uploads['error'] ) ) {
			wp_send_json_error( $uploads['error'] );
		}

		$filename = wp_unique_filename( $uploads['path'], $file['name'] );
		if ( substr( $filename, strlen( $ext ) * -1 ) !== $ext ) {
			$filename .= '.' . $ext;
		}

		$new_file = $uploads['path'] . "/$filename";
		$move_new_file = @ rename( $file['tmp_name'], $new_file );

		if ( false === $move_new_file ) {
			if ( 0 === strpos( $uploads['basedir'], ABSPATH ) ) {
				$error_path = str_replace( ABSPATH, '', $uploads['basedir'] ) . $uploads['subdir'];
			} else {
				$error_path = basename( $uploads['basedir'] ) . $uploads['subdir'];
			}
			wp_send_json_error( sprintf( __( 'The uploaded file could not be moved to %s.' ), $error_path ) );
		}

		// Set correct file permissions.
		$stat = stat( dirname( $new_file ));
		$perms = $stat['mode'] & 0000666;
		@ chmod( $new_file, $perms );

		// Compute the URL.
		$url = $uploads['url'] . "/$filename";

		if ( is_multisite() ) {
			delete_transient( 'dirsize_cache' );
		}

		return array(
			'file' => $new_file,
			'url'  => $url,
			'type' => $type
		);
	}

	function resolve_asset( $track_id ) {
		$service = "https://api.soundcloud.com/tracks/{$track_id}.json";
		$endpoint = add_query_arg( array(
			'consumer_key' => $this->client_id
		), $service );

		$response = wp_remote_get( $endpoint );
		if ( empty( $response['body'] ) ) {
			wp_send_json_error( 'Soundcloud track endpoint failed.' );
		}

		$data = json_decode( $response['body'], true );
		if ( empty( $data['stream_url'] ) ) {
			wp_send_json_error( 'We found the track, but there is no URL for the stream.' );
		}

		$stream_url = add_query_arg( array(
			'consumer_key' => $this->client_id
		), $data['stream_url'] );

		$stream_response = wp_remote_get( $stream_url, array( 'redirection' => 0 ) );
		if ( ! empty( $stream_response['headers']['location'] ) ) {
			return array(
				'title' => $data['title'],
				'audio' => $stream_response['headers']['location'],
				'image' => empty( $data['artwork_url'] ) ? '' : $data['artwork_url']
			);
		} else {
			wp_send_json_error( "There is no Audio URL: SoundCloud doesn't want you to download this :(" );
		}
	}

	function sideload_asset( $title, $url, $post_id ) {
		$file_array = array();
		$file_array['url'] = $url;
		$file_array['name'] = sanitize_title( $title );
		$file_array['tmp_name'] = download_url( $url );

		if ( is_wp_error( $file_array['tmp_name'] ) ) {
			wp_send_json_error( 'Download the URL from SoundCloud failed.' );
		}

		$time = current_time( 'mysql' );
		if ( $post = get_post( $post_id ) ) {
			if ( substr( $post->post_date, 0, 4 ) > 0 ) {
				$time = $post->post_date;
			}
		}
		$sideload = $this->_wp_handle_upload( $file_array, $time );
		if ( empty( $sideload['url'] ) ) {
			wp_send_json_error( $sideload );
		}

		$url = $sideload['url'];
		$file = $sideload['file'];
		$type = $sideload['type'];

		return compact( 'url', 'file', 'type' );
	}

	function sideload_assets( $response, $post_id ) {
		if ( empty( $response['audio'] ) ) {
			wp_send_json_error( 'There was no audio URL returned by SoundCloud. Sucks.' );
		}

		$data = $this->sideload_asset( $response['title'], $response['audio'], $post_id );

		$post_data = array(
			'post_mime_type' => $data['type'],
			'guid' => $data['url'],
			'post_parent' => $post_id,
			'post_title' => $response['title'],
			'post_content' => '',
		);

		// Save the attachment metadata
		$audio_id = wp_insert_attachment( $post_data, $data['file'], $post_id );
		if ( ! is_wp_error( $audio_id ) ) {
			wp_update_attachment_metadata( $audio_id, wp_generate_attachment_metadata( $audio_id, $data['file'] ) );
		} else {
			wp_send_json_error( 'Error inserting the audio file into your media library.' );
		}

		if ( ! empty( $response['image'] ) ) {
			$image_data = $this->sideload_asset( $response['title'], $response['image'], $post_id );

			$post_data = array(
				'post_mime_type' => $image_data['type'],
				'guid' => $image_data['url'],
				'post_parent' => $audio_id,
				'post_title' => $response['title'],
				'post_content' => '',
			);

			// Save the attachment metadata
			$id = wp_insert_attachment( $post_data, $image_data['file'] );
			if ( ! is_wp_error( $id ) ) {
				wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $image_data['file'] ) );
			}

			set_post_thumbnail( $audio_id, $id );
			add_post_meta( $audio_id, 'uploaded_by', 'av_soundcloud' );
		}
	}

	function soundcloud_save_asset() {
		$response = $this->resolve_asset( $_REQUEST['track'] );
		if ( empty( $response ) ) {
			wp_send_json_error( 'We checked SoundCloud for this track, there was no response :(' );
		}

		$this->sideload_assets( $response, $_REQUEST['post_ID'] );

		wp_send_json_success();
	}

	function soundcloud_register_key() {
		$key = stripslashes( $_REQUEST['key'] );
		if ( empty( $key ) ) {
			wp_send_json_error( 'You API key is empty.' );
		}

		update_option( 'soundcloud_client_id', $key );

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
			'apiClientId' => $this->client_id
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

		<span class="soundcloud-api-submit">
			<input class="widefat" type="text" name="soundcloud_client_id" id="soundcloud-client-id" />
			<button class="button" id="soundcloud-client-id-submit">Register Key</button>
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
	<div class="soundcloud-wrapper"></div>
	<a data-id="{{ data.id }}" class="button sc-add-to-library hidden" href="#">Add to Media Library</a>
	<span class="spinner"></span>
	<div class="clear"></div>
	<p class="errors"></p>
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
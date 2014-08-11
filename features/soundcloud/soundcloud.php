<?php
/**
 * Soundcloud manager
 *
 * @package AudioVideoBonusPack
 * @subpackage Soundcloud
 */

define( 'SOUNDCLOUD_CLIENT_ID', '' );

class AVSoundCloud extends AVSingleton {
	protected function __construct() {
		parent::__construct();

		add_action( 'media_buttons', array( $this, 'media_buttons' ) );
		add_action( 'load-post.php', array( $this, 'enqueue' ) );
		add_action( 'load-post-new.php', array( $this, 'enqueue' ) );
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
	<li class="sc-track">
		<# if ( track.artwork_url ) { #>
		<img class="sc-artwork" src="{{{ track.artwork_url }}}"/>
		<# } else { #>
		<span class="sc-artwork"></span>
		<# } #>
		<span class="meta">
			<a class="sound" data-id="{{ track.id }}" href="{{{ track.permalink_url }}}">{{{ track.title }}}</a>
			<span><strong>Posted:</strong> {{{ track.created_at.split(' ')[0] }}}</span>
			<span><strong>Duration:</strong> {{{ minutes }}}:{{{ seconds }}}</span>
			<# if ( track.favoritings_count ) { #>
			<span><strong>Favorites:</strong> {{{ track.favoritings_count.toLocaleString() }}}</span>
			<# } #>
			<# if ( track.genre ) { #>
			<span><strong>Genre:</strong> {{{ track.genre }}}</span>
			<# } #>
		</span>
		<span class="user">
			<# if ( track.user.avatar_url ) { #>
			<img class="sc-avatar" src="{{{ track.user.avatar_url }}}"/>
			<# } #>
			Posted by: <a data-id="{{{ track.user.id }}}" class="soundcloud-user" href="{{ track.user.permalink_url }}">{{{ track.user.username }}}</a>
		</span>
	</li>
	<# } ) #>
</script>
<script type="text/html" id="tmpl-default-state-single-mode">
	<div class="spin-wrapper"></div>
	<div class="soundcloud-wrapper"></div>
</script>
<?php
	}
}
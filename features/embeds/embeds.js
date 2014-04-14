/*globals jQuery, _, wp */

(function ($, _, wp) {
	"use strict";

	var embed = {
		toView:  function( content ) {
			var match = wp.shortcode.next( 'embed', content );

			if ( ! match ) {
				return;
			}
			return {
				index:   match.index,
				content: match.content,
				options: {
					content: match.content,
					shortcode: match.shortcode
				}
			};
		},
		View: wp.mce.View.extend({
			className: 'oembed-data',
			initialize: function( options ) {
				this.players = [];
				this.content = options.content;
				this.parsed = false;
				_.bindAll( this, 'setHtml', 'setNode', 'fetch' );
				$(this).on( 'ready', this.setNode );
			},

			unbind: function() {
				var self = this;
				this.pauseAllPlayers();
				_.each( this.players, function (player) {
					self.removePlayer( player );
				} );
				this.players = [];
			},

			setNode: function (e, node) {
				this.node = node;
				if ( ! this.parsed ) {
					this.fetch();
				} else {
					this.parseMediaShortcodes();
				}
			},

			fetch: function () {
				$.ajax( {
					url : ajaxurl,
					type : 'post',
					data : {
						action: 'av-parse-content',
						post_ID: $( '#post_ID' ).val(),
						oembed_content: this.content
					}
				} ).done( this.setHtml );
			},

			setHtml: function (data) {
				this.parsed = data.content;
				$( this.node ).html( this.parsed );

				this.parseMediaShortcodes();
			},

			parseMediaShortcodes: function () {
				var self = this;
				$( '.wp-audio-shortcode, .wp-video-shortcode', this.node ).each(function (i, elem) {
					self.players.push( elem, self.mejsSettings );
				});
			},

			getHtml: function() {
				if ( ! this.parsed ) {
					return '';
				}
				return this.parsed;
			}
		}),

		edit: function() {}
	};
	_.extend( embed.View.prototype, wp.media.mixin );
	wp.mce.views.register( 'embed', embed );
}(jQuery, _, wp));
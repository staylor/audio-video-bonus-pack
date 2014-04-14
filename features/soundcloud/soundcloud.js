/*globals $, _, wp */

(function ($, _, Backbone, wp) {
	"use strict";

	var media = wp.media, embedCache = {},

	SoundCloudDetailsController = media.controller.State.extend({
		defaults: {
			id: 'av-soundcloud-details',
			title: 'Edit Details',
			toolbar: 'av-soundcloud-details',
			content: 'av-soundcloud-details',
			menu: 'av-soundcloud-details',
			router: false,
			priority: 60
		},

		initialize: function( options ) {
			this.soundcloud = options.soundcloud;
			media.controller.State.prototype.initialize.apply( this, arguments );
		}
	}),

	SoundCloudDetailsView = media.view.Settings.AttachmentDisplay.extend({
		className: 'av-soundcloud-details',
		template:  media.template( 'av-soundcloud-details' ),
		prepare: function() {
			return _.defaults( {
				model: this.model.toJSON()
			}, this.options );
		}
	}),

	SoundCloudDetailsFrame = media.view.MediaFrame.Select.extend({
		defaults: {
			id:      'av-soundcloud',
			url:     '',
			type:    'link',
			title:   'Soundcloud Details',
			priority: 120
		},

		initialize: function( options ) {
			this.soundcloud = new Backbone.Model( options.metadata );
			media.view.MediaFrame.Select.prototype.initialize.apply( this, arguments );
		},

		bindHandlers: function() {
			media.view.MediaFrame.Select.prototype.bindHandlers.apply( this, arguments );

			this.on( 'menu:create:av-soundcloud-details', this.createMenu, this );
			this.on( 'content:render:av-soundcloud-details', this.contentDetailsRender, this );
			this.on( 'menu:render:av-soundcloud-details', this.menuRender, this );
			this.on( 'toolbar:render:av-soundcloud-details', this.toolbarRender, this );
		},

		contentDetailsRender: function() {
			var view = new SoundCloudDetailsView({
				controller: this,
				model: this.state().soundcloud
			}).render();

			this.content.set( view );
		},

		menuRender: function( view ) {
			var lastState = this.lastState(),
				previous = lastState && lastState.id,
				frame = this;

			view.set({
				cancel: {
					text: 'Cancel Editing',
					priority: 20,
					click: function() {
						if ( previous ) {
							frame.setState( previous );
						} else {
							frame.close();
						}
					}
				},
				separateCancel: new media.View({
					className: 'separator',
					priority: 40
				})
			});
		},

		toolbarRender: function() {
			this.toolbar.set( new media.view.Toolbar({
				controller: this,
				items: {
					button: {
						style:    'primary',
						text:     'Update SoundCloud Embed',
						priority: 80,
						click:    function() {
							var controller = this.controller;
							controller.close();
							controller.state().trigger( 'update', controller.soundcloud.toJSON() );
							controller.setState( controller.options.state );
							controller.reset();
						}
					}
				}
			}) );
		},

		createStates: function() {
			this.states.add([
				new SoundCloudDetailsController( {
					soundcloud: this.soundcloud
				} )
			]);
		}
	}),

	soundcloud = {
		coerce : media.coerce,

		defaults : {
			url : ''
		},

		edit : function ( data ) {
			var frame, shortcode = wp.shortcode.next( 'soundcloud', data ).shortcode;
			frame = new SoundCloudDetailsFrame({
				frame: 'av-soundcloud',
				state: 'av-soundcloud-details',
				metadata: _.defaults( shortcode.attrs.named, soundcloud.defaults )
			});

			return frame;
		},

		shortcode : function( model ) {
			var self = this, content;

			_.each( this.defaults, function( value, key ) {
				model[ key ] = self.coerce( model, key );

				if ( value === model[ key ] ) {
					delete model[ key ];
				}
			});

			content = model.content;
			delete model.content;

			return new wp.shortcode({
				tag: 'soundcloud',
				attrs: model,
				content: content
			});
		}
	},
	soundcloudMce = {
		toView:  function( content ) {
			var match = wp.shortcode.next( 'soundcloud', content );

			if ( ! match ) {
				return;
			}

			return {
				index:   match.index,
				content: match.content,
				options: {
					shortcode: match.shortcode
				}
			};
		},
		View: wp.mce.View.extend({
			className: 'editor-av-soundcloud',
			template:  media.template( 'editor-av-soundcloud' ),
			initialize: function( options ) {
				this.shortcode = options.shortcode;
				this.attrs = _.defaults(
					this.shortcode.attrs.named,
					soundcloud.defaults
				);
				this.parsed = false;
				_.bindAll( this, 'setHtml', 'setNode', 'fetch' );
				$(this).bind('ready', this.setNode);
			},

			setNode: function (e, node) {
				this.node = node;
				if ( ! this.parsed ) {
					this.fetch();
				} else {
					this.replaceMarker();
				}
			},

			replaceMarker: function() {
				$( '.av-replace-soundcloud', this.node ).replaceWith( this.parsed );
			},

			fetch: function () {
				$.ajax( {
					url : ajaxurl,
					type : 'post',
					data : {
						action: 'av-parse-content',
						post_ID: $( '#post_ID' ).val(),
						oembed_content: this.attrs.url
					}
				} ).done( this.setHtml );
			},

			setHtml: function (data) {
				embedCache[ this.attrs.url ] = this.parsed = data.content;
				this.replaceMarker();
			},

			getHtml: function() {
				return this.template( this.attrs );
			}
		}),

		edit: function( node ) {
			var self = this, frame, data;

			data = window.decodeURIComponent( $( node ).attr('data-wpview-text') );
			frame = soundcloud.edit( data );
			frame.state('av-soundcloud-details').on( 'update', function( selection ) {
				var shortcode = soundcloud.shortcode( selection ).string();
				$( node ).attr( 'data-wpview-text', window.encodeURIComponent( shortcode ) );
				wp.mce.views.refreshView( self, shortcode );
				frame.detach();
			});
			frame.open();
		}
	};

	media.embedCache = embedCache;
	wp.mce.views.register( 'soundcloud', soundcloudMce );
}(jQuery, _, Backbone, wp));
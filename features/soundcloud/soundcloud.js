/*globals $, _, wp */

(function ($, _, Backbone, wp) {
	"use strict";

	var loaded = false,
		settings = _soundcloudSettings,
		soundcloud = {},
		media = wp.media;

	soundcloud.DefaultState = media.controller.State.extend({
		defaults: {
			id: 'default-state',
			title: 'SoundCloud',
			toolbar: 'default-state',
			content: 'default-mode',
			menu: 'default-state',
			router: false,
			priority: 60
		}
	});

	soundcloud.SearchField = media.View.extend({
		tagName:   'input',
		className: 'search',
		id: 'soundcloud-search',

		attributes: {
			type: 'search'
		},

		initialize: function() {
			this.lastQuery = this.controller.lastQuery || '';

			if ( settings.apiClientId ) {
				this.events = _.extend( this.events || {}, {
					'keyup' : 'query'
				} );
			}
		},

		queryDone: function( tracks ) {
			this.controller.trigger( 'soundcloud:query:update', tracks );
		},

		query: _.debounce( function( e ) {
			this.lastQuery = e.currentTarget.value;
			this.controller.trigger( 'soundcloud:query:start', this.lastQuery );
			SC.get('/tracks', { q: this.lastQuery }, _.bind( this.queryDone, this ) );
		}, 350 ),

		render: function() {
			media.View.prototype.render.apply( this, arguments );

			if ( this.lastQuery ) {
				this.$el.val( this.lastQuery );
			}
		}
	});

	soundcloud.QueryResults = media.View.extend({
		tagName: 'ul',
		className: 'query-results',
		template:  media.template( 'soundcloud-query-results' ),
		initialize: function() {
			this.tracks = new Backbone.Collection();
			if ( this.controller.tracks ) {
				this.tracks.reset( this.controller.tracks );
			}
			this.tracks.on( 'reset', this.render, this );
			this.listenTo( this.controller, 'soundcloud:query:update', this.updateTracks );
		},

		events : {
			'click .sound' : 'singleMode',
			'click .soundcloud-user' : 'userMode'
		},

		singleMode: function(e) {
			e.preventDefault();
			var single = this.tracks.where({
				id: $( e.currentTarget ).data( 'id' )
			}).shift();
			this.controller.trigger( 'soundcloud:query:single', single );
		},

		userMode: function(e) {
			e.preventDefault();
			var endpoint, tracks;

			this.userId = $( e.currentTarget ).data( 'id' );

			tracks = this.controller.users.get( this.userId );

			if ( tracks ) {
				this.userQueryDone( tracks, true );
			} else {
				endpoint = '/users/' + this.userId + '/tracks';
				this.controller.trigger( 'soundcloud:query:start' );

				SC.get( endpoint, {}, _.bind( this.userQueryDone, this ) );
			}
		},

		userQueryDone: function( tracks, silent ) {
			if ( silent ) {
				this.controller.trigger( 'soundcloud:query:user' );
			} else {
				this.controller.trigger( 'soundcloud:query:user', this.userId, tracks );
			}
			this.updateTracks( tracks );
		},

		updateTracks: function( tracks ) {
			this.tracks.reset( tracks );
		},

		prepare: function() {
			return {
				tracks: this.tracks.toJSON()
			};
		}
	}),

	soundcloud.DefaultState.View = media.View.extend({
		className: 'default-state',

		initialize: function() {
			this.createSpinner();
			this.createToolbar();
			this.createContent();

			media.View.prototype.initialize.apply( this, arguments );
		},

		createSpinner: function() {
			var spinner = new media.view.Spinner({
					priority: -60
				});

			this.listenTo(
				this.controller,
				'soundcloud:query:start',
				_.bind( spinner.show, spinner )
			);

			this.listenTo(
				this.controller,
				'soundcloud:query:update',
				_.bind( spinner.hide, spinner )
			);

			this.listenTo(
				this.controller,
				'soundcloud:query:user',
				_.bind( spinner.hide, spinner )
			);

			this.spinner = spinner;
		},

		createToolbar: function() {
			this.toolbar = new media.view.Toolbar({
				controller: this.controller
			});
			this.views.add( this.toolbar );

			this.toolbar.set( 'searchLabel', new media.view.Label({
				value: 'Search for Sounds: ',
				className: '',
				attributes: {
					'for': 'soundcloud-search'
				},
				controller: this.controller,
				priority: -75
			}) );

			this.toolbar.set( 'searchField', new soundcloud.SearchField({
				controller: this.controller,
				priority: -75
			}) );

			this.toolbar.set( 'spinner', this.spinner );
		},

		createContent: function() {
			this.views.add( new soundcloud.QueryResults({
				controller: this.controller
			}).render() );
		}
	});

	soundcloud.DefaultState.SingleMode = media.View.extend({
		className: 'default-state',
		template: media.template( 'default-state-single-mode' ),

		initialize: function() {
			this.item = this.controller.currentItem;
		},

		render: function() {
			media.View.prototype.render.apply( this, arguments );

			this.spinner = $('<span class="spinner" />');
			this.$( '.spin-wrapper' ).append( this.spinner[0] );
			this.spinner.show();

			wp.ajax.send( 'parse-embed', {
				data : {
					post_ID: media.view.settings.post.id,
					shortcode: '[embed]' + this.item.get( 'permalink_url' ) + '[/embed]'
				}
			} ).done( _.bind( this.renderResponse, this ) );
		},

		renderResponse: function( html ) {
			this.$( '.soundcloud-wrapper' ).html( html );
			this.spinner.hide();
		}
	});

	soundcloud.ApiCredentials = media.View.extend({
		className: 'soundcloud-credentials',
		template: media.template( 'soundcloud-credentials' ),

		prepare: function() {
			return {
				api: {
					clientId: settings.apiClientId
				}
			};
		}
	});

	soundcloud.Frame = media.view.MediaFrame.Select.extend({
		defaults: {
			id: 'av-soundcloud'
		},

		bindHandlers: function() {
			media.view.MediaFrame.Select.prototype.bindHandlers.apply( this, arguments );

			this.users = new Backbone.Model();

			this.on( 'menu:create:default-state', this.createMenu, this );
			this.on( 'menu:render:default-state', this.menuRender, this );

			this.on( 'content:create:default-mode', this.defaultMode, this );
			this.on( 'content:create:single-mode', this.singleMode, this );

			this.on( 'soundcloud:query:start',  this.setQuery, this );
			this.on( 'soundcloud:query:update', this.setTracks, this );
			this.on( 'soundcloud:query:single', this.setSingle, this );
			this.on( 'soundcloud:query:user',   this.setUser, this );
		},

		setQuery: function( query ) {
			if ( ! query ) {
				return;
			}
			this.lastQuery = query;
		},

		setTracks: function( tracks ) {
			this.tracks = tracks;
		},

		setSingle: function( model ) {
			this.currentItem = model;
			this.content.mode( 'single-mode' );
		},

		setUser: function( userId, tracks ) {
			if ( ! userId ) {
				return;
			}
			this.lastQuery = '';
			this.lastUser = userId;
			this.users.set( userId, tracks );
			this.setTracks( tracks );
		},

		defaultMode: function( contentRegion ) {
			contentRegion.view = new soundcloud.DefaultState.View({
				controller: this
			});
		},

		singleMode: function( contentRegion ) {
			contentRegion.view = new soundcloud.DefaultState.SingleMode({
				controller: this
			});

			this.menu.render();
		},

		menuRender: function( view ) {
			var self = this, views = {};

			if ( 'single-mode' === this.content.mode() ) {
				views.goBack = {
					text:     'Go Back',
					priority: 20,
					click:    function() {
						self.content.mode( 'default-mode' );
						self.menu.render();
					}
				};
			}
			
			views.separateCancel = new media.View({
				className: 'separator',
				priority: 40
			});
			views.apiCredentials = new soundcloud.ApiCredentials({
				priority: 80
			});

			view.set( views );
		},

		createStates: function() {
			this.states.add([
				new soundcloud.DefaultState()
			]);
		}
	});

	function init() {
		var $body = $( 'body' );

		$( '.add-soundcloud' ).on( 'click', function(e) {
			e.preventDefault();

			$body.addClass( 'soundcloud-active' );

			media.frame = new soundcloud.Frame({
				frame: 'av-soundcloud',
				state: 'default-state'
			});
			media.frame.on( 'close', function() {
				$body.removeClass( 'soundcloud-active' );
			} );

			media.frame.open();

			if ( ! loaded ) {
				SC.initialize({
					client_id: settings.apiClientId
				});
				loaded = true;
			}
		} );
	}

	$( init );
}(jQuery, _, Backbone, wp));
/*globals $, _, wp */

(function ($, _, Backbone, wp) {
	"use strict";

	var settings = _soundcloudSettings,
		soundcloud = {
			loaded : false,
			queryLoaded : false,
			querySetting : 'sc_queries'
		},
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
			this.lastQuery = '';
			if ( ! soundcloud.queryLoaded ) {
				this.lastQuery = this.controller.getLastQuery();
			}

			if ( settings.apiClientId ) {
				this.events = _.extend( this.events || {}, {
					'keyup' : 'query'
				} );
			}
			this.listenTo( this.controller, 'soundcloud:query:proxy', this.proxy );
			this.listenTo( this.controller, 'soundcloud:query:user',  this.unset );
		},

		proxy: function( e ) {
			this.query( e, true );
		},

		unset: function() {
			this.lastQuery = '';
			this.$el.val( this.lastQuery );
		},

		queryDone: function( tracks ) {
			this.controller.trigger( 'soundcloud:query:update', tracks );
		},

		query: _.debounce( function( e, proxy ) {
			var value = e.currentTarget.value || $( e.currentTarget ).data( 'query' );
			if ( ! value ) {
				return;
			}
			this.lastQuery = value;
			if ( proxy ) {
				this.$el.val( this.lastQuery );
			}
			this.controller.trigger( 'soundcloud:query:start', this.lastQuery );
			SC.get( '/tracks', { q: this.lastQuery }, _.bind( this.queryDone, this ) );
		}, 350 ),

		render: function() {
			media.View.prototype.render.apply( this, arguments );

			if ( ! soundcloud.queryLoaded && this.lastQuery ) {
				soundcloud.queryLoaded = true;
				this.$el.val( this.lastQuery );
				this.query( { currentTarget: this.$el[0] } );
			}
		}
	});

	soundcloud.RecentSearches = media.View.extend({
		className:  'recent-searches',
		template:   media.template( 'soundcloud-recent-searches' ),
		initialize: function() {
			this.terms = this.controller.getSavedQueries();
		},

		events: {
			'click .query' : 'proxySearch'
		},

		proxySearch: function( e ) {
			e.preventDefault();
			this.controller.trigger( 'soundcloud:query:proxy', e );
		},

		prepare: function() {
			return {
				terms: this.terms
			};
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
			'click .soundcloud-user' : 'userMode',
			'click li' : 'singleMode'
		},

		singleMode: function( e ) {
			e.preventDefault();
			e.stopPropagation();

			var single = this.tracks.where({
				id: $( e.currentTarget ).data( 'id' )
			}).shift();
			this.controller.trigger( 'soundcloud:query:single', single );
		},

		userMode: function( e ) {
			var endpoint, tracks;
			e.preventDefault();
			e.stopPropagation();

			this.userId = $( e.currentTarget ).data( 'id' );
			tracks = this.controller.users.get( this.userId );

			this.controller.trigger( 'soundcloud:query:start' );

			if ( tracks ) {
				this.userQueryDone( tracks, true );
			} else {
				endpoint = '/users/' + this.userId + '/tracks';
				SC.get( endpoint, {}, _.bind( this.userQueryDone, this ) );
			}
		},

		userQueryDone: function( tracks, silent ) {
			if ( silent ) {
				this.controller.trigger( 'soundcloud:query:user', this.userId );
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

	soundcloud.SingleMode = media.View.extend({
		className: 'default-state-single',
		template: media.template( 'default-state-single-mode' ),

		initialize: function() {
			this.item = this.controller.currentItem;
		},

		prepare: function() {
			return this.item.toJSON();
		},

		events: {
			'click .sc-add-to-library': 'addToLibrary'
		},

		addToLibrary: function(e) {
			e.preventDefault();
			wp.ajax.send( 'soundcloud-save-asset', {
				data : {
					post_ID: media.view.settings.post.id,
					track: this.item.get( 'id' )
				}
			} ).done( _.bind( this.handleResponse, this ) );
		},

		handleResponse: function() {
			console.log( arguments );
		},

		render: function() {
			media.View.prototype.render.apply( this, arguments );

			this.spinner = $( '<span class="spinner" />' );
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
			this.$( '.sc-add-to-library' ).removeClass( 'hidden' );
			this.spinner.hide();
		}
	});

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

			this.toolbar.set( 'recentSearches', new soundcloud.RecentSearches({
				controller: this.controller,
				priority: -50
			}) );
		},

		createContent: function() {
			if ( 'single-mode' === this.controller.content.mode() ) {
				this.content = new soundcloud.SingleMode({
					controller: this.controller
				});
			} else {
				this.content = new soundcloud.QueryResults({
					controller: this.controller
				});
			}
			this.views.add( this.content );
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

//			this.on( 'all', function() {
//				console.log( arguments );
//			} );

			this.on( 'title:create:single-mode',    this.createTitle, this );
			this.on( 'title:render:default',        this.renderTitle, this );
			this.on( 'title:render:single-mode',    this.renderSingleTitle, this );

			this.on( 'menu:create',   this.createMenu, this );
			this.on( 'menu:render',   this.menuRender, this );

			this.on( 'content:create:default-mode', this.defaultMode, this );
			this.on( 'content:create:single-mode',  this.defaultMode, this );

			this.on( 'soundcloud:query:start',      this.setQuery, this );
			this.on( 'soundcloud:query:start',      this.setContent, this );
			this.on( 'soundcloud:query:update',     this.setTracks, this );
			this.on( 'soundcloud:query:single',     this.setSingle, this );
			this.on( 'soundcloud:query:user',       this.setUser, this );
			this.on( 'soundcloud:query:proxy',      this.setContent, this );
		},

		unencodeQuery: function( query ) {
			return query.replace( '0_0', ' ' );
		},

		encodeQuery: function( query ) {
			return query.replace( ' ', '0_0' );
		},

		getSavedQueries: function() {
			var setting = getUserSetting( soundcloud.querySetting );
			if ( ! setting ) {
				return [];
			}
			return this.unencodeQuery( setting ).split( '__' );
		},

		getLastQuery: function() {
			var saved;
			if ( ! soundcloud.queryLoaded ) {
				saved = this.getSavedQueries();
				if ( saved.length ) {
					this.lastQuery = _.first( saved );
				}
			}
			return this.lastQuery;
		},

		setQuery: function( query ) {
			var queries, saved, similar, self = this;
			if ( ! query ) {
				return;
			}

			this.lastQuery = query;
			saved = this.getSavedQueries();
			if ( ! saved.length ) {
				queries = [ this.lastQuery ];
			} else {
				queries = _.first( saved, 3 );
				if ( -1 === $.inArray( this.lastQuery, queries ) ) {
					similar = _.filter( queries, function( query ) {
						return self.lastQuery.indexOf( query ) > -1;
					} );
					queries = _.difference( queries, similar );
					queries.unshift( this.lastQuery );
				}
			}
			setUserSetting( soundcloud.querySetting, this.encodeQuery( queries.join( '__' ) ) );
		},

		setTracks: function( tracks ) {
			this.tracks = tracks;
		},

		renderTitle: function( view ) {
			view.$el.html( 'SoundCloud' );
		},

		renderSingleTitle: function( view ) {
			var title = this.currentItem.get( 'title' );
			view.$el.html( 'Viewing: ' + title );
		},

		setSingle: function( model ) {
			this.currentItem = model;
			this.title.mode( 'single-mode' );
			this.content.mode( 'single-mode' );
			this.menu.mode( 'single-mode' );
		},

		setContent: function() {
			if ( 'single-mode' === this.content.mode() ) {
				this.content.mode( 'default-mode' );
				this.title.mode( 'default' );
				this.menu.mode( 'default' );
			}
		},

		setUser: function( userId, tracks ) {
			this.lastQuery = '';
			this.lastUser = userId;

			if ( ! tracks ) {
				return;
			}

			this.users.set( userId, tracks );
			this.setTracks( tracks );
		},

		defaultMode: function( contentRegion ) {
			contentRegion.view = new soundcloud.DefaultState.View({
				controller: this
			});
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
		var $body = $( document.body );

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

			if ( ! soundcloud.loaded ) {
				SC.initialize({
					client_id: settings.apiClientId
				});
				soundcloud.loaded = true;
			}
		} );

		$( '.add-soundcloud' ).click();
	}

	$( init );
}(jQuery, _, Backbone, wp));
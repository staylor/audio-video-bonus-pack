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
			id: 'sc-default',
			title: 'SoundCloud',
			toolbar: 'default-state',
			content: 'default-mode',
			menu: 'default-state',
			router: false,
			priority: 60
		}
	});

	soundcloud.SingleState = media.controller.State.extend({
		defaults: {
			id: 'sc-single',
			title: 'SoundCloud',
			toolbar: 'default-state',
			content: 'single-mode',
			menu: false,
			router: false,
			priority: 60
		}
	});

	soundcloud.UserState = media.controller.State.extend({
		defaults: {
			id: 'sc-user',
			title: 'SoundCloud',
			toolbar: 'default-state',
			content: 'user-mode',
			menu: false,
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
			this.lastQuery = this.controller.getLastQuery();

			if ( settings.apiClientId ) {
				this.events = _.extend( this.events || {}, {
					'keyup' : 'query'
				} );
			}
			this.listenTo( this.controller, 'soundcloud:query:proxy', this.proxy );
		},

		proxy: function( e ) {
			this.query( e, true );
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
			if ( settings.apiClientId && this.lastQuery ) {
				this.$el.val( this.lastQuery );
				if ( ! soundcloud.queryLoaded ) {
					soundcloud.queryLoaded = true;
					this.query( { currentTarget: this.$el[0] } );
				}
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
			var controller = this.controller;

			this.tracks = new Backbone.Collection();
			if ( ! settings.apiClientId ) {
				return;
			}

			if ( controller.get( 'lastUser' ) ) {
				this.tracks.reset( controller.users.get( controller.get( 'lastUser' ) ) );
			} else if ( this.controller.get( 'tracks' ) ) {
				this.tracks.reset( controller.get( 'tracks' ) );
			}
			this.tracks.on( 'reset', this.render, this );
			this.listenTo( controller, 'soundcloud:query:update', this.updateTracks );
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
			this.controller.setSingle( single );
		},

		userMode: function( e ) {
			var endpoint, tracks, elem;
			e.preventDefault();
			e.stopPropagation();

			elem = $( e.currentTarget );
			this.userId = elem.data( 'id' );
			this.userName = elem.text();
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
			this.controller.set( 'lastUser', this.userId );
			this.controller.set( 'lastUserName', this.userName );

			if ( silent ) {
				this.controller.trigger( 'soundcloud:query:user' );
			} else {
				this.controller.trigger( 'soundcloud:query:user', tracks );
			}
			if ( tracks.errors ) {
				alert( 'something is wrong' );
			} else {
				this.updateTracks( tracks );
			}
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
			this.item = this.controller.get( 'currentItem' );
		},

		prepare: function() {
			return this.item;
		},

		events: {
			'click .sc-add-to-library': 'addToLibrary',
			'click .go-to-library':     'goToLibrary'
		},

		goToLibrary: function() {
			this.controller.setState( 'audio-library' );
		},

		addToLibrary: function(e) {
			e.preventDefault();

			this.spinner.show();

			wp.ajax.send( 'soundcloud-save-asset', {
				data : {
					post_ID: media.view.settings.post.id,
					track: this.item.id
				}
			} ).always( _.bind( this.handleResponse, this ) );
		},

		handleResponse: function( error ) {
			if ( error ) {
				this.$( '.errors' ).html( error );
				this.$( '.button' ).addClass( 'disabled' );
			} else {
				this.$( '.errors' ).html( '<a href="#" class="go-to-library">View SoundCloud Download :)</a>' );
			}

			this.spinner.hide();
		},

		render: function() {
			media.View.prototype.render.apply( this, arguments );

			this.spinner = this.$( '.spinner' );
			this.spinner.show();

			wp.ajax.send( 'parse-embed', {
				data : {
					post_ID: media.view.settings.post.id,
					shortcode: '[embed]' + this.item.permalink_url + '[/embed]'
				}
			} ).done( _.bind( this.renderResponse, this ) );
		},

		renderResponse: function( data ) {
			this.$( '.soundcloud-wrapper' ).html( data.body );
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
			var state = this.controller.state().id;

			if ( 'sc-single' === state ) {
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

		events: {
			'click .button' : 'submitCredentials'
		},

		submitCredentials: function() {
			this.key = this.$( '#soundcloud-client-id' ).val();

			wp.ajax.send( 'soundcloud-register-key', {
				data : {
					key: this.key
				}
			} ).done( _.bind( this.handleResponse, this ) );
		},

		handleResponse: function() {
			settings.apiClientId = this.key;
			this.controller.resetKey();
		},

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

		get: function( key ) {
			return this.model.get( key );
		},

		set: function( key, value ) {
			return this.model.set( key, value );
		},

		unset: function( key ) {
			return this.model.unset( key );
		},

		initialize: function() {
			media.view.MediaFrame.Select.prototype.initialize.apply( this, arguments );

			this.users = new Backbone.Model();
			this.model = new Backbone.Model();
		},

		bindHandlers: function() {
			media.view.MediaFrame.Select.prototype.bindHandlers.apply( this, arguments );

//			this.on( 'all', function() {
//				console.log( arguments );
//			} );

			this.on( 'title:create:single',    this.createTitle, this );
			this.on( 'title:create:user',      this.createTitle, this );
			this.on( 'title:render:default',        this.renderTitle, this );
			this.on( 'title:render:single',    this.renderSingleTitle, this );
			this.on( 'title:render:user',      this.renderUserTitle, this );

			this.on( 'menu:create',   this.createMenu, this );
			this.on( 'menu:render',   this.menuRender, this );

			this.on( 'content:create:default-mode',    this.defaultMode, this );
			this.on( 'content:create:single-mode',     this.defaultMode, this );
			this.on( 'content:create:user-mode',       this.defaultMode, this );
			this.on( 'content:deactivate:default-mode',this.deactivateDefault, this );
			this.on( 'content:deactivate:single-mode', this.deactivateSingle, this );
			this.on( 'content:deactivate:user-mode',   this.deactivateUser, this );

			this.on( 'soundcloud:query:start',      this.setQuery, this );
			this.on( 'soundcloud:query:start',      this.setContent, this );
			this.on( 'soundcloud:query:update',     this.setTracks, this );
			this.on( 'soundcloud:query:user',       this.setUser, this );
			this.on( 'soundcloud:query:proxy',      this.setUserContent, this );

			this.on( 'toolbar:render:audio-files',  this.setBrowseButton, this );
		},

		setBrowseButton: function() {
			this.toolbar.set( new media.view.Toolbar({
				controller: this,
				items: {
					button: {
						style:    'primary',
						text:     'Insert Sound',
						priority: 80,
						click:    function() {
							var controller = this.controller,
								state = controller.state(),
								attachment = state.get( 'selection' ).single(),
								shortcode;

							if ( ! attachment ) {
								return;
							}

							controller.close();
							controller.reset();

							shortcode = wp.media.audio.shortcode({
								src: attachment.get( 'url' )
							});
							media.editor.insert( shortcode.string() );
						}
					}
				}
			}) );
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
					this.set( 'lastQuery', _.first( saved ) );
				}
			}
			return this.get( 'lastQuery' );
		},

		setQuery: function( query ) {
			var queries, saved, similar, self = this;
			if ( ! query ) {
				return;
			}

			this.set( 'lastQuery', query );
			saved = this.getSavedQueries();
			if ( ! saved.length ) {
				queries = [ query ];
			} else {
				queries = _.first( saved, 3 );
				if ( -1 === $.inArray( query, queries ) ) {
					similar = _.filter( queries, function( query ) {
						return self.get( 'lastQuery' ).indexOf( query ) > -1;
					} );
					queries = _.difference( queries, similar );
					queries.unshift( query );
				}
			}
			setUserSetting( soundcloud.querySetting, this.encodeQuery( queries.join( '__' ) ) );
		},

		setTracks: function( tracks ) {
			this.set( 'tracks', tracks );
		},

		renderTitle: function( view ) {
			view.$el.html( 'SoundCloud' );
		},

		renderSingleTitle: function( view ) {
			var title = this.get( 'currentItem' ).title;
			view.$el.html( 'Viewing: ' + title );
		},

		renderUserTitle: function( view ) {
			view.$el.html( 'Viewing User: ' + this.get( 'lastUserName' ) );
		},

		resetKey: function() {
			this.apiInit();

			this.content._mode = 'refresh';
			this.content.mode( 'default-mode' );
			this.title._mode = 'refresh';
			this.title.mode( 'default' );
			this.menu.render();
		},

		setSingle: function( model ) {
			this.set( 'currentItem', model.toJSON() );
			this.setState( 'sc-single' );
			this.title.mode( 'single' );
			this.menu.render();
		},

		deactivateSingle: function() {
			this.unset( 'currentItem' );
		},

		setContent: function() {
			this.setState( this.options.state );
			this.menu.render();
		},

		setUserContent: function() {
			this.setState( 'sc-user' );
			this.menu.render();
		},

		deactivateDefault: function() {
			soundcloud.queryLoaded = true;
		},

		setUser: function( tracks ) {
			if ( ! tracks ) {
				return;
			}

			this.users.set( this.get( 'lastUser' ), tracks );
			this.setState( 'sc-user' );
			this.title.mode( 'user' );
		},

		deactivateUser: function() {
			this.unset( 'lastUser' );
			this.unset( 'lastUserName' );
		},

		defaultMode: function( contentRegion ) {
			contentRegion.view = new soundcloud.DefaultState.View({
				controller: this
			});
		},

		menuRender: function( view ) {
			var self = this, views = {}, state = this.state().id,
				nonDefaultMode = this.options.state !== state,
				lastState = this.lastState(),
				previous = lastState && lastState.id;

			console.log( 'RENDER' );
			if ( nonDefaultMode ) {
				views.goBack = {
					text:     'Go Back',
					priority: 20,
					click:    function() {
						if ( previous ) {
							self.setState( previous );
						} else {
							self.setState( self.options.state );
						}
					}
				};
			}

			views.separateCancel = new media.View({
				className: 'separator',
				priority: 40
			});

			views.apiCredentials = new soundcloud.ApiCredentials({
				priority: 80,
				controller: this
			});

			view.set( views );
		},

		createStates: function() {
			this.states.add([
				new soundcloud.DefaultState(),
				new soundcloud.SingleState(),
				new soundcloud.UserState(),
				new media.controller.MediaLibrary( {
					type: 'audio',
					id: 'audio-library',
					title: 'Audio Files',
					content: 'browse',
					filterable: false,
					router: false,
					toolbar: 'audio-files',
					menu: 'default-state'
				} )
			]);
		},

		apiInit: function() {
			if ( ! soundcloud.loaded && settings.apiClientId ) {
				SC.initialize({
					client_id: settings.apiClientId
				});
				soundcloud.loaded = true;
			}
		}
	});

	function init() {
		var $body = $( document.body );

		$( '.add-soundcloud' ).on( 'click', function(e) {
			e.preventDefault();

			$body.addClass( 'soundcloud-active' );

			media.frame = new soundcloud.Frame({
				frame: 'av-soundcloud',
				state: 'sc-default'
			});
			media.frame.on( 'close', function() {
				$body.removeClass( 'soundcloud-active' );
			} );
			media.frame.apiInit();
			media.frame.open();
		} );

		//$( '.add-soundcloud' ).click();
	}

	$( init );
}(jQuery, _, Backbone, wp));
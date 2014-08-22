/*globals $, _, wp */

(function ($, _, Backbone, wp) {
	var s = {},
		media = wp.media;

	s.DefaultState = media.controller.State.extend({
		defaults: {
			id:      'default-state',
			title:   'Default State Title',
			content: 'default',
			menu:    'default',
			router:  'default',
			toolbar: 'default'
		}
	});

	s.SecondState = media.controller.State.extend({
		defaults: {
			id:      'second-state',
			title:   'Second State Title',
			content: 'initial',
			menu:    'default',
			router:  'second',
			toolbar: 'default'
		}
	});

	s.OtherView = media.View.extend({
		initialize: function() {
			this.title = this.options.title;
		},
		render: function() {
			if ( 'initial' === this.controller.content.mode() ) {
				this.$el.html( 'OTHER CONTENT State 2' );
			} else {
				this.$el.html( 'OTHER CONTENT' );
			}

			return this;
		}
	});

	s.AnotherView = media.View.extend({
		initialize: function() {
			this.title = this.options.title;
		},
		render: function() {
			if ( 'second2' === this.controller.content.mode() ) {
				this.$el.html( 'ANOTHER CONTENT State 2' );
			} else {
				this.$el.html( 'ANOTHER CONTENT' );
			}
			return this;
		}
	});

	s.Frame = media.view.MediaFrame.extend({
		initialize: function() {
			media.view.MediaFrame.prototype.initialize.apply( this, arguments );

			this.createStates();
			this.bindHandlers();
		},

		bindHandlers: function() {
//			this.on( 'all', function() {
//				console.log( arguments );
//			} );

			this.on( 'menu:create',             this.createMenu, this );
			this.on( 'menu:render',             this.renderMenu, this );

			this.on( 'title:create',            this.createTitle, this );
			this.on( 'title:render',            this.renderTitle, this );

			this.on( 'router:create',           this.createRouter, this );
			this.on( 'router:render:default',   this.defaultRouter, this );
			this.on( 'router:render:second',    this.secondRouter, this );

			this.on( 'content:create',          this.defaultCreate, this );

			// State #1
			this.on( 'content:create:default',  this.defaultContent, this );
			this.on( 'content:render:default',  this.renderContent, this );
			this.on( 'content:create:example2', this.otherContent, this );
			this.on( 'content:create:example3', this.anotherContent, this );
			// State #2
			this.on( 'content:create:initial',  this.otherContent, this );
			this.on( 'content:create:second2',  this.anotherContent, this );

			this.on( 'toolbar:create:default',  this.createToolbar, this );
		},

		defaultRouter: function( routerView ) {
			routerView.set({
				'default': {
					text:     'Example 1',
					priority: 20
				},
				example2: {
					text:     'Example 2',
					priority: 40
				},
				example3: {
					text:     'Example 3',
					priority: 60
				}
			});
		},

		secondRouter: function( routerView ) {
			routerView.set({
				initial: {
					text:     'Second 1',
					priority: 20
				},
				second2: {
					text:     'Second 2',
					priority: 40
				}
			});
		},

		renderMenu: function( view ) {
			view.set({
				'sep': new media.View({
					className: 'separator',
					priority: 100
				})
			});
		},

		defaultCreate: function( contentRegion ) {
			this.nextTitle = contentRegion.view.title || this.state().get( 'title' );
			this.title.mode( this.content.mode() || 'default' );
		},

		renderTitle: function( view ) {
			view.$el.text( this.nextTitle || this.state().get( 'title' ) );
			this.nextTitle = '';
		},

		defaultContent: function( contentRegion ) {
			contentRegion.view = new media.View();
		},

		renderContent: function( view ) {
			view.$el.html( 'DEFAULT CONTENT' );
		},

		otherContent: function( contentRegion ) {
			contentRegion.view = new s.OtherView({
				title: 'Other View',
				controller: this
			});
		},

		anotherContent: function( contentRegion ) {
			contentRegion.view = new s.AnotherView({
				title: 'Another View',
				controller: this
			});
		},

		createToolbar: function( toolbar ) {
			toolbar.view = new media.view.Toolbar({
				controller: this
			});
		},

		createStates: function() {
			this.states.add([
				new s.DefaultState({
					priority: 20
				}),

				new s.SecondState({
					priority: 40
				})
			]);
		}
	});

	function init() {
		media.frame = new s.Frame({
			state: 'default-state'
		});
		media.frame.open();
	}

	$( init );
}(jQuery, _, Backbone, wp));
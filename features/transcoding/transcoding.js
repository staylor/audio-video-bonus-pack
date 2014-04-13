/*globals window, document, $, jQuery */

(function ($, _, Backbone) {
	"use strict";

	var AVQueueItemView = Backbone.View.extend({
		template: wp.template('av-queue-item'),
		render : function () {
			return this.template({
				model: this.model.toJSON()
			});
		}
	}),

	AVQueueView = Backbone.View.extend({
		initialize: function () {
			_.bindAll(this, 'render');
			this.collection.on( 'reset', this.render );

			this.$message = $('#av-queued-message');
		},

		render: function () {
			var self = this;

			this.$el.empty();

			if ( this.collection.models.length ) {
				this.$message.show();
			} else {
				this.$message.hide();
			}

			_.each( this.collection.models, function (item) {
				self.$el.append( new AVQueueItemView({
					model: item
				}).render() );
			});
		}
	}),

	AVQueueController = Backbone.Model.extend({
		initialize : function () {
			this.items = new Backbone.Collection();

			this.view = new AVQueueView({
				el: $('#av-queued-items').get(0),
				collection: this.items
			});
		}
	}),

	AVEncodeItemView = Backbone.View.extend({
		template: wp.template('av-encode-item'),
		render: function () {
			return this.template({
				model: this.model.toJSON()
			});
		}
	}),

	AVEncodeView = Backbone.View.extend({
		initialize: function () {
			_.bindAll(this, 'render');
			this.collection.on( 'reset', this.render );

			this.$message = $('#av-encode-message');
		},

		render: function () {
			var self = this;

			this.$el.empty();

			if ( this.collection.models.length ) {
				this.$message.show();
			} else {
				this.$message.hide();
			}

			_.each(this.collection.models, function (item) {
				self.$el.append( new AVEncodeItemView({
					model: item
				}).render() );
			});
		}
	}),

	AVEncodeController = Backbone.Model.extend({
		initialize: function () {
			this.items = new Backbone.Collection();

			this.view = new AVEncodeView({
				el: $('#av-encode-items').get(0),
				collection: this.items
			});
		}
	}),

	AVProgressController = Backbone.Model.extend({
		initialize: function () {
			_.bindAll(this, 'read', 'fetch');

			this.$view = $('#av-queue');

			this.queue = new AVQueueController();
			this.encodes = new AVEncodeController();

			this.fetch();
		},

		fetch : function () {
			$.ajax({
				url : ajaxurl,
				cache: false,
				data : {
					action : 'av-read-queue'
				}
			}).done(this.read);
		},

		read : function (response) {
			var data = $.parseJSON( response ), timeout = 3000;

			this.encodes.items.reset( data.encodes );
			this.queue.items.reset( data.queue );

			if ( ! data.encodes.length && ! data.queue.length ) {
				timeout = 9000;
				this.$view.hide();
			} else {
				this.$view.show();
			}
			setTimeout(this.fetch, timeout);
		}
	});

    $(document).ready(function () {
		new AVProgressController();
    });

}(jQuery, _, Backbone));
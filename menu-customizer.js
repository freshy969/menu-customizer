/* global _wpCustomizeMenusSettings, confirm, alert, wpNavMenu, console */
( function( api, wp, $ ) {
	'use strict';

	/**
	 * Set up wpNavMenu for drag and drop.
	 */
	wpNavMenu.originalInit = wpNavMenu.init;
	wpNavMenu.options.menuItemDepthPerLevel = 15;
	wpNavMenu.options.sortableItems         = '.customize-control-nav_menu_item';
	wpNavMenu.init = function() {
		this.jQueryExtensions();
	};

	api.Menus = api.Menus || {};

	// Link settings.
	api.Menus.data = {
		nonce: '',
		itemTypes: {
			taxonomies: {},
			postTypes: {}
		},
		l10n: {},
		menuItemTransport: 'postMessage',
		phpIntMax: 0,
		defaultSettingValues: {
			nav_menu: {},
			nav_menu_item: {}
		}
	};
	if ( typeof _wpCustomizeMenusSettings !== 'undefined' ) {
		$.extend( api.Menus.data, _wpCustomizeMenusSettings );
	}

	/**
	 * Newly-created Nav Menus and Nav Menu Items have negative integer IDs which
	 * serve as placeholders until Save & Publish happens.
	 *
	 * @return {number}
	 */
	api.Menus.generatePlaceholderAutoIncrementId = function() {
		return -Math.ceil( api.Menus.data.phpIntMax * Math.random() );
	};

	/**
	 * wp.customize.Menus.AvailableItemModel
	 *
	 * A single available menu item model. See PHP's WP_Customize_Nav_Menu_Item_Setting class.
	 *
	 * @constructor
	 * @augments Backbone.Model
	 */
	api.Menus.AvailableItemModel = Backbone.Model.extend( $.extend(
		{
			id: null, // This is only used by Backbone.
		},
		api.Menus.data.defaultSettingValues.nav_menu_item
	) );

	/**
	 * wp.customize.Menus.AvailableItemCollection
	 *
	 * Collection for available menu item models.
	 *
	 * @constructor
	 * @augments Backbone.Model
	 */
	api.Menus.AvailableItemCollection = Backbone.Collection.extend({
		model: api.Menus.AvailableItemModel,

		sort_key: 'order',

		comparator: function( item ) {
			return -item.get( this.sort_key );
		},

		sortByField: function( fieldName ) {
			this.sort_key = fieldName;
			this.sort();
		}
	});
	api.Menus.availableMenuItems = new api.Menus.AvailableItemCollection( api.Menus.data.availableMenuItems );

	/**
	 * wp.customize.Menus.AvailableMenuItemsPanelView
	 *
	 * View class for the available menu items panel.
	 *
	 * @constructor
	 * @augments wp.Backbone.View
	 * @augments Backbone.View
	 */
	api.Menus.AvailableMenuItemsPanelView = wp.Backbone.View.extend({

		el: '#available-menu-items',

		events: {
			'input #menu-items-search': 'debounceSearch',
			'change #menu-items-search': 'debounceSearch',
			'click #menu-items-search': 'debounceSearch',
			'focus .menu-item-tpl': 'focus',
			'click .menu-item-tpl': '_submit',
			'keypress .menu-item-tpl': '_submit',
			'click #custom-menu-item-submit': '_submitLink',
			'keypress #custom-menu-item-name': '_submitLink',
			'keydown': 'keyboardAccessible'
		},

		// Cache current selected menu item.
		selected: null,

		// Cache menu control that opened the panel.
		currentMenuControl: null,
		debounceSearch: null,
		$search: null,
		searchTerm: '',
		rendered: false,
		pages: {},
		sectionContent: '',
		loading: false,

		initialize: function() {
			var self = this;

			this.$search = $( '#menu-items-search' );
			this.sectionContent = this.$el.find( '.accordion-section-content' );

			this.debounceSearch = _.debounce( self.search, 250 );

			_.bindAll( this, 'close' );

			// If the available menu items panel is open and the customize controls are
			// interacted with (other than an item being deleted), then close the
			// available menu items panel. Also close on back button click.
			$( '#customize-controls, .customize-section-back' ).on( 'click keydown', function( e ) {
				var isDeleteBtn = $( e.target ).is( '.item-delete, .item-delete *' ),
					isAddNewBtn = $( e.target ).is( '.add-new-menu-item, .add-new-menu-item *' );
				if ( $( 'body' ).hasClass( 'adding-menu-items' ) && ! isDeleteBtn && ! isAddNewBtn ) {
					self.close();
				}
			} );

			this.$el.on( 'input', '#custom-menu-item-name.invalid, #custom-menu-item-url.invalid', function() {
				$( this ).removeClass( 'invalid' );
			});

			// Load available items if it looks like we'll need them.
			api.panel( 'menus' ).container.bind( 'expanded', function() {
				if ( ! self.rendered ) {
					self.initList();
					self.rendered = true;
				}
			});

			// Load more items.
			this.sectionContent.scroll( function() {
				var totalHeight = self.$el.find( '.accordion-section.open .accordion-section-content' ).prop( 'scrollHeight' ),
				    visibleHeight = self.$el.find( '.accordion-section.open' ).height();
				if ( ! self.loading && $( this ).scrollTop() > 3 / 4 * totalHeight - visibleHeight ) {
					var type = $( this ).data( 'type' ),
					    obj_type = $( this ).data( 'obj_type' );
					if ( 'search' === type ) {
						if ( self.searchTerm ) {
							self.doSearch( self.pages.search );
						}
					} else {
						self.loadItems( type, obj_type );
					}
				}
			});

			// Close the panel if the URL in the preview changes
			api.previewer.bind( 'url', this.close );
		},

		// Search input change handler.
		search: function( event ) {
			if ( ! event ) {
				return;
			}
			// Manual accordion-opening behavior.
			if ( this.searchTerm && ! $( '#available-menu-items-search' ).hasClass( 'open' ) ) {
				$( '#available-menu-items .accordion-section-content' ).slideUp( 'fast' );
				$( '#available-menu-items-search .accordion-section-content' ).slideDown( 'fast' );
				$( '#available-menu-items .accordion-section.open' ).removeClass( 'open' );
				$( '#available-menu-items-search' ).addClass( 'open' );
			}
			if ( '' === event.target.value ) {
				$( '#available-menu-items-search' ).removeClass( 'open' );
			}
			if ( this.searchTerm === event.target.value ) {
				return;
			}
			this.searchTerm = event.target.value;
			this.pages.search = 1;
			this.doSearch( 1 );
		},

		// Get search results.
		doSearch: function( page ) {
			var self = this, params,
			    $section = $( '#available-menu-items-search' ),
			    $content = $section.find( '.accordion-section-content' ),
			    itemTemplate = wp.template( 'available-menu-item' );

			if ( self.currentRequest ) {
				self.currentRequest.abort();
			}

			if ( page < 0 ) {
				return;
			} else if ( page > 1 ) {
				$section.addClass( 'loading-more' );
			} else if ( '' === self.searchTerm ) {
				$content.html( '' );
				return;
			}

			$section.addClass( 'loading' );
			self.loading = true;
			params = {
				'customize-menus-nonce': api.Menus.data.nonce,
				'wp_customize': 'on',
				'search': self.searchTerm,
				'page': page
			};

			self.currentRequest = wp.ajax.post( 'search-available-menu-items-customizer', params );

			self.currentRequest.done(function( data ) {
				var items;
				if ( 1 === page ) {
					// Clear previous results as it's a new search.
					$content.empty();
				}
				$section.removeClass( 'loading loading-more' );
				$section.addClass( 'open' );
				self.loading = false;
				items = new api.Menus.AvailableItemCollection( data.items );
				self.collection.add( items.models );
				items.each( function( menuItem ) {
					$content.append( itemTemplate( menuItem.attributes ) );
				} );
				if ( 20 > items.length ) {
					self.pages.search = -1; // Up to 20 posts and 20 terms in results, if <20, no more results for either.
				} else {
					self.pages.search = self.pages.search + 1;
				}
			});

			self.currentRequest.fail(function( data ) {
				$content.empty().append( $( '<p class="nothing-found"></p>' ).text( data.message ) );
				wp.a11y.speak( data.message );
				self.pages.search = -1;
			});

			self.currentRequest.always(function() {
				$section.removeClass( 'loading loading-more' );
				self.loading = false;
				self.currentRequest = null;
			});
		},

		// Render the individual items.
		initList: function() {
			var self = this;

			// Render the template for each item by type.
			_.each( api.Menus.data.itemTypes, function( typeObjects, type ) {
				_.each( typeObjects, function( typeObject, slug ) {
					if ( 'postTypes' === type ) {
						type = 'post_type';
					} else if ( 'taxonomies' === type ) {
						type = 'taxonomy';
					}
					self.pages[ slug ] = 0; // @todo should prefix with type
					self.loadItems( slug, type );
				} );
			} );
		},

		// Load available menu items.
		loadItems: function( type, obj_type ) {
			var self = this, params, request, itemTemplate;
			itemTemplate = wp.template( 'available-menu-item' );

			if ( 0 > self.pages[type] ) {
				return;
			}
			$( '#available-menu-items-' + type + ' .accordion-section-title' ).addClass( 'loading' );
			self.loading = true;
			params = {
				'customize-menus-nonce': api.Menus.data.nonce,
				'wp_customize': 'on',
				'type': type,
				'obj_type': obj_type,
				'page': self.pages[ type ]
			};
			request = wp.ajax.post( 'load-available-menu-items-customizer', params );

			request.done(function ( data ) {
				var items, typeInner;
				items = data.items;
				if ( 0 === items.length ) {
					self.pages[ type ] = -1;
					return;
				}
				items = new api.Menus.AvailableItemCollection( items ); // @todo Why is this collection created and then thrown away?
				self.collection.add( items.models );
				typeInner = $( '#available-menu-items-' + type + ' .accordion-section-content' );
				items.each(function( menu_item ) {
					typeInner.append( itemTemplate( menu_item.attributes ) );
				});
				self.pages[ type ] = self.pages[ type ] + 1;
			});
			request.fail(function ( data ) {
				if ( typeof console !== 'undefined' && console.error ) {
					console.error( data );
				}
			});
			request.always(function () {
				$( '#available-menu-items-' + type + ' .accordion-section-title' ).removeClass( 'loading' );
				self.loading = false;
			});
		},

		// Adjust the height of each section of items to fit the screen.
		itemSectionHeight: function() {
			var sections, totalHeight, accordionHeight, diff;
			totalHeight = window.innerHeight;
			sections = this.$el.find( '.accordion-section-content' );
			accordionHeight =  46 * ( 1 + sections.length ) - 16; // Magic numbers.
			diff = totalHeight - accordionHeight;
			if ( 120 < diff && 290 > diff ) {
				sections.css( 'max-height', diff );
			} else if ( 120 >= diff ) {
				this.$el.addClass( 'allow-scroll' );
			}
		},

		// Highlights a menu item.
		select: function( menuitemTpl ) {
			this.selected = $( menuitemTpl );
			this.selected.siblings( '.menu-item-tpl' ).removeClass( 'selected' );
			this.selected.addClass( 'selected' );
		},

		// Highlights a menu item on focus.
		focus: function( event ) {
			this.select( $( event.currentTarget ) );
		},

		// Submit handler for keypress and click on menu item.
		_submit: function( event ) {
			// Only proceed with keypress if it is Enter or Spacebar
			if ( 'keypress' === event.type && ( 13 !== event.which && 32 !== event.which ) ) {
				return;
			}

			this.submit( $( event.currentTarget ) );
		},

		// Adds a selected menu item to the menu.
		submit: function( menuitemTpl ) {
			var menuitemId, menu_item;

			if ( ! menuitemTpl ) {
				menuitemTpl = this.selected;
			}

			if ( ! menuitemTpl || ! this.currentMenuControl ) {
				return;
			}

			this.select( menuitemTpl );

			menuitemId = $( this.selected ).data( 'menu-item-id' );
			menu_item = this.collection.findWhere( { id: menuitemId } );
			if ( ! menu_item ) {
				return;
			}

			this.currentMenuControl.addItemToMenu( menu_item.attributes );

			$( menuitemTpl ).find( '.menu-item-handle' ).addClass( 'item-added' );
		},

		// Submit handler for keypress and click on custom menu item.
		_submitLink: function( event ) {
			// Only proceed with keypress if it is Enter.
			if ( 'keypress' === event.type && 13 !== event.which ) {
				return;
			}

			this.submitLink();
		},

		// Adds the custom menu item to the menu.
		submitLink: function() {
			var menuItem,
				itemName = $( '#custom-menu-item-name' ),
				itemUrl = $( '#custom-menu-item-url' );

			if ( ! this.currentMenuControl ) {
				return;
			}

			if ( '' === itemName.val() ) {
				itemName.addClass( 'invalid' );
				return;
			} else if ( '' === itemUrl.val() || 'http://' === itemUrl.val() ) {
				itemUrl.addClass( 'invalid' );
				return;
			}

			menuItem = {
				'title': itemName.val(),
				'url': itemUrl.val(),
				'type': 'custom',
				'type_label': api.Menus.data.l10n.custom_label,
				'object': ''
			};

			this.currentMenuControl.addItemToMenu( menuItem );

			// Reset the custom link form.
			// @todo: decide whether this should be done as a callback after adding the item, as it is in nav-menu.js.
			itemUrl.val( 'http://' );
			itemName.val( '' );
		},

		// Opens the panel.
		open: function( menuControl ) {
			this.currentMenuControl = menuControl;

			this.itemSectionHeight();

			$( 'body' ).addClass( 'adding-menu-items' );

			// Collapse all controls.
			_( this.currentMenuControl.getMenuItemControls() ).each( function( control ) {
				control.collapseForm();
			} );

			// Move delete buttons into the title bar.
			_( this.currentMenuControl.getMenuItemControls() ).each( function( control ) {
				control.toggleDeletePosition( true );
			} );

			this.$el.find( '.selected' ).removeClass( 'selected' );

			this.$search.focus();
		},

		// Closes the panel
		close: function( options ) {
			options = options || {};

			if ( options.returnFocus && this.currentMenuControl ) {
				this.currentMenuControl.container.find( '.add-new-menu-item' ).focus();
			}

			// Move delete buttons back out of the title bar.
			if ( this.currentMenuControl ) {
				_( this.currentMenuControl.getMenuItemControls() ).each( function( control ) {
					control.toggleDeletePosition( false );
				} );
			}

			this.currentMenuControl = null;
			this.selected = null;

			$( 'body' ).removeClass( 'adding-menu-items' );
			$( '#available-menu-items .menu-item-handle.item-added' ).removeClass( 'item-added' );

			this.$search.val( '' );
		},

		// Add keyboard accessiblity to the panel
		keyboardAccessible: function( event ) {
			var isEnter = ( 13 === event.which ),
				isEsc = ( 27 === event.which ),
				isDown = ( 40 === event.which ),
				isUp = ( 38 === event.which ),
				isBackTab = ( 9 === event.which && event.shiftKey ),
				selected = null,
				firstVisible = this.$el.find( '> .menu-item-tpl:visible:first' ),
				lastVisible = this.$el.find( '> .menu-item-tpl:visible:last' ),
				isSearchFocused = $( event.target ).is( this.$search );

			if ( isDown || isUp ) {
				if ( isDown ) {
					if ( isSearchFocused ) {
						selected = firstVisible;
					} else if ( this.selected && 0 !== this.selected.nextAll( '.menu-item-tpl:visible' ).length ) {
						selected = this.selected.nextAll( '.menu-item-tpl:visible:first' );
					}
				} else if ( isUp ) {
					if ( isSearchFocused ) {
						selected = lastVisible;
					} else if ( this.selected && 0 !== this.selected.prevAll( '.menu-item-tpl:visible' ).length ) {
						selected = this.selected.prevAll( '.menu-item-tpl:visible:first' );
					}
				}

				this.select( selected );

				if ( selected ) {
					selected.focus();
				} else {
					this.$search.focus();
				}

				return;
			}

			// If enter pressed but nothing entered, don't do anything
			if ( isEnter && ! this.$search.val() ) {
				return;
			}

			if ( isSearchFocused && isBackTab ) {
				this.currentMenuControl.container.find( '.add-new-menu-item' ).focus();
				event.preventDefault(); // Avoid additional back-tab.
			} else if ( isEsc ) {
				this.close( { returnFocus: true } );
			}
		}
	});

	/**
	 * wp.customize.Menus.MenusPanel
	 *
	 * Customizer panel for menus. This is used only for screen options management.
	 * Note that 'menus' must match the WP_Customize_Menu_Panel::$type.
	 *
	 * @constructor
	 * @augments wp.customize.Panel
	 */
	api.Menus.MenusPanel = api.Panel.extend({

		attachEvents: function() {
			api.Panel.prototype.attachEvents.call( this );

			var panel = this,
				panelMeta = panel.container.find( '.panel-meta' ),
				help = panelMeta.find( '.customize-help-toggle' ),
				content = panelMeta.find( '.customize-panel-description' ),
				options = $( '#screen-options-wrap' ),
				button = panelMeta.find( '.customize-screen-options-toggle' );
			button.on( 'click keydown', function( event ) {
				if ( api.utils.isKeydownButNotEnterEvent( event ) ) {
					return;
				}

				// Hide description
				if ( content.not( ':hidden' ) ) {
					content.slideUp( 'fast' );
					help.attr( 'aria-expanded', 'false' );
				}

				if ( 'true' === button.attr( 'aria-expanded' ) ) {
					button.attr( 'aria-expanded', 'false' );
					panelMeta.removeClass( 'open' );
					panelMeta.removeClass( 'active-menu-screen-options' );
					options.slideUp( 'fast' );
				} else {
					button.attr( 'aria-expanded', 'true' );
					panelMeta.addClass( 'open' );
					panelMeta.addClass( 'active-menu-screen-options' );
					options.slideDown( 'fast' );
				}

				return false;
			} );

			// Help toggle
			help.on( 'click keydown', function( event ) {
				if ( api.utils.isKeydownButNotEnterEvent( event ) ) {
					return;
				}

				if ( 'true' === button.attr( 'aria-expanded' ) ) {
					button.attr( 'aria-expanded', 'false' );
					help.attr( 'aria-expanded', 'true' );
					panelMeta.addClass( 'open' );
					panelMeta.removeClass( 'active-menu-screen-options' );
					options.slideUp( 'fast' );
					content.slideDown( 'fast' );
				}
			} );
		},

		/**
		 * Show/hide/save screen options (columns). From common.js.
		 */
		ready: function() {
			var panel = this;
			this.container.find( '.hide-column-tog' ).click( function() {
				var $t = $( this ), column = $t.val();
				if ( $t.prop( 'checked' ) ) {
					panel.checked( column );
				} else {
					panel.unchecked( column );
				}

				panel.saveManageColumnsState();
			});
			this.container.find( '.hide-column-tog' ).each( function() {
			var $t = $( this ), column = $t.val();
				if ( $t.prop( 'checked' ) ) {
					panel.checked( column );
				} else {
					panel.unchecked( column );
				}
			});
		},

		saveManageColumnsState: function() {
			var hidden = this.hidden();
			$.post( wp.ajax.settings.url, {
				action: 'hidden-columns',
				hidden: hidden,
				screenoptionnonce: $( '#screenoptionnonce' ).val(),
				page: 'nav-menus'
			});
		},

		checked: function( column ) {
			this.container.addClass( 'field-' + column + '-active' );
		},

		unchecked: function( column ) {
			this.container.removeClass( 'field-' + column + '-active' );
		},

		hidden: function() {
			this.hidden = function() {
				return $( '.hide-column-tog' ).not( ':checked' ).map( function() {
					var id = this.id;
					return id.substring( id, id.length - 5 );
				}).get().join( ',' );
			};
		}
	} );

	/**
	 * wp.customize.Menus.MenuSection
	 *
	 * Customizer section for menus. This is used only for lazy-loading child controls.
	 * Note that 'menu' must match the WP_Customize_Menu_Section::$type.
	 *
	 * @constructor
	 * @augments wp.customize.Section
	 */
	api.Menus.MenuSection = api.Section.extend({

		/**
		 * @since Menu Customizer 0.3
		 *
		 * @param {String} id
		 * @param {Object} options
		 */
		initialize: function( id, options ) {
			var section = this;
			api.Section.prototype.initialize.call( section, id, options );
			section.deferred.initSortables = $.Deferred();
		},

		/**
		 *
		 */
		ready: function () {
			var section = this;
			section.navMenuLocationSettings = {};
			section.assignedLocations = new api.Value( [] );

			api.each(function( setting, id ) {
				var matches = id.match( /^nav_menu_locations\[(.+?)]/ );
				if ( matches ) {
					section.navMenuLocationSettings[ matches[1] ] = setting;
					setting.bind( function (){
						section.refreshAssignedLocations();
					});
				}
			});

			section.assignedLocations.bind(function ( to ) {
				section.updateAssignedLocationsInSectionTitle( to );
			});

			section.refreshAssignedLocations();
		},

		/**
		 *
		 */
		refreshAssignedLocations: function() {
			var section = this,
				menuTermId = section.getMenuTermId(),
				currentAssignedLocations = [];
			_.each( section.navMenuLocationSettings, function( setting, themeLocation ) {
				if ( setting() === menuTermId ) {
					currentAssignedLocations.push( themeLocation );
				}
			});
			section.assignedLocations.set( currentAssignedLocations );
		},

		/**
		 * 
		 *
		 * @param {array} themeLocations
		 */
		updateAssignedLocationsInSectionTitle: function ( themeLocations ) {
			var section = this,
				$title;

			$title = section.container.find( '.accordion-section-title:first' );
			$title.find( '.menu-in-location' ).remove();
			_.each( themeLocations, function ( themeLocation ){
				var $label = $( '<span class="menu-in-location"></span>' );
				$label.text( api.Menus.data.l10n.menuLocation.replace( '%s', themeLocation ) );
				$title.append( $label );
			});

			section.container.toggleClass( 'assigned-to-menu-location', 0 !== themeLocations.length );

		},

		/**
		 *
		 * @returns {Number}
		 */
		getMenuTermId: function () {
			var matches = this.id.match( /^nav_menu\[(.+?)]/ ),
				menuTermId = parseInt( matches[1], 10 );
			return menuTermId;
		},

		onChangeExpanded: function( expanded, args ) {
			var section = this;

			if ( expanded ) {
				wpNavMenu.menuList = section.container.find( '.accordion-section-content:first' );
				wpNavMenu.targetList = wpNavMenu.menuList;

				// Add attributes needed by wpNavMenu
				$( '#menu-to-edit' ).removeAttr( 'id' );
				wpNavMenu.menuList.attr( 'id', 'menu-to-edit' ).addClass( 'menu' );
			}

			if ( expanded && ! section.contentEmbedded ) {
				_.each( wp.customize.section( section.id ).controls(), function( control ) {
					if ( 'menu_item' === control.params.type ) {
						control.actuallyEmbed();
					}
				} );
				section.contentEmbedded = true;

				wpNavMenu.initSortables(); // Depends on menu-to-edit ID being set above.
				section.deferred.initSortables.resolve( wpNavMenu.menuList ); // Now MenuControl can extend the sortable.
			}
			api.Section.prototype.onChangeExpanded.call( this, expanded, args );
		}

		// @todo Restore onChangeExpanded to actuallyEmbed the nav_menu_items
	});

	/**
	 * wp.customize.Menus.NewMenuSection
	 *
	 * Customizer section for new menus.
	 * Note that 'new_menu' must match the WP_Customize_New_Menu_Section::$type.
	 *
	 * @constructor
	 * @augments wp.customize.Section
	 */
	api.Menus.NewMenuSection = api.Section.extend({

		/**
		 * Add behaviors for the accordion section.
		 *
		 * @since Menu Customizer 0.3
		 */
		attachEvents: function() {
			var section = this;
			this.container.on( 'click keydown', '.add-menu-toggle', function( event ) {
				if ( api.utils.isKeydownButNotEnterEvent( event ) ) {
					return;
				}
				event.preventDefault(); // Keep this AFTER the key filter above

				if ( section.expanded() ) {
					section.collapse();
				} else {
					section.expand();
				}
			});
		},

		/**
		 * Update UI to reflect expanded state.
		 *
		 * @since 4.1.0
		 *
		 * @param {Boolean} expanded
		 */
		onChangeExpanded: function( expanded ) {
			var section = this,
			    button = section.container.find( '.add-menu-toggle' ),
				content = section.container.find( '.new-menu-section-content' ),
			    customizer = section.container.closest( '.wp-full-overlay-sidebar-content' );
			if ( expanded ) {
				button.addClass( 'open' );
				content.slideDown( 'fast', function() {
					customizer.scrollTop( customizer.height() );
				});
			} else {
				button.removeClass( 'open' );
				content.slideUp( 'fast' );
			}
		}
	});

	/**
	 * wp.customize.Menus.MenuLocationControl
	 *
	 * Customizer control for menu locations (rendered as a <select>).
	 * Note that 'menu_location' must match the WP_Customize_Menu_Location_Control::$type.
	 *
	 * @constructor
	 * @augments wp.customize.Control
	 */
	api.Menus.MenuLocationControl = api.Control.extend({
		initialize: function ( id, options ) {
			var control = this,
				matches = id.match( /^nav_menu_locations\[(.+?)]/ );
			control.themeLocation = matches[1];
			api.Control.prototype.initialize.call( control, id, options );
		},

		ready: function() {
			var control = this;
			// @todo It would be better if this was added directly on the setting itself, as opposed to the control.
			control.setting.validate = function ( value ) {
				return parseInt( value, 10 );
			};
		}

		//// Update theme location checkboxes.
		//updateMenuLocationCheckboxes: function( to, from ) {
		//
		//	var locationNames = $( '.current-menu-location-name-' + this.params.locationId ),
		//		setTo = locationNames.parent(),
		//		oldBox = $( '#menu-locations-' + from + '-' + this.params.locationId ),
		//		newBox = $( '#menu-locations-' + to + '-' + this.params.locationId ),
		//		menuName;
		//	if ( 0 === to ) {
		//		setTo.hide();
		//		oldBox.prop( 'checked', false );
		//	} else if ( ! to ) {
		//		setTo.hide();
		//	} else {
		//		//menuName = api.section( 'nav_menus[' + to + ']' ).params.title;
		//		menuName = api.section( 'nav_menus[' + to + ']' ).container.find( '.live-update-section-title' ).val();
		//		setTo.show();
		//		locationNames.text( menuName );
		//		oldBox.prop( 'checked', false );
		//		newBox.prop( 'checked', true );
		//		newBox.parent().find( '.theme-location-set' ).hide();
		//	}
		//}
	});

	/**
	 * wp.customize.Menus.MenuItemControl
	 *
	 * Customizer control for menu items.
	 * Note that 'menu_item' must match the WP_Customize_Menu_Item_Control::$type.
	 *
	 * @constructor
	 * @augments wp.customize.Control
	 */
	api.Menus.MenuItemControl = api.Control.extend({
		/**
		 * Set up the control.
		 */
		ready: function() {
			this._setupControlToggle();
			this._setupReorderUI();
			this._setupUpdateUI();
			this._setupRemoveUI();
			this._setupLinksUI();
			this._setupTitleUI();
		},

		/**
		 * Show/hide the settings when clicking on the menu item handle.
		 */
		_setupControlToggle: function() {
			var control = this;

			this.container.find( '.menu-item-handle' ).on( 'click', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				var menuControl = control.getMenuControl();
				if ( menuControl.isReordering || menuControl.isSorting ) {
					return;
				}
				control.toggleForm();
			} );
		},

		/**
		 * Set up the menu-item-reorder-nav
		 */
		_setupReorderUI: function() {
			var control = this, template, $reorderNav;

			template = wp.template( 'menu-item-reorder-nav' );

			// Add the menu item reordering elements to the menu item control.
			this.container.find( '.item-controls' ).after( template );

			// Handle clicks for up/down/left-right on the reorder nav.
			$reorderNav = this.container.find( '.menu-item-reorder-nav' );
			$reorderNav.find( '.menus-move-up, .menus-move-down, .menus-move-left, .menus-move-right' ).on( 'click keypress', function( event ) {
				if ( 'keypress' === event.type && ( 13 !== event.which && 32 !== event.which ) ) {
					return;
				}
				$( this ).focus();

				var isMoveUp = $( this ).is( '.menus-move-up' ),
					isMoveDown = $( this ).is( '.menus-move-down' ),
					isMoveLeft = $( this ).is( '.menus-move-left' ),
					isMoveRight = $( this ).is( '.menus-move-right' ),
					i = control.getMenuItemPosition();

				if ( ( isMoveUp && 0 === i ) || ( isMoveDown && i === control.getMenuControl().setting().length - 1 ) ) {
					return;
				}

				if ( isMoveUp ) {
					control.moveUp();
				} else if ( isMoveDown ) {
					control.moveDown();
				} else if ( isMoveLeft ) {
					control.moveLeft();
				} else if ( isMoveRight ) {
					control.moveRight();
				}

				$( this ).focus(); // Re-focus after the container was moved.
			} );
		},

		/**
		 * Set up event handlers for menu item updating.
		 */
		_setupUpdateUI: function() {
			var control = this,
				settingValue = control.setting();

			control.elements = {};
			control.elements.url = new api.Element( control.container.find( '.edit-menu-item-url' ) );
			control.elements.title = new api.Element( control.container.find( '.edit-menu-item-title' ) );
			control.elements.attr_title = new api.Element( control.container.find( '.edit-menu-item-attr-title' ) );
			control.elements.target = new api.Element( control.container.find( '.edit-menu-item-target' ) );
			control.elements.classes = new api.Element( control.container.find( '.edit-menu-item-classes' ) );
			control.elements.xfn = new api.Element( control.container.find( '.edit-menu-item-xfn' ) );
			control.elements.description = new api.Element( control.container.find( '.edit-menu-item-description' ) );
			// @todo allow other elements, added by plugins, to be automatically picked up here; allow additional values to be added to setting array.

			_.each( control.elements, function ( element, property ) {
				element.bind(function ( value ) {
					if ( element.element.is( 'input[type=checkbox]' ) ) {
						value = ( value ) ? element.element.val() : '';
					}

					var settingValue = control.setting();
					if ( settingValue && settingValue[ property ] !== value ) {
						settingValue = _.clone( settingValue );
						settingValue[ property ] = value;
						control.setting.set( settingValue );
					}
				});
				if ( settingValue ) {
					element.set( settingValue[ property ] );
				}
			});

			control.setting.bind(function ( to, from ) {
				if ( false === to ) {
					control.container.remove();
					// @todo this will need to now shift up any child menu items to take this parent's place, or the children should be deleted as well.
				} else {
					_.each( to, function ( value, key ) {
						if ( control.elements[key] ) {
							control.elements[key].set( to[key] );
						}
					} );

					if ( to.position !== from.position || to.menu_item_parent !== from.menu_item_parent ) {
						// @todo now we need to update the priorities and depths of all the menu item controls to reflect the new positions; there could be a MenuControl method for reflowing the menu items inside.
						// @todo self._applyCardinalOrderClassNames();
					}
				}
			});
		},

		/**
		 * Set up event handlers for menu item deletion.
		 */
		_setupRemoveUI: function() {
			var control = this, $removeBtn;

			// Configure delete button.
			$removeBtn = control.container.find( '.item-delete' );

			$removeBtn.on( 'click', function( e ) {
				e.preventDefault();

				// Find an adjacent element to add focus to when this menu item goes away
				var $adjacentFocusTarget;
				if ( control.container.next().is( '.customize-control-nav_menu_item' ) ) {
					if ( ! $( 'body' ).hasClass( 'adding-menu-items' ) ) {
						$adjacentFocusTarget = control.container.next().find( '.item-edit:first' );
					} else {
						$adjacentFocusTarget = control.container.next().find( '.item-delete:first' );
					}
				} else if ( control.container.prev().is( '.customize-control-nav_menu_item' ) ) {
					if ( ! $( 'body' ).hasClass( 'adding-menu-items' ) ) {
						$adjacentFocusTarget = control.container.prev().find( '.item-edit:first' );
					} else {
						$adjacentFocusTarget = control.container.prev().find( '.item-delete:first' );
					}
				} else {
					$adjacentFocusTarget = control.container.next( '.customize-control-nav_menu' ).find( '.add-new-menu-item' );
				}

				control.container.slideUp( function() {
					control.setting.set( false );
					wp.a11y.speak( api.Menus.data.l10n.itemDeleted );
					$adjacentFocusTarget.focus(); // keyboard accessibility
				} );
			} );
		},

		_setupLinksUI: function() {
			var $origBtn;

			// Configure original link.
			$origBtn = this.container.find( 'a.original-link' );

			$origBtn.on( 'click keydown', function( e ) {
				if ( api.utils.isKeydownButNotEnterEvent( e ) ) {
					return;
				}
				e.preventDefault();
				api.previewer.previewUrl( e.target.toString() );
			} );
		},

		/**
		 * Update item handle title when changed.
		 */
		_setupTitleUI: function() {
			var control = this;

			control.setting.bind( function ( item ) {
				if ( ! item ) {
					return;
				}

				var titleEl = control.container.find( '.menu-item-title' );

				// Don't update to an empty title.
				if ( item.title ) {
					titleEl
						.text( item.title )
						.removeClass( 'no-title' );
				} else {
					titleEl
						.text( api.Menus.data.l10n.untitled )
						.addClass( 'no-title' );
				}
			} );
		},

		/**
		 *
		 * @returns {number}
		 */
		getDepth: function () {
			var control = this, setting = control.setting(), depth = 0;
			if ( ! setting ) {
				return 0;
			}
			while ( setting && setting.menu_item_parent ) {
				depth += 1;
				control = api.control( 'nav_menu_item[' + setting.menu_item_parent + ']' );
				if ( ! control ) {
					break;
				}
				setting = control.setting();
			}
			return depth;
		},

		/**
		 * Amend the control's params with the data necessary for the JS template just in time.
		 */
		renderContent: function () {

			var control = this,
				settingValue = control.setting(),
				containerClasses;

			control.params.title = settingValue.title || '';
			control.params.depth = control.getDepth();
			containerClasses = [
				'menu-item',
				'menu-item-depth-' + String( control.params.depth ),
				'menu-item-' + settingValue.object,
				'menu-item-edit-inactive'
			];

			if ( settingValue.invalid ) {
				containerClasses.push( 'invalid' );
				control.params.title = api.Menus.data.invalidTitleTpl.replace( '%s', control.params.title );
			} else if ( 'draft' === settingValue.status ) {
				containerClasses.push( 'pending' );
				control.params.title = api.Menus.data.pendingTitleTpl.replace( '%s', control.params.title );
			}

			control.params.el_classes = containerClasses.join( ' ' );
			control.params.item_type_label = api.Menus.getTypeLabel( settingValue.type, settingValue.object );
			control.params.item_type = settingValue.type;
			control.params.url = settingValue.url;
			control.params.target = settingValue.target;
			control.params.attr_title = settingValue.attr_title;
			control.params.classes = _.isArray( settingValue.classes ) ? settingValue.classes.join( ' ' ) : settingValue.classes;
			control.params.attr_title = settingValue.attr_title;
			control.params.xfn = settingValue.xfn;
			control.params.description = settingValue.description;
			control.params.parent = settingValue.menu_item_parent;
			control.params.menu_item_id = control.getMenuItemPostId(); // @todo When the control.id changes, this needs to be updated.
			control.params.original_title = false; // @todo This is going to require Ajax.

			control.container.data( 'item-depth', control.params.depth );
			control.container.addClass( control.params.el_classes );

			api.Control.prototype.renderContent.call( control );
		},

		/***********************************************************************
		 * Begin public API methods
		 **********************************************************************/

		/**
		 * @return {wp.customize.controlConstructor.nav_menu|null}
		 */
		getMenuControl: function() {
			var control = this, settingValue = control.setting();
			if ( settingValue && settingValue.nav_menu_term_id ) {
				return api.control( 'nav_menu[' + settingValue.nav_menu_term_id + ']' );
			} else {
				return null;
			}
		},

		getMenuItemPostId: function () {
			var matches = this.id.match( /^nav_menu_item\[(.+?)]/ );
			if ( ! matches ) {
				throw new Error( 'Failed to parse ID out setting ID: ' + this.id );
			}
			return parseInt( matches[1], 10 );
		},

		/**
		 * Expand the accordion section containing a control
		 */
		expandControlSection: function() {
			var $section = this.container.closest( '.accordion-section' );

			if ( ! $section.hasClass( 'open' ) ) {
				$section.find( '.accordion-section-title:first' ).trigger( 'click' );
			}
		},

		/**
		 * Expand the menu item form control.
		 */
		expandForm: function() {
			this.toggleForm( true );
		},

		/**
		 * Collapse the menu item form control.
		 */
		collapseForm: function() {
			this.toggleForm( false );
		},

		/**
		 * Expand or collapse the menu item control.
		 *
		 * @param {boolean|undefined} [showOrHide] If not supplied, will be inverse of current visibility
		 */
		toggleForm: function( showOrHide ) {
			var self = this, $menuitem, $inside, complete;

			$menuitem = this.container;
			$inside = $menuitem.find( '.menu-item-settings:first' );
			if ( 'undefined' === typeof showOrHide ) {
				showOrHide = ! $inside.is( ':visible' );
			}

			// Already expanded or collapsed.
			if ( $inside.is( ':visible' ) === showOrHide ) {
				return;
			}

			if ( showOrHide ) {
				// Close all other menu item controls before expanding this one.
				api.control.each( function( otherControl ) {
					if ( self.params.type === otherControl.params.type && self !== otherControl ) {
						otherControl.collapseForm();
					}
				} );

				complete = function() {
					$menuitem
						.removeClass( 'menu-item-edit-inactive' )
						.addClass( 'menu-item-edit-active' );
					self.container.trigger( 'expanded' );
				};

				$inside.slideDown( 'fast', complete );

				self.container.trigger( 'expand' );
			} else {
				complete = function() {
					$menuitem
						.addClass( 'menu-item-edit-inactive' )
						.removeClass( 'menu-item-edit-active' );
					self.container.trigger( 'collapsed' );
				};

				self.container.trigger( 'collapse' );

				$inside.slideUp( 'fast', complete );
			}
		},

		/**
		* Move the control's delete button up to the title bar or down to the control body.
		*
		* @param {boolean|undefined} [top] If not supplied, will be inverse of current visibility.
		*/
		toggleDeletePosition: function( top ) {
			var button, handle, actions;
			button = this.container.find( '.item-delete' );
			handle = this.container.find( '.menu-item-handle' );
			actions = this.container.find( '.menu-item-actions' );

			if ( top ) {
				handle.append( button );
			} else {
				actions.append( button );
			}
		},

		/**
		 * Expand the containing menu section, expand the form, and focus on
		 * the first input in the control.
		 */
		focus: function() {
			this.expandControlSection();
			this.expandForm();
			this.container.find( '.menu-item-settings :focusable:first' ).focus();
		},

		/**
		 * Get the position (index) of the item in the containing menu.
		 *
		 * @returns {Number|null}
		 */
		getMenuItemPosition: function() {
			var control = this;
			return control.setting().position;
		},

		/**
		 * Move menu item up one in the menu.
		 */
		moveUp: function() {
			// Update menu control setting.
			this._moveMenuItemByOne( -1 );
			// Update UI.
			var prev = $( this.container ).prev();
			prev.before( $( this.container ) );
			wp.a11y.speak( api.Menus.data.l10n.movedUp );
			// Maybe update parent & depth if it's a sub-item.
			if ( 0 !== this.getDepth() ) {
				// @todo
			}
			// @todo also move children
			this.getMenuControl()._applyCardinalOrderClassNames();
		},

		/**
		 * Move menu item up one in the menu.
		 */
		moveDown: function() {
			// Update menu control setting.
			this._moveMenuItemByOne( 1 );
			// Update UI.
			var next = $( this.container ).next();
			next.after( $( this.container ) );
			wp.a11y.speak( api.Menus.data.l10n.movedDown );
			// Maybe update parent & depth if it's a sub-item.
			if ( 0 !== this.getDepth() ) {
				// @todo
			}
			// @todo also move children
			this.getMenuControl()._applyCardinalOrderClassNames();
		},
		/**
		 * Move menu item and all children up one level of depth.
		 */
		moveLeft: function() {
			this._moveMenuItemDepthByOne( -1 );
			wp.a11y.speak( api.Menus.data.l10n.movedLeft );
		},

		/**
		 * Move menu item and children one level deeper, as a submenu of the previous item.
		 */
		moveRight: function() {
			this._moveMenuItemDepthByOne( 1 );
			wp.a11y.speak( api.Menus.data.l10n.movedRight );
		},

		/**
		 * @private
		 *
		 * @param {Number} offset 1|-1
		 */
		_moveMenuItemByOne: function( offset ) {
			var control = this,
				position = control.getMenuItemPosition() + offset,
				clone = _.clone( control.setting() );

			if ( 1 !== offset && -1 !== offset ) {
				return;
			}

			// Update menu item position field.
			clone.position = position;

			// @todo update menu-item-reorder-nav to reflect the position change.

			// @todo update menu item parents and depth if necessary based on new previous item.

			// Update the control with our new settings.
			control.setting( clone );
		},

		/**
		 * @private
		 *
		 * @param {Number} offset 1|-1
		 */
		_moveMenuItemDepthByOne: function( offset ) {
			var depth, i, ii, parentId, parentControl, menuSetting, menuItemIds,
				previousMenuItemId, previousMenuItem, previousItemDepth,
				nextMenuItemId, nextMenuItem, nextItemDepth, childControl, childDepth,
				control = this, menuItemSetting;

			throw new Error( '_moveMenuItemDepthByOne needs to be updated to only look at the nav_menu_item settings and their position and menu_item_parent properties' );

			depth = this.getDepth();
			i = this.getMenuItemPosition();

			if ( 0 === i ) {
				// First item can never be moved into or out of a sub-menu.
				return;
			}

			/* @todo This is now wrong as it is trying to work with a nav_menu setting consisting of IDs, as opposed to working with nav_menu_item settings that have positions and menu_item_parent properties
			 * menuSetting = this.getMenuControl().setting;
			 * menuItemIds = Array.prototype.slice.call( menuSetting() );
			 * previousMenuItemId = menuItemIds[i - 1];
			 * previousMenuItem = api.Menus.getMenuItemControl( previousMenuItemId );
			 * previousItemDepth = previousMenuItem.params.depth;
			 *
			 * // Can we move this item in this direction?
			 * if ( 1 === offset && previousItemDepth < depth ) {
			 * 	// Already a sub-item of previous item.
			 * 	return;
			 * } else if ( -1 === offset && 0 === depth ) {
			 * 	// Already at the top level.
			 * 	return;
			 * }
			 *
			 * // Get new menu item parent id.
			 * if ( 1 === offset ) {
			 * 	// Parent will be previous item if they have the same depth.
			 * 	if ( previousItemDepth === depth ) {
			 * 		parentId = previousMenuItemId;
			 * 	} else {
			 * 		// Find closest previous item of the same current depth.
			 * 		ii = 1;
			 * 		while ( ii <= i ) {
			 * 			parentControl = api.Menus.getMenuItemControl( menuItemIds[i - ii] );
			 * 			if ( depth === parentControl.params.depth ) {
			 * 				parentId = menuItemIds[i - ii];
			 * 				break;
			 * 			} else {
			 * 				ii++;
			 * 			}
			 * 		}
			 * 	}
			 * } else {
			 * 	if ( 1 === depth ) {
			 * 		parentId = 0;
			 * 	} else {
			 * 		// Find closest previous item with depth of 2 less than the current depth.
			 * 		ii = 1;
			 * 		while ( ii <= i ) {
			 * 			parentControl = api.Menus.getMenuItemControl( menuItemIds[i - ii] );
			 * 			if ( depth - 2 === parentControl.params.depth ) {
			 * 				parentId = menuItemIds[i - ii];
			 * 				break;
			 * 			} else {
			 * 				ii++;
			 * 			}
			 * 		}
			 * 	}
			 * }
			 */

			// Update menu item parent field.
			menuItemSetting = _.clone( control.setting() );
			menuItemSetting.menu_item_parent = parentId;
			control.setting( menuItemSetting );

			/*
			 * @todo Note that all of the following should be done based on setting changes
			 * The logic could be wrapped in:
			 *
			 * control.setting.bind( function( newMenuItem, oldMenuItem ){ if ( newMenuItem.menu_item_parent !==  oldMenuItem.menu_item_parent ) { Now refresh positions } } );
			 *
			 * See _setupUpdateUI() for the current stub code that this logic needs to be placed inside of.
			 */

			// Update depth class for UI.
			this.container
				.removeClass( 'menu-item-depth-' + depth )
				.addClass( 'menu-item-depth-' + ( depth + offset ) );

			// Does this item have any children?
			if ( i + 1 === menuItemIds.length ) {
				// Last item.
				return;
			}
			nextMenuItemId = menuItemIds[i + 1];
			nextMenuItem = api.Menus.getMenuItemControl( nextMenuItemId );
			nextItemDepth = nextMenuItem.getDepth();
			if ( depth < nextItemDepth ) {
				ii = 1;
				while ( ii + i < menuItemIds.length ) {
					childControl = api.Menus.getMenuItemControl( menuItemIds[i + ii] );
					childDepth = childControl.getDepth();
					if ( depth === childDepth ) {
						// No longer at a child control.
						break;
					} else {
						// Update depth class for UI.
						childControl.container.find( '.menu-item' )
							.removeClass( 'menu-item-depth-' + childDepth )
							.addClass( 'menu-item-depth-' + ( childDepth + offset ) );
					}
					ii++;
				}
			}
		}
	} );

	/**
	 * wp.customize.Menus.MenuNameControl
	 *
	 * Customizer control for a nav menu's name.
	 *
	 * @constructor
	 * @augments wp.customize.Control
	 */
	api.Menus.MenuNameControl = api.Control.extend({

		ready: function () {
			var control = this,
				settingValue = control.setting();

			control.nameElement = new api.Element( control.container.find( '.menu-name-field' ) );

			control.nameElement.bind(function ( value ) {
				var settingValue = control.setting();
				if ( settingValue && settingValue.name !== value ) {
					settingValue = _.clone( settingValue );
					settingValue.name = value;
					control.setting.set( settingValue );
				}
			});
			if ( settingValue ) {
				control.nameElement.set( settingValue.name );
			}

			control.setting.bind(function ( object ) {
				if ( object ) {
					control.nameElement.set( object.name );
				}
			});
		}

	});

	/**
	 * wp.customize.Menus.MenuControl
	 *
	 * Customizer control for menus.
	 * Note that 'nav_menu' must match the WP_Menu_Customize_Control::$type
	 *
	 * @constructor
	 * @augments wp.customize.Control
	 */
	api.Menus.MenuControl = api.Control.extend({
		/**
		 * Set up the control.
		 */
		ready: function() {
			var control = this;

			control.$controlSection = control.container.closest( '.control-section' );
			control.$sectionContent = control.container.closest( '.accordion-section-content' );

			this._setupModel();

			api.section( control.section(), function( section ) {
				section.deferred.initSortables.done(function( menuList ) {
					control._setupSortable( menuList );
				});
			} );

			this._setupAddition();
			this._applyCardinalOrderClassNames();
			this._setupLocations();
			this._setupTitle();
		},

		/**
		 * Update ordering of menu item controls when the setting is updated.
		 */
		_setupModel: function() {
			var control = this;

			control.elements = {};
			control.elements.auto_add = new api.Element( control.container.find( 'input[type=checkbox].auto_add' ) );

			control.elements.auto_add.bind(function ( auto_add ) {
				var settingValue = control.setting();
				if ( settingValue && settingValue.auto_add !== auto_add ) {
					settingValue = _.clone( settingValue );
					settingValue.auto_add = auto_add;
					control.setting.set( settingValue );
				}
			});
			control.elements.auto_add.set( control.setting().auto_add );
			control.setting.bind(function ( object ) {
				if ( ! object ) {
					return;
				}
				control.elements.auto_add.set( object.auto_add );
			});

			control.setting.bind( function ( to ) {
				if ( false === to ) {
					control._handleDeletion();
				}
			} );

			control.container.find( '.menu-delete' ).on( 'click keydown', function ( event ) {
				if ( api.utils.isKeydownButNotEnterEvent( event ) ) {
					return;
				}
				event.stopPropagation();
				event.preventDefault();
				control.confirmDelete();
			});
		},

		/**
		 * Allow items in each menu to be re-ordered, and for the order to be previewed.
		 *
		 * Notice that the UI aspects here are handled by wpNavMenu.initSortables()
		 * which is called in MenuSection.onChangeExpanded()
		 *
		 * @param {object} menuList - The element that has sortable().
		 */
		_setupSortable: function( menuList ) {
			var control = this;

			if ( ! menuList.is( control.$sectionContent ) ) {
				throw new Error( 'Unexpected menuList.' );
			}

			menuList.on( 'sortstart', function() {
				control.isSorting = true;
			});

			menuList.on( 'sortstop', function() {
				setTimeout( function () { // Next tick.
					var menuItemContainerIds = control.$sectionContent.sortable( 'toArray' ),
						menuItemControls = [],
						position = 0,
						priority = 10;

					control.isSorting = false;

					_.each( menuItemContainerIds, function ( menuItemContainerId ) {
						var menuItemId, menuItemControl;
						menuItemId = parseInt( menuItemContainerId.replace( /^customize-control-nav_menu_item-/, '' ), 10 );
						if ( !menuItemId ) {
							return;
						}
						menuItemControl = api.control( 'nav_menu_item[' + String( menuItemId ) + ']' );
						if ( menuItemControl ) {
							menuItemControls.push( menuItemControl );
						}
					} );

					_.each( menuItemControls, function ( menuItemControl ) {
						var setting = _.clone( menuItemControl.setting() );
						position += 1;
						priority += 1;
						setting.position = position;
						setting.menu_item_parent = parseInt( menuItemControl.container.find( '.menu-item-data-parent-id' ).val(), 10 );
						if ( ! setting.menu_item_parent ) {
							setting.menu_item_parent = 0;
						}
						menuItemControl.setting.set( setting );
						menuItemControl.priority( priority );
					});
				});
			});

			control.isReordering = false;

			/**
			 * Keyboard-accessible reordering.
			 */
			this.container.find( '.reorder-toggle' ).on( 'click keydown', function( event ) {
				if ( 'keydown' === event.type && ! ( 13 === event.which || 32 === event.which ) ) { // Enter or Spacebar
					return;
				}

				control.toggleReordering( ! control.isReordering );
			} );
		},

		/**
		 * Set up UI for adding a new menu item.
		 */
		_setupAddition: function() {
			var self = this;

			this.container.find( '.add-new-menu-item' ).on( 'click keydown', function( event ) {
				if ( 'keydown' === event.type && ! ( 13 === event.which || 32 === event.which ) ) { // Enter or Spacebar
					return;
				}

				if ( self.$sectionContent.hasClass( 'reordering' ) ) {
					return;
				}

				if ( ! $( 'body' ).hasClass( 'adding-menu-items' ) ) {
					api.Menus.availableMenuItemsPanel.open( self );
				} else {
					api.Menus.availableMenuItemsPanel.close();
					event.stopPropagation();
				}
			} );
		},

		_handleDeletion: function() {
			var control = this,
				section,
				removeSection;
			section = api.section( control.section() );
			removeSection = function() {
				section.container.remove();
				api.section.remove( section.id );
				// @todo Remove the option from the theme location dropdowns. Should be automatic based on the setting being deleted.
				// dropdowns = $( '#accordion-section-menu_locations .customize-control select' );
				// dropdowns.find( 'option[value=' + menuId + ']' ).remove();
			};

			if ( section && section.expanded() ) {
				section.collapse({
					completeCallback: function() {
						removeSection();
						wp.a11y.speak( api.Menus.data.l10n.menuDeleted );
						api.panel( 'menus' ).focus();
					}
				});
			} else {
				removeSection();
			}
		},

		/**
		 * Add classes to the menu item controls to assist with styling.
		 */
		_applyCardinalOrderClassNames: function() {
			this.$sectionContent.find( '.customize-control-nav_menu_item' )
				.removeClass( 'first-item' )
				.removeClass( 'last-item' )
				.find( '.menus-move-down, .menus-move-up' ).prop( 'tabIndex', 0 );

			this.$sectionContent.find( '.customize-control-nav_menu_item:first' )
				.addClass( 'first-item' )
				.find( '.menus-move-up' ).prop( 'tabIndex', -1 );

			this.$sectionContent.find( '.customize-control-nav_menu_item:last' )
				.addClass( 'last-item' )
				.find( '.menus-move-down' ).prop( 'tabIndex', -1 );
		},

		// Setup theme location checkboxes.
		_setupLocations: function() {
			var control = this;

			control.container.find( '.assigned-menu-location' ).each(function () {
				var container = $( this ),
					checkbox = container.find( 'input[type=checkbox]' ),
					element,
					updateSelectedMenuLabel,
					navMenuLocationSetting = api( 'nav_menu_locations[' + checkbox.data( 'location-id' ) + ']' );

				updateSelectedMenuLabel = function ( selectedMenuId ) {
					var menuSetting = api( 'nav_menu[' + String( selectedMenuId ) + ']' );
					if ( ! selectedMenuId || ! menuSetting || ! menuSetting() ) {
						container.find( '.theme-location-set' ).hide();
					} else {
						container.find( '.theme-location-set' ).show().find( 'span' ).text( menuSetting().name );
					}
				};

				element = new api.Element( checkbox );
				element.set( navMenuLocationSetting.get() === control.getMenuTermId() );

				checkbox.on( 'change', function () {
					// Note: We can't use element.bind( function( checked ){ ... } ) here because it will trigger a change as well.
					navMenuLocationSetting.set( this.checked ? control.getMenuTermId() : 0 );
				} );

				navMenuLocationSetting.bind(function( selectedMenuId ) {
					element.set( selectedMenuId === control.getMenuTermId() );
					updateSelectedMenuLabel( selectedMenuId );
				});
				updateSelectedMenuLabel( navMenuLocationSetting.get() );

			});
		},

		/**
		 * Update Section Title as menu name is changed.
		 */
		_setupTitle: function() {
			var control = this;

			control.setting.bind( function ( menu ) {
				if ( ! menu ) {
					return;
				}

				// Empty names are not allowed (will not be saved), don't update to one.
				if ( menu.name ) {
					var section = control.container.closest( '.accordion-section' ),
						menuId = control.getMenuTermId(),
						controlTitle = section.find( '.accordion-section-title' ),
						sectionTitle = section.find( '.customize-section-title h3' ),
						location = section.find( '.menu-in-location' ),
						action = sectionTitle.find( '.customize-action' );

					// Update the control title
					controlTitle.text( menu.name );
					if ( location.length ) {
						location.appendTo( controlTitle );
					}

					// Update the section title
					sectionTitle.text( menu.name );
					if ( action.length ) {
						action.prependTo( sectionTitle );
					}

					// Update the nav menu name in location selects.
					api.control.each( function( control ) {
						if ( /^nav_menu_locations\[/.test( control.id ) ) {
							control.container.find( 'option[value=' + menuId + ']' ).text( menu.name );
						}
					} );

					// Update the nav menu name in all location checkboxes.
					section.find( '.customize-control-checkbox input' ).each( function() {
						if ( $( this ).prop( 'checked' ) ) {
							$( '.current-menu-location-name-' + $( this ).data( 'location-id' ) ).text( menu.name );
						}
					} );
				}
			} );
		},

		/***********************************************************************
		 * Begin public API methods
		 **********************************************************************/

		/**
		 *
		 * @returns {Number}
		 */
		getMenuTermId: function () {
			var matches = this.setting.id.match( /^nav_menu\[(.+?)]/ ),
				menuTermId = parseInt( matches[1], 10 );
			return menuTermId;
		},

		confirmDelete: function () {
			var control = this;
			if ( confirm( api.Menus.data.l10n.deleteWarn ) ) {
				control.setting.set( false );
			}
		},

		/**
		 * Enable/disable the reordering UI
		 *
		 * @param {Boolean} showOrHide to enable/disable reordering
		 */
		toggleReordering: function( showOrHide ) {
			showOrHide = Boolean( showOrHide );

			if ( showOrHide === this.$sectionContent.hasClass( 'reordering' ) ) {
				return;
			}

			this.isReordering = showOrHide;
			this.$sectionContent.toggleClass( 'reordering', showOrHide );

			if ( showOrHide ) {
				_( this.getMenuItemControls() ).each( function( formControl ) {
					formControl.collapseForm();
				} );
			}
		},

		/**
		 * @return {wp.customize.controlConstructor.menu_item[]}
		 */
		getMenuItemControls: function() {
			var menuControl = this,
				menuItemControls = [],
				menuTermId = menuControl.getMenuTermId();

			api.control.each(function ( control ) {
				if ( /^nav_menu_item\[/.test( control.id ) && control.setting() && menuTermId === control.setting().nav_menu_term_id ) {
					menuItemControls.push( control );
				}
			});

			return menuItemControls;
		},

		/**
		 * Add a new item to this menu.
		 *
		 * @param {object} item - Value for the nav_menu_item setting to be created.
		 * @returns {wp.customize.Menus.controlConstructor.nav_menu_item} The newly-created nav_menu_item control instance.
		 */
		addItemToMenu: function( item ) {
			var menuControl = this, customizeId, settingArgs, setting, menuItemControl, placeholderId, position = 0, priority = 10;

			_.each( menuControl.getMenuItemControls(), function ( control ) {
				if ( control.setting() ) {
					position = Math.max( position, control.setting().position );
					priority = Math.max( priority, control.priority() );
				}
			});
			position += 1;
			priority += 1;

			item = $.extend(
				{},
				api.Menus.data.defaultSettingValues.nav_menu_item,
				item,
				{
					nav_menu_term_id: menuControl.getMenuTermId(),
					position: position
				}
			);
			delete item.id; // only used by Backbone

			placeholderId = api.Menus.generatePlaceholderAutoIncrementId();
			customizeId = 'nav_menu_item[' + String( placeholderId ) + ']';
			settingArgs = {
				type: 'nav_menu_item',
				transport: 'postMessage',
				previewer: api.previewer
			};
			setting = api.create( customizeId, customizeId, {}, settingArgs );
			setting.set( item ); // Change from initial empty object to actual item to mark as dirty.

			// Add the menu control.
			menuItemControl = new api.controlConstructor.nav_menu_item( customizeId, {
				params: {
					type: 'nav_menu_item',
					content: '<li id="customize-control-nav_menu_item-' + String( placeholderId ) + '" class="customize-control customize-control-nav_menu_item"></li>',
					menu_id: placeholderId,
					section: menuControl.id,
					priority: priority,
					active: true,
					settings: {
						'default': customizeId
					}
				},
				previewer: api.previewer
			} );

			menuItemControl.toggleDeletePosition( true );

			api.control.add( customizeId, menuItemControl );
			setting.preview();

			return menuItemControl;
		}
	} );

	/**
	 * wp.customize.Menus.NewMenuControl
	 *
	 * Customizer control for creating new menus and handling deletion of existing menus.
	 * Note that 'new_menu' must match the WP_New_Menu_Customize_Control::$type.
	 *
	 * @constructor
	 * @augments wp.customize.Control
	 */
	api.Menus.NewMenuControl = api.Control.extend({
		/**
		 * Set up the control.
		 */
		ready: function() {
			this._bindHandlers();
		},

		_bindHandlers: function() {
			var self = this,
				name = $( '#customize-control-new_menu_name input' ),
				submit = $( '#create-new-menu-submit' );
			name.on( 'keydown', function( event ) {
				if ( 13 === event.which ) { // Enter.
					self.submit();
				}
			} );
			submit.on( 'click keydown', function( event ) {
				if ( api.utils.isKeydownButNotEnterEvent( event ) ) {
					return;
				}
				self.submit();
				event.stopPropagation();
				event.preventDefault();
			} );
		},

		/**
		 * Create the new menu with the name supplied.
		 *
		 * @returns {boolean}
		 */
		submit: function() {

			var control = this,
				container = control.container.closest( '.accordion-section-new-menu' ),
				nameInput = container.find( '.menu-name-field' ).first(),
				name = nameInput.val(),
				menuSection,
				customizeId, menuControl,
				placeholderId = api.Menus.generatePlaceholderAutoIncrementId();

			customizeId = 'nav_menu[' + String( placeholderId ) + ']';

			// Add the menu section.
			menuSection = new api.Menus.MenuSection( customizeId, {
				params: {
					id: customizeId,
					panel: 'menus',
					title: name,
					customizeAction: api.Menus.data.l10n.customizingMenus,
					type: 'menu',
					priority: 10
				}
			} );
			api.section.add( customizeId, menuSection );

			// Register the menu control setting.
			api.create( customizeId, customizeId, '', {
				type: 'nav_menu',
				transport: 'postMessage',
				previewer: control.setting.previewer
			} );
			api( customizeId ).set( $.extend(
				{},
				api.Menus.data.defaultSettingValues.nav_menu,
				{
					name: name
				}
			) );

			// Add the menu control.
			menuControl = new api.controlConstructor.nav_menu( customizeId, {
				params: {
					type: 'nav_menu',
					content: '<li id="customize-control-nav_menu-' + String( placeholderId ) + '" class="customize-control customize-control-nav_menu"></li>', // @todo core should do this for us
					// menu_id: placeholderId, // @todo do we need this?
					section: customizeId,
					priority: 998,
					active: true,
					settings: {
						'default': customizeId
					}
				},
				previewer: control.setting.previewer
			} );
			api.control.add( customizeId, menuControl );

			// @todo: nemu name and auto-add new items controls

			// Clear name field.
			nameInput.val( '' );

			wp.a11y.speak( api.Menus.data.l10n.menuAdded );

			// Focus on the new menu section.
			api.section( customizeId ).focus(); // @todo should we focus on the new menu's control and open the add-items panel? Thinking user flow...
		}
	});

	/**
	 * Extends wp.customize.controlConstructor with control constructor for
	 * menu_location, menu_item, nav_menu, and new_menu.
	 */
	$.extend( api.controlConstructor, {
		menu_location: api.Menus.MenuLocationControl,
		nav_menu_item: api.Menus.MenuItemControl,
		nav_menu: api.Menus.MenuControl,
		nav_menu_name: api.Menus.MenuNameControl,
		new_menu: api.Menus.NewMenuControl
	});

	/**
	 * Extends wp.customize.panelConstructor with section constructor for menus.
	 */
	$.extend( api.panelConstructor, {
		menus: api.Menus.MenusPanel
	});

	/**
	 * Extends wp.customize.sectionConstructor with section constructor for menu.
	 */
	$.extend( api.sectionConstructor, {
		nav_menu: api.Menus.MenuSection,
		new_menu: api.Menus.NewMenuSection
	});

	/**
	 * Init Customizer for menus.
	 */
	api.bind( 'ready', function() {

		// Set up the menu items panel.
		api.Menus.availableMenuItemsPanel = new api.Menus.AvailableMenuItemsPanelView({
			collection: api.Menus.availableMenuItems
		});

		api.bind( 'saved', function ( data ) {
			if ( data.nav_menu_updates || data.nav_menu_item_updates ) {
				api.Menus.applySavedData( data );
			}
		} );
	} );

	/**
	 * When customize_save comes back with a success, make sure any inserted
	 * nav menus and items are properly re-added with their newly-assigned IDs.
	 *
	 * @param {object} data
	 * @param {array} data.nav_menu_updates
	 * @param {array} data.nav_menu_item_updates
	 */
	api.Menus.applySavedData = function ( data ) {

		var insertedMenuIdMapping = {};

		_( data.nav_menu_updates ).each(function ( update ) {
			if ( 'inserted' === update.status ) {
				if ( ! update.previous_term_id ) {
					throw new Error( 'Expected previous_term_id' );
				}
				if ( ! update.term_id ) {
					throw new Error( 'Expected term_id' );
				}
				insertedMenuIdMapping[ update.previous_term_id ] = update.term_id;

				// @todo Revisit this when menus can be added again.
				// @todo Now we have to create the new menu section, menu control, and menu setting.
			}
		} );

		_( data.nav_menu_item_updates ).each(function ( update ) {
			var oldCustomizeId, newCustomizeId, oldSetting, newSetting, settingValue, newControl;
			if ( update.status === 'inserted' ) {
				if ( ! update.previous_post_id ) {
					throw new Error( 'Expected previous_post_id' );
				}
				if ( ! update.post_id ) {
					throw new Error( 'Expected previous_post_id' );
				}
				if ( ! update.post_id ) {
					throw new Error( 'Expected previous_post_id' );
				}
				oldCustomizeId = 'nav_menu_item[' + String( update.previous_post_id ) + ']';
				if ( ! api.has( oldCustomizeId ) ) {
					throw new Error( 'Expected setting to exist: ' + oldCustomizeId );
				}
				oldSetting = api( oldCustomizeId );
				if ( ! api.control.has( oldCustomizeId ) ) {
					throw new Error( 'Expected control to exist: ' + oldCustomizeId );
				}

				settingValue = oldSetting.get();
				if ( ! settingValue ) {
					throw new Error( 'Did not expect setting to be empty (deleted).' );
				}
				settingValue = _.clone( settingValue );

				// If the menu was also inserted, then make sure it uses the new menu ID for nav_menu_term_id.
				if ( insertedMenuIdMapping[ settingValue.nav_menu_term_id ] ) {
					settingValue.nav_menu_term_id = insertedMenuIdMapping[ settingValue.nav_menu_term_id ];
				}

				newCustomizeId = 'nav_menu_item[' + String( update.post_id ) + ']';
				newSetting = api.create( newCustomizeId, newCustomizeId, settingValue, {
					type: 'nav_menu_item',
					transport: 'postMessage',
					previewer: api.previewer
				} );

				// Add the menu control.
				newControl = new api.controlConstructor.nav_menu_item( newCustomizeId, {
					params: {
						type: 'nav_menu_item',
						content: '<li id="customize-control-nav_menu_item-' + String( update.post_id ) + '" class="customize-control customize-control-nav_menu_item"></li>',
						menu_id: update.post_id,
						section: 'nav_menu[' + String( settingValue.nav_menu_term_id ) + ']',
						priority: api.control( oldCustomizeId ).priority.get(),
						active: true,
						settings: {
							'default': newCustomizeId
						}
					},
					previewer: api.previewer
				} );

				// Remove old setting and control.
				api.control( oldCustomizeId ).container.remove();
				api.control.remove( oldCustomizeId );

				// Add new control to take its place.
				api.control.add( newCustomizeId, newControl );

				// Delete the placeholder and preview the new setting.
				oldSetting.callbacks.disable(); // Prevent setting from being marked as dirty when it is set to false.
				oldSetting.set( false );
				oldSetting.preview();
				newSetting.preview();
			}
		});
	};

	/**
	 * Focus a menu item control.
	 *
	 * @param {string} menuItemId
	 */
	api.Menus.focusMenuItemControl = function( menuItemId ) {
		var control = api.Menus.getMenuItemControl( menuItemId );

		if ( control ) {
			control.focus();
		}
	};

	/**
	 * Get the control for a given menu.
	 *
	 * @param menuId
	 * @return {wp.customize.controlConstructor.menus[]}
	 */
	api.Menus.getMenuControl = function( menuId ) {
		return api.control( 'nav_menu[' + menuId + ']' );
	};

	/**
	 * Given a menu item type & object, get the label associated with it.
	 *
	 * @param {string} type
	 * @param {string} object
	 * @return {string}
	 */
	api.Menus.getTypeLabel = function( type, object ) {
		var label,
			data = api.Menus.data;

		if ( 'post_type' === type ) {
			if ( data.itemTypes.postTypes[ object ] ) {
				label = data.itemTypes.postTypes[ object ].label;
			} else {
				label = data.l10n.postTypeLabel;
			}
		} else if ( 'taxonomy' === type ) {
			if ( data.itemTypes.taxonomies[ object ] ) {
				label = data.itemTypes.taxonomies[ object ].label;
			} else {
				label = data.l10n.taxonomyTermLabel;
			}
		} else {
			label = data.l10n.custom_label;
		}

		return label;
	};

	/**
	 * Given a menu item ID, get the control associated with it.
	 *
	 * @param {string} menuItemId
	 * @return {object|null}
	 */
	api.Menus.getMenuItemControl = function( menuItemId ) {
		return api.control( menuItemIdToSettingId( menuItemId ) );
	};

	/**
	 * @param {String} menuItemId
	 */
	function menuItemIdToSettingId( menuItemId ) {
		return 'nav_menu_item[' + menuItemId + ']';
	}

})( wp.customize, wp, jQuery );

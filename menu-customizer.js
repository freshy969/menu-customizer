/* global _wpCustomizeMenusSettings, confirm, alert, wpNavMenu */
( function( api, wp, $ ) {
	'use strict';

	if ( ! wp || ! wp.customize ) { return; }

	// Set up our namespace.
	var OldPreviewer;

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
	api.Menus.data = _wpCustomizeMenusSettings || {};

	/**
	 * wp.customize.Menus.MenuItemModel
	 *
	 * A single menu item model.
	 *
	 * @constructor
	 * @augments Backbone.Model
	 */
	api.Menus.MenuItemModel = Backbone.Model.extend({
		transport: api.Menus.data.menuItemTransport,
		params: [],
		menu_item_id: null,
		original_id: 0,
		menu_id: 0,
		depth: 0,
		menu_item_parent_id: 0,
		type: 'menu_item'
	});

	/**
	 * wp.customize.Menus.AvailableItemModel
	 *
	 * A single available menu item model.
	 *
	 * @constructor
	 * @augments Backbone.Model
	 */
	api.Menus.AvailableItemModel = Backbone.Model.extend({
		id: null,
		name: null,
		type: null,
		type_label: null,
		obj_type: null,
		date: null
	});

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
	 * wp.customize.Menus.MenuModel
	 *
	 * A single menu model.
	 *
	 * @constructor
	 * @augments Backbone.Model
	 */
	api.Menus.MenuModel = Backbone.Model.extend({
		id: null
	});

	/**
	 * wp.customize.Menus.MenuCollection
	 *
	 * Collection for menu models.
	 *
	 * @constructor
	 * @augments Backbone.Collection
	 */
	api.Menus.MenuCollection = Backbone.Collection.extend({
		model: api.Menus.MenuModel
	});
	api.Menus.allMenus = new api.Menus.MenuCollection( api.Menus.data.allMenus );

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
			api.Menus.Previewer.bind( 'url', this.close );
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
				thisTerm = self.searchTerm,
			    typeInner = $( '#available-menu-items-search .accordion-section-content' ),
			    itemTemplate = wp.template( 'available-menu-item' );

			if ( 0 > page ) {
				return;
			} else if ( 1 < page ) {
				$( '#available-menu-items-search' ).addClass( 'loading-more' );
			} else if ( '' === this.searchTerm ) {
				typeInner.html( '' );
				return;
			}
			$( '#available-menu-items-search' ).addClass( 'loading' );
			self.loading = true;
			params = {
				'action': 'search-available-menu-items-customizer',
				'customize-menus-nonce': api.Menus.data.nonce,
				'wp_customize': 'on',
				'search': thisTerm,
				'page': page
			};
			$.post( wp.ajax.settings.url, params, function( response ) {
				var items;
				if ( self.searchTerm !== thisTerm ) {
					// Term changed since ajax call was fired, wait for the next one.
					if ( ! self.searchTerm ) {
						$( '#available-menu-items-search' ).removeClass( 'loading loading-more' );
						self.loading = false;
					}
					return;
				}
				if ( 1 === page ) {
					// Clear previous results as it's a new search.
					typeInner.html( '' );
				}
				if ( response.data && response.data.message ) {
					if ( 0 === typeInner.children().length ) {
						// No results were found.
						typeInner.html( '<p class="nothing-found">' + response.data.message + '</p>' );
					}
					$( '#available-menu-items-search' ).removeClass( 'loading loading-more' );
					self.loading = false;
					self.pages.search = -1;
				} else if ( response.success && response.data ) {
					items = response.data.items;
					$( '#available-menu-items-search' ).removeClass( 'loading loading-more' );
					self.loading = false;
					items = new api.Menus.AvailableItemCollection( items );
					self.collection.add( items.models );
					items.each( function( menuItem ) {
						typeInner.append( itemTemplate( menuItem.attributes ) );
					} );
					if ( 20 > items.length ) {
						self.pages.search = -1; // Up to 20 posts and 20 terms in results, if <20, no more results for either.
					} else {
						self.pages.search = self.pages.search + 1;
					}
				}
			});
		},

		// Render the individual items.
		initList: function() {
			var self = this;

			// Render the template for each item by type.
			$.each( api.Menus.data.itemTypes, function( index, type ) {
				self.pages[type.type] = 0;
				self.loadItems( type.type, type.obj_type );
			} );
		},

		// Load available menu items.
		loadItems: function( type, obj_type ) {
			var self = this, params,
			    itemTemplate = wp.template( 'available-menu-item' );

			if ( 0 > self.pages[type] ) {
				return;
			}
			$( '#available-menu-items-' + type + ' .accordion-section-title' ).addClass( 'loading' );
			self.loading = true;
			params = {
				'action': 'load-available-menu-items-customizer',
				'customize-menus-nonce': api.Menus.data.nonce,
				'wp_customize': 'on',
				'type': type,
				'obj_type': obj_type,
				'page': self.pages[type]
			};
			$.post( wp.ajax.settings.url, params, function( response ) {
				var items, typeInner;
				if ( response.data && response.data.message ) {
					// Display error message
					alert( response.data.message );
				} else if ( response.success && response.data ) {
					items = response.data.items;
					$( '#available-menu-items-' + type + ' .accordion-section-title' ).removeClass( 'loading' );
					self.loading = false;
					if ( 0 === items.length ) {
						self.pages[type] = -1;
						return;
					}
					items = new api.Menus.AvailableItemCollection( items );
					self.collection.add( items.models );
					typeInner = $( '#available-menu-items-' + type + ' .accordion-section-content' );
					items.each( function( menu_item ) {
						typeInner.append( itemTemplate( menu_item.attributes ) );
					} );
					self.pages[type] = self.pages[type] + 1;
				}
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
				'id': 0,
				'name': itemName.val(),
				'url': itemUrl.val(),
				'type': 'custom',
				'type_label': api.Menus.data.l10n.custom_label,
				'obj_type': 'custom'
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
		},

		/**
		 * Show/hide the settings when clicking on the menu item handle.
		 */
		_setupControlToggle: function() {
			var self = this;

			this.container.find( '.menu-item-handle' ).on( 'click', function( e ) {
				e.preventDefault();
				e.stopPropagation();
				var menuControl = self.getMenuControl();
				if ( menuControl.isReordering || menuControl.isSorting ) {
					return;
				}
				self.toggleForm();
			} );
		},

		/**
		 * Set up the menu-item-reorder-nav
		 */
		_setupReorderUI: function() {
			var self = this, template, $reorderNav;

			template = wp.template( 'menu-item-reorder-nav' );

			/**
			 * Add the menu item reordering elements to the menu item control.
			 */
			this.container.find( '.item-controls' ).after( template );

			/**
			 * Handle clicks for up/down/left-right on the reorder nav.
			 */
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
					i = self.getMenuItemPosition();

				if ( ( isMoveUp && 0 === i ) || ( isMoveDown && i === self.getMenuControl().setting().length - 1 ) ) {
					return;
				}

				if ( isMoveUp ) {
					self.moveUp();
				} else if ( isMoveDown ) {
					self.moveDown();
				} else if ( isMoveLeft ) {
					self.moveLeft();
				} else if ( isMoveRight ) {
					self.moveRight();
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
			// @todo add more elements
			// @todo instead of applying control.params to the content template, we can apply them to the built DOM as the changes happen?


			_.each( control.elements, function ( element, property ) {
				element.bind(function ( value ) {
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

			control.setting.bind(function ( object ) {
				if ( ! object ) {
					return;
				}
				_.each( object, function ( value, key ) {
					if ( control.elements[ key ] ) {
						control.elements[ key ].set( object[ key ] );
					}
				});
			});

			// When saving, update original_id to menu_item_id, initiating new clones as needed.
			api.bind( 'save', function() {
				console.warn( 'Need to process customize_save_response.' );
			} );
		},

		/**
		 * Set up event handlers for menu item deletion.
		 */
		_setupRemoveUI: function() {
			var control = this, $removeBtn;

			// Configure delete button.
			$removeBtn = this.container.find( '.item-delete' );

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
					control.container.remove();
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
		 * Amend the control's params with the data necessary for the JS template just in time.
		 */
		renderContent: function () {

			var control = this,
				settingValue = control.setting(),
				containerClasses;

			control.params.title = settingValue.title || '';
			containerClasses = [
				'menu-item',
				'menu-item-depth-0', // @todo Need to determine depth client-side
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
			// @todo control.params.title = ( ! isset( $item->label ) || '' == $item->label ) ? $title : $item->label;

			control.params.el_classes = containerClasses.join( ' ' );
			control.params.item_type_label = settingValue.type; // @todo lookup label
			control.params.item_type = settingValue.type;
			control.params.url = settingValue.url;
			control.params.target = settingValue.target;
			control.params.attr_title = settingValue.attr_title;
			control.params.classes = settingValue.classes.join( ' ' );
			control.params.attr_title = settingValue.attr_title;
			control.params.xfn = settingValue.xfn;
			control.params.description = settingValue.description;
			control.params.parent = settingValue.parent;

			control.params.original_title = false; // @todo This is going to require Ajax.
			control.params.depth = 0; // @todo Need to calculate this client-side.

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

		/**
		 * Submit the menu item form via Ajax and get back the updated instance,
		 * along with the new menu item control form to render.
		 *
		 * @param {object} [args]
		 */
		updateMenuItem: function( args ) {

			throw new Error( 'Obsolete.' );

			var self = this, clone = 0, processing, inputs, item, params, spinner;
			// Check whether this menu item is cloned already; if not, let's clone it.
			if ( this.params.original_id === this.params.menu_item_id ) {
				clone = 1;
			}

			spinner = $( self.container ).find( '.menu-item-actions .spinner' );

			// Show spinner.
			spinner.css( 'visibility', 'visible' );

			// Trigger processing states.
			self.container.addClass( 'saving' );
			processing = api.state( 'processing' );
			processing( processing() + 1 );

			// Get item.
			item = {};
			if ( 'undefined' === typeof args ) {
				inputs = $( self.container ).find( ':input[name]' );
				inputs.each( function() {
					var name = this.name;
					name = name.replace( /\[\d+]/, '' ); // Remove the ID-part of the name which is used by nav-menus.php.
					item[name] = $( this ).val();
				} );
			} else {
				item = args;
			}

			params = {
				'action': 'update-menu-item-customizer',
				'wp_customize': 'on',
				'clone': clone,
				'item_id': self.params.menu_item_id,
				'menu-item': item,
				'customize-menu-item-nonce': api.Menus.data.nonce
			};

			// @todo replace this with wp.ajax.post()
			$.post( wp.ajax.settings.url, params, function( response ) {
				var id, menuControl, menuItemIds, i;
				if ( response.data && response.data.message ) {
					// Display error message
					alert( response.data.message );
				} else if ( response.success && response.data ) {
					id = response.data;

					// Update item control accordingly with new id.
					// Note that the id is only updated where necessary - the original id
					// is still maintained for the setting and in the UI.
					id = parseInt( id, 10 );
					self.params.menu_item_id = id;
					self.id = 'nav_menus[' + self.params.menu_id + '][' + id + ']';

					// Replace original id of this item with cloned id in the menu setting.
					menuControl = api.Menus.getMenuControl( self.params.menu_id );

					if ( clone ) {

						menuItemIds = menuControl.setting().slice();
						i = _.indexOf( menuItemIds, self.params.original_id );

						menuItemIds[i] = id;
						menuControl.setting( menuItemIds );

						// Update parent id for direct children items.
						api.control.each( function( control ) {
							if ( 'menu_item' === control.params.type && self.params.original_id === parseInt( control.params.menu_item_parent_id, 10 ) ) {
								control.params.menu_item_parent_id = id;
								//control.container.find( '.menu-item-data-parent-id' ).val( id );
								control.container.find( '.menu-item-parent-id' ).val( id );
								control.updateMenuItem(); // @todo this requires cloning all direct children, which will in turn recursively clone all submenu items - works, but is there a better approach?
							}
						} );
					} else {
						// There would be no change to the value, so just re-trigger a preview
						// @todo There should really be Customizer settings that contain all of the menu item fields
						menuControl.setting.preview();
					}
				}

				// Remove processing states.
				self.container.removeClass( 'saving' );
				processing( processing() - 1 );

				// Hide spinner.
				spinner.css( 'visibility', 'hidden' );
			} );
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

			$menuitem = this.container.find( 'div.menu-item:first' );
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
		 * Get the position (index) of the item in the containing menu.
		 *
		 * @returns {Number}
		 */
		getMenuItemDepth: function() {
			return this.params.depth;
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
			if ( 0 !== this.params.depth ) {
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
			if ( 0 !== this.params.depth ) {
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
			var i, menuSetting, menuItemIds, adjacentMenuItemId;

			i = this.getMenuItemPosition();

			menuSetting = this.getMenuControl().setting;
			menuItemIds = Array.prototype.slice.call( menuSetting() ); // clone
			adjacentMenuItemId = menuItemIds[i + offset];
			menuItemIds[i + offset] = this.params.menu_item_id;
			menuItemIds[i] = adjacentMenuItemId;

			menuSetting( menuItemIds );

			// @todo update menu item parents and depth if necessary based on new previous item.
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

			depth = this.getMenuItemDepth();
			i = this.getMenuItemPosition();

			if ( 0 === i ) {
				// First item can never be moved into or out of a sub-menu.
				return;
			}

			menuSetting = this.getMenuControl().setting;
			menuItemIds = Array.prototype.slice.call( menuSetting() );
			previousMenuItemId = menuItemIds[i - 1];
			previousMenuItem = api.Menus.getMenuItemControl( previousMenuItemId );
			previousItemDepth = previousMenuItem.params.depth;

			// Can we move this item in this direction?
			if ( 1 === offset && previousItemDepth < depth ) {
				// Already a sub-item of previous item.
				return;
			} else if ( -1 === offset && 0 === depth ) {
				// Already at the top level.
				return;
			}

			// Get new menu item parent id.
			if ( 1 === offset ) {
				// Parent will be previous item if they have the same depth.
				if ( previousItemDepth === depth ) {
					parentId = previousMenuItemId;
				} else {
					// Find closest previous item of the same current depth.
					ii = 1;
					while ( ii <= i ) {
						parentControl = api.Menus.getMenuItemControl( menuItemIds[i - ii] );
						if ( depth === parentControl.params.depth ) {
							parentId = menuItemIds[i - ii];
							break;
						} else {
							ii++;
						}
					}
				}
			} else {
				if ( 1 === depth ) {
					parentId = 0;
				} else {
					// Find closest previous item with depth of 2 less than the current depth.
					ii = 1;
					while ( ii <= i ) {
						parentControl = api.Menus.getMenuItemControl( menuItemIds[i - ii] );
						if ( depth - 2 === parentControl.params.depth ) {
							parentId = menuItemIds[i - ii];
							break;
						} else {
							ii++;
						}
					}
				}
			}

			// Update menu item parent field.
			menuItemSetting = _.clone( control.setting() );
			menuItemSetting.menu_item_parent = parentId;
			control.setting( menuItemSetting );

			// Update depth class for UI.
			this.container.find( '.menu-item' )
				.removeClass( 'menu-item-depth-' + depth )
				.addClass( 'menu-item-depth-' + ( depth + offset ) );

			// Does this item have any children?
			if ( i + 1 === menuItemIds.length ) {
				// Last item.
				return;
			}
			nextMenuItemId = menuItemIds[i + 1];
			nextMenuItem = api.Menus.getMenuItemControl( nextMenuItemId );
			nextItemDepth = nextMenuItem.params.depth;
			if ( depth < nextItemDepth ) {
				ii = 1;
				while ( ii + i < menuItemIds.length ) {
					childControl = api.Menus.getMenuItemControl( menuItemIds[i + ii] );
					childDepth = childControl.params.depth;
					if ( depth === childDepth ) {
						// No longer at a child control.
						break;
					} else {
						// Update depth parameter;
						childControl.params.depth = childDepth + offset;

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

			control.setting.bind( function ( to, from ) {
				if ( false === to ) {
					control._handleDeletion();
				} else {
					if ( ! from || to.position !== from.position ) {
						// @todo now we need to update the priorities of all the menu item controls to reflect the new positions
						// @todo self._applyCardinalOrderClassNames();
					}
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
			var self = this;

			if ( ! menuList.is( self.$sectionContent ) ) {
				throw new Error( 'Unexpected menuList.' );
			}

			menuList.on( 'sortstart', function() {
				self.isSorting = true;
			});

			menuList.on( 'sortupdate', function() {
					var menuItemContainerIds = self.$sectionContent.sortable( 'toArray' ), menuItemIds;

					/**
					 * Extract the menu item ids from the containers.
					 */
					menuItemIds = $.map( menuItemContainerIds, function( menuItemContainerId ) {
						return parseInt( menuItemContainerId.replace( 'customize-control-nav_menus-' + self.params.menu_id + '-', '' ), 10 );
					} );
					self.setting( menuItemIds );
				} );

			menuList.on( 'sortstop', function( event, ui ) {

				var id, menuItemControl;

				id = ui.item.find( '.menu-item-data-db-id' ).val();
				if ( ! id ) {
					return;
					}
				id = parseInt( id, 10 );
				menuItemControl = api.Menus.getMenuItemControl( id );
				if ( ! menuItemControl ) {
					api.control.each( function( control ) {
						if ( 'menu_item' === control.params.type && control.params.original_id === id ) {
							menuItemControl = control;
					}
					} );
				}

				if ( menuItemControl ) {
					// Ensure that the sortable's own stop() callback has fully fired.
					setTimeout( function() {
						menuItemControl.updateMenuItem();
					} );
					}

				setTimeout( function() {
					self.isSorting = false;
				}, 300 );
			} );

			self.isReordering = false;

			/**
			 * Keyboard-accessible reordering.
			 */
			this.container.find( '.reorder-toggle' ).on( 'click keydown', function( event ) {
				if ( 'keydown' === event.type && ! ( 13 === event.which || 32 === event.which ) ) { // Enter or Spacebar
					return;
				}

				self.toggleReordering( ! self.isReordering );
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
		 * @param {number}   item - Object ID.
		 * @param {function} [callback] - Callback to fire when item is added.
		 * @returns {object|false} menu_item control instance, or false on error.
		 */
		addItemToMenu: function( item, callback ) {
			throw new Error( 'No ajax should be needed now.' );
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

		submit: function() {
			throw new Error( 'This needs to be updated for the new settings.' );

			var self = this,
				processing,
				params,
				container = this.container.closest( '.accordion-section-new-menu' ),
				name = container.find( '.menu-name-field' ).first(),
				spinner = container.find( '.spinner' );

			// Show spinner.
			spinner.css( 'visibility', 'visible' );

			// Trigger customizer processing state.
			processing = wp.customize.state( 'processing' );
			processing( processing() + 1 );

			params = {
				'action': 'add-nav-menu-customizer',
				'wp_customize': 'on',
				'menu-name': name.val(),
				'customize-nav-menu-nonce': api.Menus.data.nonce
			};

			$.post( wp.ajax.settings.url, params, function( response ) {
				if ( response.data && response.data.message ) {
					// Display error message
					alert( response.data.message );

					// Remove this level of the customizer processing state.
					processing( processing() - 1 );

					// Hide spinner.
					spinner.css( 'visibility', 'hidden' );
				} else if ( response.success && response.data && response.data.id && response.data.name ) {
					var priority, sectionId, SectionConstructor, menuSection,
						menuSettingId, settingArgs, ControlConstructor, menuControl,
						sectionParams, option;
					response.data.id = parseInt( response.data.id, 10 );
					sectionId = 'nav_menus[' + response.data.id + ']';
					sectionParams = {
						id: sectionId,
						panel: 'menus',
						title: response.data.name,
						customizeAction: api.Menus.data.l10n.customizingMenus,
						type: 'menu',
						priority: priority
					};

					// Add the menu section.
					SectionConstructor = api.Section;
					menuSection = new SectionConstructor( sectionId, {
						params: sectionParams
					} );
					api.section.add( sectionId, menuSection );

					// Register the menu control setting.
					menuSettingId = 'nav_menu_' + response.data.id;
					settingArgs = {
						type: 'nav_menu',
						transport: 'refresh',
						previewer: self.setting.previewer
					};
					api.create( menuSettingId, menuSettingId, '', settingArgs );
					api( menuSettingId ).set( [] ); // Change to mark as dirty.

					// Add the menu control.
					ControlConstructor = api.controlConstructor.nav_menu;
					menuControl = new ControlConstructor( menuSettingId, {
						params: {
							type: 'nav_menu',
							content: '<li id="customize-control-nav_menu_' + response.data.id + '" class="customize-control customize-control-nav_menu"></li>', // @todo core should do this for us
							menu_id: response.data.id,
							section: sectionId,
							priority: 998,
							active: true,
							settings: {
								'default': menuSettingId
							}
						},
						previewer: self.setting.previewer
					} );
					api.control.add( menuSettingId, menuControl );

					// @todo: nemu name and auto-add new items controls
					// requires @link https://core.trac.wordpress.org/ticket/30738 at a minimum to be reasonable

					// Add the new menu as an option to each theme location control.
					option = '<option value="' + response.data.id + '">' + response.data.name + '</option>';
					$( '#accordion-section-menu_locations .customize-control select' ).append( option );

					// Remove this level of the customizer processing state.
					processing( processing() - 1 );

					// Hide spinner.
					spinner.css( 'visibility', 'hidden' );

					// Clear name field.
					name.val( '' );

					wp.a11y.speak( api.Menus.data.l10n.menuAdded );

					// Focus on the new menu section.
					api.section( sectionId ).focus(); // @todo should we focus on the new menu's control and open the add-items panel? Thinking user flow...
				}
			});

			return false;
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
		nav_menu_name: api.Menus.MenuNameControl
		//@todo new_menu: api.Menus.NewMenuControl
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
		nav_menu: api.Menus.MenuSection
		//new_menu: api.Menus.NewMenuSection
	});

	/**
	 * Capture the instance of the Previewer since it is private.
	 */
	OldPreviewer = api.Previewer;
	api.Previewer = OldPreviewer.extend( {
		initialize: function( params, options ) {
			api.Menus.Previewer = this;
			OldPreviewer.prototype.initialize.call( this, params, options );
			this.bind( 'refresh', this.refresh );
		}
	} );

	/**
	 * Init Customizer for menus.
	 */
	api.bind( 'ready', function() {
		// Set up the menu items panel.
		api.Menus.availableMenuItemsPanel = new api.Menus.AvailableMenuItemsPanelView({
			collection: api.Menus.availableMenuItems
		});
	} );

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
	 * @param menu_id
	 * @return {wp.customize.controlConstructor.menus[]}
	 */
	api.Menus.getMenuControl = function( menu_id ) {
		var settingId, menuControl;

		settingId = 'nav_menu_' + menu_id;
		menuControl = api.control( settingId );

		if ( ! menuControl ) {
			return;
		}

		return menuControl;
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

	/**
	 * Update Section Title as menu name is changed and item handle title when label is changed.
	 */
	function setupUIPreviewing() {
		// @todo This is wrong. We need to bind on menuControl.setting.bind( function ( menu ) { /* ... */ } )

		return;

		$( '#accordion-panel-menus' ).on( 'input', '.live-update-section-title', function( e ) {
			var input = $( e.currentTarget ),
				section = input.closest( '.accordion-section' ),
				name = input.val(),
				title = section.find( '.accordion-section-title' ),
				title2 = section.find( '.customize-section-title h3' ),
				id = section.attr( 'id' ),
				location = section.find( '.menu-in-location' ),
				action = title2.find( '.customize-action' );
			// Empty names are not allowed (will not be saved), don't update to one.
			if ( name ) {
				title.text( name );
				if ( location.length ) {
					location.appendTo( title );
				}
				title2.text( name );
				if ( action.length ) {
					action.prependTo( title2 );
				}
				id = id.replace( 'accordion-section-nav_menus[', '' );
				id = id.replace( ']', '' );
				// @todo $( '#accordion-section-menu_locations .customize-control select option[value=' + id + ']' ).text( name );

				// Update menu name in other location checkboxes.
				section.find( '.customize-control-checkbox input' ).each( function() {
					var locationId = $( this ).data( 'location-id' );
					if ( $( this ).prop( 'checked' ) ) {
						$( '.current-menu-location-name-' + locationId ).text( name );
					}
				} );
			}
		} );
		$( '#accordion-panel-menus' ).on( 'input', '.edit-menu-item-title', function( e ) {
			var input = $( e.currentTarget ), title, titleEl;
			title = input.val();
			titleEl = input.closest( '.menu-item' ).find( '.menu-item-title' );
			// Don't update to empty title.
			if ( title ) {
				titleEl
					.text( title )
					.removeClass( 'no-title' );
			} else {
				titleEl
					.text( api.Menus.data.l10n.untitled )
					.addClass( 'no-title' );
			}
		} );
	}

	$( document ).ready( function() {
		setupUIPreviewing();
	} );

})( wp.customize, wp, jQuery );

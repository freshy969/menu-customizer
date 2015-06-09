/*global jQuery, JSON, _wpCustomizePreviewMenusExports, _ */

wp.customize.menusPreview = ( function( $ ) {
	'use strict';
	var self;

	self = {
		renderQueryVar: null,
		renderNonceValue: null,
		renderNoncePostKey: null,
		previewCustomizeNonce: null,
		previewReady: $.Deferred(),
		requestUri: '/',
		theme: {
			active: false,
			stylesheet: ''
		},
		navMenuInstanceArgs: {}
	};

	wp.customize.bind( 'preview-ready', function() {
		self.previewReady.resolve();
	} );
	self.previewReady.done( function() {
		self.init();
	} );

	/**
	 * Bootstrap functionality.
	 */
	self.init = function() {
		var self = this;

		if ( 'undefined' !== typeof _wpCustomizePreviewMenusExports ) {
			$.extend( self, _wpCustomizePreviewMenusExports );
		}

		self.previewReady.done( function() {
			wp.customize.each( function ( setting, id ) {
				setting.id = id;
				self.bindListener( setting );
			} );

			wp.customize.preview.bind( 'setting', function( args ) {
				var id, value;
				args = args.slice();
				id = args.shift();
				value = args.shift();
				if ( ! wp.customize.has( id ) ) {
					// Currently customize-preview.js is not creating settings for dynamically-created settings in the pane; so we have to do it
					wp.customize.create( id, value ); // @todo This should be in core
					wp.customize( id ).id = id;
					self.bindListener( wp.customize( id ) );
				}
			} );
		} );
	};

	self.bindListener = function ( setting ) {
		var matches, themeLocation;

		matches = setting.id.match( /^nav_menu\[(\d+)]$/ );
		if ( matches ) {
			setting.navMenuId = parseInt( matches[1], 10 );
			setting.bind( self.onChangeNavMenuSetting );
			return;
		}

		matches = setting.id.match( /^nav_menu_item\[(\d+)]$/ );
		if ( matches ) {
			setting.navMenuItemId = parseInt( matches[1], 10 );
			setting.bind( self.onChangeNavMenuItemSetting );
			return;
		}

		matches = setting.id.match( /^nav_menu_locations\[(.+?)]/ );
		if ( matches ) {
			themeLocation = matches[1];
			setting.bind( function() {
				self.refreshMenuLocation( themeLocation );
			} );
		}
	};

	/**
	 * Handle changing of a nav_menu setting.
	 *
	 * @this {wp.customize.Setting}
	 * @param {object} to
	 */
	self.onChangeNavMenuSetting = function( to ) {
		var setting = this;
		if ( ! setting.navMenuId ) {
			throw new Error( 'Expected navMenuId property to be set.' );
		}
		self.refreshMenu( setting.navMenuId );
	};

	/**
	 * Handle changing of a nav_menu_item setting.
	 *
	 * @this {wp.customize.Setting}
	 * @param {object} to
	 * @param {object} from
	 */
	self.onChangeNavMenuItemSetting = function( to, from ) {
		if ( from && from.nav_menu_term_id && ( ! to || from.nav_menu_term_id !== to.nav_menu_term_id ) ) {
			self.refreshMenu( from.nav_menu_term_id );
		}
		if ( to && to.nav_menu_term_id ) {
			self.refreshMenu( to.nav_menu_term_id );
		}
	};

	/**
	 * Update a given menu rendered in the preview.
	 *
	 * @param {int} menuId
	 */
	self.refreshMenu = function( menuId ) {
		var self = this, assignedLocations = [];

		wp.customize.each(function ( setting, id ) {
			var matches = id.match( /^nav_menu_locations\[(.+?)]/ );
			if ( matches && menuId === setting() ) {
				assignedLocations.push( matches[1] );
			}
		});

		_.each( self.navMenuInstanceArgs, function( navMenuArgs, instanceNumber ) {
			if ( menuId === navMenuArgs.menu || -1 !== _.indexOf( assignedLocations, navMenuArgs.theme_location ) ) {
				self.refreshMenuInstance( instanceNumber );
			}
		} );
	};

	self.refreshMenuLocation = function( location ) {
		_.each( self.navMenuInstanceArgs, function( navMenuArgs, instanceNumber ) {
			if ( location === navMenuArgs.theme_location ) {
				self.refreshMenuInstance( instanceNumber );
			}
		} );
	};

	/**
	 * Update a specific instance of a given menu on the page.
	 *
	 * @param {int} instanceNumber
	 */
	self.refreshMenuInstance = function( instanceNumber ) {
		var self = this, data, customized, container, request, wpNavArgs;

		if ( ! self.navMenuInstanceArgs[ instanceNumber ] ) {
			throw new Error( 'unknown_instance_number' );
		}

		container = $( '#partial-refresh-menu-container-' + String( instanceNumber ) );

		data = {
			nonce: self.previewCustomizeNonce, // for Customize Preview
			wp_customize: 'on'
		};
		if ( ! self.theme.active ) {
			data.theme = self.theme.stylesheet;
		}
		data[ self.renderQueryVar ] = '1';
		customized = {};
		wp.customize.each( function( setting, id ) {
			if ( /^(nav_menu|nav_menu_locations)/.test( id ) ) {
				customized[ id ] = setting.get();
			}
		} );
		data.customized = JSON.stringify( customized );
		data[ self.renderNoncePostKey ] = self.renderNonceValue;

		wpNavArgs = $.extend( {}, self.navMenuInstanceArgs[ instanceNumber ] );
		data.wp_nav_menu_args_hash = wpNavArgs.args_hash;
		delete wpNavArgs.args_hash;
		data.wp_nav_menu_args = JSON.stringify( wpNavArgs );

		// @todo Allow plugins to prevent a partial refresh via jQuery event like for widgets? Fallback to self.preview.send( 'refresh' );

		container.addClass( 'customize-partial-refreshing' );

		request = wp.ajax.send( null, {
			data: data,
			url: self.requestUri
		} );
		request.done( function( data ) {
			var eventParam;
			container.empty().append( $( data ) );
			eventParam = {
				instanceNumber: instanceNumber,
				wpNavArgs: wpNavArgs
			};
			$( document ).trigger( 'customize-preview-menu-refreshed', [ eventParam ] );
		} );
		request.fail( function() {
			// @todo provide some indication for why
		} );
		request.always( function() {
			container.removeClass( 'customize-partial-refreshing' );
		} );
	};

	return self;

}( jQuery ) );

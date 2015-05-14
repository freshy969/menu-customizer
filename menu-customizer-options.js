/* global jQuery, ajaxurl */

/*
 * Menu Customizer screen options JS.
 *
 * @todo potentially put this directly into the panel by doing a custom panel, once the standard panel html is finalized in #31336.
 */

// Global ajaxurl, 
(function($) {
	var menusPanelContainer;
	var customizeMenuOptions = {
		init : function() {
			// Add a screen options button to the Menus page header.
			var button = '<a id="customizer-menu-screen-options-button" title="Menu Options" href="#"></a>',
				header = $( '#accordion-panel-menus .accordion-sub-container ' );
			header.find( '.accordion-section:first .accordion-section-title' ).append( button );
			$( '#screen-options-wrap' ).prependTo( header );
			$( '#customize-control-menu_customizer_options' ).remove();
			$( '#screen-options-wrap' ).removeClass( 'hidden' );
			$( '#customizer-menu-screen-options-button' ).click( function() {
				$( '#customizer-menu-screen-options-button' ).toggleClass( 'active' );
				$( '#screen-options-wrap' ).toggleClass( 'active' );
				return false;
			} );
		}
	};

	// Show/hide/save screen options (columns). From common.js.
	var columns = {
		init : function() {
			var that = this;
			$('.hide-column-tog').click( function() {
				var $t = $(this), column = $t.val();
				if ( $t.prop('checked') ) {
					that.checked(column);
				}
				else {
					that.unchecked(column);
				}

				that.saveManageColumnsState();
			});
			$( '.hide-column-tog' ).each( function() {
			var $t = $(this), column = $t.val();
				if ( $t.prop('checked') ) {
					that.checked(column);
				}
				else {
					that.unchecked(column);
				}
			} );
		},

		saveManageColumnsState : function() {
			var hidden = this.hidden();
			$.post(ajaxurl, {
				action: 'hidden-columns',
				hidden: hidden,
				screenoptionnonce: $('#screenoptionnonce').val(),
				page: 'nav-menus'
			});
		},

		checked : function(column) {
			menusPanelContainer.addClass( 'field-' + column + '-active' );
		},

		unchecked : function(column) {
			menusPanelContainer.removeClass( 'field-' + column + '-active' );
		},

		hidden : function() {
			this.hidden = function(){
				return $('.hide-column-tog').not(':checked').map(function() {
					var id = this.id;
					return id.substring( id, id.length - 5 );
				}).get().join(',');
			};
		}
	};

	$( document ).ready( function() {
		menusPanelContainer = $( '#accordion-panel-menus' );
		columns.init();
		customizeMenuOptions.init();
	} );

})(jQuery);
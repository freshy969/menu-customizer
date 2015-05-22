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
			// @todo when this file is merged into menu-customizer.js add the button text to l10n
			var $button,
				$panel = $( '#accordion-panel-menus .panel-meta' ),
				$header = $panel.find( '.accordion-section-title' ),
				$help = $panel.find( '.customize-help-toggle' ),
				$content = $panel.find( '.customize-panel-description' ),
				$options = $( '#screen-options-wrap' ),
				buttonId = 'customizer-menu-screen-options-button',
				button = '<button id="' + buttonId + '" aria-expanded="false" tabindex="0"><span class="screen-reader-text">Menu Options</span></button>';

			// Add button
			$header.append( button );
			$button = $panel.find( '#' + buttonId );

			// Add menu options
			$options.insertAfter( $header.next( 'div' ) );
			$( '#customize-control-menu_customizer_options' ).remove();
			$options.removeClass( 'hidden' ).hide();

			// Menu options toggle
			$button.on( 'click', function() {
				// Hide description
				if ( $content.not( ':hidden' ) ) {
					$content.slideUp( 'fast' );
					$help.attr( 'aria-expanded', 'false' );
				}

				if ( $button.attr( 'aria-expanded' ) == 'true' ) {
					$button.attr( 'aria-expanded', 'false' );
					$panel.removeClass( 'open' );
					$panel.removeClass( 'active-menu-screen-options' );
					$options.slideUp( 'fast' );
				} else {
					$button.attr( 'aria-expanded', 'true' );
					$panel.addClass( 'open' );
					$panel.addClass( 'active-menu-screen-options' );
					$options.slideDown( 'fast' );
				}

				return false;
			} );

			// Help toggle
			$help.on( 'click', function() {
				if ( $button.attr( 'aria-expanded' ) == 'true' ) {
					$button.attr( 'aria-expanded', 'false' );
					$help.attr( 'aria-expanded', 'true' );
					$panel.addClass( 'open' );
					$panel.removeClass( 'active-menu-screen-options' );
					$options.slideUp( 'fast' );
					$content.slideDown( 'fast' );
				}
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
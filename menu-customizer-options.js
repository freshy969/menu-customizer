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
				$content = $panel.find( '.accordion-section-content' ),
				$options = $( '#screen-options-wrap' ),
				buttonId = 'customizer-menu-screen-options-button',
				button = '<span id="' + buttonId + '"><span class="screen-reader-text">Menu Options</span></span>';

			// Add button
			$header.append( button );
			$button = $( '#' + buttonId );

			// Add menu options
			$options.insertAfter( $header.next( 'div' ) );
			$( '#customize-control-menu_customizer_options' ).remove();
			$options.removeClass( 'hidden' ).hide();

			// Panel is actually open
			if ( $content.not( ':hidden' ) ) {
				$panel.addClass( 'open' );
				$panel.addClass( 'active-panel-description' );
			}
			
			// Toogle menu options 
			$button.on( 'click', function() {
				// Toggle .open if the content is already hidden
				if ( $content.is( ':hidden' ) ) {
					if ( $options.is( ':hidden' ) ) {
						$panel.addClass( 'open' );
					} else {
						$panel.removeClass( 'open' );
					}
				}

				// Hide description
				if ( $content.not( ':hidden' ) ) {
					$content.slideUp( 'fast' );
					$panel.removeClass( 'active-panel-description' );
				}

				// Toggle menu options
				$panel.toggleClass( 'active-menu-screen-options' );
				$options.slideToggle( 'fast' );

				return false;
			} );

			// Close menu options
			$header.on( 'click', function() {
				if ( $panel.hasClass( 'active-menu-screen-options' ) ) {
					$panel.removeClass( 'active-menu-screen-options' );
					$panel.addClass( 'open' );
					$options.slideUp( 'fast' );
					$content.slideDown( 'fast' );
				}

				$panel.toggleClass( 'active-panel-description' );
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
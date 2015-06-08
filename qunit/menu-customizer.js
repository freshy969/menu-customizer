/* global wp, module, equal, jQuery, test */

jQuery( function( ) {

	module( 'Customizer: Menu' );

	test( 'Menus is an object', function() {
		equal( typeof wp.customize.Menus, 'object' );
	});

});

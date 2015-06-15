<?php
/**
 * Customize Nav Menu Section Class
 *
 * @package WordPress
 * @subpackage customize
 */

/**
 * Customize Menu Section Class
 *
 * Custom section only needed in JS.
 */
class WP_Customize_Nav_Menu_Section extends WP_Customize_Section {
	/**
	 * Control type
	 *
	 * @access public
	 * @var string
	 */
	public $type = 'nav_menu';

	/**
	 * Get section params for JS.
	 *
	 * @return array
	 */
	function json() {
		$exported = parent::json();
		$exported['menu_id'] = intval( preg_replace( '/^nav_menu\[(\d+)\]/', '$1', $this->id ) );
		return $exported;
	}
}

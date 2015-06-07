<?php
/**
 * WordPress Customize Nav Menu Name control class.
 *
 * @package WordPress
 * @subpackage Customize
 * @since 4.3.0
 */

/**
 * Customize control to represent the name field for a given menu.
 *
 * @since 4.3.0
 */
class WP_Customize_Nav_Menu_Name_Control extends WP_Customize_Control {

	/**
	 * Type of control, used by JS.
	 *
	 * @var string
	 */
	public $type = 'nav_menu_name';

	/**
	 * No-op since we're using JS template.
	 */
	protected function render_content() {}

	/**
	 * Render the Underscore template for this control.
	 */
	protected function content_template() {
		?>
		<label>
			<input type="text" class="menu-name-field live-update-section-title" />
		</label>
		<?php
	}
}

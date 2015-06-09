<?php
/**
 * Custom Customizer Controls for the Menu Customizer.
 *
 * @package WordPress
 * @subpackage Customize
 */

/**
 * Customize Menu Section Class
 *
 * Implements the new-menu-ui toggle button instead of a regular section.
 */
class WP_Customize_New_Menu_Section extends WP_Customize_Section {
	/**
	 * Control type
	 *
	 * @access public
	 * @var string
	 */
	public $type = 'new_menu';

	/**
	 * Render the section, and the controls that have been added to it.
	 *
	 * @since Menu Customize 0.3
	 */
	protected function render() {
		?>
		<li id="accordion-section-<?php echo esc_attr( $this->id ); ?>" class="accordion-section-new-menu">

			<button class="button-secondary add-new-menu-item add-menu-toggle" tabindex="0">
				<?php echo esc_html( $this->title ); ?>
				<span class="screen-reader-text"><?php _e( 'Press return or enter to open' ); ?></span>
			</button>
			<ul class="new-menu-section-content"></ul>
		</li>
		<?php
	}
}

/**
 * New Menu Customize Control Class
 */
class WP_New_Menu_Customize_Control extends WP_Customize_Control {
	/**
	 * Control type
	 *
	 * @access public
	 * @var string
	 */
	public $type = 'new_menu';

	/**
	 * Render the control's content.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function render_content() {
		?>
		<button type="button" class="button button-primary" id="create-new-menu-submit"><?php _e( 'Create Menu' ); ?></button>
		<span class="spinner"></span>
		<?php
	}
}

<?php
/**
 * Customize Menu Section Class
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
			<button type="button" class="button-secondary add-new-menu-item add-menu-toggle">
				<?php echo esc_html( $this->title ); ?>
				<span class="screen-reader-text"><?php _e( 'Press return or enter to open' ); ?></span>
			</button>
			<ul class="new-menu-section-content"></ul>
		</li>
		<?php
	}
}

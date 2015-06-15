<?php
/**
 * Customize Nav Menu Control Class
 *
 * @package WordPress
 * @subpackage customize
 */

/**
 * Customize Nav Menu Control Class
 */
class WP_Customize_Nav_Menu_Control extends WP_Customize_Control {
	/**
	 * Control type
	 *
	 * @access public
	 * @var string
	 */
	public $type = 'nav_menu';

	/**
	 * The nav menu setting
	 *
	 * @var WP_Customize_Nav_Menu_Setting
	 */
	public $setting;

	/**
	 * Don't render the control's content - it uses a JS template instead.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function render_content() {}

	/**
	 * JS/Underscore template for the control UI.
	 *
	 * @since Menu Customizer 0.2
	 */
	public function content_template() {
		?>
		<button type="button" class="button-secondary add-new-menu-item">
			<?php _e( 'Add Items' ); ?>
		</button>
		<button type="button" class="not-a-button reorder-toggle">
			<span class="reorder"><?php _ex( 'Reorder', 'Reorder menu items in Customizer' ); ?></span>
			<span class="reorder-done"><?php _ex( 'Done', 'Cancel reordering menu items in Customizer' ); ?></span>
		</button>
		<span class="add-menu-item-loading spinner"></span>
		<span class="menu-delete-item">
			<button type="button" class="not-a-button menu-delete">
				<?php _e( 'Delete menu' ); ?> <span class="screen-reader-text">{{ data.menu_name }}</span>
			</button>
		</span>
		<?php if ( current_theme_supports( 'menus' ) ) : ?>
			<ul class="menu-settings">
				<li class="customize-control">
					<span class="customize-control-title"><?php _e( 'Menu locations' ); ?></span>
				</li>
				<?php
				$locations = get_registered_nav_menus(); ?>
				<?php foreach ( $locations as $location => $description ) : ?>

					<li class="customize-control customize-control-checkbox assigned-menu-location">
						<label>
							<input type="checkbox" data-menu-id="{{ data.menu_id }}" data-location-id="<?php echo esc_attr( $location ); ?>" class="menu-location" /> <?php echo $description; ?>
							<span class="theme-location-set"> <?php printf( _x( '(Current: %s)', 'Current menu location' ), '<span class="current-menu-location-name-' . esc_attr( $location ) . '"></span>' ); ?> </span>
						</label>
					</li>

				<?php endforeach; ?>

			</ul>
		<?php endif; ?>
		<p>
			<label>
				<input type="checkbox" class="auto_add">
				<?php _e( 'Automatically add new top-level pages to this menu.' ) ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Return params for this control.
	 *
	 * @return array
	 */
	function json() {
		$exported = parent::json();
		$exported['menu_id'] = $this->setting->term_id;
		return $exported;
	}
}

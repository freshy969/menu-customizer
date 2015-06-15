<?php
/**
 * Menu panel.
 *
 * @package WordPress
 * @subpackage customize
 */

/**
 * Customize Menu Panel Class
 *
 * Needed to add screen options.
 *
 * @since 4.3.0
 */
class WP_Customize_Menus_Panel extends WP_Customize_Panel {
	/**
	 * Control type
	 *
	 * @access public
	 * @var string
	 */
	public $type = 'menus';

	/**
	 * Render screen options for Menus.
	 */
	public function render_screen_options() {
		// Essentially adds the screen options.
		add_filter( 'manage_nav-menus_columns', array( $this, 'wp_nav_menu_manage_columns' ) );

		// Display screen options.
		$screen = WP_Screen::get( 'nav-menus.php' );
		$screen->render_screen_options();
	}

	/**
	 * Copied from wp-admin/includes/nav-menu.php. Returns the advanced options for the nav menus page.
	 *
	 * Link title attribute added as it's a relatively advanced concept for new users.
	 *
	 * @since 0.0
	 *
	 * @return Array The advanced menu properties.
	 */
	function wp_nav_menu_manage_columns() {
		return array(
			'_title' => __( 'Show advanced menu properties' ),
			'cb' => '<input type="checkbox" />',
			'link-target' => __( 'Link Target' ),
			'attr-title' => __( 'Title Attribute' ),
			'css-classes' => __( 'CSS Classes' ),
			'xfn' => __( 'Link Relationship (XFN)' ),
			'description' => __( 'Description' ),
		);
	}

	/**
	 * An Underscore (JS) template for this panel's content (but not its container).
	 *
	 * Class variables for this panel class are available in the `data` JS object;
	 * export custom variables by overriding {@see WP_Customize_Panel::json()}.
	 *
	 * @see WP_Customize_Panel::print_template()
	 *
	 * @since Menu Customizer 0.5
	 */
	protected function content_template() {
		?>
		<li class="panel-meta customize-info accordion-section <# if ( ! data.description ) { #> cannot-expand<# } #>">
			<button type="button" class="customize-panel-back" tabindex="-1"><span class="screen-reader-text"><?php _e( 'Back' ); ?></span></button>
			<div class="accordion-section-title">
				<span class="preview-notice"><?php
					/* translators: %s is the site/panel title in the Customizer */
					echo sprintf( __( 'You are customizing %s' ), '<strong class="panel-title">{{ data.title }}</strong>' );
				?></span>
				<button type="button" class="customize-screen-options-toggle"aria-expanded="false"><span class="screen-reader-text"><?php _e( 'Menu Options' ); ?></span></button>
				<button type="button" class="customize-help-toggle dashicons dashicons-editor-help" aria-expanded="false"><span class="screen-reader-text"><?php _e( 'Help' ); ?></span></button>
			</div>
			<# if ( data.description ) { #>
				<div class="description customize-panel-description">
					{{{ data.description }}}
				</div>
			<# } #>
			<?php $this->render_screen_options(); ?>
		</li>
		<?php
	}
}

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
class WP_Customize_Nav_Menu_Item_Control extends WP_Customize_Control {
	/**
	 * Control type
	 *
	 * @access public
	 * @var string
	 */
	public $type = 'nav_menu_item';

	/**
	 * The nav menu item setting
	 *
	 * @var WP_Customize_Nav_Menu_Item_Setting
	 */
	public $setting;

	/**
	 * Constructor.
	 *
	 * @uses WP_Customize_Control::__construct()
	 *
	 * @param WP_Customize_Manager $manager An instance of the WP_Customize_Manager class.
	 * @param string               $id      The control ID.
	 * @param array                $args    Overrides class property defaults.
	 */
	public function __construct( $manager, $id, $args = array() ) {
		parent::__construct( $manager, $id, $args );
	}

	/**
	 * Don't render the control's content - it's rendered with a JS template.
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
			<dl class="menu-item-bar">
				<dt class="menu-item-handle">
					<span class="item-type">{{ data.item_type_label }}</span>
					<span class="item-title">
						<span class="spinner"></span>
						<span class="menu-item-title">{{ data.title }}</span>
					</span>
					<span class="item-controls">
						<button type="button" class="not-a-button item-edit"><span class="screen-reader-text"><?php _e( 'Edit Menu Item' ); ?></span></button>
						<button type="button" class="not-a-button item-delete submitdelete deletion"><span class="screen-reader-text"><?php _e( 'Remove Menu Item' ); ?></span></button>
					</span>
				</dt>
			</dl>

			<div class="menu-item-settings" id="menu-item-settings-{{ data.menu_item_id }}">
				<# if ( 'custom' === data.item_type ) { #>
					<p class="field-url description description-thin">
						<label for="edit-menu-item-url-{{ data.menu_item_id }}">
							<?php _e( 'URL' ); ?><br />
							<input class="widefat code edit-menu-item-url" type="text" id="edit-menu-item-url-{{ data.menu_item_id }}" name="menu-item-url" />
						</label>
					</p>
				<# } #>
				<p class="description description-thin">
					<label for="edit-menu-item-title-{{ data.menu_item_id }}">
						<?php _e( 'Navigation Label' ); ?><br />
						<input type="text" id="edit-menu-item-title-{{ data.menu_item_id }}" class="widefat edit-menu-item-title" name="menu-item-title" />
					</label>
				</p>
				<p class="field-link-target description description-thin">
					<label for="edit-menu-item-target-{{ data.menu_item_id }}">
						<input type="checkbox" id="edit-menu-item-target-{{ data.menu_item_id }}" class="edit-menu-item-target" value="_blank" name="menu-item-target" />
						<?php _e( 'Open link in a new tab' ); ?>
					</label>
				</p>
				<p class="field-attr-title description description-thin">
					<label for="edit-menu-item-attr-title-{{ data.menu_item_id }}">
						<?php _e( 'Title Attribute' ); ?><br />
						<input type="text" id="edit-menu-item-attr-title-{{ data.menu_item_id }}" class="widefat edit-menu-item-attr-title" name="menu-item-attr-title" />
					</label>
				</p>
				<p class="field-css-classes description description-thin">
					<label for="edit-menu-item-classes-{{ data.menu_item_id }}">
						<?php _e( 'CSS Classes' ); ?><br />
						<input type="text" id="edit-menu-item-classes-{{ data.menu_item_id }}" class="widefat code edit-menu-item-classes" name="menu-item-classes" />
					</label>
				</p>
				<p class="field-xfn description description-thin">
					<label for="edit-menu-item-xfn-{{ data.menu_item_id }}">
						<?php _e( 'Link Relationship (XFN)' ); ?><br />
						<input type="text" id="edit-menu-item-xfn-{{ data.menu_item_id }}" class="widefat code edit-menu-item-xfn" name="menu-item-xfn" />
					</label>
				</p>
				<p class="field-description description description-thin">
					<label for="edit-menu-item-description-{{ data.menu_item_id }}">
						<?php _e( 'Description' ); ?><br />
						<textarea id="edit-menu-item-description-{{ data.menu_item_id }}" class="widefat edit-menu-item-description" rows="3" cols="20" name="menu-item-description">{{ data.description }}</textarea>
						<span class="description"><?php _e( 'The description will be displayed in the menu if the current theme supports it.' ); ?></span>
					</label>
				</p>

				<div class="menu-item-actions description-thin submitbox">
					<# if ( 'custom' != data.item_type && '' != data.original_title ) { #>
						<p class="link-to-original">
							<?php _e( 'Original:' ); ?> <a class="original-link" href="{{ data.url }}">{{{ data.original_title }}}</a>
						</p>
					<# } #>

					<button type="button" class="not-a-button item-delete submitdelete deletion"><?php _e( 'Remove' ); ?></button>
					<span class="spinner"></span>
				</div>
				<input type="hidden" name="menu-item-db-id[{{ data.menu_item_id }}]" class="menu-item-data-db-id" value="{{ data.menu_item_id }}" />
				<input type="hidden" name="menu-item-parent-id[{{ data.menu_item_id }}]" class="menu-item-data-parent-id" value="{{ data.parent }}" />
			</div><!-- .menu-item-settings-->
			<ul class="menu-item-transport"></ul>
		<?php
	}

	/**
	 * Return params for this control.
	 *
	 * @return array
	 */
	function json() {
		$exported = parent::json();
		$exported['menu_item_id'] = $this->setting->post_id;
		return $exported;
	}
}

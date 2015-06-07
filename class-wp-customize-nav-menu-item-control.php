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
	 * Depth
	 *
	 * @todo Eliminate in favor of JS.
	 *
	 * @access public
	 * @var int
	 */
	public $depth = 0;

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
	 * Determine the depth of a menu item by recursion.
	 *
	 * @todo This needs to be done entirely in JS.
	 *
	 * @param int $parent_id The id of the parent menu item.
	 * @param int $depth Inverse current item depth.
	 * @return int Depth of the original menu item.
	 */
	public function depth( $parent_id, $depth = 0 ) {
		if ( 0 == $parent_id ) {
			// This is a top-level item, so the current depth is the maximum.
			return $depth;
		} else {
			// Increase depth.
			$depth = $depth + 1;

			// Find menu item parent's parent menu item id (the grandparent id).
			$parent = get_post( $parent_id ); // WP_Post object.
			$parent = wp_setup_nav_menu_item( $parent ); // Adds menu item properties.
			$parent_parent_id = $parent->menu_item_parent;

			return $this->depth( $parent_parent_id, $depth );
		}
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
		<div id="menu-item-{{ data.menu_item_id }}" class="menu-item {{ data.el_classes }}" data-item-depth="{{ data.depth }}">
			<dl class="menu-item-bar">
				<dt class="menu-item-handle">
					<span class="item-type">{{{ data.item_type_label }}}</span>
					<span class="item-title">
						<span class="spinner"></span>
						<span class="menu-item-title">{{{ data.title }}}</span>
						<span class="is-submenu"><?php _e( 'sub item' ); ?></span>
					</span>
					<span class="item-controls">
						<a class="item-edit" id="edit-{{ data.menu_item_id }}" title="<?php esc_attr_e( 'Edit Menu Item' ); ?>" href="#"><?php _e( 'Edit Menu Item' ); ?></a>
					</span>
				</dt>
			</dl>

			<div class="menu-item-settings" id="menu-item-settings-{{ data.menu_item_id }}">
				<# if ( 'custom' == data.item_type ) { #>
					<p class="field-url description description-thin">
						<label for="edit-menu-item-url-{{ data.menu_item_id }}">
							<?php _e( 'URL' ); ?><br />
							<input class="widefat code edit-menu-item-url" type="text" value="{{ data.url }}" id="edit-menu-item-url-{{ data.menu_item_id }}" name="menu-item-url" />
						</label>
					</p>
				<# } #>
				<p class="description description-thin">
					<label for="edit-menu-item-title-{{ data.menu_item_id }}">
						<?php _e( 'Navigation Label' ); ?><br />
						<input type="text" id="edit-menu-item-title-{{ data.menu_item_id }}" class="widefat edit-menu-item-title" name="menu-item-title" value="{{ data.title }}" />
					</label>
				</p>
				<p class="field-link-target description description-thin">
					<label for="edit-menu-item-target-{{ data.menu_item_id }}">
						<input type="checkbox" id="edit-menu-item-target-{{ data.menu_item_id }}" value="_blank" name="menu-item-target" <# if ( '_blank' == data.target ) { #> checked="checked" <# } #> />
								<?php _e( 'Open link in a new tab' ); ?>
					</label>
				</p>
				<p class="field-attr-title description description-thin">
					<label for="edit-menu-item-attr-title-{{ data.menu_item_id }}">
						<?php _e( 'Title Attribute' ); ?><br />
						<input type="text" id="edit-menu-item-attr-title-{{ data.menu_item_id }}" class="widefat edit-menu-item-attr-title" name="menu-item-attr-title" value="{{ data.attr_title }}" />
					</label>
				</p>
				<p class="field-css-classes description description-thin">
					<label for="edit-menu-item-classes-{{ data.menu_item_id }}">
						<?php _e( 'CSS Classes' ); ?><br />
						<input type="text" id="edit-menu-item-classes-{{ data.menu_item_id }}" class="widefat code edit-menu-item-classes" name="menu-item-classes" value="{{ data.classes }}" />
					</label>
				</p>
				<p class="field-xfn description description-thin">
					<label for="edit-menu-item-xfn-{{ data.menu_item_id }}">
						<?php _e( 'Link Relationship (XFN)' ); ?><br />
						<input type="text" id="edit-menu-item-xfn-{{ data.menu_item_id }}" class="widefat code edit-menu-item-xfn" name="menu-item-xfn" value="{{ data.xfn }}" />
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
					<# if ( 'custom' != data.item_type && data.original_title !== false ) { #>
						<p class="link-to-original">
							<?php _e( 'Original:' ); ?> <a class="original-link" href="{{ data.url }}">{{{ data.original_title }}}</a>
						</p>
					<# } #>
					<a class="item-delete submitdelete deletion" id="delete-menu-item-{{ data.menu_item_id }}" href="#"><?php _e( 'Remove' ); ?></a>
					<span class="spinner"></span>
				</div>
				<input type="hidden" name="menu-item-parent-id" class="menu-item-parent-id" id="edit-menu-item-parent-id-{{ data.menu_item_id }}" value="{{ data.parent }}" />
			</div><!-- .menu-item-settings-->
			<ul class="menu-item-transport"></ul>
		</div>
	<?php
	}
}

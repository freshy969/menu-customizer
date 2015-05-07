<?php
/**
 * Customize Menu Class
 *
 * Implements menu management in the Customizer.
 *
 * @package WordPress
 * @subpackage Customize
 * @since 4.2.0
 */
class WP_Customize_Menus {

	/**
	 * WP_Customize_Manager instance.
	 *
	 * @since Menu Customizer 0.3.
	 * @access public
	 * @var WP_Customize_Manager
	 */
	public $manager;

	/**
	 * Previewed Menus (used to be $menu_customizer_previewed_settings in menu-customizer.php)
	 *
	 * @access public
	 */
	public $previewed_menus;

	/**
	 * Constructor
	 *
	 * @since Menu Customizer 0.3
	 * @access public
	 * @param $manager WP_Customize_Manager instance
	 */
	public function __construct( $manager ) {
		$this->previewed_menus = array();
		$this->manager = $manager;

		add_action( 'wp_ajax_add-nav-menu-customizer', array( $this, 'new_menu_ajax' ) );
		add_action( 'wp_ajax_delete-menu-customizer', array( $this, 'delete_menu_ajax' ) );
		add_action( 'wp_ajax_update-menu-item-customizer', array( $this, 'update_item_ajax' ) );
		add_action( 'wp_ajax_add-menu-item-customizer', array( $this, 'add_item_ajax' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'customize_register', array( $this, 'customize_register' ), 11 ); // Needs to run after core Navigation section is set up.
		add_action( 'customize_update_menu_name', array( $this, 'update_menu_name' ), 10, 2 );
		add_action( 'customize_update_menu_autoadd', array( $this, 'update_menu_autoadd' ), 10, 2 );
		add_action( 'customize_preview_nav_menu', array( $this, 'preview_nav_menu' ), 10, 1 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_nav_menu_items_for_preview' ), 10, 2 );
		add_action( 'customize_update_nav_menu', array( $this, 'update_nav_menu' ), 10, 2 );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'print_templates' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'available_items_template' ) );

	}

	/**
	 * Ajax handler for creating a new menu.
	 *
	 * @since Menu Customizer 0.0.
	 * @access public
	 */
	public function new_menu_ajax() {
		check_ajax_referer( 'customize-menus', 'customize-nav-menu-nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( -1 );
		}

		$menu_name = sanitize_text_field( $_POST['menu-name'] );

		// Create the menu.
		$menu_id = wp_create_nav_menu( $menu_name );

		if ( is_wp_error( $menu_id ) ) {
			// @todo error handling, ideally providing user feedback (most likely case here is a duplicate menu name).
			wp_die();
		}

		// Output the data for this new menu.
		echo wp_json_encode( array( 'name' => $menu_name, 'id' => $menu_id ) );

		wp_die();
	}

	/**
	 * Ajax handler for deleting a menu.
	 *
	 * @since Menu Customizer 0.0.
	 * @access public
	 */
	public function delete_menu_ajax() {
		check_ajax_referer( 'customize-menus', 'customize-nav-menu-nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( -1 );
		}

		$menu_id = absint( $_POST['menu_id'] );

		if ( is_nav_menu( $menu_id ) ) {
			$deletion = wp_delete_nav_menu( $menu_id );
			if ( is_wp_error( $deletion ) ) {
				echo $deletion->message();
			}
		} else {
			_e( 'Error: invalid menu to delete.' );
		}

		wp_die();
	}

	/**
	 * Ajax handler for updating a menu item.
	 *
	 * @since Menu Customizer 0.0.
	 * @access public
	 */
	public function update_item_ajax() {
		check_ajax_referer( 'customize-menus', 'customize-menu-item-nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( -1 );
		}

		$clone = $_POST['clone'];
		$item_id = $_POST['item_id'];
		$menu_item_data = (array) $_POST['menu-item'];

		$id = $this->update_item( 0, $item_id, $menu_item_data, $clone );

		if ( ! is_wp_error( $id ) ) {
			echo $id;
		} else {
			echo $id->message();
		}

		wp_die();
	}

	/**
	 * Ajax handler for adding a menu item. Based on wp_ajax_add_menu_item().
	 *
	 * @since Menu Customizer 0.0.
	 * @access public
	 */
	public function add_item_ajax() {
		check_ajax_referer( 'customize-menus', 'customize-menu-item-nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( -1 );
		}

		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

		$menu_item_data = (array) $_POST['menu-item'];
		$menu_id = absint( $_POST['menu'] ); // Used only for display, new item is created as an orphan - menu id of 0.
		$id = 0;

		// For performance reasons, we omit some object properties from the checklist.
		// The following is a hacky way to restore them when adding non-custom items.
		// @todo: do we really need this - do we need to populate the description field here?

		if ( ! empty( $menu_item_data['obj_type'] ) &&
			'custom' != $menu_item_data['obj_type'] &&
			! empty( $menu_item_data['id'] )
		) {
			switch ( $menu_item_data['obj_type'] ) {
				case 'post_type' :
					$id = absint( str_replace( 'post-', '', $menu_item_data['id'] ) );
					$_object = get_post( $id );
				break;

				case 'taxonomy' :
					$id = absint( str_replace( 'term-', '', $menu_item_data['id'] ) );
					$_object = get_term( $id, $menu_item_data['type'] );
				break;
			}

			$_menu_items = array_map( 'wp_setup_nav_menu_item', array( $_object ) );
			$_menu_item = array_shift( $_menu_items );

			// Restore the missing menu item properties
			$menu_item_data['menu-item-description'] = $_menu_item->description;
		}

		// make the "Home" item into the custom link that it actually is.
		if ( 'page' == $menu_item_data['type'] && 'custom' == $menu_item_data['obj_type'] ) {
			$menu_item_data['type'] = 'custom';
			$menu_item_data['url'] = home_url( '/' );
		}

		// Map data from menu customizer keys to nav-menu.php keys.
		$item_data = array(
			'menu-item-db-id'        => 0,
			'menu-item-object-id'    => $id,
			'menu-item-object'       => ( isset( $menu_item_data['type'] ) ? $menu_item_data['type'] : '' ),
			'menu-item-type'         => ( isset( $menu_item_data['obj_type'] ) ? $menu_item_data['obj_type'] : '' ),
			'menu-item-title'        => ( isset( $menu_item_data['name'] ) ? $menu_item_data['name'] : '' ),
			'menu-item-url'          => ( isset( $menu_item_data['url'] ) ? $menu_item_data['url'] : '' ),
			'menu-item-description'  => ( isset( $menu_item_data['menu-item-description'] ) ? $menu_item_data['menu-item-description'] : '' ),
		);

		// `wp_save_nav_menu_items` requires `menu-item-db-id` to not be set for custom items.
		if ( 'custom' == $item_data['menu-item-type'] ) {
			unset( $item_data['menu-item-db-id'] );
		}

		$items_id = wp_save_nav_menu_items( 0, array( 0 => $item_data ) );
		if ( is_wp_error( $items_id ) || empty( $items_id ) ) {
			wp_die( 0 );
		}

		$item = get_post( $items_id[0] );
		if ( ! empty( $item->ID ) ) {
			$item = wp_setup_nav_menu_item( $item );
			$item->label = $item->title; // Don't show "(pending)" in ajax-added item.

			// Output the json for this item's control.
			require_once( plugin_dir_path( __FILE__ ) . '/menu-customize-controls.php' );

			$section_id = 'nav_menus[' . $menu_id . ']';
			$setting_id = $section_id . '[' . $item->ID . ']';
			$this->manager->add_setting( $setting_id, array(
				'type' => 'option',
				'default' => array(),
			) );
			$control = new WP_Customize_Menu_Item_Control( $this->manager, $setting_id, array(
				'label'       => $item->title,
				'section'     => $section_id,
				'priority'    => $_POST['priority'],
				'menu_id'     => $menu_id,
				'item'        => $item,
			) );
			echo wp_json_encode( $control->json() );// @todo convert to json
		}

		wp_die();
	}

	public function enqueue() {
		wp_enqueue_style( 'menu-customizer', plugin_dir_url( __FILE__ ) . 'menu-customizer.css' );
		wp_enqueue_script( 'menu-customizer-options', plugin_dir_url( __FILE__ ) . 'menu-customizer-options.js', array( 'jquery' ) );
		wp_enqueue_script( 'menu-customizer', plugin_dir_url( __FILE__ ) . 'menu-customizer.js', array( 'jquery', 'wp-backbone', 'customize-controls', 'accordion' ) );

		global $wp_scripts;

		// Pass data to JS.
		$settings = array(
			'nonce'                => wp_create_nonce( 'customize-menus' ),
			'allMenus'             => wp_get_nav_menus(),
			'availableMenuItems'   => $this->available_items(),
			'itemTypes'            => $this->available_item_types(),
			'l10n'                 => array(
				'untitled'        => _x( '(no label)', 'Missing menu item navigation label.' ),
				'custom_label'    => _x( 'Custom', 'Custom menu item type label.' ),
				'deleteWarn'      => __( 'You are about to permanently delete this menu. "Cancel" to stop, "OK" to delete.' ),
			),
		);

		$data = sprintf( 'var _wpCustomizeMenusSettings = %s;', json_encode( $settings ) );
		$wp_scripts->add_data( 'menu-customizer', 'data', $data );
	}

	/**
	 * Add the customizer settings and controls.
	 *
	 * @since Menu Customizer 0.0
	 * @param WP_Customize_Manager $manager Theme Customizer object.
	 */
	public function customize_register( $manager ) {
		require_once( plugin_dir_path( __FILE__ ) . '/menu-customize-controls.php' );

		// Require JS-rendered control types.
		$this->manager->register_control_type( 'WP_Customize_Nav_Menu_Control' );
		$this->manager->register_control_type( 'WP_Customize_Menu_Item_Control' );

		// Create a panel for Menus.
		$this->manager->add_panel( 'menus', array(
			'title'        => __( 'Menus' ),
			'description'  => __( '<p>This panel is user for managing your custom navigation menus. You can add pages, posts, categories, tags, and custom links to your menus.</p><p>Menus can be displayed in locations definedd by your theme, and also used in sidebars by adding a "Custom Menu" widget in the Widgets panel.</p>' ),
			'priority'     => 30,
		) );

		// Rebrand the existing "Navigation" section to the global theme locations section.
		$locations = get_registered_nav_menus();
		$num_locations = count( array_keys( $locations ) );
		$description = sprintf( _n( 'Your theme contains %s menu location. Select which menu you would like to use.', 'Your theme contains %s menu locations. Select which menu appears in each location.', $num_locations ), number_format_i18n( $num_locations ) );
		$description .= '<br>' . __( 'You can also place menus in widget areas with the Custom Menu widget.' );

		$this->manager->get_section( 'nav' )->title = __( 'Theme Locations' );
		$this->manager->get_section( 'nav' )->description = $description;
		$this->manager->get_section( 'nav' )->priority = 5;
		$this->manager->get_section( 'nav' )->panel = 'menus';

		// Add the screen options control to the existing "Navigation" section (it gets moved around in the JS).
		$this->manager->add_setting( 'menu_customizer_options', array(
			'type' => 'menu_options',
		) );
		$this->manager->add_control( new WP_Menu_Options_Customize_Control( $this->manager, 'menu_customizer_options', array(
			'section' => 'nav',
			'priority' => 20,
		) ) );

		// Register each custom menu as a Customizer section, and add each menu item to each menu.
		$menus = wp_get_nav_menus();

		foreach ( $menus as $menu ) {
			$menu_id = $menu->term_id;

			// Create a section for each menu.
			$section_id = 'nav_menus[' . $menu_id . ']';
			$this->manager->add_section( $section_id, array(
				'title'     => $menu->name,
				'priority'  => 10,
				'panel'     => 'menus',
			) );

			// Add a setting & control for the menu name.
			$menu_name_setting_id = $section_id . '[name]';
			$this->manager->add_setting( $menu_name_setting_id, array(
				'default'  => $menu->name,
				'type'     => 'menu_name',
			) );

			$this->manager->add_control( $menu_name_setting_id, array(
				'label'        => '',
				'section'      => $section_id,
				'type'         => 'text',
				'priority'     => 0,
				'input_attrs'  => array(
					'class'  => 'menu-name-field live-update-section-title',
				),
			) );

			// Add the menu contents.
			$menu_items = array();

			foreach ( wp_get_nav_menu_items( $menu_id ) as $menu_item ) {
				$menu_items[ $menu_item->ID ] = $menu_item;
			}

			// @todo we need to implement something like WP_Customize_Widgets::prepreview_added_sidebars_widgets() so that wp_get_nav_menu_items() will include the new menu items
			if ( ! empty( $_POST['customized'] ) && ( $customized = json_decode( wp_unslash( $_POST['customized'] ), true ) ) && is_array( $customized ) ) {
				foreach ( $customized as $incoming_setting_id => $incoming_setting_value ) {
					if ( preg_match( '/^nav_menus\[(?P<menu_id>\d+)\]\[(?P<menu_item_id>\d+)\]$/', $incoming_setting_id, $matches ) ) {
						if ( ! isset( $menu_items[ $matches['menu_item_id'] ] ) ) {
							$incoming_setting_value = (object) $incoming_setting_value;
							if ( ! isset( $incoming_setting_value->ID ) ) {
								// @TODO: This should be supplied already
								$incoming_setting_value->ID = $matches['menu_item_id'];
							}
							if ( ! isset ( $incoming_setting_value->title ) ) {
								// @TODO: This should be supplied already
								$incoming_setting_value->title = 'UNTITLED';
							}
							if ( ! isset ( $incoming_setting_value->menu_item_parent ) ) {
								// @TODO: This should be supplied already
								$incoming_setting_value->menu_item_parent = 0;
							}
							$menu_items[ $matches['menu_item_id'] ] = $incoming_setting_value;
						}
					}
				}
			}

			$item_ids = array();
			foreach ( array_values( $menu_items ) as $i => $item ) {
				$item_ids[] = $item->ID;

				// Create a setting for each menu item (which doesn't actually manage data, currently).
				$menu_item_setting_id = $section_id . '[' . $item->ID . ']';
				$this->manager->add_setting( $menu_item_setting_id, array(
					'type'     => 'option',
					'default'  => array(),
				) );

				// Create a control for each menu item.
				$this->manager->add_control( new WP_Customize_Menu_Item_Control( $this->manager, $menu_item_setting_id, array(
					'label'       => $item->title,
					'section'     => $section_id,
					'priority'    => 10 + $i,
					'menu_id'     => $menu_id,
					'item'        => $item,
				) ) );
			}

			// Add the menu control, which handles adding and ordering.
			$nav_menu_setting_id = 'nav_menu_' . $menu_id;
			$this->manager->add_setting( $nav_menu_setting_id, array(
				'type'     => 'nav_menu',
				'default'   => $item_ids,
			) );

			$this->manager->add_control( new WP_Customize_Nav_Menu_Control( $this->manager, $nav_menu_setting_id, array(
				'section'   => $section_id,
				'menu_id'   => $menu_id,
				'priority'  => 998,
			) ) );

			// Add the auto-add new pages option.
			$auto_add = get_option( 'nav_menu_options' );
			if ( ! isset( $auto_add['auto_add'] ) ) {
				$auto_add = false;
			}
			elseif ( false !== array_search( $menu_id, $auto_add['auto_add'] ) ) {
				$auto_add = true;
			}
			else {
				$auto_add = false;
			}

			$menu_autoadd_setting_id = $section_id . '[auto_add]';
			$this->manager->add_setting( $menu_autoadd_setting_id, array(
				'type'     => 'menu_autoadd',
				'default'  => $auto_add,
			) );

			$this->manager->add_control( $menu_autoadd_setting_id, array(
				'label'     => __( 'Automatically add new top-level pages to this menu.' ),
				'section'   => $section_id,
				'type'      => 'checkbox',
				'priority'  => 999,
			) );
		}

		// Add the add-new-menu section and controls.
		$this->manager->add_section( 'add_menu', array(
			'title'     => __( 'New Menu' ),
			'panel'     => 'menus',
			'priority'  => 99,
		) );

		$this->manager->add_setting( 'new_menu_name', array(
			'type'     => 'new_menu',
			'default'  => '',
		) );

		$this->manager->add_control( 'new_menu_name', array(
			'label'        => '',
			'section'      => 'add_menu',
			'type'         => 'text',
			'input_attrs'  => array(
				'class'        => 'menu-name-field',
				'placeholder'  => __( 'New menu name' ),
			),
		) );

		$this->manager->add_setting( 'create_new_menu', array(
			'type' => 'new_menu',
		) );

		$this->manager->add_control( new WP_New_Menu_Customize_Control( $this->manager, 'create_new_menu', array(
			'section'  => 'add_menu',
		) ) );
	}

	/**
	 * Save the Menu Name when it's changed.
	 *
	 * Menu Name is not previewed because it's designed primarily for admin uses.
	 *
	 * @since Menu Customizer 0.0.
	 * @param mixed                $value   Value of the setting.
	 * @param WP_Customize_Setting $setting WP_Customize_Setting instance.
	 */
	public function update_menu_name( $value, $setting ) {
		if ( ! $value || ! $setting ) {
			return;
		}

		// Get the menu id from the setting id.
		$id = str_replace( 'nav_menus[', '', $setting->id );
		$id = str_replace( '][name]', '', $id );

		if ( 0 == $id ) {
			return;
		}

		// Update the menu name with the new $value.
		wp_update_nav_menu_object( $id, array( 'menu-name' => trim( esc_html( $value ) ) ) );
	}

	/**
	 * Update the `auto_add` nav menus option.
	 *
	 * Auto-add is not previewed because it is administration-specific.
	 *
	 * @since Menu Customizer 0.0
	 *
	 * @param mixed                $value   Value of the setting.
	 * @param WP_Customize_Setting $setting WP_Customize_Setting instance.
	 */
	public function update_menu_autoadd( $value, $setting ) {
		if ( ! $setting ) {
			return;
		}

		// Get the menu id from the setting id.
		$id = str_replace( 'nav-menus[', '', $setting->id );
		$id = absint( str_replace( '][auto_add]', '', $id ) );

		if ( ! $id ) {
			return;
		}

		$nav_menu_option = (array) get_option( 'nav_menu_options' );
		if ( ! isset( $nav_menu_option['auto_add'] ) ) {
			$nav_menu_option['auto_add'] = array();
		}
		if ( $value ) {
			if ( ! in_array( $id, $nav_menu_option['auto_add'] ) ) {
				$nav_menu_option['auto_add'][] = $id;
			}
		} else {
			if ( false !== ( $key = array_search( $id, $nav_menu_option['auto_add'] ) ) ) {
				unset( $nav_menu_option['auto_add'][$key] );
			}
		}

		// Remove nonexistent/deleted menus.
		$nav_menu_option['auto_add'] = array_intersect( $nav_menu_option['auto_add'], wp_get_nav_menus( array( 'fields' => 'ids' ) ) );
		update_option( 'nav_menu_options', $nav_menu_option );
	}

	/**
	 * Preview changes made to a nav menu.
	 *
	 * Filters nav menu display to show customized items in the customized order.
	 *
	 * @since Menu Customizer 0.0
	 *
	 * @param WP_Customize_Setting $setting WP_Customize_Setting instance.
	 * @return WP_Post|WP_Error The nav_menu post that corresponds to a setting, or a WP_Error if it doesn't exist.
	 */
	public function preview_nav_menu( $setting ) {
		$menu_id = str_replace( 'nav_menu_', '', $setting->id );

		// Ensure that $menu_id is valid.
		$menu_id = (int) $menu_id;
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu || ! $menu_id ) {
			return new WP_Error( 'invalid_menu_id', __( 'Invalid menu ID.' ) );
		}
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		$this->previewed_menus[ $menu->term_id ] = $setting;
		return $menu;
	}

	/**
	 * Filter for wp_get_nav_menu_items to apply the previewed changes for a setting.
	 *
	 * @param array $items
	 * @param stdClass $menu aka WP_Term
	 * @return array
	 */
	public function filter_nav_menu_items_for_preview( $items, $menu ) {
		if ( ! isset( $this->previewed_menus[ $menu->term_id ] ) ) {
			return $items;
		}
		$setting = $this->previewed_menus[ $menu->term_id ];

		// Note that setting value is only posted if it's changed.
		if ( is_array( $setting->post_value() ) ) {
			$new_ids = $setting->post_value();
			$new_items = array();
			$i = 0;

			// For each time, get object and update menu order property.
			foreach ( $new_ids as $item_id ) {
				$item = get_post( $item_id );
				$item = wp_setup_nav_menu_item( $item );
				$item->menu_order = $i;
				$new_items[] = $item;
				$i++;
			}

			$items = $new_items;
		}
		return $items;
	}

	/**
	 * Save changes made to a nav menu.
	 *
	 * Assigns cloned & modified items to this menu, publishing them.
	 * Updates the order of all items in the menu.
	 *
	 * @since Menu Customizer 0.0
	 *
	 * @param array                $value   Ordered array of the new menu item ids.
	 * @param WP_Customize_Setting $setting WP_Customize_Setting instance.
	 */
	public function update_nav_menu( $value, $setting ) {
		$menu_id = str_replace( 'nav_menu_', '', $setting->id );

		// Ensure that $menu_id is valid.
		$menu_id = (int) $menu_id;
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu || ! $menu_id ) {
			return new WP_Error( 'invalid_menu_id', __( 'Invalid menu ID.' ) );
		}
		if ( is_wp_error( $menu ) ) {
			return $menu;
		}

		// Get original items in this menu. Any that aren't there anymore need to be deleted.
		$originals = wp_get_nav_menu_items( $menu_id );
		// Convert to just an array of ids.
		$original_ids = array();
		foreach ( $originals as $item ) {
			$original_ids[] = $item->ID;
		}

		$items = $value; // Ordered array of item ids.

		if ( $original_ids === $items ) {
			// This menu is completely unchanged - don't need to do anything else
			return $value;
		}

		// Are there removed items that need to be deleted?
		// This will also include any items that have been cleared.
		$old_items = array_diff( $original_ids, $items );

		$i = 1;
		foreach ( $items as $item_id ) {
			// Assign the existing item to this menu, in case it's orphaned. Update the order, regardless.
			$this->update_item_order( $menu_id, $item_id, $i );
			$i++;
		}

		foreach ( $old_items as $item_id ) {
			if ( is_nav_menu_item( $item_id ) ) {
				wp_delete_post( $item_id, true );
			}
		}
	}

	 public function update_menu_item_order( $menu_id, $item_id, $order ) {
		$item_id = (int) $item_id;

		// Make sure that we don't convert non-nav_menu objects into nav_menu_item_objects.
		if ( ! is_nav_menu_item( $item_id ) ) {
			return new WP_Error( 'update_nav_menu_item_failed', __( 'The given object ID is not that of a menu item.' ) );
		}

		// Associate the menu item with the menu term.
		// Only set the menu term if it isn't set to avoid unnecessary wp_get_object_terms().
		if ( $menu_id && ! is_object_in_term( $item_id, 'nav_menu', (int) $menu_id ) ) {
			wp_set_object_terms( $item_id, array( $menu_id ), 'nav_menu' );
		}

		// Populate the potentially-changing fields of the menu item object.
		$post = array(
			'ID'           => $item_id,
			'menu_order'   => $order,
			'post_status'  => 'publish',
		);

		// Update the menu item object.
		wp_update_post( $post );

		return $item_id;
	}

	/**
	 * Update properties of a nav menu item, with the option to create a clone of the item.
	 *
	 * Wrapper for wp_update_nav_menu_item() that only requires passing changed properties.
	 *
	 * @link https://core.trac.wordpress.org/ticket/28138
	 *
	 * @since Menu Customizer 0.0
	 *
	 * @param int   $menu_id The ID of the menu. If "0", makes the menu a draft orphan.
	 * @param int   $item_id The ID of the menu item. If "0", creates a new menu item.
	 * @param array $data    The new data for the menu item.
	 * @param int|WP_Error   The menu item's database ID or WP_Error object on failure.
	 */
	public function update_item( $menu_id, $item_id, $data, $clone = false ) {
		$item = get_post( $item_id );
		$item = wp_setup_nav_menu_item( $item );
		$defaults = array(
			'menu-item-db-id'        => $item_id,
			'menu-item-object-id'    => $item->object_id,
			'menu-item-object'       => $item->object,
			'menu-item-parent-id'    => $item->menu_item_parent,
			'menu-item-position'     => $item->menu_order,
			'menu-item-type'         => $item->type,
			'menu-item-title'        => $item->title,
			'menu-item-url'          => $item->url,
			'menu-item-description'  => $item->description,
			'menu-item-attr-title'   => $item->attr_title,
			'menu-item-target'       => $item->target,
			'menu-item-classes'      => implode( ' ', $item->classes ),
			'menu-item-xfn'          => $item->xfn,
			'menu-item-status'       => $item->publish,
		);

		$args = wp_parse_args( $data, $defaults );

		if ( $clone ) {
			$item_id = 0;
		}

		return wp_update_nav_menu_item( $menu_id, $item_id, $args );
	}

	/**
	 * Return all potential menu items.
	 *
	 * @todo: pagination and lazy-load, rather than loading everything at once.
	 *
	 * @since Menu Customizer 0.0
	 *
	 * @return array All potential menu items' names, object ids, and types.
	 */
	public function available_items() {
		$items = array();

		$post_types = get_post_types( array( 'show_in_nav_menus' => true ), 'object' );

		if ( ! $post_types ) {
			return array();
		}

		foreach ( $post_types as $post_type ) {
			if ( $post_type ) {
				$args = array(
					'posts_per_page'  => -1,
					'orderby'         => 'post_date',
					'order'           => 'DESC',
					'post_type'       => $post_type->name,
				);
				$allposts = get_posts( $args );
				foreach ( $allposts as $post ) {
					$items[] = array(
						'id'          => 'post-' . $post->ID,
						'name'        => $post->post_title,
						'type'        => $post_type->name,
						'type_label'  => $post_type->labels->singular_name,
						'obj_type'    => 'post_type',
						'order'       => strtotime( $post->post_modified ), // Posts are ordered by time updated.
					);
				}
			}
		}

		$taxonomies = get_taxonomies( array( 'show_in_nav_menus' => true ), 'object' );

		if ( $taxonomies ) {
			foreach ( $taxonomies as $tax ) {
				if ( $tax ) {
					$name = $tax->name;
					$args = array(
						'child_of'      => 0,
						'exclude'       => '',
						'hide_empty'    => false,
						'hierarchical'  => 1,
						'include'       => '',
						'number'        => 0,
						'offset'        => 0,
						'order'         => 'ASC',
						'orderby'       => 'name',
						'pad_counts'    => false,
					);
					$terms = get_terms( $name, $args );

					foreach ( $terms as $term ) {
						$items[] = array(
							'id'          => 'term-' . $term->term_id,
							'name'        => $term->name,
							'type'        => $name,
							'type_label'  => $tax->labels->singular_name,
							'obj_type'    => 'taxonomy',
							'order'       => $term->count, // Terms are ordered by count; will always be after all posts when combined.
						);
					}
				}
			}
		}

		// Add "Home" link. Treat as a page, but switch to custom on add.
		$home = array(
			'id'          => 0,
			'name'        => _x( 'Home', 'nav menu home label' ),
			'type'        => 'page',
			'type_label'  => __( 'Page' ),
			'obj_type'    => 'custom',
			'order'       => time(), // Will be the first item.
		);
		$items[] = $home;

		return $items;
	}

	/**
	 * No docs yet
	 */
	public function available_item_types() {
		$types = get_post_types( array( 'show_in_nav_menus' => true ), 'names' );
		$taxes = get_taxonomies( array( 'show_in_nav_menus' => true ), 'names' );
		return array_merge( $types, $taxes );
	}

	/**
	 * Print the JavaScript templates used to render Menu Customizer components.
	 *
	 * Templates are imported into the JS use wp.template.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function print_templates() {
		?>
		<script type="text/html" id="tmpl-available-menu-item">
			<div id="menu-item-tpl-{{ data.id }}" class="menu-item-tpl" data-menu-item-id="{{ data.id }}">
				<dl class="menu-item-bar">
					<dt class="menu-item-handle">
						<span class="item-type">{{ data.type_label }}</span>
						<span class="item-title">{{ data.name }}</span>
						<a class="item-add" href="#">Add Menu Item</a>
					</dt>
				</dl>
			</div>
		</script>

		<script type="text/html" id="tmpl-available-menu-item-type">
			<div id="available-menu-items-{{ data.type }}" class="accordion-section">
				<h4 class="accordion-section-title">{{ data.type_label }}</h4>
				<div class="accordion-section-content">
				</div>
			</div>
		</script>

		<script type="text/html" id="tmpl-loading-menu-item">
			<li class="nav-menu-inserted-item-loading added-menu-item added-dbid-{{ data.id }} customize-control customize-control-menu_item nav-menu-item-wrap">
				<div class="menu-item menu-item-depth-0 menu-item-edit-inactive">
					<dl class="menu-item-bar">
						<dt class="menu-item-handle">
							<span class="spinner" style="display: block;"></span>
							<span class="item-type">{{ data.type_label }}</span>
							<span class="item-title menu-item-title">{{ data.name }}</span>
						</dt>
					</dl>
				</div>
			</li>
		</script>

		<script type="text/html" id="tmpl-menu-item-reorder-nav">
			<div class="menu-item-reorder-nav">
				<?php
				printf(
					'<span class="menus-move-up" tabindex="0">%1$s</span><span class="menus-move-down" tabindex="0">%2$s</span><span class="menus-move-left" tabindex="0">%3$s</span><span class="menus-move-right" tabindex="0">%4$s</span>',
					esc_html__( 'Move up' ),
					esc_html__( 'Move down' ),
					esc_html__( 'Move one level up' ),
					esc_html__( 'Move one level down' )
				);
				?>
			</div>
		</script>

		<script type="text/html" id="tmpl-menu-section-for-core">
			<li id="accordion-section-{{ data.id }}" class="accordion-section control-section control-section-default">
				<h3 class="accordion-section-title" tabindex="0">{{ data.title }}<span class="screen-reader-text">Press return or enter to expand</span>
				</h3>
				<ul class="accordion-section-content"></ul>
			</li>
		</script>
		<?php // @todo the section template should be removed in favor of being in core, whenever a section is dynamically added
	}

	/**
	 * Print the html template used to render the add-menu-item frame.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function available_items_template() {
		?>
		<div id="available-menu-items" class="accordion-container">
			<div id="new-custom-menu-item" class="accordion-section">
				<h4 class="accordion-section-title"><?php _e( 'Links' ); ?></h4>
				<div class="accordion-section-content">
					<input type="hidden" value="custom" id="custom-menu-item-type" name="menu-item[-1][menu-item-type]" />
					<p id="menu-item-url-wrap">
						<label class="howto" for="custom-menu-item-url">
							<span>URL</span>
							<input id="custom-menu-item-url" name="menu-item[-1][menu-item-url]" type="text" class="code menu-item-textbox" value="http://">
						</label>
					</p>
					<p id="menu-item-name-wrap">
						<label class="howto" for="custom-menu-item-name">
							<span>Link Text</span>
							<input id="custom-menu-item-name" name="menu-item[-1][menu-item-title]" type="text" class="regular-text menu-item-textbox">
						</label>
					</p>
					<p class="button-controls">
						<span class="add-to-menu">
							<input type="submit" class="button-secondary submit-add-to-menu right" value="Add to Menu" name="add-custom-menu-item" id="custom-menu-item-submit">
							<span class="spinner"></span>
						</span>
					</p>
				</div>
			</div>
			<div id="available-menu-items-search" class="accordion-section">
				<div class="accordion-section-title">
					<label class="screen-reader-text" for="menu-items-search"><?php _e( 'Search Menu Items' ); ?></label>
					<input type="search" id="menu-items-search" placeholder="<?php esc_attr_e( 'Search menu items&hellip;' ) ?>" />
				</div>
				<div class="accordion-section-content">
				</div>
			</div>
			<?php

			// @todo: consider user add_meta_box/do_accordion_section and making screen-optional?

			// Containers for per-post-type item browsing; items added with JS.
			// @todo: render these (and the contents) with JS, rather than here.
			$post_types = get_post_types( array( 'show_in_nav_menus' => true ), 'object' );
			if ( $post_types ) {
				foreach ( $post_types as $type ) {
					?>
					<div id="available-menu-items-<?php echo esc_attr( $type->name ); ?>" class="accordion-section">
						<h4 class="accordion-section-title"><?php echo esc_html( $type->label ); ?></h4>
						<div class="accordion-section-content">
						</div>
					</div>
					<?php
				}
			}

			$taxonomies = get_taxonomies( array( 'show_in_nav_menus' => true ), 'object' );
			if ( $taxonomies ) {
				foreach ( $taxonomies as $tax ) {
					?>
					<div id="available-menu-items-<?php echo esc_attr( $tax->name ); ?>" class="accordion-section">
						<h4 class="accordion-section-title"><?php echo esc_html( $tax->label ); ?></h4>
						<div class="accordion-section-content">
						</div>
					</div>
					<?php
				}
			}
			?>
		</div><!-- #available-menu-items -->
		<?php
	}
}

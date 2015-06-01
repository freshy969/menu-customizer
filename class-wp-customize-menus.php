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
	 * Previewed Menus.
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

		$this->register_styles( wp_styles() );
		$this->register_scripts( wp_scripts() );

		add_action( 'wp_ajax_add-nav-menu-customizer', array( $this, 'new_menu_ajax' ) );
		add_action( 'wp_ajax_delete-menu-customizer', array( $this, 'delete_menu_ajax' ) );
		add_action( 'wp_ajax_update-menu-item-customizer', array( $this, 'update_item_ajax' ) );
		add_action( 'wp_ajax_add-menu-item-customizer', array( $this, 'add_item_ajax' ) );
		add_action( 'wp_ajax_load-available-menu-items-customizer', array( $this, 'load_available_items_ajax' ) );
		add_action( 'wp_ajax_search-available-menu-items-customizer', array( $this, 'search_available_items_ajax' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'customize_register', array( $this, 'customize_register' ), 11 ); // Needs to run after core Navigation section is set up.
		add_action( 'customize_update_menu_name', array( $this, 'update_menu_name' ), 10, 2 );
		add_action( 'customize_update_menu_autoadd', array( $this, 'update_menu_autoadd' ), 10, 2 );
		add_action( 'customize_preview_nav_menu', array( $this, 'preview_nav_menu' ), 10, 1 );
		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_nav_menu_items_for_preview' ), 10, 2 );
		add_action( 'customize_update_nav_menu', array( $this, 'update_nav_menu' ), 10, 2 );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'print_templates' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'available_items_template' ) );
		add_action( 'customize_preview_init', array( $this, 'customize_preview_init' ) );
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
			wp_send_json_error( array( 'message' => __( 'Error: invalid user capabilities.' ) ) );
		}

		$menu_name = sanitize_text_field( $_POST['menu-name'] );

		if ( empty( $menu_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Menu name is required.' ) ) );
		}

		// Create the menu.
		$menu_id = wp_create_nav_menu( $menu_name );

		if ( is_wp_error( $menu_id ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $menu_id->get_error_message(), true ) ) );
		}

		// Output the data for this new menu.
		wp_send_json_success( array( 'name' => $menu_name, 'id' => $menu_id ) );
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
			wp_send_json_error( array( 'message' => __( 'Error: invalid user capabilities.' ) ) );
		}

		$menu_id = absint( $_POST['menu'] );

		if ( is_nav_menu( $menu_id ) ) {
			$deletion = wp_delete_nav_menu( $menu_id );
			if ( is_wp_error( $deletion ) ) {
				wp_send_json_error( array( 'message' => wp_strip_all_tags( $deletion->get_error_message(), true ) ) );
			} else {
				wp_send_json_success();
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Error: invalid menu to delete.' ) ) );
		}
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
			wp_send_json_error( array( 'message' => __( 'Error: invalid user capabilities.' ) ) );
		}

		$clone = $_POST['clone'];
		$item_id = $_POST['item_id'];
		$menu_item_data = (array) $_POST['menu-item'];

		$id = $this->update_item( 0, $item_id, $menu_item_data, $clone );

		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $id->get_error_message(), true ) ) );
		} else {
			wp_send_json_success( $id );
		}
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
			wp_send_json_error( array( 'message' => __( 'Error: invalid user capabilities.' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/nav-menu.php';

		$menu_item_data = (array) $_POST['menu-item'];
		$menu_id = absint( $_POST['menu'] ); // Used only for display, new item is created as an orphan - menu id of 0.
		$id = 0;

		// For performance reasons, we omit some object properties from the checklist.
		// The following is a hacky way to restore them when adding non-custom items.
		// @todo: do we really need this - do we need to populate the description field here? (note: copied from existing core system)

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

		// Make the "Home" item into the custom link that it actually is.
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
		if ( is_wp_error( $items_id ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $items_id->get_error_message(), true ) ) );
		} else if ( empty( $items_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Menu ID is required.' ) ) );
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
			wp_send_json_success( $control->json() );
		}

		wp_send_json_error( array( 'message' => __( 'The menu item could not be added.' ) ) );
	}

	/**
	 * Ajax handler for loading available menu items.
	 *
	 * @since Menu Customizer 0.3
	 * @access public
	 */
	public function load_available_items_ajax() {
		check_ajax_referer( 'customize-menus', 'customize-menus-nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Error: invalid user capabilities.' ) ) );
		}

		$page = absint( $_POST['page'] );
		$type = esc_html( $_POST['type'] );
		$obj_type = esc_html( $_POST['obj_type'] );
		$items = array();
		$posts = $terms = false;

		if ( 'post_type' === $obj_type ) {
			if ( 0 === $page && 'page' === $type ) {
				// Add "Home" link. Treat as a page, but switch to custom on add.
				$home = array(
					'id'          => 0,
					'name'        => _x( 'Home', 'nav menu home label' ),
					'type'        => 'page',
					'type_label'  => __( 'Custom Link' ),
					'obj_type'    => 'custom',
				);
				$items[] = $home;
			}
			$args = array(
				'numberposts' => 10,
				'offset'        => 10 * $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_type'      => $type,
			);
			$posts = get_posts( $args );
			foreach ( $posts as $post ) {
				$items[] = array(
					'id'         => 'post-' . $post->ID,
					'name'       => $post->post_title,
					'type'       => $type,
					'type_label' => get_post_type_object( $type )->labels->singular_name,
					'obj_type'   => 'post_type',
				);
			}
		} else {
			$args = array(
				'child_of'      => 0,
				'exclude'       => '',
				'hide_empty'    => false,
				'hierarchical'  => 1,
				'include'       => '',
				'number'        => 10,
				'offset'        => 10 * $page,
				'order'         => 'DESC',
				'orderby'       => 'count',
				'pad_counts'    => false,
			);
			$terms = get_terms( $type, $args );

			foreach ( $terms as $term ) {
				$items[] = array(
					'id'         => 'term-' . $term->term_id,
					'name'       => $term->name,
					'type'       => $type,
					'type_label' => get_taxonomy( $type )->labels->singular_name,
					'obj_type'   => 'taxonomy',
				);
			}
		}

		if ( $terms && is_wp_error( $terms ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $terms->get_error_message(), true ) ) );
		} elseif ( $posts && is_wp_error( $posts ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $posts->get_error_message(), true ) ) );
		} else {
			wp_send_json_success( array( 'items' => $items ) );
		}
	}

	/**
	 * Ajax handler for searching available menu items.
	 *
	 * @since Menu Customizer 0.4
	 * @access public
	 */
	public function search_available_items_ajax() {
		check_ajax_referer( 'customize-menus', 'customize-menus-nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Error: invalid user capabilities.' ) ) );
		}

		$p = absint( $_POST['page'] );
		$s = esc_html( $_POST['search'] );
		$results = $this->search_available_items_query( array( 'pagenum' => $p, 's' => $s ) );
		if ( ! $results ) {
			wp_send_json_error( array( 'message' => __( 'No results found.' ) ) );
		} else {
			wp_send_json_success( array( 'items' => $results ) );
		}
	}

	/*
	 * Performs post queries for available-item searching.
	 *
	 * Based on WP_Editor::wp_link_query().
	 *
	 * @since Menu Customizer 0.4
	 *
	 * @static
	 * @param array $args Optional. Accepts 'pagenum' and 's' (search) arguments.
	 * @return false|array Results.
	 */
	public static function search_available_items_query( $args = array() ) {
		$pts = get_post_types( array( 'show_in_nav_menus' => true ), 'objects' );
		$pt_names = array_keys( $pts );
		$query = array(
			'post_type' => $pt_names,
			'suppress_filters' => true,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'post_status' => 'publish',
			'posts_per_page' => 20,
		);
		$args['pagenum'] = isset( $args['pagenum'] ) ? absint( $args['pagenum'] ) : 1;
		if ( isset( $args['s'] ) ) {
			$query['s'] = $args['s'];
		}
		$query['offset'] = $args['pagenum'] > 1 ? $query['posts_per_page'] * ( $args['pagenum'] - 1 ) : 0;
		// Do main query.
		$get_posts = new WP_Query;
		$posts = $get_posts->query( $query );
		// Check if any posts were found.
		if ( ! $get_posts->post_count ) {
			return false;
		}
		// Build results.
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'id'         => 'post-' . $post->ID,
				'name'       => trim( esc_html( strip_tags( get_the_title( $post ) ) ) ),
				'type'       => $post->post_type,
				'type_label' => $pts[ $post->post_type ]->labels->singular_name,
				'obj_type'   => 'post',
			);
		}
		// Query taxonomy terms.
		$taxonomies = get_taxonomies( array( 'show_in_nav_menus' => true ), 'names' );
		$terms = get_terms( $taxonomies, array(
			'name__like' => $args['s'],
			'number' => 20,
			'offset' => 20 * ($args['pagenum'] - 1),
		));
		foreach ( $terms as $term ) {
			$results[] = array(
				'id'         => 'term-' . $term->term_id,
				'name'       => $term->name,
				'type'       => $term->taxonomy,
				'type_label' => get_taxonomy( $term->taxonomy )->labels->singular_name,
				'obj_type'   => 'taxonomy',
			);
		}
		return $results;
	}

	/**
	 * Register all scripts used by plugin.
	 *
	 * @param WP_Scripts $wp_scripts
	 */
	public function register_scripts( $wp_scripts ) {
		$handle = 'menu-customizer';
		$src = plugin_dir_url( __FILE__ ) . 'menu-customizer.js';
		$deps = array( 'jquery', 'wp-backbone', 'customize-controls', 'accordion', 'nav-menu', 'wp-a11y' );
		$wp_scripts->add( $handle, $src, $deps );

		$handle = 'customize-menus-preview';
		$src = plugin_dir_url( __FILE__ ) . 'customize-menus-preview.js';
		$deps = array( 'customize-preview', 'wp-util' );
		$args = array(
			'in_footer' => true,
		);
		$wp_scripts->add( $handle, $src, $deps, false, $args );
	}

	/**
	 * Register all styles used by plugin.
	 *
	 * @param WP_Styles $wp_styles
	 */
	public function register_styles( $wp_styles ) {
		$handle = 'menu-customizer';
		$src = plugin_dir_url( __FILE__ ) . 'menu-customizer.css';
		$wp_styles->add( $handle, $src );

		$handle = 'customize-menus-preview';
		$src = plugin_dir_url( __FILE__ ) . 'customize-menus-preview.css';
		$wp_styles->add( $handle, $src );
	}

	/**
	 * Enqueue scripts and styles for Customizer pane.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function enqueue() {
		wp_enqueue_style( 'menu-customizer' );
		wp_enqueue_script( 'menu-customizer' );

		// Pass data to JS.
		$settings = array(
			'nonce'                => wp_create_nonce( 'customize-menus' ),
			'allMenus'             => wp_get_nav_menus(),
			'itemTypes'            => $this->available_item_types(),
			'l10n'                 => array(
				'untitled'        => _x( '(no label)', 'Missing menu item navigation label.' ),
				'custom_label'    => _x( 'Custom', 'Custom menu item type label.' ),
				'menuLocation'    => _x( '(Currently set to: %s)', 'Current menu location.' ),
				'deleteWarn'      => __( 'You are about to permanently delete this menu. "Cancel" to stop, "OK" to delete.' ),
				'itemAdded'       => __( 'Menu item added' ),
				'itemDeleted'     => __( 'Menu item deleted' ),
				'menuAdded'       => __( 'Menu created' ),
				'menuDeleted'     => __( 'Menu deleted' ),
				'movedUp'         => __( 'Menu item moved up' ),
				'movedDown'       => __( 'Menu item moved down' ),
				'movedLeft'       => __( 'Menu item moved out of submenu' ),
				'movedRight'      => __( 'Menu item is now a sub-item' ),
			),
			'menuItemTransport'    => 'postMessage',
		);

		$data = sprintf( 'var _wpCustomizeMenusSettings = %s;', json_encode( $settings ) );
		wp_scripts()->add_data( 'menu-customizer', 'data', $data );

		// This is copied from nav-menus.php, and it has an unfortunate object name of `menus`.
		$nav_menus_l10n = array(
			'oneThemeLocationNoMenus' => null,
			'moveUp'       => __( 'Move up one' ),
			'moveDown'     => __( 'Move down one' ),
			'moveToTop'    => __( 'Move to the top' ),
			/* translators: %s: previous item name */
			'moveUnder'    => __( 'Move under %s' ),
			/* translators: %s: previous item name */
			'moveOutFrom'  => __( 'Move out from under %s' ),
			/* translators: %s: previous item name */
			'under'        => __( 'Under %s' ),
			/* translators: %s: previous item name */
			'outFrom'      => __( 'Out from under %s' ),
			/* translators: 1: item name, 2: item position, 3: total number of items */
			'menuFocus'    => __( '%1$s. Menu item %2$d of %3$d.' ),
			/* translators: 1: item name, 2: item position, 3: parent item name */
			'subMenuFocus' => __( '%1$s. Sub item number %2$d under %3$s.' ),
		);
		wp_localize_script( 'nav-menu', 'menus', $nav_menus_l10n );
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
		$this->manager->register_panel_type( 'WP_Customize_Menus_Panel' );
		$this->manager->register_control_type( 'WP_Customize_Nav_Menu_Control' );
		$this->manager->register_control_type( 'WP_Customize_Menu_Item_Control' );
		$this->manager->register_section_type( 'WP_Customize_Menu_Section' );

		// Create a panel for Menus.
		$this->manager->add_panel( new WP_Customize_Menus_Panel( $this->manager, 'menus', array(
			'title'        => __( 'Menus' ),
			'description'  => '<p>' . __( 'This panel is used for managing navigation menus for content you have already published on your site. You can create menus and add items for existing content such as pages, posts, categories, tags, formats, or custom links.' ) . '</p><p>' . __( 'Menus can be displayed in locations defined by your theme or in widget areas by adding a "Custom Menu" widget.' ) . '</p>',
			'priority'     => 30,
			//'theme_supports' => 'menus|widgets', @todo allow multiple theme supports
		) ) );

		// Menu loactions.
		$this->manager->remove_section( 'nav' ); // Remove old core section. @todo core merge remove corresponding code from WP_Customize_Manager::register_controls().
		$locations     = get_registered_nav_menus();
		$menus         = wp_get_nav_menus();
		$num_locations = count( array_keys( $locations ) );
		$description   = '<p>' . sprintf( _n( 'Your theme contains %s menu location. Select which menu you would like to use.', 'Your theme contains %s menu locations. Select which menu appears in each location.', $num_locations ), number_format_i18n( $num_locations ) );
		$description  .= '</p><p>' . __( 'You can also place menus in widget areas with the Custom Menu widget.' ) . '</p>';

		$this->manager->add_section( 'menu_locations', array(
			'title'       => __( 'Menu Locations' ),
			'panel'       => 'menus',
			'priority'    => 5,
			'description' => $description,
		) );

		// @todo if ( ! $menus ) : make a "default" menu

		if ( $menus ) {
			$choices = array( '' => __( '&mdash; Select &mdash;' ) );
			foreach ( $menus as $menu ) {
				    $choices[ $menu->term_id ] = wp_html_excerpt( $menu->name, 40, '&hellip;' );
			}

			foreach ( $locations as $location => $description ) {
				$menu_setting_id = "nav_menu_locations[{$location}]";

				$this->manager->add_setting( $menu_setting_id, array(
					'sanitize_callback' => 'absint',
					'theme_supports'    => 'menus',
				) );

				$this->manager->add_control( new WP_Customize_Menu_Location_Control( $this->manager, $menu_setting_id, array(
					'label'       => $description,
					'location_id' => $location,
					'section'     => 'menu_locations',
					'choices'     => $choices,
				) ) );
			}
		}

		// Register each menu as a Customizer section, and add each menu item to each menu.
		foreach ( $menus as $menu ) {
			$menu_id = $menu->term_id;

			// Create a section for each menu.
			$section_id = 'nav_menus[' . $menu_id . ']';
			$this->manager->add_section( new WP_Customize_Menu_Section( $this->manager, $section_id, array(
				'title'     => $menu->name,
				'priority'  => 10,
				'panel'     => 'menus',
			) ) );

			// Add a setting & control for the menu name.
			$menu_name_setting_id = $section_id . '[name]';
			$this->manager->add_setting( $menu_name_setting_id, array(
				'default'   => $menu->name,
				'type'      => 'menu_name',
				'transport' => 'postMessage', // Not previewed, so don't trigger a refresh.
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
							if ( ! isset( $incoming_setting_value->title ) ) {
								// @TODO: This should be supplied already
								$incoming_setting_value->title = 'UNTITLED';
							}
							if ( ! isset( $incoming_setting_value->menu_item_parent ) ) {
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
				'type'      => 'nav_menu',
				'default'   => $item_ids,
				'transport' => 'postMessage',
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
			} elseif ( false !== array_search( $menu_id, $auto_add['auto_add'] ) ) {
				$auto_add = true;
			} else {
				$auto_add = false;
			}

			$menu_autoadd_setting_id = $section_id . '[auto_add]';
			$this->manager->add_setting( $menu_autoadd_setting_id, array(
				'type'      => 'menu_autoadd',
				'default'   => $auto_add,
				'transport' => 'postMessage', // Not previewed, so don't trigger a refresh.
			) );

			$this->manager->add_control( $menu_autoadd_setting_id, array(
				'label'     => __( 'Automatically add new top-level pages to this menu.' ),
				'section'   => $section_id,
				'type'      => 'checkbox',
				'priority'  => 999,
			) );
		}

		// Add the add-new-menu section and controls.
		$this->manager->add_section( new WP_Customize_New_Menu_Section( $this->manager, 'add_menu', array(
			'title'     => __( 'Add a Menu' ),
			'panel'     => 'menus',
			'priority'  => 999,
		) ) );

		$this->manager->add_setting( 'new_menu_name', array(
			'type'      => 'new_menu',
			'default'   => '',
			'transport' => 'postMessage', // Not previewed, so don't trigger a refresh.
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
				unset( $nav_menu_option['auto_add'][ $key ] );
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
	 * @return WP_Nav_Menu_Object|WP_Error The nav_menu term that corresponds to a setting, or a WP_Error if it doesn't exist.
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

			// For each item, get object and update menu order property.
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
			$this->update_menu_item_order( $menu_id, $item_id, $i );
			$i++;
		}

		foreach ( $old_items as $item_id ) {
			if ( is_nav_menu_item( $item_id ) ) {
				wp_delete_post( $item_id, true );
			}
		}
	}

	/**
	 * Updates the order for and publishes an existing menu item.
	 *
	 * Skips the mess that is wp_update_nav_menu_item() and avoids
	 * handling menu item fields that are not changed.
	 *
	 * Based on the parts of wp_update_nav_menu_item() that are needed here. $menu_id must already be
	 * validated before running this function (to avoid re-validating for each item in the menu).
	 *
	 * @since Menu Customizer 0.0
	 *
	 * @param int $menu_id The valid ID of the menu.
	 * @param int $item_id The ID of the (existing) menu item.
	 * @param int $order   The menu item's new order/position.
	 * @return int|WP_Error The menu item's database ID or WP_Error object on failure.
	 */
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
	 * Return an array of all the available item types.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function available_item_types() {
		$items = array();
		$types = get_post_types( array( 'show_in_nav_menus' => true ), 'names' );
		foreach ( $types as $type ) {
			$items[] = array( 'type' => $type, 'obj_type' => 'post_type' );
		}
		$taxes = get_taxonomies( array( 'show_in_nav_menus' => true ), 'names' );
		foreach ( $taxes as $tax ) {
			$items[] = array( 'type' => $tax, 'obj_type' => 'taxonomy' );
		}
		return $items;
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
						<span class="item-title">{{{ data.name }}}</span>
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
							<span class="spinner" style="visibility: visible;"></span>
							<span class="item-type">{{ data.type_label }}</span>
							<span class="item-title menu-item-title">{{{ data.name }}}</span>
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
		<?php
	}

	/**
	 * Print the html template used to render the add-menu-item frame.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function available_items_template() {
		?>
		<div id="available-menu-items" class="accordion-container">
			<div class="customize-section-title">
				<button class="customize-section-back" tabindex="-1">
					<span class="screen-reader-text"><?php _e( 'Back' ); ?></span>
				</button>
				<h3>
					<span class="customize-action"><?php
						/* translators: &#9656; is the unicode right-pointing triangle, and %s is the section title in the Customizer */
						echo sprintf( __( 'Customizing &#9656; %s' ), esc_html( $this->manager->get_panel( 'menus' )->title ) );
					?></span>
					<?php _e( 'Add Menu Items' ); ?>
				</h3>
			</div>
			<div id="available-menu-items-search" class="accordion-section cannot-expand">
				<div class="accordion-section-title">
					<label class="screen-reader-text" for="menu-items-search"><?php _e( 'Search Menu Items' ); ?></label>
					<input type="text" id="menu-items-search" placeholder="<?php esc_attr_e( 'Search menu items&hellip;' ) ?>" />
					<span class="spinner"></span>
				</div>
				<div class="accordion-section-content" data-type="search"></div>
			</div>
			<div id="new-custom-menu-item" class="accordion-section">
				<h4 class="accordion-section-title"><?php _e( 'Links' ); ?><button type="button" class="not-a-button"><?php _e( 'Toggle' ); ?></button></h4>
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
			<?php

			// @todo: consider using add_meta_box/do_accordion_section and making screen-optional?

			// Containers for per-post-type item browsing; items added with JS.
			$post_types = get_post_types( array( 'show_in_nav_menus' => true ), 'object' );
			if ( $post_types ) {
				foreach ( $post_types as $type ) {
					?>
					<div id="available-menu-items-<?php echo esc_attr( $type->name ); ?>" class="accordion-section">
						<h4 class="accordion-section-title"><?php echo esc_html( $type->label ); ?><span class="spinner"></span><button type="button" class="not-a-button"><?php _e( 'Toggle' ); ?></button></h4>
						<div class="accordion-section-content" data-type="<?php echo $type->name; ?>" data-obj_type="post_type"></div>
					</div>
					<?php
				}
			}

			$taxonomies = get_taxonomies( array( 'show_in_nav_menus' => true ), 'object' );
			if ( $taxonomies ) {
				foreach ( $taxonomies as $tax ) {
					?>
					<div id="available-menu-items-<?php echo esc_attr( $tax->name ); ?>" class="accordion-section">
						<h4 class="accordion-section-title"><?php echo esc_html( $tax->label ); ?><span class="spinner"></span><button type="button" class="not-a-button"><?php _e( 'Toggle' ); ?></button></h4>
						<div class="accordion-section-content" data-type="<?php echo $tax->name; ?>" data-obj_type="taxonomy"></div>
					</div>
					<?php
				}
			}
			?>
		</div><!-- #available-menu-items -->
		<?php
	}

	// Start functionality specific to partial-refresh of menu changes in Customizer preview.

	const RENDER_AJAX_ACTION = 'customize_render_menu_partial';
	const RENDER_NONCE_POST_KEY = 'render-menu-nonce';
	const RENDER_QUERY_VAR = 'wp_customize_menu_render';

	/**
	 * The number of wp_nav_menu() calls which have happened in the preview.
	 *
	 * @var int
	 */
	public $preview_nav_menu_instance_number = 0;

	/**
	 * Nav menu args used for each instance.
	 *
	 * @var array[]
	 */
	public $preview_nav_menu_instance_args = array();

	/**
	 * Add hooks for the Customizer preview.
	 */
	function customize_preview_init() {
		add_action( 'template_redirect', array( $this, 'render_menu' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'customize_preview_enqueue_deps' ) );
		if ( ! isset( $_REQUEST[ self::RENDER_QUERY_VAR ] ) ) {
			add_filter( 'wp_nav_menu', array( $this, 'filter_wp_nav_menu' ), 10, 2 );
		}
	}

	/**
	 * Filter whether to short-circuit the wp_nav_menu() output.
	 *
	 * @see wp_nav_menu()
	 *
	 * @param string $nav_menu_content The HTML content for the navigation menu.
	 * @param object $args             An object containing wp_nav_menu() arguments.
	 * @return null
	 */
	function filter_wp_nav_menu( $nav_menu_content, $args ) {
		$this->preview_nav_menu_instance_number += 1;

		// Get the nav menu based on the requested menu.
		$nav_menu = wp_get_nav_menu_object( $args->menu );

		// Get the nav menu based on the theme_location.
		if ( ! $nav_menu && $args->theme_location && ( $locations = get_nav_menu_locations() ) && isset( $locations[ $args->theme_location ] ) ) {
			$nav_menu = wp_get_nav_menu_object( $locations[ $args->theme_location ] );
		}

		// Get the first menu that has items if we still can't find a menu.
		if ( ! $nav_menu && ! $args->theme_location ) {
			$menus = wp_get_nav_menus();
			foreach ( $menus as $menu_maybe ) {
				if ( $menu_items = wp_get_nav_menu_items( $menu_maybe->term_id, array( 'update_post_term_cache' => false ) ) ) {
					$nav_menu = $menu_maybe;
					break;
				}
			}
		}

		if ( $nav_menu ) {
			$exported_args = get_object_vars( $args );
			unset( $exported_args['fallback_cb'] ); // This could be a closure which would blow serialization, so remove.
			unset( $exported_args['echo'] ); // We'll be forcing echo in the Ajax request handler anyway.
			$exported_args['menu'] = $nav_menu->term_id; // Eliminate location-based and slug-based calls; always use menu ID.
			ksort( $exported_args );
			$exported_args['args_hash'] = $this->hash_nav_menu_args( $exported_args );
			$this->preview_nav_menu_instance_args[ $this->preview_nav_menu_instance_number ] = $exported_args;
			$nav_menu_content = sprintf( '<div id="partial-refresh-menu-container-%1$d" class="partial-refresh-menu-container" data-instance-number="%1$d">%2$s</div>', $this->preview_nav_menu_instance_number, $nav_menu_content );
		}

		return $nav_menu_content;
	}

	/**
	 * Hash (hmac) the arguments with the nonce and secret auth key to ensure they
	 * are not tampered with when submitted in the Ajax request.
	 *
	 * @param array $args
	 * @return string
	 */
	function hash_nav_menu_args( $args ) {
		return wp_hash( wp_create_nonce( self::RENDER_AJAX_ACTION ) . serialize( $args ) );
	}

	/**
	 * Enqueue scripts for the Customizer preview.
	 */
	function customize_preview_enqueue_deps() {
		wp_enqueue_script( 'customize-menus-preview' );
		wp_enqueue_style( 'customize-menus-preview' );

		add_action( 'wp_print_footer_scripts', array( $this, 'export_preview_data' ) );
	}

	/**
	 * Export data from PHP to JS.
	 */
	function export_preview_data() {

		// Why not wp_localize_script? Because we're not localizing, and it forces values into strings
		$exports = array(
			'renderQueryVar' => self::RENDER_QUERY_VAR,
			'renderNonceValue' => wp_create_nonce( self::RENDER_AJAX_ACTION ),
			'renderNoncePostKey' => self::RENDER_NONCE_POST_KEY,
			'requestUri' => '/',
			'theme' => array(
				'stylesheet' => $this->manager->get_stylesheet(),
				'active'     => $this->manager->is_theme_active(),
			),
			'previewCustomizeNonce' => wp_create_nonce( 'preview-customize_' . $this->manager->get_stylesheet() ),
			'navMenuInstanceArgs' => $this->preview_nav_menu_instance_args,
		);
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$exports['requestUri'] = esc_url_raw( home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		}
		printf( '<script>var _wpCustomizePreviewMenusExports = %s;</script>', wp_json_encode( $exports ) );
	}

	/**
	 * Render a specific menu via wp_nav_menu() using the supplied arguments.
	 *
	 * @see wp_nav_menu()
	 */
	function render_menu() {
		if ( empty( $_POST[ self::RENDER_QUERY_VAR ] ) ) {
			return;
		}

		$generic_error = __( 'An error has occurred. Please reload the page and try again.', 'customize-partial-preview-refresh' );
		try {
			$this->manager->remove_preview_signature();

			// @todo Instead of throwing Exceptions, we'll have to switch to passing around WP_Error objects.

			if ( empty( $_POST[ self::RENDER_NONCE_POST_KEY ] ) ) {
				throw new WP_Customize_Menus_Exception( __( 'Missing nonce param', 'customize-partial-preview-refresh' ) );
			}
			if ( ! is_customize_preview() ) {
				throw new WP_Customize_Menus_Exception( __( 'Expected customizer preview', 'customize-partial-preview-refresh' ) );
			}
			if ( ! check_ajax_referer( self::RENDER_AJAX_ACTION, self::RENDER_NONCE_POST_KEY, false ) ) {
				throw new WP_Customize_Menus_Exception( __( 'Nonce check failed. Reload and try again?', 'customize-partial-preview-refresh' ) );
			}
			if ( ! current_user_can( 'edit_theme_options' ) ) {
				throw new WP_Customize_Menus_Exception( __( 'Current user cannot!', 'customize-partial-preview-refresh' ) );
			}
			if ( ! isset( $_POST['wp_nav_menu_args'] ) ) {
				throw new WP_Customize_Menus_Exception( __( 'Missing wp_nav_menu_args param', 'customize-partial-preview-refresh' ) );
			}
			if ( ! isset( $_POST['wp_nav_menu_args_hash'] ) ) {
				throw new WP_Customize_Menus_Exception( __( 'Missing wp_nav_menu_args_hash param', 'customize-partial-preview-refresh' ) );
			}
			$wp_nav_menu_args_hash = wp_unslash( sanitize_text_field( $_POST['wp_nav_menu_args_hash'] ) );
			$wp_nav_menu_args = json_decode( wp_unslash( $_POST['wp_nav_menu_args'] ), true );
			if ( json_last_error() ) {
				throw new WP_Customize_Menus_Exception( sprintf( __( 'JSON Error: %s', 'customize-partial-preview-refresh' ), json_last_error() ) );
			}
			if ( ! is_array( $wp_nav_menu_args ) ) {
				throw new WP_Customize_Menus_Exception( __( 'Expected wp_nav_menu_args to be an array', 'customize-partial-preview-refresh' ) );
			}
			if ( $this->hash_nav_menu_args( $wp_nav_menu_args ) !== $wp_nav_menu_args_hash ) {
				throw new WP_Customize_Menus_Exception( __( 'Supplied wp_nav_menu_args does not hash to be wp_nav_menu_args_hash', 'customize-partial-preview-refresh' ) );
			}

			$wp_nav_menu_args['echo'] = false;
			wp_send_json_success( wp_nav_menu( $wp_nav_menu_args ) );
		} catch ( Exception $e ) {
			if ( $e instanceof WP_Customize_Menus_Exception && ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				$message = $e->getMessage();
			} else {
				trigger_error( esc_html( sprintf( '%s in %s: %s', get_class( $e ), __FUNCTION__, $e->getMessage() ) ), E_USER_WARNING );
				$message = $generic_error;
			}
			wp_send_json_error( compact( 'message' ) );
		}
	}
}


class WP_Customize_Menus_Exception extends Exception {}

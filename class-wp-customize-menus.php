<?php
/**
 * Base Customize Menus
 *
 * @package WordPress
 * @subpackage Customize
 */

/**
 * Base Customize Menus class which implements menu management in the Customizer.
 *
 * @since 4.3.0
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
	 * @var array
	 */
	public $previewed_menus;

	/**
	 * Constructor
	 *
	 * @since Menu Customizer 0.3
	 * @access public
	 * @param object $manager An instance of the WP_Customize_Manager class.
	 */
	public function __construct( $manager ) {
		$this->previewed_menus = array();
		$this->manager = $manager;

		$this->register_styles( wp_styles() );
		$this->register_scripts( wp_scripts() );

		add_action( 'wp_ajax_add-nav-menu-customizer', array( $this, 'new_menu_ajax' ) ); // Removed.
		add_action( 'wp_ajax_delete-menu-customizer', array( $this, 'delete_menu_ajax' ) ); // Removed.
		add_action( 'wp_ajax_update-menu-item-customizer', array( $this, 'update_item_ajax' ) ); // Removed.
		add_action( 'wp_ajax_add-menu-item-customizer', array( $this, 'add_item_ajax' ) ); // Removed.

		add_action( 'wp_ajax_load-available-menu-items-customizer', array( $this, 'load_available_items_ajax' ) );
		add_action( 'wp_ajax_search-available-menu-items-customizer', array( $this, 'search_available_items_ajax' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'customize_register', array( $this, 'customize_register' ), 11 ); // Needs to run after core Navigation section is set up.
		add_filter( 'customize_dynamic_setting_args', array( $this, 'filter_dynamic_setting_args' ), 10, 2 );
		add_filter( 'customize_dynamic_setting_class', array( $this, 'filter_dynamic_setting_class' ), 10, 3 );
		add_action( 'customize_update_menu_autoadd', array( $this, 'update_menu_autoadd' ), 10, 2 );
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
		wp_send_json_error( 'ajax_eliminated' );
	}

	/**
	 * Ajax handler for deleting a menu.
	 *
	 * @since Menu Customizer 0.0.
	 * @access public
	 */
	public function delete_menu_ajax() {
		wp_send_json_error( 'ajax_eliminated' );
	}

	/**
	 * Ajax handler for updating a menu item.
	 *
	 * @since Menu Customizer 0.0.
	 * @access public
	 */
	public function update_item_ajax() {
		wp_send_json_error( 'ajax_eliminated' );
	}

	/**
	 * Ajax handler for adding a menu item. Based on wp_ajax_add_menu_item().
	 *
	 * @since Menu Customizer 0.0.
	 * @access public
	 */
	public function add_item_ajax() {
		wp_send_json_error( 'ajax_eliminated' );
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
		if ( empty( $_POST['obj_type'] ) || empty( $_POST['type'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing obj_type or type param.' ) ) );
		}
		$obj_type = sanitize_key( $_POST['obj_type'] );
		if ( ! in_array( $obj_type, array( 'post_type', 'taxonomy' ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid obj_type param: ' . $obj_type ) ) );
		}
		$taxonomy_or_post_type = sanitize_key( $_POST['type'] );
		$page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;

		$items = array();

		if ( 'post_type' === $obj_type ) {
			if ( ! get_post_type_object( $taxonomy_or_post_type ) ) {
				wp_send_json_error( array( 'message' => __( 'Unknown post type.' ) ) );
			}

			if ( 0 === $page && 'page' === $taxonomy_or_post_type ) {
				// Add "Home" link. Treat as a page, but switch to custom on add.
				$home = array(
					'id'          => 'home',
					'title'       => _x( 'Home', 'nav menu home label' ),
					'type'        => 'custom',
					'object'      => '',
					'url'         => home_url(),
				);
				$items[] = $home;
			}
			$args = array(
				'numberposts'    => 10,
				'offset'         => 10 * $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_type'      => $taxonomy_or_post_type,
			);
			$posts = get_posts( $args );
			foreach ( $posts as $post ) {
				$items[] = array(
					'id'         => "post-{$post->ID}",
					'title'      => $post->post_title,
					'type'       => 'post_type',
					'object'     => $post->post_type,
					'object_id'  => (int) $post->ID,
				);
			}
		} else if ( 'taxonomy' === $obj_type ) {
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
			$terms = get_terms( $taxonomy_or_post_type, $args );
			if ( is_wp_error( $terms ) ) {
				wp_send_json_error( array( 'message' => wp_strip_all_tags( $terms->get_error_message(), true ) ) );
			}

			foreach ( $terms as $term ) {
				$items[] = array(
					'id'         => "term-{$term->term_id}",
					'title'      => $term->name,
					'type'       => 'taxonomy',
					'object'     => $term->taxonomy,
					'object_id'  => $term->term_id,
				);
			}
		}

		wp_send_json_success( array( 'items' => $items ) );
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
		if ( empty( $_POST['search'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Error: missing search parameter.' ) ) );
		}

		$p = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 0;
		if ( $p < 1 ) {
			$p = 1;
		}

		$s = sanitize_text_field( wp_unslash( $_POST['search'] ) );
		$results = $this->search_available_items_query( array( 'pagenum' => $p, 's' => $s ) );

		if ( empty( $results ) ) {
			wp_send_json_error( array( 'message' => __( 'No results found.' ) ) );
		} else {
			wp_send_json_success( array( 'items' => $results ) );
		}
	}

	/**
	 * Performs post queries for available-item searching.
	 *
	 * Based on WP_Editor::wp_link_query().
	 *
	 * @since Menu Customizer 0.4
	 *
	 * @param array $args Optional. Accepts 'pagenum' and 's' (search) arguments.
	 * @return array Results.
	 */
	public function search_available_items_query( $args = array() ) {
		$post_type_objects = get_post_types( array( 'show_in_nav_menus' => true ), 'objects' );
		$post_type_names = array_keys( $post_type_objects );
		$query = array(
			'post_type' => $post_type_names,
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
		$get_posts = new WP_Query();
		$posts = $get_posts->query( $query );
		// Check if any posts were found.
		if ( ! $get_posts->post_count ) {
			return array();
		}
		// Build results.
		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'id'         => 'post-' . $post->ID,
				'type'       => 'post_type',
				'object'     => $post->post_type,
				'object_id'  => intval( $post->ID ),
				'title'      => $post->post_title,
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
				'type'       => 'taxonomy',
				'object'     => $term->taxonomy,
				'object_id'  => intval( $term->term_id ),
				'title'      => $term->name,
			);
		}
		return $results;
	}

	/**
	 * Register all scripts used by plugin.
	 *
	 * @param WP_Scripts $wp_scripts The WP_Scripts object for printing scripts.
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
	 * @param WP_Styles $wp_styles The WP_Styles object for printing styles.
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

		$temp_nav_menu_setting = new WP_Customize_Nav_Menu_Setting( $this->manager, 'nav_menu[-1]' );
		$temp_nav_menu_item_setting = new WP_Customize_Nav_Menu_Item_Setting( $this->manager, 'nav_menu_item[-1]' );

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
				'customizingMenus' => _x( 'Customizing &#9656; Menus', '&#9656 is the unicode right-pointing triangle' ),
				'invalidTitleTpl' => __( '%s (Invalid)' ),
				'pendingTitleTpl' => __( '%s (Pending)' ),
				'taxonomyTermLabel' => __( 'Taxonomy' ),
				'postTypeLabel'     => __( 'Post Type' ),
			),
			'menuItemTransport'    => 'postMessage',
			'phpIntMax' => PHP_INT_MAX,
			'defaultSettingValues' => array(
				'nav_menu' => $temp_nav_menu_setting->default,
				'nav_menu_item' => $temp_nav_menu_item_setting->default,
			),
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
	 * Filter a dynamic setting's constructor args.
	 *
	 * For a dynamic setting to be registered, this filter must be employed
	 * to override the default false value with an array of args to pass to
	 * the WP_Customize_Setting constructor.
	 *
	 * @param false|array $setting_args The arguments to the WP_Customize_Setting constructor.
	 * @param string      $setting_id   ID for dynamic setting, usually coming from `$_POST['customized']`.
	 * @return array|false
	 */
	public function filter_dynamic_setting_args( $setting_args, $setting_id ) {
		if ( preg_match( WP_Customize_Nav_Menu_Setting::ID_PATTERN, $setting_id ) ) {
			$setting_args = array(
				'type' => WP_Customize_Nav_Menu_Setting::TYPE,
			);
		} else if ( preg_match( WP_Customize_Nav_Menu_Item_Setting::ID_PATTERN, $setting_id ) ) {
			$setting_args = array(
				'type' => WP_Customize_Nav_Menu_Item_Setting::TYPE,
			);
		}
		return $setting_args;
	}

	/**
	 * Allow non-statically created settings to be constructed with custom WP_Customize_Setting subclass.
	 *
	 * @param string $setting_class WP_Customize_Setting or a subclass.
	 * @param string $setting_id    ID for dynamic setting, usually coming from `$_POST['customized']`.
	 * @param array  $setting_args  WP_Customize_Setting or a subclass.
	 * @return string
	 */
	public function filter_dynamic_setting_class( $setting_class, $setting_id, $setting_args ) {
		unset( $setting_id );
		if ( ! empty( $setting_args['type'] ) && WP_Customize_Nav_Menu_Setting::TYPE === $setting_args['type'] ) {
			$setting_class = 'WP_Customize_Nav_Menu_Setting';
		} else if ( ! empty( $setting_args['type'] ) && WP_Customize_Nav_Menu_Item_Setting::TYPE === $setting_args['type'] ) {
			$setting_class = 'WP_Customize_Nav_Menu_Item_Setting';
		}
		return $setting_class;
	}

	/**
	 * Add the customizer settings and controls.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function customize_register() {

		// Require JS-rendered control types.
		$this->manager->register_panel_type( 'WP_Customize_Menus_Panel' );
		$this->manager->register_control_type( 'WP_Customize_Nav_Menu_Control' );
		$this->manager->register_control_type( 'WP_Customize_Nav_Menu_Name_Control' );
		$this->manager->register_control_type( 'WP_Customize_Nav_Menu_Item_Control' );

		// Create a panel for Menus.
		$this->manager->add_panel( new WP_Customize_Menus_Panel( $this->manager, 'menus', array(
			'title'        => __( 'Menus' ),
			'description'  => '<p>' . __( 'This panel is used for managing navigation menus for content you have already published on your site. You can create menus and add items for existing content such as pages, posts, categories, tags, formats, or custom links.' ) . '</p><p>' . __( 'Menus can be displayed in locations defined by your theme or in widget areas by adding a "Custom Menu" widget.' ) . '</p>',
			'priority'     => 30,
			// 'theme_supports' => 'menus|widgets', @todo allow multiple theme supports
		) ) );
		$menus = wp_get_nav_menus();

		// Menu loactions.
		$this->manager->remove_section( 'nav' ); // Remove old core section. @todo core merge remove corresponding code from WP_Customize_Manager::register_controls().
		$locations     = get_registered_nav_menus();
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
					'type'              => 'theme_mod',
					'transport'         => 'postMessage',
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
			$section_id = 'nav_menu[' . $menu_id . ']';
			$this->manager->add_section( new WP_Customize_Nav_Menu_Section( $this->manager, $section_id, array(
				'title'     => $menu->name,
				'priority'  => 10,
				'panel'     => 'menus',
			) ) );

			$nav_menu_setting_id = 'nav_menu[' . $menu_id . ']';
			$this->manager->add_setting( new WP_Customize_Nav_Menu_Setting( $this->manager, $nav_menu_setting_id ) );

			// Add a control for the menu name.
			$control_id = $nav_menu_setting_id . '[name]';
			$this->manager->add_control( new WP_Customize_Nav_Menu_Name_Control( $this->manager, $control_id, array(
				'label'        => '',
				'section'      => $section_id,
				'priority'     => 0,
				'settings'     => $nav_menu_setting_id,
			) ) );

			// Add the menu contents.
			$menu_items = array();

			$menu_items = wp_get_nav_menu_items( $menu_id );
			if ( false === $menu_items ) {
				$menu_items = array();
			}

			foreach ( array_values( $menu_items ) as $i => $item ) {

				// Create a setting for each menu item (which doesn't actually manage data, currently).
				$menu_item_setting_id = 'nav_menu_item[' . $item->ID . ']';
				$this->manager->add_setting( new WP_Customize_Nav_Menu_Item_Setting( $this->manager, $menu_item_setting_id ) );

				// Create a control for each menu item.
				$this->manager->add_control( new WP_Customize_Nav_Menu_Item_Control( $this->manager, $menu_item_setting_id, array(
					'label'       => $item->title,
					'section'     => $section_id,
					'priority'    => 10 + $i,
				) ) );
			}

			// Add the menu control, which handles adding, ordering, auto-add, and location assignment. Note that the name control is split out above.
			$this->manager->add_control( new WP_Customize_Nav_Menu_Control( $this->manager, $nav_menu_setting_id, array(
				'section'   => $section_id,
				'priority'  => 999,
			) ) );
		}

		/*
		 * // Add the add-new-menu section and controls.
		 * $this->manager->add_section( new WP_Customize_New_Menu_Section( $this->manager, 'add_menu', array(
		 * 	'title'     => __( 'Add a Menu' ),
		 * 	'panel'     => 'menus',
		 * 	'priority'  => 999,
		 * ) ) );
		 *
		 * $this->manager->add_setting( 'new_menu_name', array(
		 * 	'type'      => 'new_menu',
		 * 	'default'   => '',
		 * 	'transport' => 'postMessage', // Not previewed, so don't trigger a refresh.
		 * ) );
		 *
		 * $this->manager->add_control( 'new_menu_name', array(
		 * 	'label'        => '',
		 * 	'section'      => 'add_menu',
		 * 	'type'         => 'text',
		 * 	'input_attrs'  => array(
		 * 		'class'        => 'menu-name-field',
		 * 		'placeholder'  => __( 'New menu name' ),
		 * 	),
		 * ) );
		 *
		 * $this->manager->add_setting( 'create_new_menu', array(
		 * 	'type' => 'new_menu',
		 * ) );
		 *
		 * $this->manager->add_control( new WP_New_Menu_Customize_Control( $this->manager, 'create_new_menu', array(
		 * 	'section'  => 'add_menu',
		 * ) ) );
		 */
	}

	/**
	 * Update the `auto_add` nav menus option.
	 */
	public function update_menu_autoadd( $value, $setting ) {
		throw new Exception( 'eliminated' );
	}

	/**
	 * Updates the order for and publishes an existing menu item.
	 */
	public function update_menu_item_order() {
		throw new Exception( 'eliminated' );
	}

	/**
	 * Update properties of a nav menu item, with the option to create a clone of the item.
	 */
	public function update_item() {
		throw new Exception( 'eliminated' );
	}

	/**
	 * Return an array of all the available item types.
	 *
	 * @todo This needs to export the names as well.
	 *
	 * @since Menu Customizer 0.0
	 */
	public function available_item_types() {
		$items = array(
			'postTypes' => array(),
			'taxonomies' => array(),
		);
		$post_types = get_post_types( array( 'show_in_nav_menus' => true ), 'objects' );
		foreach ( $post_types as $slug => $post_type ) {
			$items['postTypes'][ $slug ] = array(
				'label' => $post_type->labels->singular_name,
			);
		}
		$taxonomies = get_taxonomies( array( 'show_in_nav_menus' => true ), 'objects' );
		foreach ( $taxonomies as $slug => $taxonomy ) {
			$items['taxonomies'][ $slug ] = array(
				'label' => $taxonomy->labels->singular_name,
			);
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
						<span class="item-title">{{ data.title }}</span>
						<span class="item-added"><?php _e( 'Added' ); ?></span>
						<button type="button" class="not-a-button item-add"><?php _e( 'Add Menu Item' ) ?></button>
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

		<script type="text/html" id="tmpl-menu-item-reorder-nav">
			<div class="menu-item-reorder-nav">
				<?php
				printf(
					'<button type="button" class="menus-move-up">%1$s</button><button type="button" class="menus-move-down">%2$s</button><button type="button" class="menus-move-left">%3$s</button><button type="button" class="menus-move-right">%4$s</button>',
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
			if ( ! is_string( $exported_args['fallback_cb'] ) ) {
				// This could be a closure or object method which would blow serialization, so override.
				$exported_args['fallback_cb'] = '__return_empty_string';
			}
			unset( $exported_args['echo'] ); // We'll be forcing echo in the Ajax request handler anyway.
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
	 * @param array $args The arguments to hash.
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

		// Why not wp_localize_script? Because we're not localizing, and it forces values into strings.
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
	 *
	 * @throws WP_Customize_Menus_Exception To pass around errors.
	 */
	function render_menu() {
		if ( empty( $_POST[ self::RENDER_QUERY_VAR ] ) ) {
			return;
		}

		$this->manager->remove_preview_signature();

		if ( empty( $_POST[ self::RENDER_NONCE_POST_KEY ] ) ) {
			wp_send_json_error( 'missing_nonce_param' );
		}
		if ( ! is_customize_preview() ) {
			wp_send_json_error( 'expected_customize_preview' );
		}
		if ( ! check_ajax_referer( self::RENDER_AJAX_ACTION, self::RENDER_NONCE_POST_KEY, false ) ) {
			wp_send_json_error( 'nonce_check_fail' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_send_json_error( 'unauthorized' );
		}
		if ( ! isset( $_POST['wp_nav_menu_args'] ) ) {
			wp_send_json_error( 'missing_param' );
		}
		if ( ! isset( $_POST['wp_nav_menu_args_hash'] ) ) {
			wp_send_json_error( 'missing_param' );
		}
		$wp_nav_menu_args_hash = sanitize_text_field( wp_unslash( $_POST['wp_nav_menu_args_hash'] ) );
		$wp_nav_menu_args = json_decode( wp_unslash( $_POST['wp_nav_menu_args'] ), true );
		if ( json_last_error() ) {
			wp_send_json_error( 'json_parse_error' );
		}
		if ( ! is_array( $wp_nav_menu_args ) ) {
			wp_send_json_error( 'wp_nav_menu_args_not_array' );
		}
		if ( $this->hash_nav_menu_args( $wp_nav_menu_args ) !== $wp_nav_menu_args_hash ) {
			wp_send_json_error( 'wp_nav_menu_args_hash_mismatch' );
		}

		$wp_nav_menu_args['echo'] = false;
		wp_send_json_success( wp_nav_menu( $wp_nav_menu_args ) );
	}
}

/**
 * Customize Menus Exception Class
 */
class WP_Customize_Menus_Exception extends Exception {}

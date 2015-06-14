<?php
/**
 * WordPress Customize Nav Menu Setting class.
 *
 * @package WordPress
 * @subpackage Customize
 * @since 4.3.0
 */

/**
 * Customize Setting to represent a nav_menu.
 *
 * Subclass of WP_Customize_Setting to represent a nav_menu taxonomy term, and
 * the IDs for the nav_menu_items associated with the nav menu.
 *
 * @since 4.3.0
 *
 * @see wp_get_nav_menu_items()
 * @see WP_Customize_Setting
 */
class WP_Customize_Nav_Menu_Item_Setting extends WP_Customize_Setting {

	const ID_PATTERN = '/^nav_menu_item\[(?P<id>-?\d+)\]$/';

	const POST_TYPE = 'nav_menu_item';

	const TYPE = 'nav_menu_item';

	/**
	 * Setting type.
	 *
	 * @var string
	 */
	public $type = self::TYPE;

	/**
	 * Default setting value.
	 *
	 * @see wp_setup_nav_menu_item()
	 * @var array
	 */
	public $default = array(
		// The $menu_item_data for wp_update_nav_menu_item().
		'object_id' => 0,
		'object' => '', // Taxonomy name.
		'menu_item_parent' => 0, // A.K.A. menu-item-parent-id; note that post_parent is different, and not included.
		'position' => 0, // A.K.A. menu_order.
		'type' => 'custom', // Note that type_label is not included here.
		'title' => '',
		'url' => '',
		'target' => '',
		'attr_title' => '',
		'description' => '',
		'classes' => '',
		'xfn' => '',
		'status' => 'publish',
		'original_title' => '',
		'nav_menu_term_id' => 0, // This will be supplied as the $menu_id arg for wp_update_nav_menu_item().
		// @todo also expose invalid?
	);

	/**
	 * Default transport.
	 *
	 * @var string
	 */
	public $transport = 'postMessage';

	/**
	 * The post ID represented by this setting instance. This is the db_id.
	 *
	 * A negative value represents a placeholder ID for a new menu not yet saved.
	 *
	 * @todo Should this be $db_id, and also use this for WP_Customize_Nav_Menu_Setting::$term_id
	 *
	 * @var int
	 */
	public $post_id;

	/**
	 * Previous (placeholder) post ID used before creating a new menu item.
	 *
	 * This value will be exported to JS via the customize_save_response filter
	 * so that JavaScript can update the settings to refer to the newly-assigned
	 * post ID. This value is always negative to indicate it does not refer to
	 * a real post.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 * @see WP_Customize_Nav_Menu_Item_Setting::amend_customize_save_response()
	 *
	 * @var int
	 */
	public $previous_post_id;

	/**
	 * When previewing or updating a menu item, this stores the previous nav_menu_term_id
	 * which ensures that we can apply the proper filters.
	 *
	 * @var int
	 */
	public $original_nav_menu_term_id;

	/**
	 * Whether or not preview() was called.
	 *
	 * @var bool
	 */
	protected $is_previewed = false;

	/**
	 * Whether or not update() was called.
	 *
	 * @var bool
	 */
	protected $is_updated = false;

	/**
	 * Status for calling the update method, used in customize_save_response filter.
	 *
	 * When status is inserted, the placeholder post ID is stored in $previous_post_id.
	 * When status is error, the error is stored in $update_error.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 * @see WP_Customize_Nav_Menu_Item_Setting::amend_customize_save_response()
	 *
	 * @var string updated|inserted|deleted|error
	 */
	public $update_status;

	/**
	 * Any error object returned by wp_update_nav_menu_item() when setting is updated.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 * @see WP_Customize_Nav_Menu_Item_Setting::amend_customize_save_response()
	 *
	 * @var WP_Error
	 */
	public $update_error;

	/**
	 * Constructor.
	 *
	 * Any supplied $args override class property defaults.
	 *
	 * @param WP_Customize_Manager $manager Manager instance.
	 * @param string               $id      An specific ID of the setting. Can be a
	 *                                       theme mod or option name.
	 * @param array                $args    Setting arguments.
	 * @throws Exception If $id is not valid for this setting type.
	 */
	public function __construct( WP_Customize_Manager $manager, $id, array $args = array() ) {
		if ( empty( $manager->menus ) ) {
			throw new Exception( 'Expected WP_Customize_Manager::$menus to be set.' );
		}

		if ( ! preg_match( self::ID_PATTERN, $id, $matches ) ) {
			throw new Exception( "Illegal widget setting ID: $id" );
		}

		$this->post_id = intval( $matches['id'] );

		$menu = $this->value();
		$this->original_nav_menu_term_id = $menu['nav_menu_term_id'];

		parent::__construct( $manager, $id, $args );
	}

	/**
	 * Get the instance data for a given widget setting.
	 *
	 * @see wp_setup_nav_menu_item()
	 * @return array
	 */
	public function value() {
		if ( $this->is_previewed && $this->_previewed_blog_id === get_current_blog_id() ) {
			$undefined = new stdClass(); // Symbol.
			$post_value = $this->post_value( $undefined );
			if ( $undefined === $post_value ) {
				$value = $this->_original_value;
			} else {
				$value = $post_value;
			}
		} else {
			$value = false;

			// Note that a ID of less than one indicates a nav_menu not yet inserted.
			if ( $this->post_id > 0 ) {
				$post = get_post( $this->post_id );
				if ( $post && self::POST_TYPE === $post->post_type ) {
					$item = wp_setup_nav_menu_item( $post );
					$value = wp_array_slice_assoc(
						(array) $item,
						array_keys( $this->default )
					);
					$value['position'] = $item->menu_order;
					$value['status'] = $item->post_status;
					$value['original_title'] = '';
					$menus = wp_get_post_terms( $post->ID, WP_Customize_Nav_Menu_Setting::TAXONOMY, array(
						'fields' => 'ids',
					) );
					if ( ! empty( $menus ) ) {
						$value['nav_menu_term_id'] = array_shift( $menus );
					} else {
						$value['nav_menu_term_id'] = 0;
					}
					if ( 'post_type' === $value['type'] ) {
						$original_title = get_the_title( $value['object_id'] );
					} else if ( 'taxonomy' === $value['type'] ) {
						$original_title = get_term_field( 'name', $value['object_id'], $value['object'], 'raw' );
						if ( is_wp_error( $original_title ) ) {
							$original_title = '';
						}
					}
					if ( ! empty( $original_title ) ) {
						$value['original_title'] = $original_title;
					}
				}
			}

			if ( ! is_array( $value ) ) {
				$value = $this->default;
			}
		}
		if ( is_array( $value ) ) {
			foreach ( array( 'object_id', 'menu_item_parent', 'nav_menu_term_id' ) as $key ) {
				$value[ $key ] = intval( $value[ $key ] );
			}
		}
		return $value;
	}

	/**
	 * Handle previewing the setting.
	 *
	 * @see WP_Customize_Manager::post_value()
	 * @return void
	 */
	public function preview() {
		if ( $this->is_previewed ) {
			return;
		}
		$this->is_previewed = true;
		$this->_original_value = $this->value();
		$this->original_nav_menu_term_id = $this->_original_value['nav_menu_term_id'];
		$this->_previewed_blog_id = get_current_blog_id();

		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_wp_get_nav_menu_items' ), 10, 3 );

		$sort_callback = array( __CLASS__, 'sort_wp_get_nav_menu_items' );
		if ( ! has_filter( 'wp_get_nav_menu_items', $sort_callback ) ) {
			add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'sort_wp_get_nav_menu_items' ), 1000, 3 );
		}

		// @todo Add get_post_metadata filters for plugins to add their data.
	}

	/**
	 * Filter the wp_get_nav_menu_items() result to supply the previewed menu items.
	 *
	 * @see wp_get_nav_menu_items()
	 * @param array  $items An array of menu item post objects.
	 * @param object $menu  The menu object.
	 * @param array  $args  An array of arguments used to retrieve menu item objects.
	 * @return array
	 */
	function filter_wp_get_nav_menu_items( $items, $menu, $args ) {
		$this_item = $this->value();
		$current_nav_menu_term_id = $this_item['nav_menu_term_id'];
		unset( $this_item['nav_menu_term_id'] );

		$should_filter = (
			$menu->term_id === $this->original_nav_menu_term_id
			||
			$menu->term_id === $current_nav_menu_term_id
		);
		if ( ! $should_filter ) {
			return $items;
		}

		// Handle deleted menu item, or menu item moved to another menu.
		$should_remove = (
			false === $this_item
			||
			(
				$this->original_nav_menu_term_id === $menu->term_id
				&&
				$current_nav_menu_term_id !== $this->original_nav_menu_term_id
			)
		);
		if ( $should_remove ) {
			$filtered_items = array();
			foreach ( $items as $item ) {
				if ( $item->db_id !== $this->post_id ) {
					$filtered_items[] = $item;
				}
			}
			return $filtered_items;
		}

		$mutated = false;
		$should_update = (
			is_array( $this_item )
			&&
			$current_nav_menu_term_id === $menu->term_id
		);
		if ( $should_update ) {
			foreach ( $items as $item ) {
				if ( $item->db_id === $this->post_id ) {
					foreach ( get_object_vars( $this->value_as_wp_post_nav_menu_item() ) as $key => $value ) {
						$item->$key = $value;
					}
					$mutated = true;
				}
			}

			// Not found so we have to append it..
			if ( ! $mutated ) {
				$items[] = $this->value_as_wp_post_nav_menu_item();
			}
		}

		return $items;
	}

	/**
	 * Re-apply the tail logic also applied on $items by wp_get_nav_menu_items().
	 *
	 * @see wp_get_nav_menu_items()
	 *
	 * @param array  $items An array of menu item post objects.
	 * @param object $menu  The menu object.
	 * @param array  $args  An array of arguments used to retrieve menu item objects.
	 * @return array
	 */
	static function sort_wp_get_nav_menu_items( $items, $menu, $args ) {
		// @todo We should probably re-apply some constraints imposed by $args.
		unset( $args['include'] );

		// Remove invalid items only in frontend.
		if ( ! is_admin() ) {
			$items = array_filter( $items, '_is_valid_nav_menu_item' );
		}

		if ( ARRAY_A === $args['output'] ) {
			$GLOBALS['_menu_item_sort_prop'] = $args['output_key'];
			usort( $items, '_sort_nav_menu_items' );
			$i = 1;
			foreach ( $items as $k => $item ) {
				$items[ $k ]->$args['output_key'] = $i++;
			}
		}

		return $items;
	}

	/**
	 * Get the value emulated into a WP_Post and set up as a nav_menu_item.
	 *
	 * @return WP_Post With {@see wp_setup_nav_menu_item()} applied.
	 */
	public function value_as_wp_post_nav_menu_item() {
		$item = (object) $this->value();
		unset( $item->nav_menu_term_id );
		$item->post_status = $item->status;
		unset( $item->status );
		$item->post_type = 'nav_menu_item';
		$item->menu_order = $item->position;
		unset( $item->position );
		$item->post_author = get_current_user_id();
		if ( $item->title ) {
			$item->post_title = $item->title;
		}
		$item->ID = $this->post_id;
		$post = new WP_Post( (object) $item );
		$post = wp_setup_nav_menu_item( $post );
		return $post;
	}

	/**
	 * Sanitize an input.
	 *
	 * Note that parent::sanitize() erroneously does wp_unslash() on $value, but
	 * we remove that in this override.
	 *
	 * @param array $menu_item_value The value to sanitize.
	 * @return array|false|null Null if an input isn't valid. False if it is marked for deletion. Otherwise the sanitized value.
	 */
	public function sanitize( $menu_item_value ) {
		// Menu is marked for deletion.
		if ( false === $menu_item_value ) {
			return $menu_item_value;
		}

		// Invalid.
		if ( ! is_array( $menu_item_value ) ) {
			return null;
		}

		$default = array(
			'object_id' => 0,
			'object' => '',
			'menu_item_parent' => 0,
			'position' => 0,
			'type' => 'custom',
			'title' => '',
			'url' => '',
			'target' => '',
			'attr_title' => '',
			'description' => '',
			'classes' => '',
			'xfn' => '',
			'status' => 'publish',
			'original_title' => '',
			'nav_menu_term_id' => 0,
		);
		$menu_item_value = array_merge( $default, $menu_item_value );
		$menu_item_value = wp_array_slice_assoc( $menu_item_value, array_keys( $default ) );
		$menu_item_value['position'] = max( 0, intval( $menu_item_value['position'] ) );
		foreach ( array( 'object_id', 'menu_item_parent', 'nav_menu_term_id' ) as $key ) {
			// Note we need to allow negative-integer IDs for previewed objects not inserted yet.
			$menu_item_value[ $key ] = intval( $menu_item_value[ $key ] );
		}
		foreach ( array( 'type', 'object', 'target' ) as $key ) {
			$menu_item_value[ $key ] = sanitize_key( $menu_item_value[ $key ] );
		}
		foreach ( array( 'xfn', 'classes' ) as $key ) {
			$value = $menu_item_value[ $key ];
			if ( ! is_array( $value ) ) {
				$value = explode( ' ', $value );
			}
			$menu_item_value[ $key ] = implode( ' ', array_map( 'sanitize_html_class', $value ) );
		}
		foreach ( array( 'title', 'attr_title', 'description', 'original_title' ) as $key ) {
			// @todo Should esc_attr() the attr_title as well?
			$menu_item_value[ $key ] = sanitize_text_field( $menu_item_value[ $key ] );
		}
		$menu_item_value['url'] = esc_url_raw( $menu_item_value['url'] );
		if ( ! get_post_status_object( $menu_item_value['status'] ) ) {
			$menu_item_value['status'] = 'publish';
		}

		/** This filter is documented in wp-includes/class-wp-customize-setting.php */
		return apply_filters( "customize_sanitize_{$this->id}", $menu_item_value, $this );
	}

	/**
	 * Create/update the nav_menu_item post for this setting.
	 *
	 * Any created menu items will have their assigned post IDs exported to the client
	 * via the customize_save_response filter. Likewise, any errors will be exported
	 * to the client via the customize_save_response() filter.
	 *
	 * To delete a menu, the client can send false as the value.
	 *
	 * @see wp_update_nav_menu_item()
	 *
	 * @param array|false $value The menu item array to update. If false, then the menu item will be deleted entirely.
	 *                             See {@see WP_Customize_Nav_Menu_Item_Setting::$default} for what the value should consist of.
	 * @return void
	 */
	protected function update( $value ) {
		if ( $this->is_updated ) {
			return;
		}
		$this->is_updated = true;
		$is_placeholder = ( $this->post_id < 0 );
		$is_delete = ( false === $value );

		add_filter( 'customize_save_response', array( $this, 'amend_customize_save_response' ) );

		if ( $is_delete ) {
			// If the current setting post is a placeholder, a delete request is a no-op.
			if ( $is_placeholder ) {
				$this->update_status = 'deleted';
			} else {
				$r = wp_delete_post( $this->post_id, true );
				if ( false === $r ) {
					$this->update_error = new WP_Error( 'delete_failure' );
					$this->update_status = 'error';
				} else {
					$this->update_status = 'deleted';
				}
				// @todo send back the IDs for all associated nav menu items deleted, so these settings (and controls) can be removed from Customizer?
			}
		} else {

			// Handle saving menu items for menus that are being newly-created.
			if ( $value['nav_menu_term_id'] < 0 ) {
				$nav_menu_setting_id = sprintf( 'nav_menu[%s]', $value['nav_menu_term_id'] );
				$nav_menu_setting = $this->manager->get_setting( $nav_menu_setting_id );
				if ( ! $nav_menu_setting || ! ( $nav_menu_setting instanceof WP_Customize_Nav_Menu_Setting ) ) {
					$this->update_status = 'error';
					$this->update_error = new WP_Error( 'unexpected_nav_menu_setting' );
					return;
				}
				if ( false === $nav_menu_setting->save() ) {
					$this->update_status = 'error';
					$this->update_error = new WP_Error( 'nav_menu_setting_failure' );
				}
				if ( $nav_menu_setting->previous_term_id !== intval( $value['nav_menu_term_id'] ) ) {
					$this->update_status = 'error';
					$this->update_error = new WP_Error( 'unexpected_previous_term_id' );
					return;
				}
				$value['nav_menu_term_id'] = $nav_menu_setting->term_id;
			}

			// Handle saving a nav menu item that is a child of a nav menu item being newly-created.
			if ( $value['menu_item_parent'] < 0 ) {
				$parent_nav_menu_item_setting_id = sprintf( 'nav_menu_item[%s]', $value['menu_item_parent'] );
				$parent_nav_menu_item_setting = $this->manager->get_setting( $parent_nav_menu_item_setting_id );
				if ( ! $parent_nav_menu_item_setting || ! ( $parent_nav_menu_item_setting instanceof WP_Customize_Nav_Menu_Item_Setting ) ) {
					$this->update_status = 'error';
					$this->update_error = new WP_Error( 'unexpected_nav_menu_item_setting' );
					return;
				}
				if ( false === $parent_nav_menu_item_setting->save() ) {
					$this->update_status = 'error';
					$this->update_error = new WP_Error( 'nav_menu_item_setting_failure' );
				}
				if ( $parent_nav_menu_item_setting->previous_post_id !== intval( $value['menu_item_parent'] ) ) {
					$this->update_status = 'error';
					$this->update_error = new WP_Error( 'unexpected_previous_post_id' );
					return;
				}
				$value['menu_item_parent'] = $parent_nav_menu_item_setting->post_id;

			}

			// Insert or update menu.
			$menu_item_data = array(
				'menu-item-object-id'   => $value['object_id'],
				'menu-item-object'      => $value['object'],
				'menu-item-parent-id'   => $value['menu_item_parent'],
				'menu-item-position'    => $value['position'],
				'menu-item-type'        => $value['type'],
				'menu-item-title'       => $value['title'],
				'menu-item-url'         => $value['url'],
				'menu-item-description' => $value['description'],
				'menu-item-attr-title'  => $value['attr_title'],
				'menu-item-target'      => $value['target'],
				'menu-item-classes'     => $value['classes'],
				'menu-item-xfn'         => $value['xfn'],
				'menu-item-status'      => $value['status'],
			);

			$r = wp_update_nav_menu_item(
				$value['nav_menu_term_id'],
				$is_placeholder ? 0 : $this->post_id,
				$menu_item_data
			);

			if ( is_wp_error( $r ) ) {
				$this->update_status = 'error';
				$this->update_error = $r;
			} else {
				if ( $is_placeholder ) {
					$this->previous_post_id = $this->post_id;
					$this->post_id = $r;
					$this->update_status = 'inserted';
				} else {
					$this->update_status = 'updated';
				}
			}
		}

	}

	/**
	 * Export data for the JS client.
	 *
	 * @param array $data Additional information passed back to the 'saved'
	 *                      event on `wp.customize`.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 * @return array
	 */
	function amend_customize_save_response( $data ) {
		if ( ! isset( $data['nav_menu_item_updates'] ) ) {
			$data['nav_menu_item_updates'] = array();
		}
		$result = array(
			'post_id' => $this->post_id,
			'previous_post_id' => $this->previous_post_id,
			'error' => $this->update_error ? $this->update_error->get_error_code() : null,
			'status' => $this->update_status,
		);

		$data['nav_menu_item_updates'][] = $result;
		return $data;
	}
}

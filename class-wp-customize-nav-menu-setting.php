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
 * @see wp_get_nav_menu_object()
 * @see get_term()
 * @see WP_Customize_Setting
 */
class WP_Customize_Nav_Menu_Setting extends WP_Customize_Setting {

	const ID_PATTERN = '/^nav_menu\[(?P<id>-?\d+)\]$/';

	const TAXONOMY = 'nav_menu';

	const TYPE = 'nav_menu';

	/**
	 * Setting type.
	 *
	 * @var string
	 */
	public $type = self::TYPE;

	/**
	 * Default setting value;
	 *
	 * @see get_term_by()
	 *
	 * @todo Include object_ids for the menu items associated with this nav_menu?
	 *
	 * @var array
	 */
	public $default = array(
		'name' => '',
		'description' => '',
		'parent' => 0,
		'auto_add' => false,
		// @todo theme_locations
	);

	/**
	 * Default transport.
	 *
	 * @var string
	 */
	public $transport = 'postMessage';

	/**
	 * The term ID represented by this setting instance.
	 *
	 * A negative value represents a placeholder ID for a new menu not yet saved.
	 *
	 * @todo Should we use GUIDs instead of negative integers for placeholders?
	 *
	 * @var int
	 */
	public $term_id;

	/**
	 * Previous (placeholder) term ID used before creating a new menu.
	 *
	 * This value will be exported to JS via the customize_save_response filter
	 * so that JavaScript can update the settings to refer to the newly-assigned
	 * term ID. This value is always negative to indicate it does not refer to
	 * a real term.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::update()
	 * @see WP_Customize_Nav_Menu_Setting::amend_customize_save_response()
	 *
	 * @var int
	 */
	public $previous_term_id;

	/**
	 * Whether or not preview() was called.
	 *
	 * @var bool
	 */
	public $is_previewed = false;

	/**
	 * Whether or not update() was called.
	 *
	 * @var bool
	 */
	public $is_updated = false;

	/**
	 * Status for calling the update method, used in customize_save_response filter.
	 *
	 * When status is inserted, the placeholder term ID is stored in $previous_term_id.
	 * When status is error, the error is stored in $update_error.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::update()
	 * @see WP_Customize_Nav_Menu_Setting::amend_customize_save_response()
	 *
	 * @var string updated|inserted|deleted|error
	 */
	public $update_status;

	/**
	 * Any error object returned by wp_update_nav_menu_object() when setting is updated.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::update()
	 * @see WP_Customize_Nav_Menu_Setting::amend_customize_save_response()
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

		$this->term_id = intval( $matches['id'] );

		parent::__construct( $manager, $id, $args );
	}

	/**
	 * Get the instance data for a given widget setting.
	 *
	 * @see wp_get_nav_menu_object()
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

			// Note that a term_id of less than one indicates a nav_menu not yet inserted.
			if ( $this->term_id > 0 ) {
				$term = wp_get_nav_menu_object( $this->term_id );
				if ( $term ) {
					$value = wp_array_slice_assoc( (array) $term, array_keys( $this->default ) );

					$nav_menu_options = (array) get_option( 'nav_menu_options', array() );
					$value['auto_add'] = false;
					if ( isset( $nav_menu_options['auto_add'] ) && is_array( $nav_menu_options['auto_add'] ) ) {
						$value['auto_add'] = in_array( $term->term_id, $nav_menu_options['auto_add'] );
					}
				}
			}

			if ( ! is_array( $value ) ) {
				$value = $this->default;
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
		$this->_previewed_blog_id = get_current_blog_id();

		add_filter( 'pre_get_term', array( $this, 'filter_pre_get_term' ), 10, 2 );
		add_filter( 'default_option_nav_menu_options', array( $this, 'filter_nav_menu_options' ) );
		add_filter( 'option_nav_menu_options', array( $this, 'filter_nav_menu_options' ) );
	}

	/**
	 * Filter the get_term() result to supply the previewed menu object.
	 *
	 * @see get_term()
	 * @param null|mixed $pre        Potential override of the normal return value for get_term().
	 * @param array      $args       These arguments are defined on get_term().
	 * @return bool|array|object
	 */
	function filter_pre_get_term( $pre, $args ) {
		$ok = (
			self::TAXONOMY === $args['taxonomy']
			&&
			get_current_blog_id() === $this->_previewed_blog_id
			&&
			$args['term'] === $this->term_id
		);
		if ( ! $ok ) {
			return $pre;
		}

		$menu = $this->value();

		// Handle deleted menus.
		if ( false === $menu ) {
			return false;
		}

		// Handle sanitization failure by preventing short-circuiting.
		if ( null === $menu ) {
			return $pre;
		}

		$_term = (object) array_merge(
			array(
				'term_id' => $this->term_id,
				'term_taxonomy_id' => $this->term_id,
				'slug' => sanitize_title( $menu['name'] ),
				'count' => 0,
				'term_group' => 0,
				'taxonomy' => self::TAXONOMY,
				'filter' => $args['filter'],
			),
			$menu
		);

		$taxonomy = $args['taxonomy'];
		$filter = $args['filter'];
		$output = $args['output'];

		/*
		 * The following lines are adapted from get_term().
		 */

		/** This filter is documented in wp-includes/taxonomy.php */
		$_term = apply_filters( 'get_term', $_term, $args['taxonomy'] );

		/** This filter is documented in wp-includes/taxonomy.php */
		$_term = apply_filters( "get_$taxonomy", $_term, $taxonomy );

		$_term = sanitize_term( $_term, $taxonomy, $filter );

		// Placeholder (negative) term IDs get blown away by sanitize_term(), so we set them here.
		$_term->term_id = $this->term_id;
		$_term->term_taxonomy_id = $this->term_id;

		if ( OBJECT === $output ) {
			return $_term;
		} elseif ( ARRAY_A === $output ) {
			$__term = get_object_vars( $_term );
			return $__term;
		} elseif ( ARRAY_N === $output ) {
			$__term = array_values( get_object_vars( $_term ) );
			return $__term;
		} else {
			return $_term;
		}
	}

	/**
	 * Filter the nav_menu_options option to include this menu's auto_add preference.
	 *
	 * @param array $nav_menu_options  Nav menu options including auto_add.
	 * @return array
	 */
	function filter_nav_menu_options( $nav_menu_options ) {
		if ( $this->_previewed_blog_id !== get_current_blog_id() ) {
			return $nav_menu_options;
		}
		$menu = $this->value();
		$nav_menu_options = $this->filter_nav_menu_options_value(
			$nav_menu_options,
			$this->term_id,
			false === $menu ? false : $menu['auto_add']
		);
		return $nav_menu_options;
	}

	/**
	 * Sanitize an input.
	 *
	 * Note that parent::sanitize() erroneously does wp_unslash() on $value, but
	 * we remove that in this override.
	 *
	 * @param array $value The value to sanitize.
	 * @return array|false|null Null if an input isn't valid. False if it is marked for deletion. Otherwise the sanitized value.
	 */
	public function sanitize( $value ) {
		// Menu is marked for deletion.
		if ( false === $value ) {
			return $value;
		}

		// Invalid.
		if ( ! is_array( $value ) ) {
			return null;
		}

		$default = array(
			'name' => '',
			'description' => '',
			'parent' => 0,
			'auto_add' => false,
		);
		$value = array_merge( $default, $value );
		$value = wp_array_slice_assoc( $value, array_keys( $default ) );

		$value['name'] = trim( esc_html( $value['name'] ) ); // This sanitization code is used in wp-admin/nav-menus.php.
		$value['description'] = sanitize_text_field( $value['description'] );
		$value['parent'] = max( 0, intval( $value['parent'] ) );
		$value['auto_add'] = ! empty( $value['auto_add'] );

		/** This filter is documented in wp-includes/class-wp-customize-setting.php */
		return apply_filters( "customize_sanitize_{$this->id}", $value, $this );
	}

	/**
	 * Create/update the nav_menu term for this setting.
	 *
	 * Any created menus will have their assigned term IDs exported to the client
	 * via the customize_save_response filter. Likewise, any errors will be exported
	 * to the client via the customize_save_response() filter.
	 *
	 * To delete a menu, the client can send false as the value.
	 *
	 * @see wp_update_nav_menu_object()
	 *
	 * @param array|false $value {
	 *     The value to update. Note that slug cannot be updated via wp_update_nav_menu_object().
	 *     If false, then the menu will be deleted entirely.
	 *
	 *     @type string $name        The name of the menu to save.
	 *     @type string $description The term description. Default empty string.
	 *     @type int    $parent      The id of the parent term. Default 0.
	 *     @type bool   $auto_add    Whether pages will auto_add to this menu. Default false.
	 * }
	 * @return void
	 */
	protected function update( $value ) {
		if ( $this->is_updated ) {
			return;
		}
		$this->is_updated = true;
		$is_placeholder = ( $this->term_id < 0 );
		$is_delete = ( false === $value );

		add_filter( 'customize_save_response', array( $this, 'amend_customize_save_response' ) );

		$auto_add = null;
		if ( $is_delete ) {
			// If the current setting term is a placeholder, a delete request is a no-op.
			if ( $is_placeholder ) {
				$this->update_status = 'deleted';
			} else {
				$r = wp_delete_nav_menu( $this->term_id );
				if ( is_wp_error( $r ) ) {
					$this->update_status = 'error';
					$this->update_error = $r;
				} else {
					$this->update_status = 'deleted';
					$auto_add = false;
				}
			}
		} else {
			// Insert or update menu.
			$menu_data = wp_array_slice_assoc( $value, array( 'description', 'parent' ) );
			if ( isset( $value['name'] ) ) {
				$menu_data['menu-name'] = $value['name'];
			}
			$r = wp_update_nav_menu_object( $is_placeholder ? 0 : $this->term_id, $menu_data );
			if ( is_wp_error( $r ) ) {
				$this->update_status = 'error';
				$this->update_error = $r;
			} else {
				if ( $is_placeholder ) {
					$this->previous_term_id = $this->term_id;
					$this->term_id = $r;
					$this->update_status = 'inserted';
				} else {
					$this->update_status = 'updated';
				}
				$auto_add = $value['auto_add'];
			}
			// @todo Send back the saved sanitized value to update the client?
		}

		if ( null !== $auto_add ) {
			$nav_menu_options = $this->filter_nav_menu_options_value(
				(array) get_option( 'nav_menu_options', array() ),
				$this->term_id,
				$auto_add
			);
			update_option( 'nav_menu_options', $nav_menu_options );
		}

		// Make sure that new menus assigned to nav menu locations use their new IDs.
		if ( 'inserted' === $this->update_status ) {
			foreach ( $this->manager->settings() as $setting ) {
				if ( ! preg_match( '/^nav_menu_locations\[/', $setting->id ) ) {
					continue;
				}
				$post_value = $setting->post_value( null );
				// @todo We need to make sure this change gets applied to the client as well.
				if ( ! is_null( $post_value ) && $this->previous_term_id === intval( $post_value ) ) {
					$this->manager->set_post_value( $setting->id, $this->term_id );
					$setting->save();
				}
			}
		}
	}

	/**
	 * Update a nav_menu_options array.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::filter_nav_menu_options()
	 * @see WP_Customize_Nav_Menu_Setting::update()
	 *
	 * @param array $nav_menu_options  Array as returned by get_option( 'nav_menu_options' ).
	 * @param int   $menu_id           The term ID for the given menu.
	 * @param bool  $auto_add          Whether to auto-add or not.
	 * @return array
	 */
	protected function filter_nav_menu_options_value( $nav_menu_options, $menu_id, $auto_add ) {
		$nav_menu_options = (array) $nav_menu_options;
		if ( ! isset( $nav_menu_options['auto_add'] ) ) {
			$nav_menu_options['auto_add'] = array();
		}
		$i = array_search( $menu_id, $nav_menu_options['auto_add'] );
		if ( $auto_add && false === $i ) {
			array_push( $nav_menu_options['auto_add'], $this->term_id );
		} else if ( ! $auto_add && false !== $i ) {
			array_splice( $nav_menu_options['auto_add'], $i, 1 );
		}
		return $nav_menu_options;
	}

	/**
	 * Export data for the JS client.
	 *
	 * @param array $data Additional information passed back to the 'saved'
	 *                      event on `wp.customize`.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::update()
	 * @return array
	 */
	function amend_customize_save_response( $data ) {
		if ( ! isset( $data['nav_menu_updates'] ) ) {
			$data['nav_menu_updates'] = array();
		}
		$result = array(
			'term_id' => $this->term_id,
			'previous_term_id' => $this->previous_term_id,
			'error' => $this->update_error ? $this->update_error->get_error_code() : null,
			'status' => $this->update_status,
		);

		$data['nav_menu_updates'][] = $result;
		return $data;
	}
}

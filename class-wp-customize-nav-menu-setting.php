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

	const ID_PATTERN = '/^nav_menu\[(?P<term_id>-?\d+)\]$/';

	const TAXONOMY = 'nav_menu';

	/**
	 * Setting type.
	 *
	 * @var string
	 */
	public $type = 'nav_menu';

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

		$this->term_id = intval( $matches['term_id'] );

		parent::__construct( $manager, $id, $args );
	}

	/**
	 * Get the instance data for a given widget setting.
	 *
	 * Note that we are using get_term_by() instead of wp_get_nav_menu_object()
	 * because we want an array as opposed to an object.
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
					$value = wp_array_slice_assoc( (array) $term, array( 'name', 'description', 'parent' ) );
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
		if ( self::TAXONOMY !== $args['taxonomy'] ) {
			return $pre;
		}
		if ( $args['term'] !== $this->term_id ) {
			return $pre;
		}

		$menu = $this->value();

		// Handle deleted menus.
		if ( is_null( $menu ) ) {
			return false;
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
	 * Sanitize an input.
	 *
	 * Note that parent::sanitize() erroneously does wp_unslash() on $value, but
	 * we remove that in this override.
	 *
	 * @param array $value The value to sanitize.
	 * @return array|null Null if an input isn't valid, otherwise the sanitized value.
	 */
	public function sanitize( $value ) {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$default = array(
			'name' => '',
			'description' => '',
			'parent' => 0,
		);
		$value = array_merge( $default, $value );
		$value = wp_array_slice_assoc( $value, array_keys( $default ) );

		$value['name'] = trim( esc_html( $value['name'] ) ); // This sanitization code is used in wp-admin/nav-menus.php.
		$value['description'] = sanitize_text_field( $value['description'] );
		$value['parent'] = max( 0, intval( $value['parent'] ) );

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
	 * }
	 * @return void
	 */
	protected function update( $value ) {
		$is_placeholder_term = ( $this->term_id < 0 );
		$is_delete = ( false === $value );

		if ( $is_delete ) {
			// If the current setting term is a placeholder, a delete request is a no-op.
			if ( $is_placeholder_term ) {
				$this->update_status = 'deleted';
			} else {
				$r = wp_delete_nav_menu( $this->term_id );
				if ( is_wp_error( $r ) ) {
					$this->update_status = 'error';
					$this->update_error = $r;
				} else {
					$this->update_status = 'deleted';
				}
				// @todo send back the IDs for all nav_menu_item posts that were deleted, so these settings (and controls) can be removed from Customizer.
			}
		} else {
			// Insert or update menu.
			$menu_data = wp_array_slice_assoc( $value, array( 'description', 'parent' ) );
			if ( isset( $value['name'] ) ) {
				$menu_data['menu-name'] = $value['name'];
			}
			$r = wp_update_nav_menu_object( $is_placeholder_term ? 0 : $this->term_id, $menu_data );
			if ( is_wp_error( $r ) ) {
				$this->update_status = 'error';
				$this->update_error = $r;
			} else {
				if ( $is_placeholder_term ) {
					$this->previous_term_id = $this->term_id;
					$this->term_id = $r;
					$this->update_status = 'inserted';
				} else {
					$this->update_status = 'updated';
				}
			}
		}

		add_filter( 'customize_save_response', array( $this, 'amend_customize_save_response' ) );
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

<?php
/**
 * Tests WP_Customize_Nav_Menu_Item_Setting.
 *
 * @package WordPress
 * @subpackage Customize
 * @since 4.3.0
 */

/**
 * Tests WP_Customize_Nav_Menu_Item_Setting.
 *
 * @see WP_Customize_Nav_Menu_Item_Setting
 */
class Test_WP_Customize_Nav_Menu_Item_Setting extends WP_UnitTestCase {

	/**
	 * Instance of WP_Customize_Manager which is reset for each test.
	 *
	 * @var WP_Customize_Manager
	 */
	public $wp_customize;

	/**
	 * Set up a test case.
	 *
	 * @see WP_UnitTestCase::setup()
	 */
	function setUp() {
		parent::setUp();
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		global $wp_customize;
		$this->wp_customize = new WP_Customize_Manager();
		$wp_customize = $this->wp_customize;
	}

	/**
	 * Delete the $wp_customize global when cleaning up scope.
	 */
	function clean_up_global_scope() {
		global $wp_customize;
		$wp_customize = null;
		parent::clean_up_global_scope();
	}

	/**
	 * Test constants and statics.
	 */
	function test_constants() {
		do_action( 'customize_register', $this->wp_customize );
		$this->assertTrue( post_type_exists( WP_Customize_Nav_Menu_Item_Setting::POST_TYPE ) );
	}

	/**
	 * Test constructor.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::__construct()
	 */
	function test_construct() {
		do_action( 'customize_register', $this->wp_customize );

		$setting = new WP_Customize_Nav_Menu_Item_Setting( $this->wp_customize, 'nav_menu_item[123]' );
		$this->assertEquals( 'nav_menu_item', $setting->type );
		$this->assertEquals( 'postMessage', $setting->transport );
		$this->assertEquals( 123, $setting->post_id );
		$this->assertNull( $setting->previous_post_id );
		$this->assertFalse( $setting->is_previewed );
		$this->assertNull( $setting->update_status );
		$this->assertNull( $setting->update_error );
		$this->assertInternalType( 'array', $setting->default );

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
			'nav_menu_term_id' => 0,
		);
		$this->assertEquals( $default, $setting->default );

		$exception = null;
		try {
			$bad_setting = new WP_Customize_Nav_Menu_Item_Setting( $this->wp_customize, 'foo_bar_baz' );
			unset( $bad_setting );
		} catch ( Exception $e ) {
			$exception = $e;
		}
		$this->assertInstanceOf( 'Exception', $exception );
	}

	/**
	 * Test constructor for placeholder (draft) menu.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::__construct()
	 */
	function test_construct_placeholder() {
		do_action( 'customize_register', $this->wp_customize );
		$default = array(
			'title' => 'Lorem',
			'description' => 'ipsum',
			'menu_item_parent' => 123,
		);
		$setting = new WP_Customize_Nav_Menu_Item_Setting( $this->wp_customize, 'nav_menu_item[-5]', compact( 'default' ) );
		$this->assertEquals( -5, $setting->post_id );
		$this->assertNull( $setting->previous_post_id );
		$this->assertEquals( $default, $setting->default );
	}

	/**
	 * Test value method.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::value()
	 */
	function test_value() {
		do_action( 'customize_register', $this->wp_customize );

		$post_id = $this->factory->post->create( array( 'post_title' => 'Hello World' ) );

		$menu_id = wp_create_nav_menu( 'Menu' );
		$item_title = 'Greetings';
		$item_id = wp_update_nav_menu_item( $menu_id, 0, array(
			'menu-item-type' => 'post_type',
			'menu-item-object' => 'post',
			'menu-item-object-id' => $post_id,
			'menu-item-title' => $item_title,
			'menu-item-status' => 'publish',
		) );

		$post = get_post( $item_id );
		$menu_item = wp_setup_nav_menu_item( $post );
		$this->assertEquals( $item_title, $menu_item->title );

		$setting_id = "nav_menu_item[$item_id]";
		$setting = new WP_Customize_Nav_Menu_Item_Setting( $this->wp_customize, $setting_id );

		$value = $setting->value();
		$this->assertEquals( $menu_item->title, $value['title'] );
		$this->assertEquals( $menu_item->type, $value['type'] );
		$this->assertEquals( $menu_item->object_id, $value['object_id'] );
		$this->assertEquals( $menu_id, $value['nav_menu_term_id'] );

		$other_menu_id = wp_create_nav_menu( 'Menu2' );
		wp_update_nav_menu_item( $other_menu_id, $item_id, array(
			'menu-item-title' => 'Hola',
		) );
		$value = $setting->value();
		$this->assertEquals( 'Hola', $value['title'] );
		$this->assertEquals( $other_menu_id, $value['nav_menu_term_id'] );
	}

	/**
	 * Test preview method for updated menu.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::preview()
	 */
	function test_preview_updated() {
		do_action( 'customize_register', $this->wp_customize );

		$first_post_id = $this->factory->post->create( array( 'post_title' => 'Hello World' ) );
		$second_post_id = $this->factory->post->create( array( 'post_title' => 'Hola Muno' ) );

		$primary_menu_id = wp_create_nav_menu( 'Primary' );
		$secondary_menu_id = wp_create_nav_menu( 'Secondary' );
		$item_title = 'Greetings';
		$item_id = wp_update_nav_menu_item( $primary_menu_id, 0, array(
			'menu-item-type' => 'post_type',
			'menu-item-object' => 'post',
			'menu-item-object-id' => $first_post_id,
			'menu-item-title' => $item_title,
			'menu-item-status' => 'publish',
		) );
		$this->assertNotEmpty( wp_get_nav_menu_items( $primary_menu_id, array( 'post_status' => 'publish,draft' ) ) );

		$post_value = array(
			'type' => 'post_type',
			'object_id' => $second_post_id,
			'title' => 'Saludos',
			'nav_menu_term_id' => $secondary_menu_id,
		);
		$setting_id = "nav_menu_item[$item_id]";
		$setting = new WP_Customize_Nav_Menu_Item_Setting( $this->wp_customize, $setting_id );
		$this->wp_customize->set_post_value( $setting_id, $post_value );
		$setting->preview();

		// Make sure the menu item appears in the new menu.
		$this->assertNotContains( $item_id, wp_list_pluck( wp_get_nav_menu_items( $primary_menu_id ), 'db_id' ) );
		$menu_items = wp_get_nav_menu_items( $secondary_menu_id );
		$this->assertContains( $item_id, wp_list_pluck( $menu_items, 'db_id' ) );
	}

	/**
	 * Test preview method for inserted menu.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::preview()
	 */
	function test_preview_inserted() {
		$this->markTestIncomplete( 'Needs to be implemented.' );
	}

	/**
	 * Test preview method for deleted menu.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::preview()
	 */
	function test_preview_deleted() {
		$this->markTestIncomplete( 'Needs to be implemented.' );
	}

	/**
	 * Test sanitize method.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::sanitize()
	 */
	function test_sanitize() {
		$this->markTestIncomplete( 'Needs to be implemented.' );
	}

	/**
	 * Test protected update() method via the save() method, for updated menu.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 */
	function test_save_updated() {
		$this->markTestIncomplete( 'Needs to be implemented.' );
	}

	/**
	 * Test protected update() method via the save() method, for inserted menu.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 */
	function test_save_inserted() {
		$this->markTestIncomplete( 'Needs to be implemented.' );
	}

	/**
	 * Test protected update() method via the save() method, for deleted menu.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 */
	function test_save_deleted() {
		$this->markTestIncomplete( 'Needs to be implemented.' );
	}

}

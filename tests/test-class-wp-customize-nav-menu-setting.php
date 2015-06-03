<?php
/**
 * Tests WP_Customize_Nav_Menu_Setting.
 *
 * @package WordPress
 * @subpackage Customize
 * @since 4.3.0
 */

/**
 * Tests WP_Customize_Nav_Menu_Setting.
 *
 * @see WP_Customize_Nav_Menu_Setting
 */
class Test_WP_Customize_Nav_Menu_Setting extends WP_UnitTestCase {

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
		$this->assertTrue( taxonomy_exists( WP_Customize_Nav_Menu_Setting::TAXONOMY ) );
	}

	/**
	 * Test constructor.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::__construct()
	 */
	function test_construct() {
		do_action( 'customize_register', $this->wp_customize );

		$setting = new WP_Customize_Nav_Menu_Setting( $this->wp_customize, 'nav_menu[123]' );
		$this->assertEquals( 'nav_menu', $setting->type );
		$this->assertEquals( 'postMessage', $setting->transport );
		$this->assertEquals( 123, $setting->term_id );
		$this->assertNull( $setting->previous_term_id );
		$this->assertFalse( $setting->is_previewed );
		$this->assertNull( $setting->update_status );
		$this->assertNull( $setting->update_error );
		$this->assertInternalType( 'array', $setting->default );
		foreach ( array( 'name', 'description', 'parent' ) as $key ) {
			$this->assertArrayHasKey( $key, $setting->default );
		}
		$this->assertEquals( '', $setting->default['name'] );
		$this->assertEquals( '', $setting->default['description'] );
		$this->assertEquals( 0, $setting->default['parent'] );

		$exception = null;
		try {
			$bad_setting = new WP_Customize_Nav_Menu_Setting( $this->wp_customize, 'foo_bar_baz' );
			unset( $bad_setting );
		} catch ( Exception $e ) {
			$exception = $e;
		}
		$this->assertInstanceOf( 'Exception', $exception );
	}

	/**
	 * Test constructor for placeholder (draft) menu.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::__construct()
	 */
	function test_construct_placeholder() {
		do_action( 'customize_register', $this->wp_customize );
		$default = array(
			'name' => 'Lorem',
			'description' => 'ipsum',
			'parent' => 123,
		);
		$setting = new WP_Customize_Nav_Menu_Setting( $this->wp_customize, 'nav_menu[-5]', compact( 'default' ) );
		$this->assertEquals( -5, $setting->term_id );
		$this->assertEquals( $default, $setting->default );
	}

	/**
	 * Test value method.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::value()
	 */
	function test_value() {
		do_action( 'customize_register', $this->wp_customize );

		$menu_name = 'Test 123';
		$parent_menu_id = wp_create_nav_menu( "Parent $menu_name" );
		$description = 'Hello my world.';
		$menu_id = wp_update_nav_menu_object( 0, array(
			'menu-name' => $menu_name,
			'parent' => $parent_menu_id,
			'description' => $description,
		) );

		$setting_id = "nav_menu[$menu_id]";
		$setting = new WP_Customize_Nav_Menu_Setting( $this->wp_customize, $setting_id );

		$value = $setting->value();
		$this->assertInternalType( 'array', $value );
		foreach ( array( 'name', 'description', 'parent' ) as $key ) {
			$this->assertArrayHasKey( $key, $value );
		}
		$this->assertEquals( $menu_name, $value['name'] );
		$this->assertEquals( $description, $value['description'] );
		$this->assertEquals( $parent_menu_id, $value['parent'] );

		$new_menu_name = 'Foo';
		wp_update_nav_menu_object( $menu_id, array( 'menu-name' => $new_menu_name ) );
		$updated_value = $setting->value();
		$this->assertEquals( $new_menu_name, $updated_value['name'] );
	}

	/**
	 * Test preview method.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::preview()
	 */
	function test_preview() {
		do_action( 'customize_register', $this->wp_customize );

		$menu_id = wp_update_nav_menu_object( 0, array(
			'menu-name' => 'Name 1',
			'description' => 'Description 1',
			'parent' => 0,
		) );
		$setting_id = "nav_menu[$menu_id]";
		$setting = new WP_Customize_Nav_Menu_Setting( $this->wp_customize, $setting_id );

		$post_value = array(
			'name' => 'Name 2',
			'description' => 'Description 2',
			'parent' => 1,
		);
		$this->wp_customize->set_post_value( $setting_id, $post_value );

		$value = $setting->value();
		$this->assertEquals( 'Name 1', $value['name'] );
		$this->assertEquals( 'Description 1', $value['description'] );
		$this->assertEquals( 0, $value['parent'] );

		$term = get_term( $menu_id, 'nav_menu', ARRAY_A );
		$this->assertEqualSets( $value, wp_array_slice_assoc( $term, array_keys( $value ) ) );

		$setting->preview();
		$value = $setting->value();
		$this->assertEquals( 'Name 2', $value['name'] );
		$this->assertEquals( 'Description 2', $value['description'] );
		$this->assertEquals( 1, $value['parent'] );
		$term = get_term( $menu_id, 'nav_menu', ARRAY_A );
		$this->assertEqualSets( $value, wp_array_slice_assoc( $term, array_keys( $value ) ) );

		$menu_object = wp_get_nav_menu_object( $menu_id );
		$this->assertEquals( (object) $term, $menu_object );
		$this->assertEquals( $post_value['name'], $menu_object->name );
	}

	/**
	 * Test sanitize method.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::sanitize()
	 */
	function test_sanitize() {
		do_action( 'customize_register', $this->wp_customize );
		$setting = new WP_Customize_Nav_Menu_Setting( $this->wp_customize, 'nav_menu[123]' );

		$this->assertNull( $setting->sanitize( 'not an array' ) );
		$this->assertNull( $setting->sanitize( 123 ) );

		$value = array(
			'name' => ' Hello <b>world</b> ',
			'description' => "New\nline",
			'parent' => -12,
			'extra' => 'ignored',
		);
		$sanitized = $setting->sanitize( $value );
		$this->assertEquals( 'Hello &lt;b&gt;world&lt;/b&gt;', $sanitized['name'] );
		$this->assertEquals( 'New line', $sanitized['description'] );
		$this->assertEquals( 0, $sanitized['parent'] );
		$this->assertEqualSets( array( 'name', 'description', 'parent' ), array_keys( $sanitized ) );
	}

	/**
	 * Test protected update() method via the save() method.
	 *
	 * @see WP_Customize_Nav_Menu_Setting::update()
	 */
	function test_save_updated() {
		do_action( 'customize_register', $this->wp_customize );

		$menu_id = wp_update_nav_menu_object( 0, array(
			'menu-name' => 'Name 1',
			'description' => 'Description 1',
			'parent' => 0,
		) );
		$setting_id = "nav_menu[$menu_id]";
		$setting = new WP_Customize_Nav_Menu_Setting( $this->wp_customize, $setting_id );

		$new_value = array(
			'name' => 'Name 2',
			'description' => 'Description 2',
			'parent' => 1,
		);

		$this->wp_customize->set_post_value( $setting_id, $new_value );
		$setting->save();

		$menu_object = wp_get_nav_menu_object( $menu_id );
		foreach ( $new_value as $k => $v ) {
			$this->assertEquals( $v, $menu_object->$k );
		}
		$this->assertEqualSets( $new_value, wp_array_slice_assoc( (array) $menu_object, array_keys( $new_value ) ) );
		$this->assertEquals( $new_value, $setting->value() );

		$save_response = apply_filters( 'customize_save_response', array() );
		$this->assertArrayHasKey( 'nav_menu_updates', $save_response );
		$update_result = array_shift( $save_response['nav_menu_updates'] );
		$this->assertArrayHasKey( 'term_id', $update_result );
		$this->assertArrayHasKey( 'previous_term_id', $update_result );
		$this->assertArrayHasKey( 'error', $update_result );
		$this->assertArrayHasKey( 'status', $update_result );

		$this->assertEquals( $menu_id, $update_result['term_id'] );
		$this->assertNull( $update_result['previous_term_id'] );
		$this->assertNull( $update_result['error'] );
		$this->assertEquals( 'updated', $update_result['status'] );

	}

}

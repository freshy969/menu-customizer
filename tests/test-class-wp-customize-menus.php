<?php
/**
 * Tests WP_Customize_Menus.
 *
 * @package WordPress
 * @subpackage Customize
 * @since 4.3.0
 */

/**
 * Tests WP_Customize_Menus.
 *
 * @see WP_Customize_Menus
 */
class Test_WP_Customize_Menus extends WP_UnitTestCase {

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
	 * Test constructor.
	 *
	 * @see WP_Customize_Menus::__construct()
	 */
	function test_construct() {
		do_action( 'customize_register', $this->wp_customize );
		$menus = new WP_Customize_Menus( $this->wp_customize );
		$this->assertInstanceOf( 'WP_Customize_Manager', $menus->manager );
	}

	/**
	 * Test the ajax_test_load_available_items method.
	 *
	 * @see WP_Customize_Menus::ajax_load_available_items()
	 */
	function test_ajax_load_available_items() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the ajax_search_available_items method.
	 *
	 * @see WP_Customize_Menus::ajax_search_available_items()
	 */
	function test_ajax_search_available_items() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the search_available_items_query method.
	 *
	 * @see WP_Customize_Menus::search_available_items_query()
	 */
	function test_search_available_items_query() {
		$menus = new WP_Customize_Menus( $this->wp_customize );

		// Create posts
		$post_ids = array();
		$post_ids[] = $this->factory->post->create( array( 'post_title' => 'Search & Test' ) );
		$post_ids[] = $this->factory->post->create( array( 'post_title' => 'Some Other Title' ) );
		
		// Create terms
		$term_ids = array();
		$term_ids[] = $this->factory->category->create( array( 'name' => 'Dogs Are Cool' ) );
		$term_ids[] = $this->factory->category->create( array( 'name' => 'Cats Drool' ) );
		
		// Test empty results
		$expected = array();
		$results = $menus->search_available_items_query( array( 'pagenum' => 1, 's' => 'This Does NOT Exist' ) );
		$this->assertEquals( $expected, $results );

		// Test posts
		foreach ( $post_ids as $post_id ) {
			$expected = array(
				'id' => 'post-' . $post_id,
				'type' => 'post_type',
				'type_label' => get_post_type_object( 'post' )->labels->singular_name,
				'object' => 'post',
				'object_id' => intval( $post_id ),
				'title' => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			);
			wp_set_object_terms( $post_id, $term_ids, 'category' );
			$search = $post_id === $post_ids[0] ? 'test & search' : 'other title';
			$s = sanitize_text_field( wp_unslash( $search ) );
			$results = $menus->search_available_items_query( array( 'pagenum' => 1, 's' => $s ) );
			$this->assertEquals( $expected, $results[0] );
		}

		// Test terms
		foreach ( $term_ids as $term_id ) {
			$term = get_term_by( 'id', $term_id, 'category' );
			$expected = array(
				'id' => 'term-' . $term_id,
				'type' => 'taxonomy',
				'type_label' => get_taxonomy( 'category' )->labels->singular_name,
				'object' => 'category',
				'object_id' => intval( $term_id ),
				'title' => $term->name,
			);
			$s = sanitize_text_field( wp_unslash( $term->name ) );
			$results = $menus->search_available_items_query( array( 'pagenum' => 1, 's' => $s ) );
			$this->assertEquals( $expected, $results[0] );
		}
		
	}

	/**
	 * Test the enqueue method.
	 *
	 * @see WP_Customize_Menus::enqueue()
	 */
	function test_enqueue() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the filter_dynamic_setting_args method.
	 *
	 * @see WP_Customize_Menus::filter_dynamic_setting_args()
	 */
	function test_filter_dynamic_setting_args() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the filter_dynamic_setting_class method.
	 *
	 * @see WP_Customize_Menus::filter_dynamic_setting_class()
	 */
	function test_filter_dynamic_setting_class() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the customize_register method.
	 *
	 * @see WP_Customize_Menus::customize_register()
	 */
	function test_customize_register() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the intval_base10 method.
	 *
	 * @see WP_Customize_Menus::intval_base10()
	 */
	function test_intval_base10() {

		$menus = new WP_Customize_Menus( $this->wp_customize );

		$this->assertEquals( 2, $menus->intval_base10( 2 ) );
		$this->assertEquals( 4, $menus->intval_base10( 4.1 ) );
		$this->assertEquals( 4, $menus->intval_base10( '4' ) );
		$this->assertEquals( 4, $menus->intval_base10( '04' ) );
		$this->assertEquals( 42, $menus->intval_base10( +42 ) );
		$this->assertEquals( -42, $menus->intval_base10( -42 ) );
		$this->assertEquals( 26, $menus->intval_base10( 0x1A ) );
		$this->assertEquals( 0, $menus->intval_base10( array() ) );

	}

	/**
	 * Test the available_item_types method.
	 *
	 * @see WP_Customize_Menus::available_item_types()
	 */
	function test_available_item_types() {

		$menus = new WP_Customize_Menus( $this->wp_customize );
		$expected = array(
			'postTypes' => array(
				'post' => array( 'label' => 'Post' ),
				'page' => array( 'label' => 'Page' ),
			),
			'taxonomies' => array(
				'category' => array( 'label' => 'Category' ),
				'post_tag' => array( 'label' => 'Tag' ),
			),
		);
		if ( current_theme_supports( 'post-formats' ) ) {
			$expected['taxonomies']['post_format'] = array( 'label' => 'Format' );
		}
		$this->assertEquals( $expected, $menus->available_item_types() );
		
		register_taxonomy( 'wptests_tax', array( 'post' ), array( 'labels' => array( 'name' => 'Foo' ) ) );
		$expected = array(
			'postTypes' => array(
				'post' => array( 'label' => 'Post' ),
				'page' => array( 'label' => 'Page' ),
			),
			'taxonomies' => array(
				'category' => array( 'label' => 'Category' ),
				'post_tag' => array( 'label' => 'Tag' ),
				'wptests_tax' => array( 'label' => 'Foo' ),
			),
		);
		if ( current_theme_supports( 'post-formats' ) ) {
			$wptests_tax = array_pop( $expected['taxonomies'] );
			$expected['taxonomies']['post_format'] = array( 'label' => 'Format' );
			$expected['taxonomies']['wptests_tax'] = $wptests_tax;
		}
		$this->assertEquals( $expected, $menus->available_item_types() );

	}

	/**
	 * Test the print_templates method.
	 *
	 * @see WP_Customize_Menus::print_templates()
	 */
	function test_print_templates() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the available_items_template method.
	 *
	 * @see WP_Customize_Menus::available_items_template()
	 */
	function test_available_items_template() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the customize_preview_init method.
	 *
	 * @see WP_Customize_Menus::customize_preview_init()
	 */
	function test_customize_preview_init() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the filter_wp_nav_menu_args method.
	 *
	 * @see WP_Customize_Menus::filter_wp_nav_menu_args()
	 */
	function test_filter_wp_nav_menu_args() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the filter_wp_nav_menu method.
	 *
	 * @see WP_Customize_Menus::filter_wp_nav_menu()
	 */
	function test_filter_wp_nav_menu() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the hash_nav_menu_args method.
	 *
	 * @see WP_Customize_Menus::hash_nav_menu_args()
	 */
	function test_hash_nav_menu_args() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the customize_preview_enqueue_deps method.
	 *
	 * @see WP_Customize_Menus::customize_preview_enqueue_deps()
	 */
	function test_customize_preview_enqueue_deps() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the export_preview_data method.
	 *
	 * @see WP_Customize_Menus::export_preview_data()
	 */
	function test_export_preview_data() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

	/**
	 * Test the render_menu method.
	 *
	 * @see WP_Customize_Menus::render_menu()
	 */
	function test_render_menu() {

		$this->markTestIncomplete( 'This test has not been implemented.' );

	}

}

<?php

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
	 * @see WP_Customize_Nav_Menu_Setting::__construct()
	 */
	function test_construct() {
		do_action( 'customize_register', $this->wp_customize );
		$menus = new WP_Customize_Menus( $this->wp_customize );
		$this->assertInstanceOf( 'WP_Customize_Manager', $menus->manager );
	}

	/**
	 * Test search_available_items_query method.
	 *
	 * @see WP_Customize_Menus::search_available_items_query()
	 */
	function test_search_available_items_query() {
		$menus = new WP_Customize_Menus( $this->wp_customize );

		// Create posts
		$post_ids = array();
		$post_ids[] = $this->factory->post->create( array(
			'post_author' => get_current_user_id(),
			'post_status' => 'publish',
			'post_content' => rand_str(),
			'post_title' => 'Search Test',
			'post_type' => 'post'
		) );
		$post_ids[] = $this->factory->post->create( array(
			'post_author' => get_current_user_id(),
			'post_status' => 'publish',
			'post_content' => rand_str(),
			'post_title' => 'Some Other Title',
			'post_type' => 'post'
		) );
		
		// Create terms
		$term_ids = array();
		$term_ids[] = $this->factory->category->create( array(
			'name' => 'Dogs Are Cool',
		) );

		$term_ids[] = $this->factory->category->create( array(
			'name' => 'Cats Drool',
		) );
		
		// Test empty results
		$results = $menus->search_available_items_query( array( 'pagenum' => 1, 's' => 'This Does NOT Exist' ) );
		$this->assertEquals( $results, array() );

		// Test posts
		foreach ( $post_ids as $post_id ) {
			$expected = array(
				'id' => 'post-' . $post_id,
				'type' => 'post_type',
				'type_label' => get_post_type_object( 'post' )->labels->singular_name,
				'object' => 'post',
				'object_id' => intval( $post_id ),
				'title' => html_entity_decode( get_the_title( $post_id ), ENT_HTML401 | ENT_QUOTES, get_bloginfo( 'charset' ) ),
			);
			wp_set_object_terms( $post_id, $term_ids, 'category' );
			$s = sanitize_text_field( wp_unslash( get_the_title( $post_id ) ) );
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

}

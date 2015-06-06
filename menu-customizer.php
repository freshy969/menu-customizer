<?php
/**
 * Plugin Name: Menu Customizer
 * Plugin URI: http://wordpress.org/plugins/menu-customizer
 * Description: Manage your Menus in the Customizer. WordPress core feature-plugin, and former GSoC Project.
 * Version: 0.5
 * Author: The Customizer Team
 * Author URI: http://make.wordpress.org/core/component/customize
 * Tags: menus, custom menus, customizer, theme customizer, gsoc
 * License: GPL

=====================================================================================
Copyright (C) 2015 WordPress

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with WordPress; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
=====================================================================================
 *
 * @package WordPress
 * @subpackage Customize
 */

require_once( plugin_dir_path( __FILE__ ) . 'class-wp-customize-menus.php' );

/**
 * Initialize the Customizer Menus
 *
 * @param object $wp_customize An instance of the WP_Customize_Manager class.
 */
function menu_customizer_init( $wp_customize ) {
	// All our sections, settings, and controls will be added here.
	$menu_customizer = new WP_Customize_Menus( $wp_customize );
}
add_action( 'customize_register', 'menu_customizer_init' );

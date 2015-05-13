<?php
/**
 * Plugin Name: Menu Customizer
 * Plugin URI: http://wordpress.org/plugins/menu-customizer
 * Description: Manage your Menus in the Customizer. GSoC Project & WordPress core feature-plugin.
 * Version: 0.2
 * Author: Nick Halsey
 * Author URI: http://nick.halsey.co/
 * Tags: menus, custom menus, customizer, theme customizer, gsoc
 * License: GPL

=====================================================================================
Copyright (C) 2014 Nick Halsey

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
*/

require_once( plugin_dir_path( __FILE__ ) . 'class-wp-customize-menus.php' );

function menu_customizer_init( $wp_customize ) {
	// All our sections, settings, and controls will be added here
	$menu_customizer = new WP_Customize_Menus( $wp_customize );
}
add_action( 'customize_register', 'menu_customizer_init' );

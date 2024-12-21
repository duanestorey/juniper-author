<?php
/*
    Plugin Name: Juniper/Author
    Plugin URI: https://github.com/nowporg/juniper-author
    Banner: https://github.com/nowporg/juniper-author/blob/main/assets/banner.jpg?raw=true
    Description: Facilitates code signing and releases for WordPress add-ons using the Juniper release system for WordPress
    Author: Duane Storey
    Author URI: https://duanestorey.com
    Version: 1.0.0
    Requires PHP: 6.0
    Requires at least: 6.0
    Tested up to: 6.7
    Update URI: https://github.com/nowporg/juniper-author
    Text Domain: juniper
    Domain Path: /lang
    GitHub Plugin URI: nowporg/juniper-author
    Stable: 1.0.0
    Primary Branch: main

    Copyright (C) 2024-025 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
*/

namespace NOTWPORG\JuniperAuthor;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'JUNIPER_AUTHOR_VER', '1.0.0' );
define( 'JUNIPER_AUTHOR_PATH', dirname( __FILE__ ) );
define( 'JUNIPER_AUTHOR_RELEASES_PATH', dirname( __FILE__ ) . '/releases' );
define( 'JUNIPER_AUTHOR_MAIN_FILE', __FILE__ );
define( 'JUNIPER_AUTHOR_MAIN_DIR', dirname( __FILE__ ) );

require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/juniper-author.php' );

function initialize_juniper_author( $params ) {
    load_plugin_textdomain( 'juniper', false, 'juniper-author/lang/' );

    JuniperAuthor::instance()->init(); 
}

function handle_uninstall() {
    // clean up the options table
    Settings::deleteAllOptions();
}

add_action( 'init', __NAMESPACE__ . '\initialize_juniper_author' );
register_uninstall_hook( __FILE__, __NAMESPACE__ . '\handle_uninstall' );

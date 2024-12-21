<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */

namespace NOTWPORG\JuniperAuthor;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/github-updater.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/settings.php' );

class JuniperAuthor extends GithubUpdater {

    private static $instance = null;

    protected $settings = null;

    protected function __construct() {
        $this->settings = new Settings();;

        // Plugin action links
        add_filter( 'plugin_action_links_' . plugin_basename( JUNIPER_AUTHOR_MAIN_FILE ), array( $this, 'add_action_links' ) );
        add_filter( 'admin_init', array( $this, 'loadAssets' ) );
        add_filter( 'admin_init', array( $this, 'handleRepoLinks' ) );

        // initialize the updater
        parent::__construct( 
            'juniper-author/juniper-author.php',
            'notwporg',
            'juniper-author',
            'main'
        );
    }

    public function init() {
        $this->settings->init();
    }

    public function loadAssets() {
        if ( !empty( $_GET[ 'page' ] ) ) {
            $currentPage = $_GET[ 'page' ];

            if ( $currentPage == 'juniper-options' || $currentPage == 'juniper-repos' ) {
                wp_enqueue_style( 'juniper-author', plugins_url( 'dist/juniper.css', JUNIPER_AUTHOR_MAIN_FILE ), false );
            }
        }
    }

    public function handleRepoLinks() {
        if ( !empty( $_GET[ 'juniper_nonce' ] ) ) {
            $nonce = $_GET[ 'juniper_nonce' ];

            if ( !empty( $_GET[ 'juniper_remove_repo' ] ) ) {
                if ( wp_verify_nonce( $nonce, 'juniper' ) && current_user_can( 'manage_options' ) ) {
                    $repos = $this->settings->getSetting( 'repositories' );
                    if ( $repos && isset( $repos[ $_GET[ 'juniper_remove_repo' ] ] ) ) {
                        unset( $repos[ $_GET[ 'juniper_remove_repo' ] ] );
                        $this->settings->setSetting( 'repositories', $repos );
                        $this->settings->saveSettings();
                    }
                }
            }
        }
    }

    public function get_setting( $name ) {
        return $this->settings->get_setting( $name );
    }

    public function add_action_links( $actions ) {
        $links = array(
            '<a href="' . admin_url( 'options-general.php?page=juniper' ) . '">' . esc_html__( 'Settings', 'wp-api-privacy' ) . '</a>'
        );

        return array_merge( $links, $actions );
    }

    static function instance() {
        if ( self::$instance == null ) {
            self::$instance = new JuniperAuthor();
        }
        
        return self::$instance;
    }
}

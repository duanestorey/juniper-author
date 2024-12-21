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
        add_action( 'rest_api_init', array( $this, 'setupRestApi' ) );
        add_action( 'shutdown', array( $this, 'lookForReleases' ) );

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

    public function signReleases( $passphrase = false) {             
        // process zips
        $releases = $this->settings->getSetting( 'releases' );

        @mkdir( JUNIPER_AUTHOR_RELEASES_PATH, 0755 );

        foreach( $releases as $repo => $releaseInfo ) {
            foreach( $releaseInfo as $actualRelease ) {
                $releasePath = JUNIPER_AUTHOR_RELEASES_PATH . '/' . basename( $repo ) . '/' . $actualRelease->tag_name	;

                if ( !file_exists( $releasePath ) ) {
                    @mkdir( $releasePath, 0755, true );
                };

                if ( !empty( $actualRelease->assets[0]->name ) ) {
                    $zipName = $actualRelease->assets[0]->name;
                    $destinationZipFile = $releasePath . '/' . $zipName;

                    if ( !file_exists( $destinationZipFile ) ) {
                        copy( $actualRelease->assets[0]->browser_download_url, $destinationZipFile ); 
                    }
                }
            }
        }     
    }

    public function lookForReleases() {
        if ( true || time() > $this->settings->getSetting( 'next_release_time' ) ) {
            $repos = $this->settings->getSetting( 'repositories' );

            $releases = [];

            $hadReleases = false;
            if ( count( $repos ) ) {
                foreach( $repos as $url => $repoData ) {
                    $repoUrl = str_replace( 'https://github.com/', 'https://api.github.com/repos/', $url . '/releases' );

                    $info = $this->getReleaseInfo( $repoUrl );

                    if ( $info ) {
                        $hadReleases = true;

                        $releases[ $url ] = $info;
                       
                    }
                }
                if ( $hadReleases ) {
                     $this->settings->setSetting( 'releases', $releases );
                }  
            }

            // update next time for at least 15 minutes
            $this->settings->setSetting( 'next_release_time', time() + 15*60 );
            $this->settings->saveSettings();
        }

        $this->signReleases();   
    }

    public function outputPublicKey( $data ) {
        $data = new \stdClass;
        $data->public_key = '';
        
        $public_key = $this->settings->getSetting( 'public_key' );
        if ( $public_key ) {
            $data->version = '1.0';
            $data->key_type = $this->settings->getSetting( 'key_type' );
            $data->public_key = trim( str_replace( 
                array( '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----' ), 
                array( '', '' ),
                $public_key
            ) );
        }

        return $data;
    }

    public function setupRestApi() {
        register_rest_route( 
            'juniper/v1', '/public_key/', 
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'outputPublicKey' ),
            ) 
        );
    }

    public function loadAssets() {
        if ( !empty( $_GET[ 'page' ] ) ) {
            $currentPage = $_GET[ 'page' ];

            if ( $currentPage == 'juniper-options' || $currentPage == 'juniper-repos' || $currentPage == 'juniper' ) {
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

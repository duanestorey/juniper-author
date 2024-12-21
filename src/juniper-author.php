<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */

namespace NOTWPORG\JuniperAuthor;

use phpseclib3\Crypt\PublicKeyLoader;

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
        add_action( 'admin_init', array( $this, 'loadAssets' ) );
        add_action( 'admin_init', array( $this, 'handleRepoLinks' ) );
        add_action( 'rest_api_init', array( $this, 'setupRestApi' ) );
       // add_action( 'init', array( $this, 'lookForReleases' ) );
        add_action( 'wp_ajax_handle_ajax', array( $this, 'handleAjax' ) );
        add_action( 'wp_ajax_nopriv_handle_ajax', array( $this, 'handleAjax' ) );

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

        $this->lookForReleases();
    }

    protected function testPrivateKey( $passPhrase ) {
        try {
            require_once( JUNIPER_AUTHOR_MAIN_DIR . '/vendor/autoload.php' );

            $private_key = PublicKeyLoader::loadPrivateKey( $this->settings->getSetting( 'private_key' ), $passPhrase );

            return true;
        } catch ( \phpseclib3\Exception\NoKeyLoadedException $e ) {
            return false;
        }
    }

    protected function signRepoPackage( $repo, $tagName, $passPhrase ) {
        try {
            require_once( JUNIPER_AUTHOR_MAIN_DIR . '/vendor/autoload.php' );

            $private_key = PublicKeyLoader::loadPrivateKey( $this->settings->getSetting( 'private_key' ), $passPhrase );
            $private_key->withSignatureFormat( 'IEEE' );

            $current_user = wp_get_current_user();

            @mkdir( JUNIPER_AUTHOR_RELEASES_PATH, 0755 );

            $releases = $this->settings->getSetting( 'releases' );
            if ( !empty( $releases[ $repo ] ) ) {
                foreach( $releases[ $repo ] as $num => $releaseInfo ) {
                    if ( $releaseInfo->tag_name == $tagName ) {
                        $releasePath = JUNIPER_AUTHOR_RELEASES_PATH . '/' . basename( $repo ) . '/' . $releaseInfo->tag_name	;

                        if ( !file_exists( $releasePath ) ) {
                            @mkdir( $releasePath, 0755, true );

                            if ( !empty( $releaseInfo->assets[0]->name ) ) {
                                $zipName = $releaseInfo->assets[0]->name;
                                $destinationZipFile = $releasePath . '/' . $zipName;

                                if ( !file_exists( $destinationZipFile ) ) {
                                    copy( $releaseInfo->assets[0]->browser_download_url, $destinationZipFile ); 

                                    $destinationSignedZipFile = str_replace( '.zip', '.signed.zip', $destinationZipFile );

                                    $zip = new \ZipArchive();

                                    $sigFile = $releasePath . '/juniper.sig';
                                
                                    $sig = array();

                                    if ( $current_user->display_name ) {
                                        $sig[ 'author' ] = $current_user->display_name;
                                    }


                                    $hashBin = hash_file( 'SHA256', $destinationZipFile, true );
                                    
                                    $sig[ 'hash' ] = base64_encode( $hashBin );
                                    $sig[ 'hash_type' ] = 'SHA256';
                                    $sig[ 'signature' ] = base64_encode( $private_key->sign( $hashBin ) );
                                
                                    file_put_contents( $sigFile, json_encode( $sig ) );         

                                    if ( $zip->open( $destinationSignedZipFile, \ZipArchive::OVERWRITE | \ZipArchive::CREATE ) === TRUE ) {
                                        $zip->addFile( $sigFile, 'signature.json' );
                                        $zip->addFile( $destinationZipFile, $zipName );
                                        $zip->setArchiveComment( json_encode( $sig ) );

                                        $zip->close();
                                    }

                                    rename( $destinationZipFile, str_replace( '.zip', '.bak.zip', $destinationZipFile ) );

                                    return basename( $destinationSignedZipFile );
                                }
                            }
                        };

                        break;
                    }
                }
            }
        } catch ( \phpseclib3\Exception $e ) {
        
        }
        
    }

    public function handleAjax() {
        $action = $_POST[ 'juniper_action' ];
        $nonce = $_POST[ 'juniper_nonce' ];

        $response = new \stdClass;
        $response->success = 0;

        if ( wp_verify_nonce( $nonce, 'juniper' ) && current_user_can( 'manage_options' ) ) {
            switch( $action ) {
                case 'sign_release':
                    $repo = $_POST[ 'repo' ];
                    $tag = $_POST[ 'tag' ];
                    $passPhrase = $_POST[ 'pw' ];

                    $response->package = $this->signRepoPackage( $repo, $tag, $passPhrase );

                    $response->signed = true;
                    $response->signed_text = __( 'Yes', 'juniper' );
                    
                    break;
                case 'test_key':
                    $passPhrase = $_POST[ 'pw' ];

                    $response->key_valid = $this->testPrivateKey( $passPhrase );
                    break;
            }
        }

        echo json_encode( $response );

        wp_die();
    }

    public function lookForReleases() {
        if ( time() > $this->settings->getSetting( 'next_release_time' ) ) {
            // update all repos
            $repos = $this->settings->getSetting( 'repositories' );
            $this->settings->setSetting( 'reposistories', [] );
            foreach( $repos as $name => $info ) {
                $this->settings->mayebAddRepo( $name );
            }
            
            $repos = $this->settings->getSetting( 'repositories' );

            $releases = [];

            $hadReleases = false;
            if ( count( $repos ) ) {
                foreach( $repos as $url => $repoData ) {
                    $repoUrl = str_replace( 'https://github.com/', 'https://api.github.com/repos/', $url . '/releases' );

                    $info = $this->getReleaseInfo( $repoUrl );

                    if ( $info) {
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
    }

    public function getPublicKey() {
        $public_key = $this->settings->getSetting( 'public_key' );
        if ( $public_key ) {
            $public_key = trim( str_replace( 
                array( '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----' ), 
                array( '', '' ),
                $public_key
            ) );
        }

        return $public_key;   
    }

    public function outputPublicKey( $data ) {
        $data = new \stdClass;
        $data->public_key = '';
        
        $public_key = $this->settings->getSetting( 'public_key' );
        if ( $public_key ) {
            $data->version = '1.0';
            $data->key_type = $this->settings->getSetting( 'key_type' );
            $data->public_key = $this->getPublicKey();
        }

        return $data;
    }

    public function outputReleases( $params ) {
        $data = new \stdClass;

        $releases = $this->settings->getReleases();
        foreach( $releases as $repo => $releaseInfo ) {
            $pluginData = new \stdClass;

            $pluginData->name = $this->settings->getSetting( 'repositories' )[ $repo ];
            $pluginData->slug = basename( $repo );
            $pluginData->releases = [];

            foreach( $releaseInfo as $oneRelease ) {
                $pluginData->releases[] = $oneRelease;
            }

            $data->plugins[] = $pluginData;
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

        register_rest_route( 
            'juniper/v1', '/releases/', 
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'outputReleases' ),
            ) 
        );
    }

    public function loadAssets() {
        if ( !empty( $_GET[ 'page' ] ) ) {
            $currentPage = $_GET[ 'page' ];

            if ( $currentPage == 'juniper-options' || $currentPage == 'juniper-repos' || $currentPage == 'juniper' ) {
                wp_enqueue_style( 'juniper-author', plugins_url( 'dist/juniper.css', JUNIPER_AUTHOR_MAIN_FILE ), false );
                wp_enqueue_script( 'juniper-author', plugins_url( 'dist/juniper.js', JUNIPER_AUTHOR_MAIN_FILE ), array( 'jquery' ) );

                $data = array(
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce' => wp_create_nonce( 'juniper' )
                );

                wp_localize_script( 'juniper-author','Juniper', $data );
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
            } else if ( !empty( $_GET[ 'juniper_verify_package' ] ) ) {
                $package = $_GET[ 'juniper_verify_package' ];

                require_once( JUNIPER_AUTHOR_MAIN_DIR . '/vendor/autoload.php' );

                $public_key = PublicKeyLoader::loadPublicKey( $this->settings->getSetting( 'public_key' ) );
                $zip = new \ZipArchive();
                $result = $zip->open( $package, \ZipArchive::RDONLY );
                if ( $result === TRUE ) {
                    $comment = $zip->getArchiveComment();
                    if ( $comment ) {
                        $comment = json_decode( $comment );
                       // $comment->signature[0] = 'B';
                        $sigBin = base64_decode( $comment->signature );
                        $hashBin = base64_decode( $comment->hash );
                    }
                    $result = $public_key->verify( $hashBin, $sigBin );
                    echo (int)$result . '<br/>';
                    echo $comment->hash . '<br/>' . $comment->signature;
                }
                
                die;   
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

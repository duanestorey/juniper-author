<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */

namespace DuaneStorey\JuniperAuthor;

use phpseclib3\Crypt\PublicKeyLoader;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/utils.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/github-updater.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/settings.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/wordpress.php' );

class JuniperAuthor extends GithubUpdater {
    private static $instance = null;

    protected $settings = null;
    protected $utils = null;

    protected function __construct() {
        $this->settings = new Settings();;
        $this->utils = new Utils( $this->settings );

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

       // $this->lookForReleases();
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
                                    
                                    $sig[ 'ver' ] = '1.0';
                                    $sig[ 'hash' ] = base64_encode( $hashBin );
                                    $sig[ 'hash_type' ] = 'SHA256';
                                    $sig[ 'signature' ] = base64_encode( $private_key->sign( $hashBin ) );

                                    ksort( $sig );

                                    // Sign the entire package with the private key so we can make sure the variables haven't been tampered with
                                    $sig[ 'auth' ] = base64_encode( $private_key->sign( hash( 'sha256', json_encode( $sig ), true ) ) );
                                    //$sig[ 'package_url' ] =  plugins_url( basename( $repo ) . '/' . $releaseInfo->tag_name . '/' . str_replace( basename( $release->zipName ), JUNIPER_AUTHOR_MAIN_FILE );
                                
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

    public function verifyPackage( $package ) {
        $verifyResult = new \stdClass;
        $verifyResult->signature_valid = '0';
        $verifyResult->file_valid = '0';
        $verifyResult->package = str_replace( JUNIPER_AUTHOR_RELEASES_PATH, '', $package );

        require_once( JUNIPER_AUTHOR_MAIN_DIR . '/vendor/autoload.php' );

        $public_key = PublicKeyLoader::loadPublicKey( $this->settings->getSetting( 'public_key' ) );
        $zip = new \ZipArchive();
        $result = $zip->open( $package, \ZipArchive::RDONLY );
        if ( $result === TRUE ) {
            $comment = $zip->getArchiveComment();
        
            if ( $comment ) {
                $comment = json_decode( $comment );

                $sigBin = base64_decode( $comment->signature );
                $hashBin = base64_decode( $comment->hash );
            }

            $result = $public_key->verify( $hashBin, $sigBin );
            if ( $result ) { 
                $verifyResult->signature_valid = '1';

                $tempDir = sys_get_temp_dir() . '/' . md5( time() );

                $zip->extractTo( $tempDir );
                $originalName = $tempDir . '/' . str_replace( '.signed.zip', '.zip', basename( $package ) );
                $verifyResult->local_file_hash = base64_encode( hash_file( 'SHA256', $originalName, true ) );
                $verifyResult->local_file_path = $originalName;

                $verifyResult->file_valid = ( $verifyResult->local_file_hash == $comment->hash ) ? '1' : '0';
            }

            $zip->close();
        }
        
        return $verifyResult; 
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
                case 'verify_package':
                    $package = $_POST[ 'package' ];
                    $response->verify = $this->verifyPackage( $package );
                    break;
            }
        }

        echo json_encode( $response );

        wp_die();
    }

    public function lookForReleases() {
        /*
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

                    $info = $this->utils->curlGitHubRequest( $repoUrl );

                    if ( $info) {
                        $info = json_decode( $info );

                        $hadReleases = true;

                        $releases[ $url ] = $info;
                       
                    }
                }
                if ( $hadReleases ) {
                     $this->settings->setSetting( 'releases', $releases );
                }  
            }

            // update next time for at least 15 minutes
            $this->settings->setSetting( 'next_release_time', time() + 5*60 );
            $this->settings->saveSettings();
        }
        */
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
        return $this->getRepositories();
    }   

    public function getRepositories() {
        return $this->settings->getSetting( 'repositories' );
    }

    public function getFilteredRepositories( $repoType = 'plugin' ) {
        $repos = $this->getRepositories();
        if ( $repos ) {
            $foundRepos = [];

            foreach( $repos as $name => $info ) {
                if ( $info->info->type == $repoType ) {
                    $foundRepos[] = $info;
                }
            }

            return $foundRepos;
        }

        return [];
    }

    public function outputPlugins() {
        return $this->getFilteredRepositories( 'plugin' );
    }

    public function outputThemes() {
        return $this->getFilteredRepositories( 'theme' );
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

        register_rest_route( 
            'juniper/v1', '/plugins/', 
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'outputPlugins' ),
            ) 
        );

        register_rest_route( 
            'juniper/v1', '/themes/', 
            array(
                'methods' => 'GET',
                'callback' => array( $this, 'outputThemes' ),
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

    public function parseRelevantRepoInfo( $repoInfo ) {
        $newRepoInfo = new \stdClass;

        $newRepoInfo->fullName = $repoInfo->full_name;

        $newRepoInfo->owner = new \stdClass;
        $newRepoInfo->owner->user = $repoInfo->owner->login;
        $newRepoInfo->owner->avatarUrl = $repoInfo->owner->avatar_url;
        $newRepoInfo->owner->ownerUrl = $repoInfo->owner->html_url;

        $newRepoInfo->repoUrl =  $repoInfo->html_url;
        $newRepoInfo->description = $repoInfo->description;
        $newRepoInfo->issuesUrl = $repoInfo->issues_url;
        $newRepoInfo->openIssuesCount = $repoInfo->open_issues_count;
        $newRepoInfo->forkCount = $repoInfo->forks; 

        $newRepoInfo->lastUpdatedAt = strtotime( $repoInfo->updated_at );
        $newRepoInfo->starsCount = $repoInfo->stargazers_count;
        $newRepoInfo->hasIssues = $repoInfo->has_issues;

        return $newRepoInfo;
    }

    public function parseRelevantIssueInfo( $issues ) {
        $returnIssues  = [];

        foreach( $issues as $num => $issue ) {
            $newIssue = new \stdClass;
            $newIssue->id = $issue->id;
            $newIssue->url = $issue->html_url;
            $newIssue->title = $issue->title;
            $newIssue->state = $issue->state;
            $newIssue->comments = $issue->comments;
            $newIssue->updatedAt = strtotime( $issue->updated_at );
            $newIssue->body = $issue->body;
            $newIssue->timelineUrl = $issue->timeline_url;

            $newIssue->postedBy = new \stdClass;
            $newIssue->postedBy->user = $issue->user->login;
            $newIssue->postedBy->avatarUrl = $issue->user->avatar_url;
            $newIssue->postedBy->userUrl = $issue->user->html_url;

            $returnIssues[] = $newIssue;
        }

        return $returnIssues;
    }

    public function parseRelevantReleaseInfo( $releases ) {
        $returnReleases  = [];

        foreach( $releases as $num => $release ) {
            $newRelease = new \stdClass;
            $newRelease->id = $release->id;
            $newRelease->url = $release->html_url;
            $newRelease->tag = $release->tag_name;
            $newRelease->signed = false;

            $newRelease->name = $release->name;
            $newRelease->body = $release->body;

            $newRelease->publishedAt = strtotime( $release->published_at );
            $newRelease->downloadUrl = '';
            $newRelease->downloadSize = 0;
            $newRelease->downloadCount = 0;

            if ( !empty( $release->assets[ 0 ] ) ) {
                $newRelease->downloadUrl = $release->assets[ 0 ]->browser_download_url;
                $newRelease->downloadSize = $release->assets[ 0 ]->size;
                $newRelease->downloadCount = $release->assets[ 0 ]->download_count;
            }

            $newRelease->postedBy = new \stdClass;
            $newRelease->postedBy->user = $release->author->login;
            $newRelease->postedBy->avatarUrl = $release->author->avatar_url;
            $newRelease->postedBy->userUrl = $release->author->html_url;

            $returnReleases[] = $newRelease;
        }

        return $returnReleases;
    }

    public function refreshRepositories() {
        $orgInfo = 'https://api.github.com/user/repos';
        $result = $this->utils->curlGitHubRequest( $orgInfo );
    
        if ( $result ) {
            $decodedResult = json_decode( $result );
            $repos = [];

            foreach( $decodedResult as $oneResult ) {
                // Skip private repos for now since we are using authenticated requests
                if ( $oneResult->private ) {
                    continue;
                }

                $possiblePluginFile = 'https://raw.githubusercontent.com/' . $oneResult->full_name . '/refs/heads/main/' . basename( $oneResult->full_name ) . '.php';
                if ( !$this->utils->curlRemoteFileExists( $possiblePluginFile ) ) {
                    continue;
                }

                $pluginFile = $this->utils->curlGitHubRequest( $possiblePluginFile );

                $wordPress = new WordPress( $this->utils );
                
                $pluginInfo = $wordPress->parseReadmeHeader( $pluginFile );

                $pluginInfo->bannerImage = '';
                $pluginInfo->bannerImageLarge = '';

                $testBannerImage = 'https://raw.githubusercontent.com/' . $oneResult->full_name . '/refs/heads/main/assets/banner-1544x500.jpg';
                if ( $this->utils->curlRemoteFileExists( $testBannerImage ) ) {
                    $pluginInfo->bannerImageLarge = $testBannerImage;
                    $pluginInfo->bannerImage = $testBannerImage;
                }

                $pluginInfo->readme = '';
                $pluginInfo->readmeHtml = '';

                $readmeFile = 'https://raw.githubusercontent.com/' . $oneResult->full_name . '/refs/heads/main/README.md';

                if ( $this->utils->curlRemoteFileExists( $readmeFile ) ) {
                    require_once( JUNIPER_AUTHOR_PATH . '/vendor/autoload.php' );

                    $parsedown = new \Parsedown();
                    $pluginInfo->readme = $this->utils->curlGitHubRequest( $readmeFile );
                    $pluginInfo->readmeHtml = $parsedown->text( $pluginInfo->readme );
                }

                $oneClass = new \stdClass;
                $oneClass->info = $pluginInfo;
                $oneClass->repository = $this->parseRelevantRepoInfo( $oneResult );

                // get issues
                $issuesUrl = 'https://api.github.com/repos/' . $oneResult->full_name . '/issues?state=all';

                $oneClass->issues = [];
                $issues = $this->utils->curlGitHubRequest( $issuesUrl );
                if ( $issues ) {
                    $decodedIssues = json_decode( $issues );

                    $oneClass->issues = $this->parseRelevantIssueInfo( $decodedIssues );
                }

                // get releases
                $releasesUrl = 'https://api.github.com/repos/' . $oneResult->full_name . '/releases';

                $oneClass->releases = [];
                $releases = $this->utils->curlGitHubRequest( $releasesUrl );
                if ( $releases ) {
                    $decodedReleases = json_decode( $releases );

                    $oneClass->releases = $this->parseRelevantReleaseInfo( $decodedReleases );
                }

                $repos[] = $oneClass;
            }

            $this->settings->setSetting( 'repositories', $repos );
            $this->settings->saveSettings();

            header( 'Location: ' . admin_url( 'admin.php?page=juniper-repos' ) );
            die;
        }    
    }

    public function getReleases() {
        $releases = [];

        if ( !empty( $this->settings->releases ) ) {
            foreach( $this->settings->releases as $repo => $releaseInfo ) {
                $releases[ $repo ] = [];

                foreach( $releaseInfo as $oneRelease ) {
                    $release = new \stdClass;

                    $release->tagName = $oneRelease->tag_name;
                    $release->name = $oneRelease->name;
                    $release->description = $oneRelease->body;
                    $release->publishedDate = strtotime( $oneRelease->published_at );

                    $releasePath = JUNIPER_AUTHOR_RELEASES_PATH . '/' . basename( $repo ) . '/' . $oneRelease->tag_name;
                    
                    $release->signed = false;

                    if ( !empty( $oneRelease->assets[0]->browser_download_url ) ) {
                        $signedZip = $releasePath . '/' . str_replace( '.zip', '.signed.zip', basename( $oneRelease->assets[0]->browser_download_url ) );
                        $release->signed = file_exists( $signedZip );
                    }
                    
                    $release->package = '';

                    if ( $release->signed ) {
                        $release->package = $signedZip;
                        
                    } else {
                        if ( !empty( $oneRelease->assets[0]->browser_download_url ) ) {
                            $release->package = $oneRelease->assets[0]->browser_download_url;
                        }
                    }

                    $release->package_url = plugins_url( basename( $repo ) . '/' . $oneRelease->tag_name . '/' . basename( $release->package ), JUNIPER_AUTHOR_MAIN_FILE );
                    
                    $releases[ $repo ][] = $release;
                }
            }
        }   

        return $releases;
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
                            print_r( $comment );
                       //    $comment->signature[0] = 'B';
                        $sigBin = base64_decode( $comment->signature );
                        $hashBin = base64_decode( $comment->hash );
                    }
                    $result = $public_key->verify( $hashBin, $sigBin );
                    echo (int)$result . '<br/>';
                    echo $comment->hash . '<br/>' . $comment->signature;

                }
                
                die;   
            } else if ( !empty( $_GET[ 'juniper_action' ] ) ) {
                if ( wp_verify_nonce( $nonce, 'juniper' ) && current_user_can( 'manage_options' ) ) {
                    if ( $_GET[ 'juniper_action' ] == 'refresh' ) {
                        $this->refreshRepositories();
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

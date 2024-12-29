<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */

namespace DuaneStorey\JuniperAuthor;
use DuaneStorey\Juniper\JuniperBerry;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/utils.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/github-updater.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/settings.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/wordpress.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/debug.php' );
require_once( JUNIPER_AUTHOR_MAIN_DIR . '/src/juniper-berry.php' );

class JuniperAuthor extends JuniperBerry {
    private static $instance = null;
    public const UPDATE_REPO_TIME = 30*60;

    protected $settings = null;
    protected $utils = null;

    protected function __construct() {
        $this->settings = new Settings( $this );
        $this->utils = new Utils( $this->settings );

        DebugLog::instance()->enable( $this->settings->getSetting( 'debug_file_enabled' ) );

        // Plugin action links
        add_filter( 'plugin_action_links_' . plugin_basename( JUNIPER_AUTHOR_MAIN_FILE ), array( $this, 'add_action_links' ) );
        add_action( 'admin_init', array( $this, 'loadAssets' ) );
        add_action( 'admin_init', array( $this, 'handleRepoLinks' ) );
        add_action( 'admin_init', array( $this, 'checkForDownload' ) );
        add_action( 'rest_api_init', array( $this, 'setupRestApi' ) );
        add_filter( 'juniper_repos', array( $this, 'modifyReleasesWithSignedInfo' ) );
        add_action( 'wp_ajax_handle_ajax', array( $this, 'handleAjax' ) );
        add_action( 'wp_ajax_nopriv_handle_ajax', array( $this, 'handleAjax' ) );

        // initialize the updater
        parent::__construct( 
            'juniper-author/juniper-author.php',
            JUNIPER_AUTHOR_VER
        );
    }

    public function init() {
        $this->settings->init();
        $this->checkForRepoUpdate();
    }

    public function checkForRepoUpdate() {
        if ( !wp_doing_ajax() && $this->settings->getSetting( 'github_token' ) ) {
            DEBUG_LOG( "Checking to see if it is time to update the repo" );
            if (  time() > ( $this->settings->getSetting( 'last_repo_update_time' ) + JuniperAuthor::UPDATE_REPO_TIME ) ) {
                DEBUG_LOG( "...time to update the repo, triggering magic AJAX request" );

                $postFields = [
                    'action' => 'handle_ajax',
                    'juniper_action' => 'update_repo',
                    'juniper_nonce' => wp_create_nonce( 'juniper' )
                ];

                $cookies = [];
				foreach ( $_COOKIE as $name => $value ) {
					$cookies[] = "$name=" . urlencode( is_array( $value ) ? serialize( $value ) : $value );
				}

                // Inspired by the wp-async-task plugin
                $request_args = array(
					'timeout'   => 0.1,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
					'body'      => $postFields,
					'headers'   => array(
						'cookie' => implode( '; ', $cookies ),
					),
				);

                $this->settings->setSetting( 'last_repo_update_time', time() );
                wp_remote_post( admin_url( 'admin-ajax.php' ), $request_args );
            }
        }
    }


    public function getReleaseFromRepoAndTag( $repoName, $tagName ) {
        $repositories = $this->getRepositories();
        foreach( $repositories as $oneRepo ) {
            if ( $oneRepo->repository->fullName == $repoName ) {
                foreach( $oneRepo->releases as $releaseInfo ) {
                    if ( $releaseInfo->tag == $tagName ) {
                        return $releaseInfo;
                    }
                }
            }
        }    

        return false;
    }
    
    public function registerDownloadForFile( $fileName ) {
        $downloads = $this->settings->getSetting( 'downloads' );
        if ( isset( $downloads[ $fileName ] ) ) {
            $downloads[ $fileName ]++;
        } else {
            $downloads[ $fileName ] = 1;
        }
        $this->settings->setSetting( 'downloads', $downloads );
    }

    public function getDownloadCountForFile( $fileName ) {
        $downloads = $this->settings->getSetting( 'downloads' );
        if ( $downloads ) {
            if ( isset( $downloads[ $fileName ] ) ) {
                return $downloads[ $fileName ];
            } 
        }   

        return 0;
    }

    public function checkForDownload() {
        if ( isset( $_GET[ 'download_package' ] ) ) {
            $tag = $_GET[ 'tag' ];
            $repo = $_GET[ 'repo' ];

            $releaseInfo = $this->getReleaseFromRepoAndTag( $repo, $tag );
            if ( $releaseInfo ) {
                $signedAppend =  str_replace( '.zip', '.signed.zip', $repo . '/' . $releaseInfo->tag . '/' . basename( $releaseInfo->downloadUrl ) ); 
                $releasePath = JUNIPER_AUTHOR_RELEASES_PATH;
                $signedZip = $releasePath . '/' . $signedAppend;
                $signedUrl = plugins_url( 'releases', JUNIPER_AUTHOR_MAIN_FILE ) . '/' . $signedAppend;

                if ( file_exists( $signedZip ) ) {
                    $this->registerDownloadForFile( $signedZip );
                    
                    header( 'Location: ' . $signedUrl );
                    die;
                }
            }
        }
    }

    // Since libsodium doesn't verify stored a hash of the password so we don't accidentally create garbage signatures 
    protected function testPrivateKey( $passPhrase ) {
        $hashed_password = $this->settings->getSetting( 'hashed_password' );

        return sodium_crypto_pwhash_str_verify( $hashed_password, $passPhrase );
    }

    protected function signRepoPackage( $repo, $tagName, $passPhrase ) {
        $current_user = wp_get_current_user();

        @mkdir( JUNIPER_AUTHOR_RELEASES_PATH, 0755 );

        $repositories = $this->settings->getRepositories();

        $settings = $this->settings->getAllSettings();
        if ( empty( $settings->password_salt ) ) {
            return;
        }

        $salt = sodium_base642bin( $settings->password_salt, SODIUM_BASE64_VARIANT_ORIGINAL );
        $hash = sodium_crypto_pwhash( 
            SODIUM_CRYPTO_SIGN_SEEDBYTES, 
            $passPhrase, 
            $salt, 
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, 
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE 
        );

        $key_pair = sodium_crypto_sign_seed_keypair( $hash );
        $private_key = sodium_crypto_sign_secretkey( $key_pair );

        if ( !$private_key ) {
            DEBUG_LOG( "Decryption failed" );
        }
        
        foreach( $repositories as $oneRepo ) {
            if ( $oneRepo->repository->fullName == $repo ) {
                foreach( $oneRepo->releases as $releaseInfo ) {
                    if ( $releaseInfo->tag == $tagName ) {
                        $releasePath = JUNIPER_AUTHOR_RELEASES_PATH . '/' . $repo . '/' . $releaseInfo->tag	;

                        DEBUG_LOG( "Release path => " . $releasePath );

                        if ( !file_exists( $releasePath ) ) {
                            @mkdir( $releasePath, 0755, true );
                        }

                        DEBUG_LOG( 'Release download url is => ' . $releaseInfo->downloadUrl );
                
                        if ( !empty( $releaseInfo->downloadUrl ) ) {
                            $zipName = basename( $releaseInfo->downloadUrl );
                            $destinationZipFile = $releasePath . '/' . $zipName;

                            if ( !file_exists( $destinationZipFile ) ) {
                                copy( $releaseInfo->downloadUrl, $destinationZipFile ); 
                            }

                            if ( file_exists( $destinationZipFile ) ) {
                                $sigFile = $releasePath . '/signature.json';    
                                $sig = array();

                                $hashBin = hash_file( 'sha384', $destinationZipFile, true );
                                
                                $sig[ 'ver' ] = '1.0';
                                $sig[ 'filename' ] = basename( $destinationZipFile );
                                $sig[ 'modified' ] = filemtime( $destinationZipFile );
                                $sig[ 'hash' ] = sodium_bin2base64( $hashBin, SODIUM_BASE64_VARIANT_ORIGINAL );
                                $sig[ 'hash_type' ] = 'sha384';
                                $sig[ 'signature' ] = sodium_bin2base64( 
                                    sodium_crypto_sign_detached( $hashBin, $private_key ), 
                                    SODIUM_BASE64_VARIANT_ORIGINAL 
                                );

                                ksort( $sig );

                                // Sign the entire package with the private key so we can make sure the variables haven't been tampered with
                                $sig[ 'auth' ] = sodium_bin2base64( 
                                    sodium_crypto_sign_detached( 
                                        hash( 'sha384', json_encode( $sig ), true ), 
                                        $private_key 
                                    ), 
                                    SODIUM_BASE64_VARIANT_ORIGINAL 
                                );

                                file_put_contents( $sigFile, json_encode( $sig ) );
                                
                                DEBUG_LOG( 'Signed file is => ' . basename( $destinationZipFile ) );     
                            }

                            sodium_memzero( $key_pair );
                            sodium_memzero( $private_key );
                            return basename( $destinationZipFile );
                        };

                        break;
                                
                    }
                }
            }
        }

        if ( $private_key ) {
            sodium_memzero( $private_key );
        }

        if ( $key_pair ) {
            sodium_memzero( $key_pair );
        }
    }

    public function verifyPackage( $package ) {
        $verifyResult = new \stdClass;
        $verifyResult->signature_valid = '0';
        $verifyResult->file_valid = '0';
        $verifyResult->package = $package;

        $public_key = sodium_base642bin( $this->settings->getSetting( 'public_key' ), SODIUM_BASE64_VARIANT_ORIGINAL );

        DEBUG_LOG( "Trying to verify using public key => " . $this->settings->getSetting( 'public_key' ) );
        $zipFileName = JUNIPER_AUTHOR_RELEASES_PATH . '/' . $package;
        $signatureFile = dirname ( $zipFileName ) . '/signature.json';
    
        if ( $signatureFile ) {
            $signatureInfo = json_decode( file_get_contents( $signatureFile ) );

            $sigBin = sodium_base642bin( $signatureInfo->signature, SODIUM_BASE64_VARIANT_ORIGINAL );
            $hashBin = sodium_base642bin( $signatureInfo->hash, SODIUM_BASE64_VARIANT_ORIGINAL );
    
            $result = sodium_crypto_sign_verify_detached( $sigBin, $hashBin, $public_key );
            
            DEBUG_LOG( "Signature result => " . $result );
            if ( $result ) { 
                $verifyResult->signature_valid = '1';

                $verifyResult->local_file_hash = base64_encode( hash_file( 'sha384', $zipFileName, true ) );
                $verifyResult->local_file_path = $zipFileName;

                $verifyResult->file_valid = ( $verifyResult->local_file_hash == $signatureInfo->hash ) ? '1' : '0';
            }
        }
        
        return $verifyResult; 
    }

    public function doRefreshStage( $stage ) {
        $response = new \stdClass;
        $response->pass = 0;
        $response->done = 0;
        $response->msg = '';
        $response->next_stage = 0;

        switch( $stage ) {
            case 0:
                // update repositories
                DEBUG_LOG( "Starting repository refresh, updating repository list" );
                $allRepos = [];
                $page = 1;
                
                while ( true ) {
                    $orgInfo = 'https://api.github.com/user/repos?per_page=100&page=' . $page;
                    $result = $this->utils->curlGitHubRequest( $orgInfo );
                    if ( $result ) {
                        $decodedResult = json_decode( $result );
                        DEBUG_LOG( sprintf( "...requested page [%d]", count( $decodedResult ) ) );

                        $allRepos = array_merge( $allRepos, $decodedResult );

                        if ( $decodedResult && count( $decodedResult ) == 100 ) {
                            $page++;
                        } else {
                            break;
                        }
                    }
                }

                if ( $allRepos && count( $allRepos ) ) {
                    $this->settings->setSetting( 'ajax_repos', $allRepos );
                    /* translators: this is the number of found repositories */
                    $response->msg = '...' . sprintf( __( 'Found %d respositories', 'juniper' ), count( $allRepos ) );
                    $response->pass = 1;
                    $response->next_stage = 1;
                } 
                break;
            case 1:
                DEBUG_LOG( "...Looking for WordPress plugins" );
                $repos = $this->settings->getSetting( 'ajax_repos' );
                if ( $repos ) {
                    $newRepos = [];
                    $privateRepos = 0;
                    $forkedRepos = 0;

                    foreach( $repos as $oneRepo ) {
                        // Skip private repos for now since we are using authenticated requests
                        if ( $oneRepo->private ) {
                            $privateRepos++;
                            DEBUG_LOG( sprintf( "......skipping private repo [%s]", $oneRepo->full_name ) );
                            continue;
                        }

                        if ( $oneRepo->fork ) {
                            $forkedRepos++;
                            DEBUG_LOG( sprintf( "......skipping forked repo [%s]", $oneRepo->full_name ) );
                            continue;
                        }

                        DEBUG_LOG( "...Checking for main plugin file" );
                        $possiblePluginFile = 'https://raw.githubusercontent.com/' . $oneRepo->full_name . '/refs/heads/' . $oneRepo->default_branch . '/' . basename( $oneRepo->full_name ) . '.php';
                        if ( !$this->utils->curlRemoteFileExists( $possiblePluginFile ) ) {
                            DEBUG_LOG( "......Checking for main style file file" );
                            $possibleThemeFile = 'https://raw.githubusercontent.com/' . $oneRepo->full_name . '/refs/heads/' . $oneRepo->default_branch . '/style.css';
                            if ( !$this->utils->curlRemoteFileExists( $possibleThemeFile ) ) {

                                $possibleThemeFile = 'https://raw.githubusercontent.com/' . $oneRepo->full_name . '/refs/heads/' . $oneRepo->default_branch . '/style.scss';
                                if ( !$this->utils->curlRemoteFileExists( $possibleThemeFile ) ) {
                                    DEBUG_LOG( "......can't find either, so looking at all files now" );

                                    $fileListApi = 'https://api.github.com/repos/' . $oneRepo->full_name . '/git/trees/' . $oneRepo->default_branch;
                                    DEBUG_LOG( "......contacting " . $fileListApi );
                                    $result = $this->utils->curlGitHubRequest( $fileListApi );
                                    if ( $result ) {
                                        $decodedFileList = json_decode( $result );

                                        $fileList = [];
                                        if ( !empty( $decodedFileList->tree ) ) {
                                            foreach( $decodedFileList->tree as $num => $fileInfo ) {
                                                if ( $fileInfo->path = 'index.php' ) {
                                                    continue;
                                                }

                                                if ( $fileInfo->type == 'blob' && strpos( $fileInfo->path, '.php' ) !== false ) {
                                                    $oneFile = 'https://raw.githubusercontent.com/' . $oneRepo->full_name . '/refs/heads/' . $oneRepo->default_branch . '/' . $fileInfo->path;
                                                
                                                    DEBUG_LOG( sprintf( ".........found possible plugin file at [%s]", $possiblePluginFile ) ); 

                                                    $fileList[] = $oneFile;
                                                }
                                            }
                                        }

                                        if ( count( $fileList) != 1 ) {
                                            DEBUG_LOG( ".........too many files in main directory, skipping for now." ); 
                                            continue;
                                        }

                                        $possiblePluginFile = $fileList[0];
                                    }
                                } else {
                                    $possiblePluginFile = $possibleThemeFile; 
                                }
                            } else {
                                $possiblePluginFile = $possibleThemeFile; 
                            }
                        }

                        if (!$possiblePluginFile ) {
                            continue;
                        }

                        if ( !is_array( $possiblePluginFile ) ) {
                            DEBUG_LOG( sprintf( '...Found possible file [%s]', $possiblePluginFile  ) );
                        }

                        $oneRepo->possiblePluginFile = $possiblePluginFile;

                        $newRepos[] = $oneRepo;
                    }

                    $response->msg = '...' . sprintf( __( 'Detected %d valid and non-private respositories for inclusion, skipped %d private and %d forked' ), count( $newRepos ), $privateRepos, $forkedRepos );
                    $response->pass = 1;
                    $response->next_stage = 2;

                    $this->settings->setSetting( 'ajax_repos', $newRepos );
                }
                break;
            case 2:
                DEBUG_LOG( "...Fetching plugin header and README.md files" );
                $repos = $this->settings->getSetting( 'ajax_repos' );
                if ( $repos ) {
                    $assembledData = [];
                    $wordPress = new WordPress( $this->utils );
                    $failedPlugins = 0;

                    foreach( $repos as $oneRepo ) {
                        // we already know it's valid
                        $pluginFileName = $oneRepo->possiblePluginFile;
                        DEBUG_LOG( sprintf( "......trying to load [%s]", $pluginFileName ) );
                        
                        $pluginFile = $this->utils->curlGitHubRequest( $pluginFileName );
     
                        $pluginInfo = $wordPress->parseReadmeHeader( $pluginFile );  
                        if ( !$pluginInfo ) {
                            DEBUG_LOG( sprintf( "......ERROR, plugin header can't be easily parsed [%s]", $pluginFileName ) );
                            $failedPlugins++;
                            continue;
                        } else {
                            if ( !isset( $pluginInfo->pluginName ) && !isset( $pluginInfo->themeName ) ) {
                                DEBUG_LOG( "......Found plugin/theme info, but doesn't appear to have a name " );
                                continue;
                            }
                        }

                        $pluginInfo->readme = '';
                        $pluginInfo->readmeHtml = '';

                        $readmeFile = 'https://raw.githubusercontent.com/' . $oneRepo->full_name . '/refs/heads/' . $oneRepo->default_branch . '/README.md';

                        if ( $this->utils->curlRemoteFileExists( $readmeFile ) ) {
                            require_once( JUNIPER_AUTHOR_PATH . '/vendor/autoload.php' );

                            $parsedown = new \Parsedown();
                            $pluginInfo->readme = $this->utils->curlGitHubRequest( $readmeFile );
                            $pluginInfo->readmeHtml = $parsedown->text( $pluginInfo->readme );
                        }

                        $oneClass = new \stdClass;
                        $oneClass->info = $pluginInfo;
                        $oneClass->repository = $this->parseRelevantRepoInfo( $oneRepo );

                        $assembledData[] = $oneClass;     
                    }

                    $response->msg = '...' . sprintf( __( 'Plugin/Theme headers parsed and README.md data loaded, %d plugins/themes excluded', 'juniper' ), $failedPlugins );
                    $response->next_stage = 3;
                    $response->pass = 1;

                    $this->settings->setSetting( 'ajax_update_data', $assembledData );
                }
                break;
            case 3:
                DEBUG_LOG( "...Updating banner images" );
                $repos = $this->settings->getSetting( 'ajax_update_data' );
                if ( $repos ) {
                    $assembledData = [];

                    foreach( $repos as $oneRepo ) {
                        $oneRepo->info->bannerImage = '';
                        $oneRepo->info->bannerImageLarge = '';

                        $testBannerImage = 'https://raw.githubusercontent.com/' . $oneRepo->repository->fullName . '/refs/heads/' . $oneRepo->repository->primaryBranch . '/assets/banner-1544x500.jpg';
                        if ( $this->utils->curlRemoteFileExists( $testBannerImage ) ) {
                            $oneRepo->info->bannerImageLarge = $testBannerImage;
                            $oneRepo->info->bannerImage = $testBannerImage;
                        }

                        $assembledData[] = $oneRepo;
                    }

                    $response->msg = '...' . sprintf( __( 'Banner images loaded', 'juniper' ) );
                    $response->next_stage = 4;
                    $response->pass = 1;

                    $this->settings->setSetting( 'ajax_update_data', $assembledData );
                }
                break;
            case 4:
                DEBUG_LOG( "...Refreshing repository issues" );
                $repos = $this->settings->getSetting( 'ajax_update_data' );
                if ( $repos ) {
                    $assembledData = [];
                    foreach( $repos as $oneRepo ) {
                        // get issues
                        $issuesUrl = 'https://api.github.com/repos/' . $oneRepo->repository->fullName . '/issues?state=all';

                        $oneRepo->issues = [];
                        $issues = $this->utils->curlGitHubRequest( $issuesUrl );
                        if ( $issues ) {
                            $decodedIssues = json_decode( $issues );

                            $oneRepo->issues = $this->parseRelevantIssueInfo( $decodedIssues );
                        }

                        $assembledData[] = $oneRepo;
                    }

                    $this->settings->setSetting( 'ajax_update_data', $assembledData );

                    $response->msg = '...' . sprintf( __( 'All Github issues loaded', 'juniper' ) );
                    $response->pass = 1;
                    $response->next_stage = 5;
                }
                break;
            case 5:
                DEBUG_LOG( "...Refreshing repository releases" );
                $repos = $this->settings->getSetting( 'ajax_update_data' );
                if ( $repos ) {
                    foreach( $repos as $oneRepo ) {
                        // get releases
                        $releasesUrl = 'https://api.github.com/repos/' . $oneRepo->repository->fullName . '/releases';

                        $oneRepo->releases = [];
                        $releases = $this->utils->curlGitHubRequest( $releasesUrl );
                        if ( $releases ) {
                            $decodedReleases = json_decode( $releases );

                            $oneRepo->releases = $this->parseRelevantReleaseInfo( $oneRepo, $decodedReleases );
                        }
                    }

                    // update the main data
                    $this->settings->setSetting( 'repositories', $repos );

                    $response->msg = '...' . sprintf( __( 'All Github releases loaded', 'juniper' ) );
                    $response->next_stage = 6;
                    $response->pass = 1;
                }
                break;
            case 6:
                DEBUG_LOG( "...Refreshing user information" );
                $decodedUserInfo = false;
                $userUrl = 'https://api.github.com/user';
                $userInfo = $this->utils->curlGitHubRequest( $userUrl );
                if ( $userInfo ) {
                    $decodedUserInfo = json_decode( $userInfo );

                    $response->msg = '...' . sprintf( __( 'Github user information loaded', 'juniper' ) );
                    $response->next_stage = 0;
                    $response->pass = 1;
                    $response->done = 1;
                }

                $this->settings->setSetting( 'user_info', $decodedUserInfo );

                DEBUG_LOG( "Repository update complete" );
                break;
            case 10:
                DEBUG_LOG( "Starting partial repository updates" );
                // hack to start at a later stage
                $repos = $this->settings->getSetting( 'repositories' );
                
                $this->settings->setSetting( 'ajax_repos', $repos );
                $response->msg = '...' . sprintf( __( 'merging in previous data', 'juniper' ) );
                $response->next_stage = 2;
                $response->pass = 1;
                break;
            default:
                break;
        }

        return $response;
    }

    public function handleAjax() {
        $action = $_POST[ 'juniper_action' ];
        $nonce = $_POST[ 'juniper_nonce' ];

        $response = new \stdClass;
        $response->success = 0;

        if ( wp_verify_nonce( $nonce, 'juniper' ) && current_user_can( 'manage_options' ) ) {
            switch( $action ) {
                case 'remove_repo':
                    $repo = $_POST[ 'repo' ];
                    $hiddenRepos = $this->settings->getSetting( 'hidden_repos' );
                    if ( is_array( $hiddenRepos ) ) {
                        if ( !in_array( $repo, $hiddenRepos ) ) {
                            $hiddenRepos[] = $repo;

                            sort( $hiddenRepos );

                            $this->settings->setSetting( 'hidden_repos', $hiddenRepos );
                        }
                    }
                    break;
                case 'restore_repo':
                    $repo = $_POST[ 'repo' ];
                    $hiddenRepos = $this->settings->getSetting( 'hidden_repos' );
                    if ( is_array( $hiddenRepos ) ) {
                        if ( in_array( $repo, $hiddenRepos ) ) {
                            $hiddenRepos = array_diff( $hiddenRepos, [ $repo ] );

                            sort( $hiddenRepos );

                            $this->settings->setSetting( 'hidden_repos', $hiddenRepos );
                        }
                    }
                    break;               
                case 'update_repo':
                    DEBUG_LOG( "...Magic AJAX request received" );
                    add_action( 'shutdown', array( $this, 'handleRefreshOnShutdown' ) );
                    break;
                case 'remove_image':
                    $image = $_POST[ 'image' ];

                    $response->image = $image;
                    $this->settings->setSetting( 'banner_image', false );
                    break;
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
                case 'ajax_refresh':
                        $this->settings->setSetting( 'repo_updating', true );

                        $stage = $_POST[ 'stage' ];
                        $response->done = 0;
                        $response->stage = $stage;
                        $response->result = '';
                        $response->pass = 0;

                        switch( $stage ) {
                            case 0:
                                $result = $this->doRefreshStage( 0 );
                                $response->result = $result;
                                break;
                            case 1:
                                $result = $this->doRefreshStage( 1 );
                                $response->result = $result;
                                break;
                            case 2:
                                $result = $this->doRefreshStage( 2 );
                                $response->result = $result;
                                break;
                            case 3:
                                $result = $this->doRefreshStage( 3 );
                                $response->result = $result;
                                break;
                            case 4:
                                $result = $this->doRefreshStage( 4 );
                                $response->result = $result;
                                break;
                            case 5:
                                $result = $this->doRefreshStage( 5 );
                                $response->result = $result;
                                break; 
                            case 6:
                                $result = $this->doRefreshStage( 6 );
                                $response->result = $result;
                                break;       
                            case 10:
                                $result = $this->doRefreshStage( 10 );
                                $response->result = $result;
                                break;
                            default:
                                $response->pass = 0;
                                break;
                        }  

                        if ( $response->done || $response->pass == 0 ) {
                            $this->settings->setSetting( 'repo_updating', false );
                        }
                    
                        break;
                 
            }
        }

        echo json_encode( $response );

        wp_die();
    }

    public function handleRefreshOnShutdown() {
        DEBUG_LOG( "...handling refresh on PHP shutdown hook" );

        $isRefreshing = $this->settings->getSetting( 'repo_updating' );
        $this->settings->setSetting( 'repo_updating', true );

        $result = $this->doRefreshStage( 0 );
        if ( $result->pass ) {
            $result = $this->doRefreshStage( 1 );
            if ( $result->pass ) {
                $result = $this->doRefreshStage( 2 );
                if ( $result->pass ) { 
                    $result = $this->doRefreshStage( 3 );
                    if ( $result->pass ) {
                        $result = $this->doRefreshStage( 4 );
                        if ( $result->pass ) {
                            $result = $this->doRefreshStage( 5 );
                            if ( $result->pass ) {
                                $result = $this->doRefreshStage( 6 );
                            }
                        }
                    }
                }
            }
        }

        $this->settings->setSetting( 'repo_updating', false );
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

    public function outputPrivateKey( $data ) {
        $data = new \stdClass;
        $data->error = 1;
        
        if ( $_SERVER[ 'REMOTE_ADDR' ] != $this->settings->getSetting( 'private_key_ip_addr' ) ) {
            $data->msg = _e( 'Invalid IP address', 'juniper' );
            return $data;
        }

        if ( isset( $_POST[ 'pw' ] ) ) {
            $hashedPassword = $this->settings->getSetting( 'hashed_password' );
            if ( sodium_crypto_pwhash_str_verify( $hashedPassword, $_POST[ 'pw' ] ) ) {
                // password is legit
                $passwordSalt = sodium_base642bin( $this->settings->getSetting( 'password_salt' ), SODIUM_BASE64_VARIANT_ORIGINAL );

                $hash = sodium_crypto_pwhash(
                    SODIUM_CRYPTO_SIGN_SEEDBYTES, 
                    $_POST[ 'pw' ], 
                    $passwordSalt, 
                    SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, 
                    SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE 
                );

                $key_pair = sodium_crypto_sign_seed_keypair( $hash );

                if ( $key_pair ) {
                    $data->error = 0;
                    $data->private_key = sodium_bin2base64( sodium_crypto_sign_secretkey( $key_pair ), SODIUM_BASE64_VARIANT_ORIGINAL );

                    return $data;
                }  
            }
        }   

        return $data;
    }

    public function outputPublicKey( $data ) {
        $data = new \stdClass;
        $data->public_key = '';
        
        $public_key = $this->settings->getSetting( 'public_key' );
        if ( $public_key ) {
            $data->version = '1.0';
            $data->public_key = $this->getPublicKey();
            $data->key_type = $this->settings->getSetting( 'key_type' );   
        }

        return $data;
    }

    public function getUserInfo() {
        $filteredUserInfo = new \stdClass;

        $userInfo = $this->settings->getSetting( 'user_info' );
        if ( $userInfo ) {
            $filteredUserInfo->login = $userInfo->login;
            $filteredUserInfo->avatarUrl = $userInfo->avatar_url;
            $filteredUserInfo->url = $userInfo->url;
            $filteredUserInfo->name = $userInfo->name;
            $filteredUserInfo->bio = $userInfo->bio;
            $filteredUserInfo->company = $userInfo->company;
            $filteredUserInfo->location = $userInfo->location;
            $filteredUserInfo->blog_url = $userInfo->blog;
            $filteredUserInfo->twitter_name = $userInfo->twitter_username;
        }

        return $filteredUserInfo;
    }

    public function outputReleases( $params ) {
        $result = new \stdClass;
        $result->client_version = JUNIPER_AUTHOR_VER;
        $result->user = $this->getUserInfo();
        $result->releases = $this->getRepositories();

        return $result;
    }   

    public function addDefaultBannerImageIfNeeded( $imageUrl, $size ) {
        if ( $imageUrl ) {
            return $imageUrl;
        }

        $bannerImage = false;
        if ( $size == 'small' ) {
            $bannerImage = $this->settings->getSetting( 'banner_image_small' );
        } else if ( $size == 'large' ) {
            $bannerImage = $this->settings->getSetting( 'banner_image' );
        }

        if ( $bannerImage ) {
            $imageUrl = $bannerImage;
        }
    
        return $bannerImage;
    }

    public function modifyReleasesWithSignedInfo( $repositories ) {
        foreach( $repositories as $oneRepo ) {
            // prime
            $oneRepo->totalReleaseDownloads = 0;

            $oneRepo->info->bannerImage = $this->addDefaultBannerImageIfNeeded( $oneRepo->info->bannerImageLarge, 'small' );
            $oneRepo->info->bannerImageLarge = $this->addDefaultBannerImageIfNeeded( $oneRepo->info->bannerImageLarge, 'large' );

            if ( !empty( $oneRepo->releases ) ) {
                $totalDownloads = 0;

                foreach( $oneRepo->releases as $oneRelease ) {
                    if ( !empty( $oneRelease->downloadUrl ) ) {
                        $downloadUrl = $oneRelease->downloadUrl;
                        $zipFile = basename( $downloadUrl );
                        $signatureFile = JUNIPER_AUTHOR_RELEASES_PATH . '/' . $oneRepo->repository->fullName . '/' . $oneRelease->tag . '/signature.json';

                        if ( file_exists( $signatureFile ) ) {
                            $oneRelease->signed = true;
                            $oneRelease->signedDate = filemtime( $signatureFile );
                            $oneRelease->signatureInfo = json_decode( file_get_contents( $signatureFile ) );          
                         } else {
                            $oneRelease->signed = false;
                        }

                        $oneRelease->downloadCountTotal = $oneRelease->downloadCount;
                        $totalDownloads += $oneRelease->downloadCountTotal;
                    }
                }

                $oneRepo->totalReleaseDownloads = $totalDownloads;
            }
        }

        return $repositories;
    }

    public function getRepositories() {
        $hiddenRepos = $this->settings->getSetting( 'hidden_repos' );
        
        $repos = apply_filters( 'juniper_repos', $this->settings->getSetting( 'repositories' ) );
        $filteredRepos = [];
        foreach( $repos as $name => $info ) { 
            if ( !in_array( $info->repository->fullName, $hiddenRepos ) ) {
                $filteredRepos[] = $info;
            }
        }

        return $filteredRepos;
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
        $result = new \stdClass;
        $result->client_version = JUNIPER_AUTHOR_VER;
        $result->user = $this->getUserInfo();
        $result->plugins = $this->getFilteredRepositories( 'plugin' );

        return $result;
    }

    public function outputThemes() {
        $result = new \stdClass;
        $result->client_version = JUNIPER_AUTHOR_VER;
        $result->user = $this->getUserInfo();
        $result->themes = $this->getFilteredRepositories( 'theme' );

        return $result;
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
            'juniper/v1', '/private_key/', 
            array(
                'methods' => 'POST',
                'callback' => array( $this, 'outputPrivateKey' ),
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

            if ( $currentPage == 'juniper-options' || $currentPage == 'juniper-repos' || $currentPage == 'juniper' || $currentPage == 'juniper-issues' ) {
                wp_enqueue_style( 'juniper-author', plugins_url( 'dist/juniper-author.css', JUNIPER_AUTHOR_MAIN_FILE ), false );
                wp_enqueue_script( 'juniper-author', plugins_url( 'dist/juniper-author.js', JUNIPER_AUTHOR_MAIN_FILE ), array( 'jquery' ) );

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

        $newRepoInfo->primaryBranch = $repoInfo->default_branch;

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

    public function parseRelevantReleaseInfo( $repo, $releases ) {
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
                
                $newRelease->downloadUpdatedTime = strtotime( $release->assets[ 0 ]->updated_at );
            } else {    
                $newRelease->downloadUrl = 'https://github.com/' . $repo->repository->fullName . '/archive/refs/tags/' . $newRelease->tag . '.zip';
                $newRelease->downloadUpdatedTime = strtotime( $release->published_at );
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

                $possiblePluginFile = 'https://raw.githubusercontent.com/' . $oneResult->full_name . '/refs/heads/' . $oneResult->default_branch . '/' . basename( $oneResult->full_name ) . '.php';
                if ( !$this->utils->curlRemoteFileExists( $possiblePluginFile ) ) {
                    continue;
                }

                $pluginFile = $this->utils->curlGitHubRequest( $possiblePluginFile );

                $wordPress = new WordPress( $this->utils );
                
                $pluginInfo = $wordPress->parseReadmeHeader( $pluginFile );

                $pluginInfo->bannerImage = '';
                $pluginInfo->bannerImageLarge = '';

                $testBannerImage = 'https://raw.githubusercontent.com/' . $oneResult->full_name . '/refs/heads/' . $oneResult->default_branch . '/assets/banner-1544x500.jpg';
                if ( $this->utils->curlRemoteFileExists( $testBannerImage ) ) {
                    $pluginInfo->bannerImageLarge = $testBannerImage;
                    $pluginInfo->bannerImage = $testBannerImage;
                }

                $pluginInfo->readme = '';
                $pluginInfo->readmeHtml = '';

                $readmeFile = 'https://raw.githubusercontent.com/' . $oneResult->full_name . '/refs/heads/' . $oneResult->default_branch . '/README.md';

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
                    } else if ( $_GET[ 'juniper_action' ] == 'submit' ) {
                        $mirrorUrl = $this->settings->getSetting( 'mirror_url' );

                        $this->utils->curlRequest( trailingslashit( $mirrorUrl ) . 'addsite/?url=' . get_bloginfo( 'home' ) );

                        header( 'Location: ' . admin_url( 'admin.php?page=juniper-repos' ) );
                        update_option( Settings::UPDATED_KEY, 2 );
                        die;
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
            '<a href="' . admin_url( 'admin.php?page=juniper-options' ) . '">' . esc_html__( 'Settings', 'wp-api-privacy' ) . '</a>'
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

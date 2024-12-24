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
        $this->settings = new Settings( $this );
        $this->utils = new Utils( $this->settings );

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
            'duanestorey',
            'juniper-author',
            JUNIPER_AUTHOR_VER,
            'main'
        );
    }

    public function init() {
        $this->settings->init();
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

            $repositories = $this->settings->getRepositories();
            
            foreach( $repositories as $oneRepo ) {
                if ( $oneRepo->repository->fullName == $repo ) {
                    foreach( $oneRepo->releases as $releaseInfo ) {
                        if ( $releaseInfo->tag == $tagName ) {
                            $releasePath = JUNIPER_AUTHOR_RELEASES_PATH . '/' . $repo . '/' . $releaseInfo->tag	;

                            if ( !file_exists( $releasePath ) ) {
                                @mkdir( $releasePath, 0755, true );
                       
                                if ( !empty( $releaseInfo->downloadUrl ) ) {
                                    $zipName = basename( $releaseInfo->downloadUrl );
                                    $destinationZipFile = $releasePath . '/' . $zipName;

                                    if ( !file_exists( $destinationZipFile ) ) {
                                        copy( $releaseInfo->downloadUrl, $destinationZipFile ); 

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
            }
        } catch ( \phpseclib3\Exception $e ) {
        
        }
    }

    public function verifyPackage( $package ) {
        $verifyResult = new \stdClass;
        $verifyResult->signature_valid = '0';
        $verifyResult->file_valid = '0';
        $verifyResult->package = $package;

        require_once( JUNIPER_AUTHOR_MAIN_DIR . '/vendor/autoload.php' );

        $public_key = PublicKeyLoader::loadPublicKey( $this->settings->getSetting( 'public_key' ) );
        $zip = new \ZipArchive();
        $result = $zip->open( JUNIPER_AUTHOR_RELEASES_PATH . '/' . $package, \ZipArchive::RDONLY );
        if ( $result === TRUE ) {
            $comment = $zip->getArchiveComment();
        
            if ( $comment ) {
                $comment = json_decode( $comment );

                $sigBin = base64_decode( $comment->signature );
                $hashBin = base64_decode( $comment->hash  );
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
                    $stage = $_POST[ 'stage' ];
                    $response->done = 0;

                    switch( $stage ) {
                        case 0:
                            // update repositories
                            $orgInfo = 'https://api.github.com/user/repos';
                            $result = $this->utils->curlGitHubRequest( $orgInfo );
                            if ( $result ) {
                                $decodedResult = json_decode( $result );
                                $this->settings->setSetting( 'ajax_repos', $decodedResult );
                                $response->msg = '...' . sprintf( __( 'Found %d respositories', 'juniper' ), count( $decodedResult ) );
                                $response->pass = 1;
                                $response->next_stage = 1;
                            } else {
                                $response->pass = 0;
                            }
                            break;
                        case 1:
                            $response->pass = 0;
                            $repos = $this->settings->getSetting( 'ajax_repos' );
                            if ( $repos ) {
                                $newRepos = [];
                                foreach( $repos as $oneRepo ) {
                                    // Skip private repos for now since we are using authenticated requests
                                    if ( $oneRepo->private ) {
                                        continue;
                                    }

                                    $possiblePluginFile = 'https://raw.githubusercontent.com/' . $oneRepo->full_name . '/refs/heads/' . $oneRepo->default_branch . '/' . basename( $oneRepo->full_name ) . '.php';
                                    if ( !$this->utils->curlRemoteFileExists( $possiblePluginFile ) ) {
                                        continue;
                                    }

                                    $newRepos[] = $oneRepo;
                                }

                                $response->msg = '...' . sprintf( __( 'Detected %d valid and non-private respositories for inclusion' ), count( $newRepos ) );
                                $response->pass = 1;
                                $response->next_stage = 2;

                                $this->settings->setSetting( 'ajax_repos', $newRepos );
                            }
                            break;
                        case 2:
                            $response->pass = 0;
                            $repos = $this->settings->getSetting( 'ajax_repos' );
                            if ( $repos ) {
                                $assembledData = [];

                                foreach( $repos as $oneRepo ) {
                                    // we already know it's valid
                                    $pluginFileName = 'https://raw.githubusercontent.com/' . $oneRepo->full_name . '/refs/heads/' . $oneRepo->default_branch . '/' . basename( $oneRepo->full_name ) . '.php';
                                    $pluginFile = $this->utils->curlGitHubRequest( $pluginFileName );

                                    $wordPress = new WordPress( $this->utils );
                                    
                                    $pluginInfo = $wordPress->parseReadmeHeader( $pluginFile );

                                    $pluginInfo->bannerImage = '';
                                    $pluginInfo->bannerImageLarge = '';

                                    $testBannerImage = 'https://raw.githubusercontent.com/' . $oneRepo->full_name . '/refs/heads/' . $oneRepo->default_branch . '/assets/banner-1544x500.jpg';
                                    if ( $this->utils->curlRemoteFileExists( $testBannerImage ) ) {
                                        $pluginInfo->bannerImageLarge = $testBannerImage;
                                        $pluginInfo->bannerImage = $testBannerImage;
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

                                $response->msg = '...' . sprintf( __( 'Plugin headers parsed and README.md data loaded', 'juniper' ) );
                                $response->pass = 1;
                                $response->next_stage = 3;

                                $this->settings->setSetting( 'ajax_update_data', $assembledData );
                            }
                            break;
                        case 3:
                            $response->pass = 0;
                            $repos = $this->settings->getSetting( 'ajax_update_data' );
                            if ( $repos ) {
                                foreach( $repos as $oneRepo ) {
                                    // get issues
                                    $issuesUrl = 'https://api.github.com/repos/' . $oneRepo->repository->fullName . '/issues?state=all';

                                    $oneRepo->issues = [];
                                    $issues = $this->utils->curlGitHubRequest( $issuesUrl );
                                    if ( $issues ) {
                                        $decodedIssues = json_decode( $issues );

                                        $oneRepo->issues = $this->parseRelevantIssueInfo( $decodedIssues );
                                    }
                                }

                                $this->settings->setSetting( 'ajax_update_data', $repos );

                                $response->msg = '...' . sprintf( __( 'All Github issues loaded', 'juniper' ) );
                                $response->pass = 1;
                                $response->next_stage = 4;
                            }
                            break;
                        case 4:
                            $response->pass = 0;
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
                                $response->pass = 1;
                                $response->next_stage = 0;
                                $response->done = 1;
                            }
                            break;
                        case 10:
                            // hack to start at a later stage
                            $repos = $this->settings->getSetting( 'repositories' );
                            $this->settings->setSetting( 'ajax_update_data', $repos );
                            $response->msg = '...' . sprintf( __( 'merging in previous data', 'juniper' ) );
                            $response->pass = 1;
                            $response->next_stage = 3;
                            $response->done = 0;
                            break;
                        default:
                            $response->pass = 0;

                            break;
                    }
                    
                    $response->stage = $stage;
                    $response->result = '';
                    break;
            }
        }

        echo json_encode( $response );

        wp_die();
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
        $result = new \stdClass;
        $result->client_version = JUNIPER_AUTHOR_VER;
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
                        $signedName = $oneRepo->repository->fullName . '/' . $oneRelease->tag . '/' . str_replace( '.zip', '.signed.zip', $zipFile );
                        $signedFile = JUNIPER_AUTHOR_RELEASES_PATH . '/' . $signedName;

                        if ( file_exists( $signedFile ) ) {
                            $oneRelease->signed = true;
                            $oneRelease->signedName = $signedName;
                            $oneRelease->downloadCountSigned = $this->getDownloadCountForFile( $signedFile );
                            $oneRelease->donwloadCountTotal = $oneRelease->downloadCount + $oneRelease->downloadCountSigned;
                            $oneRelease->downloadUrlSigned = admin_url( 'admin.php?page=juniper&download_package=1&repo=' . \urlencode( $oneRepo->repository->fullName ) . '&tag=' . $oneRelease->tag );
                        } else {
                            $oneRelease->donwloadCountTotal = $oneRelease->downloadCount;
                        }

                        $totalDownloads += $oneRelease->donwloadCountTotal;
                    }
                }

                $oneRepo->totalReleaseDownloads = $totalDownloads;
            }
        }

        return $repositories;
    }

    public function getRepositories() {
        return apply_filters( 'juniper_repos', $this->settings->getSetting( 'repositories' ) );
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
            } else {    
                $newRelease->downloadUrl = 'https://github.com/' . $repo->repository->fullName . '/archive/refs/tags/' . $newRelease->tag . '.zip';
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

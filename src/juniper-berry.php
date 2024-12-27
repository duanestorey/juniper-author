<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */
namespace DuaneStorey\Juniper;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

class JuniperBerry {
    private const CACHE_TIME = ( 60 * 15 ); // 15 minutes

    protected $cacheModifier = null;
    protected $cacheKey = null;
    
    protected $pluginSlug = null;
    protected $currentVersion = null;

    protected $updateInfo = null;

    public function __construct( $pluginSlug, $currentVersion ) {
        $this->pluginSlug = $pluginSlug;
        $this->currentVersion = $currentVersion;

        if ( $this->hasValidInfo() && current_user_can( 'update_plugins' ) ) {
            $this->setupTransientKeys();

            // check if the user has manually tried to check all updates at Home/Updates in the WP admin
            if ( is_admin() && strpos( $_SERVER[ 'REQUEST_URI' ], 'update-core.php?force-check=1' ) !== false ) {
                $this->deleteTransients();
            }

            add_filter( 'plugins_list', [ $this, 'filterPluginList' ] );
            add_action( 'admin_init', array( $this, 'checkForUpdate' ) );
            add_filter( 'plugins_api', [ $this, 'handlePluginInfo' ], 20, 3 );
            add_filter( 'site_transient_update_plugins', array( $this, 'handleUpdate' ) );
          //  add_filter( 'plugin_action_links_' . $this->pluginSlug, [ $this, 'addActionLinks' ] );
        }
    }


    public function addActionLinks( $actions ) {
        $links = array(
            '<a href="' . admin_url( 'admin.php?page=juniper-options' ) . '">' . esc_html__( 'Info', 'wp-api-privacy' ) . '</a>'
        );

        return array_merge( $links, $actions );
    }


    public function filterPluginList( $plugins ) {
        if ( !empty( $plugins[ 'all' ][ $this->pluginSlug ][ 'Name'] ) ) {
            $plugins[ 'all' ][ $this->pluginSlug ][ 'Name' ] = '<img src="https://www.google.com/url?q=https://pngtree.com/free-png-vectors/lock-icon&sa=U&ved=2ahUKEwjo05HOv8SKAxU7HjQIHa5_IhYQqoUBegQIDBAB&usg=AOvVaw0Ou_qBxE6juHaCcuSioPEw">' . $plugins[ 'all' ][ $this->pluginSlug ][ 'Name' ];
            $plugins[ 'all' ][ $this->pluginSlug ][ 'Name' ]  = $plugins[ 'all' ][ $this->pluginSlug ][ 'Name' ] . ' &#128274;';
        }

       return $plugins;
    }

    public function handlePluginInfo( $response, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $response;
        }

        // do nothing if it is not our plugin
        if ( empty( $args->slug ) || basename( $this->pluginSlug, '.php' ) !== $args->slug ) {
            return $response;
        }

        if ( !$this->updateInfo ) {
            $this->checkForUpdate();
        }

        $response = new \stdClass();

        $response->name           = $this->updateInfo->pluginInfo->pluginName . ' &#128274;';
        $response->slug           = $this->pluginSlug;
        $response->version        = $this->updateInfo->latestRelease->tag;
        $response->tested         = $this->updateInfo->pluginInfo->testedUpTo;
        $response->requires       = $this->updateInfo->pluginInfo->requiresAtLeast;
        $response->author         = $this->updateInfo->pluginInfo->author;
        $response->author_profile = $this->updateInfo->pluginInfo->authorUrl;
        $response->homepage       = 'https://github.com/' . $this->updateInfo->repository->fullName;

       // if ( !empty( $this->updateInfo->latestRelease->downloadUrlSigned ) ) {
        //    $response->download_link  = $this->updateInfo->latestRelease->downloadUrlSigned;
       // } else {
        $response->download_link  = $this->updateInfo->latestRelease->downloadUrl;
      //  }
        
        $response->requires_php   = $this->updateInfo->pluginInfo->requiresPHP;
        $response->last_updated   = date( 'M-d-y g:i', $this->updateInfo->latestRelease->publishedAt );

        $response->sections = [
            'description'  => $this->updateInfo->pluginInfo->description,
       //     'installation' => $remote->sections->installation,
            'changelog'    => $this->updateInfo->changeLog
        ];

        if ( ! empty( $this->updateInfo->pluginInfo->bannerImageLarge ) ) {
            $response->banners = [
                'high' => $this->updateInfo->pluginInfo->bannerImageLarge
            ];
        }

        return $response;        
    }

    public function handleUpdate( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        if ( !$this->updateInfo ) {
            $this->checkForUpdate();
        }

        if ( $this->updateInfo ) {
            $versionCompare = version_compare( $this->currentVersion, $this->updateInfo->pluginInfo->version, '<' );
            $wpCompare = !empty( $this->updateInfo->pluginInfo->requires ) ? version_compare( $this->updateInfo->pluginInfo->requires, get_bloginfo( 'version' ), '<=' ) : true;
            $phpCompare = !empty( $this->updateInfo->pluginInfo->requiresPhp ) ? version_compare( $this->updateInfo->pluginInfo->requiresPhp, PHP_VERSION, '<' )  : true;

            if (
                $versionCompare && 
                $wpCompare && 
                $phpCompare
            ) {
                $response = new \stdClass;

                $response->slug = basename( $this->pluginSlug, '.php' );
                $response->plugin = $this->pluginSlug;
                $response->new_version = $this->updateInfo->latestRelease->tag;
                $response->tested = $this->updateInfo->pluginInfo->testedUpTo;
                $response->package = $this->updateInfo->latestRelease->downloadUrl;

                $transient->response[ $response->plugin ] = $response;
            }
        }

        return $transient;
    }

    protected function hasValidInfo() {
        return ( $this->pluginSlug );
    }

    protected function setupTransientKeys() {
        $this->cacheModifier = md5( $this->pluginSlug );
        $this->cacheKey = 'juniper_' . $this->cacheModifier;
    }

    private function generateChangeLog( $releases ) {
        $changeLog = '';
        foreach( $releases as $oneRelease ) {

            $changeLog .= '<strong>' . $oneRelease->tag .  ' - ' . $oneRelease->name . '</strong>';
            $changeLog .= '<p>' . str_replace( "\r\n", "<br>", $oneRelease->body ) . '</p>';
        }

        return $changeLog;
    }

    public function checkForUpdate() {
        $this->updateInfo = get_transient( $this->cacheKey . '_info' );

        if ( !$this->updateInfo ) {
            $pluginFile = trailingslashit( WP_PLUGIN_DIR ) . $this->pluginSlug;
            if ( file_exists( $pluginFile ) ) {
                $pluginContents = file_get_contents( $pluginFile );

                if ( preg_match( '/Authority: (.*)/i', $pluginContents, $match ) ) {
                    $updateData = new \stdClass;
                    $updateData->authorityUrl = trailingslashit( $match[ 1 ] );
                    $updateData->pluginName = false;

                    if ( preg_match( '/Plugin Name: (.*)/i', $pluginContents, $match ) ) {
                        $updateData->pluginName = $match[1];
                    }

                    if ( $updateData->authorityUrl && $updateData->pluginName ) {        
                        $url = $updateData->authorityUrl . 'wp-json/juniper/v1/releases';
                        $result = wp_remote_get( $url, [ 'headers' => [ 'accept' => 'application/json' ] ]  );

                        if ( is_array( $result ) && ! is_wp_error( $result ) ) {
                            $body = $result['body']; 
                            if ( $body ) {
                                $decodedContent = json_decode( $body );
                                $foundContent = false;
                                foreach( $decodedContent->releases as $num => $repo ) {
                                    if ( $repo->info->pluginName == $updateData->pluginName ) {
                                    
                                        unset( $repo->info->readme );
                                        unset( $repo->info->readmeHtml );

                                        $updateData->repository = $repo->repository;
                                        $updateData->ownerInfo = $decodedContent->user;
                                        $updateData->pluginInfo = $repo->info;
                                        $updateData->changeLog = $this->generateChangelog( $repo->releases );
                                        $updateData->latestRelease = $repo->releases[ 0 ];

                                        set_transient( $this->cacheKey . '_info', $updateData, JuniperBerry::CACHE_TIME );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }    

    private function deleteTransients() {
        if ( $this->hasValidInfo() ) {
            delete_transient( $this->cacheKey );
        }
    }

    private function _getReleaseInfo() {
        return $this->getReleaseInfo( $this->githubTagApi );
    }

    public function getReleaseInfo( $releaseUrl ) {
        $cache_key = 'wp_juniper_releases_' . md5( $releaseUrl );
        //delete_transient( $cache_key );
        // Use the Github API to obtain release information
        $githubTagData = get_transient( $cache_key );
        if ( $githubTagData === false ) {
         
            $result = wp_remote_get( $releaseUrl );
            if ( !is_wp_error( $result ) ) {
                $githubTagData = json_decode( wp_remote_retrieve_body( $result ) );
               
                if ( $githubTagData !== false ) {
                    set_transient( $cache_key, $githubTagData, GitHubUpdater::CACHE_TIME );
                }
            }
        } 

        return $githubTagData;
    }
}
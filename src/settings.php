<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */

namespace NOTWPORG\JuniperAuthor;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Settings {
    // The WordPress settings key
    public const SETTING_KEY = "juniper_author_settings";
    public const UPDATED_KEY = "juniper_author_settings_updated";
    public const ERROR_KEY = "juniper_author_last_error";

    protected $settings = null;
    protected $settingsPages = [];
    protected $settingsSections = [];

    public function __construct() {
        $this->loadSettings();
    }
    
    public function init() {
        //delete_option( Settings::SETTING_KEY );
        if ( is_admin() ) {
            

            add_action( 'admin_menu', array( $this, 'setupSettingsPage' ) );

            $this->processSubmittedSettings();

             $this->settingsPages[ 'repos' ] = [];
             $this->addSettingsSection( 
                $this->settingsPages[ 'repos' ],
                'Add New', 
                __( 'Add New Repository', 'juniper' ),
                array(
                        $this->addSetting( 'text', 'new_repo_name', __( 'Enter Github Repository URL', 'juniper' ) ),
                )
            );

            $this->settingsPages[ 'options' ] = [];
            $this->addSettingsSection( 
                $this->settingsPages[ 'options' ],
                'Signing', 
                __( 'Code Signing', 'juniper' ),
                array(
                        $this->addSetting( 'textarea', 'private_key', __( 'Private Key', 'juniper' ) ),
                        $this->addSetting( 'textarea', 'public_key', __( 'Public Key', 'juniper' ) ),
                        $this->addSetting( 'txtrecord', 'public_key', __( 'Update TXT records' ) ),
                        $this->addSetting( 'checkbox', 'reset_key', __( 'Delete keys (this is destructive, for testing only)', 'juniper' ) ),
                )
            );
        }
    }

    public function doOptionsHeader() {
        if ( get_option( Settings::UPDATED_KEY, false ) ) {
            echo '<div class="notice notice-success settings-error is-dismissible"><p>' . esc_html( __( 'Your settings have been saved', 'wp-api-privacy' ) ) . '</p></div>';
            
            delete_option( Settings::UPDATED_KEY );
        }
    }

    public function getRepoName( $repoName ) {
        if ( !empty( $this->settings->repositories[ $repoName ] ) ) {
            return $this->settings->repositories[ $repoName ]->pluginName;
        } else {
            return false;
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

    public function processSubmittedSettings() {
        // These are our settings  
        if ( isset( $_POST[ 'juniper_author_settings' ] ) ) {
            $nonce = $_POST[ 'juniper_author_nonce' ];
            if ( wp_verify_nonce( $nonce, 'juniper' ) && current_user_can( 'manage_options' ) ) {
                // get a list of submitted settings that don't include our hidden fields
                $defaultSettings = $this->getDefaultSettings();
                foreach( $defaultSettings as $name => $dontNeed ) {
                    if ( isset( $_POST[ 'wpcheckbox_' . $name ] ) ) {
                        // this is a checkbox
                        if ( isset( $_POST[ 'wpsetting_' . $name ] ) ) {
                            $this->settings->$name = true;
                        } else {
                            $this->settings->$name = false;
                        }
                    } else {
                        if ( isset( $_POST[ 'wpsetting_' . $name ] ) ) {
                            $this->settings->$name = $_POST[ 'wpsetting_' . $name ];
                        }
                    }
                }

                // Settings are saved, show notification on next page
                update_option( Settings::UPDATED_KEY, 1, false );
                if ( isset( $this->settings->reset_key ) && $this->settings->reset_key ) {
                    $this->settings->private_key = null;
                    $this->settings->public_key = null;
                    $this->settings->reset_key = false;
                    $this->saveSettings();
                } else if ( !empty( $this->settings->new_repo_name ) ) {
                    $this->mayebAddRepo( $this->settings->new_repo_name );

                    $this->settings->new_repo_name = false;
                    $this->saveSettings();
                } else {
                    $this->saveSettings();
                } 
            }
        } else if ( isset( $_POST[ 'juniper_author_gen_keypair' ] ) ) {
            $nonce = $_POST[ 'juniper_author_nonce' ];
            if ( wp_verify_nonce( $nonce, 'juniper' ) && current_user_can( 'manage_options' ) ) {
                if ( $_POST[ 'juniper_private_pw_1' ] == $_POST[ 'juniper_private_pw_2' ] ) {
                    require_once( JUNIPER_AUTHOR_MAIN_DIR . '/vendor/autoload.php' );

                    //$private = RSA::createKey()->withPassword( $_POST[ 'juniper_private_pw_1' ] );
                    $private = EC::createKey('Ed25519')->withPassword( $_POST[ 'juniper_private_pw_1' ] );
                    $public = $private->getPublicKey();

                    $this->settings->private_key = $private->toString( 'PKCS8' );
                    $this->settings->key_type = 'ed25519';
                    $this->settings->public_key = $private->getPublicKey()->toString( 'PKCS8' );;

                    $this->saveSettings();
                }
            }
        }
    }

    public function curlExists( $url ) {
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        $retcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ( $retcode == 200 );
    }

    public function curlGet( $url ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt ($ch,CURLOPT_TIMEOUT, 10000);

        $headers[] = 'Authorization: Bearer ';
        $headers[] = 'X-GitHub-Api-Version: 2022-11-28';

        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'Juniper/Author' );

        $response = curl_exec( $ch );

        return $response;
    }

    protected function getRepoInfo( $repoUrl ) {
        if ( strpos( $repoUrl, 'https://github.com') !== false ) {
            // github URL
            $phpFile = basename( $repoUrl ) . '.php';

            $parsed = parse_url( $repoUrl );
            $path = str_replace( '.git', '', $parsed[ 'path' ] );
            $url = 'https://raw.githubusercontent.com/' . $path . '/refs/heads/main/' . $phpFile;
            $readmeUrl = 'https://raw.githubusercontent.com/' . $path . '/refs/heads/main/README.md';

            $contents = file_get_contents( $url );
            if ( $contents ) {
                $headers = [];
                if ( preg_match_all( '/(.*): (.*)/', $contents, $matches ) ) {
                    foreach( $matches[ 1 ] as $key => $value ) {
                        $headers[ strtolower( trim( str_replace( ' * ', '', $value ) ) ) ] = trim( $matches[ 2 ][ $key ] );
                    }
                    
                    $repoInfo = new \stdClass;
                    $repoInfo->type = 'plugin';
                    $repoInfo->bannerImage = '';
                    $repoInfo->bannerImageLarge = '';
                    $repoInfo->requiresPHP = '';
                    $repoInfo->authorUrl = '';
                    $repoInfo->testedUpTo = '';
                    $repoInfo->signingAuthority = '';
                    $repoInfo->requiresAtLeast = '';
                    $repoInfo->readme = file_get_contents( $readmeUrl );
                    $repoInfo->readmeHtml = '';

                    if ( $repoInfo->readme ) {
                        require_once( JUNIPER_AUTHOR_PATH . '/vendor/autoload.php' );

                        $parsedown = new \Parsedown();
                        $repoInfo->readmeHtml = $parsedown->text( $repoInfo->readme );
                    }

                    $testBannerImage = 'https://raw.githubusercontent.com' . $path . '/refs/heads/main/assets/banner-1544x500.jpg';
                    //      https://github.com/wp-privacy/wp-api-privacy/raw/refs/heads/main/assets/banner-772x250.jpg
                    //      https://raw.githubusercontent.com/wp-privacy/wp-api-privacy/refs/heads/main/assets/banner-772x250.jpg

                    $mapping = array(
                        'plugin name' => 'pluginName',
                        'stable' => 'stableVersion',
                        'version' => 'version',
                        'description' => 'description',
                        'author' => 'author',
                        'author uri' => 'authorUrl',
                        'requires php' => 'requiresPHP',
                        'requires at least' => 'requiresAtLeast',
                        'tested up to' => 'testedUpTo',
                        'signing authority' => 'signingAuthority'
                    );

                    foreach( $mapping as $key => $value ) {
                        if ( isset( $headers[ $key ] ) ) {
                            $repoInfo->$value = $headers[ $key ];
                        }
                    } 

                    if ( $this->curlExists( $testBannerImage ) ) {
                        $repoInfo->bannerImage = $testBannerImage;
                    }

                    if ( empty( $repoInfo->stableVersion ) ) {
                        $repoInfo->stableVersion = $repoInfo->version;
                    }

                    $issuesUrl = str_replace( 'https://github.com/', 'https://api.github.com/repos/', $repoUrl . '/issues' );

                    $repoInfo->issues = [];
                    $issues = $this->curlGet( $issuesUrl );
                    if ( $issues ) {
                        $decodedIssues = json_decode( $issues );

                        $repoInfo->issues = $decodedIssues;
                    }

                    $repoInfoUrl = str_replace( 'https://github.com/', 'https://api.github.com/repos/', $repoUrl );

                    $repoInfo->repoInfo = [];
                    $repositoryData = $this->curlGet( $repoInfoUrl );

                    if ( $repositoryData ) {
                        $decodedRepositoryData = json_decode( $repositoryData );

                        $repoInfo->repoInfo = $decodedRepositoryData;
                    }


                    return $repoInfo;
                }
            }
        }

        return false;
    }   

    public function mayebAddRepo( $repoUrl ) {
        $repoInfo = $this->getRepoInfo( $repoUrl );

        if ( $repoInfo ) {
                $this->settings->repositories[ $repoUrl ] = $repoInfo;
        }
    }

    public function saveSettings() {
        update_option( Settings::SETTING_KEY, $this->settings, false );
    }

    public function loadSettings() {
        $settings = get_option( Settings::SETTING_KEY );
        if ( $settings ) {
            $defaults = $this->getDefaultSettings();

            // merge in defaults to ensure new settings are added to old
            foreach( $defaults as $key => $value ) {
                if ( !isset( $settings->$key ) ) {
                    $settings->$key = $defaults->$key;
                }
            }

            // removing deprecated settings
            foreach( $settings as $key => $value ) {
                if ( !isset( $defaults->$key ) ) {
                    unset( $settings->$key );
                }
            }

            // update merged settings
            $this->settings = $settings;
        } else {
            $this->settings = $this->getDefaultSettings();
        }
    }

    public function addSettingsSection( &$page, $section, $desc, $settings ) {
       $page[ $section ] = [ $desc, $settings  ];
    }

    public function addSetting( $settingType, $settingName, $settingDesc ) {
        $setting = new \stdClass;
        $setting->type = $settingType;
        $setting->name = $settingName;
        $setting->desc = $settingDesc;

        return $setting;
    }

    public function getSetting( $name ) {
        return $this->settings->$name;
    }

    public function setSetting( $name, $value ) {
        $this->settings->$name = $value;
    }

    public function renderOneSetting( $setting ) {
        echo '<div class="setting ' . $setting->type . '">';
        switch( $setting->type ) {
            case 'checkbox':
                $checked = ( $this->getSetting( $setting->name ) ? ' checked' : '' );
                echo '<label for="wpsetting_' . esc_attr( $setting->name ) . '">';
                echo '<input type="checkbox" name="wpsetting_' . esc_attr( $setting->name ) . '" ' . $checked . '/> ';
                echo '<input type="hidden" name="wpcheckbox_' . esc_attr( $setting->name ) . '" value="1" />';
                echo esc_html( $setting->desc ) . '</label>';
                echo "<br>";
                break;
            case 'textarea':
                $currentSetting = $this->getSetting( $setting->name );
                echo '<label for="wpsetting_' . esc_attr( $setting->name ) . '"><strong>' . esc_html( $setting->desc ) . '</strong></label><br/>';
                echo '<textarea rows="6" name="wpsetting_' . esc_attr( $setting->name ) . '" readonly>';
                echo esc_html( $currentSetting );
                echo '</textarea>';
                break;
            case 'text':
                $currentSetting = $this->getSetting( $setting->name );
                echo '<input type="text" name="wpsetting_' . esc_attr( $setting->name ) . '" value="' . esc_attr( $currentSetting ) . '" />';
                echo '<label for="wpsetting_' . esc_attr( $setting->name ) . '">' . esc_html( $setting->desc ) . '</label><br/>';
                break;
            case 'select':
                echo '<select name="wpsetting_'. esc_attr( $setting->name ) . '">';
                $currentSetting = $this->getSetting( $setting->name );
                foreach( $setting->desc as $key => $value ) {
                    $selected = ( $currentSetting == $key ) ? ' selected' : '';
                    echo '<option value="' . esc_attr( $key ) . '"' . $selected . '>' . esc_html( $value ) . '</option>';
                }
                echo '</select><br>';
                break;
            case 'txtrecord':
                $currentSetting = $this->getSetting( $setting->name );
                echo '<p>' . __( 'Please ensure you set a DNS record for the URL of your signing-authority URL of type TXT for <strong>_wpsign</strong>, using your entire public key (not private) as the value', 'juniper' ) . '</p>';
                break;
        }
        echo '</div>';
    }

    public function getDefaultSettings() {
        $settings = new \stdClass;

        // Adding default settings
        $settings->private_key = '';
        $settings->public_key = '';
        $settings->key_type = false;

        $settings->reset_key = false;
        $settings->new_repo_name = false;

        $settings->repositories = [];

        $settings->next_release_time = 0;
        $settings->releases = [];

        return $settings;
    }

    public function renderReleasesPage() {
        require_once( JUNIPER_AUTHOR_MAIN_DIR . '/templates/releases.php' );
    }

    public function renderReposPage() {
        require_once( JUNIPER_AUTHOR_MAIN_DIR . '/templates/repos.php' );
    }

    public function renderOptionsPage() {
        require_once( JUNIPER_AUTHOR_MAIN_DIR . '/templates/options-page.php' );
    }

    public function setupSettingsPage() {
    
        add_menu_page( 
            'Juniper',
            'Juniper',
            'manage_options',
            'juniper',
            array( $this, 'renderReleasesPage' ),
            'dashicons-update',
            60
        );

        add_submenu_page(
            'juniper',
            __( 'Manage Releases', 'juniper' ),
            __( 'Manage Releases', 'juniper' ),
            'manage_options',
            'juniper',
            array( $this, 'renderReleasesPage' )
        );  

        
        add_submenu_page(
            'juniper',
            __( 'Repositories', 'juniper' ),
            __( 'Repositories', 'juniper' ),
            'manage_options',
            'juniper-repos',
            array( $this, 'renderReposPage' )
        );    

        add_submenu_page(
            'juniper',
            __( 'Authorship Options', 'juniper' ),
            __( 'Authorship Options', 'juniper' ),
            'manage_options',
            'juniper-options',
            array( $this, 'renderOptionsPage' )
        );   


    }

    static function deleteAllOptions() {
        delete_option( NOTWPORG\Juniper\Settings::SETTING_KEY );
        delete_option( NOTWPORG\Juniper\Settings::UPDATED_KEY );
        delete_option( NOTWPORG\Juniper\Settings::ERROR_KEY );   
    }
}
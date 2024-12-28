<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */

namespace DuaneStorey\JuniperAuthor;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Settings {
    var $main = null;

    // The WordPress settings key
    public const SETTING_KEY = "juniper_author_settings";
    public const UPDATED_KEY = "juniper_author_settings_updated";
    public const ERROR_KEY = "juniper_author_last_error";

    protected $settings = null;
    protected $settingsPages = [];
    protected $settingsSections = [];

    public function __construct( $main ) {
        $this->main = $main;

        $this->loadSettings();
    }

    public function getRepositories() {
        return $this->main->getRepositories();
    }
    
    public function init() {
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'setupSettingsPage' ) );

            $this->processSubmittedSettings();

            $this->settingsPages[ 'repos' ] = [];

            $this->settingsPages[ 'options' ] = [];
            $this->addSettingsSection( 
                $this->settingsPages[ 'options' ],
                'Signing', 
                __( 'Code Signing', 'juniper' ),
                array(
                        $this->addSetting( 'textarea', 'private_key', __( 'Private Key', 'juniper' ) ),
                        $this->addSetting( 'textarea', 'public_key', __( 'Public Key', 'juniper' ) ),
                        $this->addSetting( 'text', 'hash_salt', __( 'Hash Salt', 'juniper' ) ),
                        $this->addSetting( 'text', 'key_nonce', __( 'Key Nonce', 'juniper' ) ),
                        $this->addSetting( 'checkbox', 'reset_key', __( 'Delete keys (this is destructive, for testing only)', 'juniper' ) ),
                )
            );

            $this->addSettingsSection( 
                $this->settingsPages[ 'options' ],
                'Options', 
                __( 'Options', 'juniper' ),
                array(
                    $this->addSetting( 'text', 'github_token', __( 'Github Token', 'juniper' ) ),
                    $this->addSetting( 'text', 'mirror_url', __( 'Juniper/Server URL', 'juniper' ) ),
                    $this->addSetting( 'checkbox', 'debug_file_enabled', __( 'Enable writing a debug file', 'juniper' ) ),
                )
            );

            $this->addSettingsSection( 
                $this->settingsPages[ 'options' ],
                'Repository', 
                __( 'Repository', 'juniper' ),
                array(
                    /* translators: this is the width and height of an image */
                    $this->addSetting( 'image', 'banner_image', sprintf( __( 'Default repository banner image (ideally %1$dx%2$d, but it will be resized automatically)', 'juniper' ), JUNIPER_AUTHOR_BANNER_WIDTH, JUNIPER_AUTHOR_BANNER_HEIGHT ) )
                )
            );           

            $this->addSettingsSection( 
                $this->settingsPages[ 'options' ],
                'Nuclear', 
                __( 'Scorched Earth Nuclear', 'juniper' ),
                array(
                    $this->addSetting( 'checkbox', 'reset_settings', __( 'Delete all settings and restore defaults (this is destructive, use with care)', 'juniper' ) ),
                )
            );
        }
    }

    public function doOptionsHeader() {
        $optionsHeader = get_option( Settings::UPDATED_KEY, false );
        if ( $optionsHeader ) {
            echo '<div class="notice notice-success settings-error is-dismissible"><p>';
            switch( $optionsHeader ) {
                case 1:
                    esc_html_e( __( 'Your settings have been saved', 'wp-api-privacy' ) );
                    break;
                case 2:
                    esc_html_e( __( 'Your repositories have been submitted to the specified mirror for inclusion', 'wp-api-privacy' ) );
                    break;
            }
            
            echo '</p></div>';
            
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
                    } if ( isset( $_FILES ) && !empty( $_FILES[ 'wpsetting_' . $name ] ) && isset( $_FILES[ 'wpsetting_' . $name ][ 'name' ] ) && !empty( $_FILES[ 'wpsetting_' . $name ][ 'name' ] ) ) {
                        $fileType = $_FILES[ 'wpsetting_' . $name ][ 'type' ];
                        if ( $fileType = 'image/jpeg' || $fileType == 'image/jpg' ) {
                            $fileName = $_FILES[ 'wpsetting_' . $name ][ 'name' ];
                           
                            $fileTempName = $_FILES[ 'wpsetting_' . $name ][ 'tmp_name' ];

                            $uploadInfo = wp_upload_dir();
                            $destinationLocation = $uploadInfo['basedir'] . '/juniper';
                            $destinationUrl = $uploadInfo['baseurl'] . '/juniper';
                            @mkdir( $destinationLocation, 0775, true );

                            $destinationFileName = $destinationLocation . '/banner-image.jpg';
                            $destinationFileNameSmall = $destinationLocation . '/banner-image-small.jpg';
                            @unlink( $destinationFileName );
                            @unlink( $destinationFileNameSmall );
               
                            $doCopy = false;
                            if ( function_exists( 'imagecreatefromjpeg' ) ) {
                                $imageSize = getimagesize( $fileTempName );
                                if ( $imageSize ) {
                                    if ( $imageSize[0] != JUNIPER_AUTHOR_BANNER_WIDTH && $imageSize[1] != JUNIPER_AUTHOR_BANNER_HEIGHT ) {
                                        $srcImage = imagecreatefromjpeg( $fileTempName );
                                        $destImage = imagecreatetruecolor( JUNIPER_AUTHOR_BANNER_WIDTH, JUNIPER_AUTHOR_BANNER_HEIGHT );
                                        $destImageSmall = imagecreatetruecolor( JUNIPER_AUTHOR_BANNER_WIDTH/2, JUNIPER_AUTHOR_BANNER_HEIGHT/2 );
                                        $origRatio = JUNIPER_AUTHOR_BANNER_WIDTH / JUNIPER_AUTHOR_BANNER_HEIGHT;
                                        $thisRatio = $imageSize[0]/$imageSize[1];

                                        if ( $thisRatio >= $origRatio ) {
                                            // this is wider than expected, so we have to crop it horizontally
                                            $newWidth = $imageSize[1]*$origRatio;
                                            $widthCrop = ( $imageSize[0] - $newWidth ) / 2;

                                            $startX = 0 + $widthCrop;
                                            $startY = 0;
                                            $height = $imageSize[1];
                                            $width = $newWidth;
                                        } else {
                                            // this is taller than expected, so we have to crop it vertically
                                            $newHeight = $imageSize[0]/$origRatio;
                                            $heightCrop = ( $imageSize[1] - $newHeight ) / 2;

                                            $startX = 0;
                                            $startY = 0 + $heightCrop;
                                            $height = $newHeight;
                                            $width = $imageSize[0];

                                            imagecopyresampled( $destImage, $srcImage, 0, 0, $startX, $startY, JUNIPER_AUTHOR_BANNER_WIDTH, JUNIPER_AUTHOR_BANNER_HEIGHT, $width, $height );
                                            imagecopyresampled( $destImageSmall, $destImage, 0, 0, 0, 0, JUNIPER_AUTHOR_BANNER_WIDTH/2, JUNIPER_AUTHOR_BANNER_HEIGHT/2, JUNIPER_AUTHOR_BANNER_WIDTH, JUNIPER_AUTHOR_BANNER_HEIGHT );
                                        }

                                        imagejpeg( $destImage, $destinationFileName, 90 );
                                        imagejpeg( $destImageSmall, $destinationFileNameSmall, 90 );
                                        $doCopy = false;
                                    }
                                }
                            } 

                            if ( $doCopy ) {
                                rename( $fileTempName, $destinationFileName );
                            }
                            
                            // add cache busting since we are using the same filename
                            $this->settings->$name = $destinationUrl . '/banner-image.jpg?cache=' . time();    
                            $this->settings->{$name . '_small'} = $destinationUrl . '/banner-image-small.jpg?cache=' . time();                    
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
                    $this->settings->hash_salt = false;
                    $this->settings->key_nonce = false;
                    $this->saveSettings();
                } else if ( $this->settings->reset_settings ) {
                    $this->deleteAllOptions();
                    $this->settings = null;
                    $this->loadSettings();
                } else {
                    $this->saveSettings();
                } 
            }
        } else if ( isset( $_POST[ 'juniper_author_gen_keypair' ] ) ) {
            $nonce = $_POST[ 'juniper_author_nonce' ];
            if ( wp_verify_nonce( $nonce, 'juniper' ) && current_user_can( 'manage_options' ) ) {
                if ( $_POST[ 'juniper_private_pw_1' ] == $_POST[ 'juniper_private_pw_2' ] ) {
                    $salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES  );
                    $hash = sodium_crypto_pwhash( 32, $_POST[ 'juniper_private_pw_1' ], $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE );

                    $key_pair = sodium_crypto_sign_keypair();
                    if ( $key_pair ) {
                        $private_key = sodium_crypto_sign_secretkey( $key_pair );

                        $nonce = random_bytes( 24 );
                        $encrypted = sodium_crypto_secretbox( $private_key, $nonce, $hash );

                        $this->settings->hash_salt = sodium_bin2base64( $salt, SODIUM_BASE64_VARIANT_ORIGINAL  );
                        $this->settings->key_nonce = sodium_bin2base64( $nonce, SODIUM_BASE64_VARIANT_ORIGINAL  );

                        // This is encrypted with a hash based on the entered password
                        $this->settings->private_key = sodium_bin2base64( $encrypted, SODIUM_BASE64_VARIANT_ORIGINAL );
                        $this->settings->key_type = 'sodium';
                        $this->settings->public_key = sodium_bin2base64( sodium_crypto_sign_publickey( $key_pair ), SODIUM_BASE64_VARIANT_ORIGINAL );

                        $decrypted = sodium_crypto_secretbox_open( 
                            sodium_base642bin( $this->settings->private_key, SODIUM_BASE64_VARIANT_ORIGINAL ), 
                            sodium_base642bin( $this->settings->key_nonce, SODIUM_BASE64_VARIANT_ORIGINAL ), 
                            $hash
                        );

                        // Our test decryption failed
                        if ( $decrypted != $private_key ) {
                            $this->settings->hash_salt = false;
                            $this->settings->key_nonce = false;
                            $this->settings->private_key = false;
                            $this->settings->key_type = false;
                            $this->settings->public_key = false;  
                        }

                        sodium_memzero( $hash );
                        sodium_memzero( $nonce );
                        sodium_memzero( $private_key );
                    }

        
                    $this->saveSettings();
                }
            }
        }
    }

    public function getAllSettings() {
        return $this->settings;
    }

    public function saveSettings() {
        update_option( Settings::SETTING_KEY, $this->settings, false );
    }

    public function loadSettings() {
        $settings = get_option( Settings::SETTING_KEY, false );
        if ( $settings !== false ) {
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
        $this->saveSettings();
    }

    public function renderOneSetting( $setting ) {
        echo '<div class="setting ' . $setting->type . '">';
        switch( $setting->type ) {
            case 'image':
                $currentSetting = $this->getSetting( $setting->name );
               
                if ( $currentSetting ) { 
                    echo '<label for="wpsetting_' . esc_attr( $setting->name ) . '">' . esc_html( $setting->desc ) . '</label>';
                    echo '<br/>';
                    echo '<img class="image" src="' . $currentSetting . '" />';
                    echo '<a href="#" class="remove" data-image="' . esc_attr( $setting->name ) . '">' . __( 'Remove', 'juniper' ) . '</a>';
                } else {
                    echo '<label for="wpsetting_' . esc_attr( $setting->name ) . '">' . esc_html( $setting->desc ) . '</label><br/>';
                    echo '<input type="file" accept="image/jpeg, image/jpg" name="wpsetting_' . esc_attr( $setting->name ) . '" >';
                }
                break;
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
                echo '<textarea rows="3" name="wpsetting_' . esc_attr( $setting->name ) . '" readonly>';
                echo esc_html( $currentSetting );
                echo '</textarea>';
                break;
            case 'password':
                $currentSetting = $this->getSetting( $setting->name );
                echo '<input type="password" name="wpsetting_' . esc_attr( $setting->name ) . '" value="' . esc_attr( $currentSetting ) . '" /> ';
                echo '<label for="wpsetting_' . esc_attr( $setting->name ) . '">' . esc_html( $setting->desc ) . '</label><br/>';
                break;
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
        $settings->hash_salt = '';
        $settings->key_nonce = '';

        $settings->key_type = false;

        $settings->reset_key = false;
        $settings->new_repo_name = false;

        $settings->repositories = [];
        $settings->hidden_repos = [];
        $settings->user_info = [];

        $settings->github_token = false;

        $settings->reset_settings = false;

        $settings->next_release_time = 0;
        $settings->releases = [];
        $settings->downloads = [];

        $settings->mirror_url = 'https://notwp.org';

        $settings->banner_image = false;
        $settings->banner_image_small = false;

        $settings->debug_file_enabled = 0;
        $settings->last_repo_update_time = time();

        // for ajax updates
        $settings->ajax_repos = [];
        $settings->ajax_update_data = [];
        $settings->repo_updating = false;
        $settings->ajax_stage = 0;

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

    public function renderIssuesPage() {
        require_once( JUNIPER_AUTHOR_MAIN_DIR . '/templates/issues.php' );
    }

    public function getOpenIssues() {
        $repos = $this->getSetting( 'repositories' );
        $openIssues = [];

        foreach( $repos as $oneRepo ) {
            if ( !empty( $oneRepo->issues ) && count( $oneRepo->issues ) ) {
                foreach( $oneRepo->issues as $oneIssue ) {
                    if ( $oneIssue->state == 'open' ) {
                        $oneIssue->repoInfo = $oneRepo->repository;
                        $openIssues[] = $oneIssue;
                    }
                }
            }
        }

        return $openIssues;
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

        $openIssues = count( $this->getOpenIssues() );
        if ( $openIssues ) {
            /* translators: This is a string representing the number of open issues */
            $issueTitle = sprintf(__( '%s Manage Issues', 'juniper' ), '<span class="awaiting-mod"> ' . count( $this->getOpenIssues() ) . '</span>' );
        } else {
            $issueTitle = __( 'Manage Issues', 'juniper' );
        }

        add_submenu_page(
            'juniper',
            __( 'Open Issues', 'juniper' ),
            $issueTitle,
            'manage_options',
            'juniper-issues',
            array( $this, 'renderIssuesPage' )
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
        delete_option( Settings::SETTING_KEY );
        delete_option( Settings::UPDATED_KEY );
        delete_option( Settings::ERROR_KEY );   
    }
}
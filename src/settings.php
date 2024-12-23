<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */

namespace DuaneStorey\JuniperAuthor;

use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;

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
                        $this->addSetting( 'checkbox', 'reset_key', __( 'Delete keys (this is destructive, for testing only)', 'juniper' ) ),
                )
            );

            $this->addSettingsSection( 
                $this->settingsPages[ 'options' ],
                'Options', 
                __( 'Options', 'juniper' ),
                array(
                    $this->addSetting( 'text', 'github_token', __( 'Github Token', 'juniper' ) ),
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
                } else if ( $this->settings->reset_settings ) {
                    $this->deleteAllOptions();
                    $this->settings = false;
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
        $settings->key_type = false;

        $settings->reset_key = false;
        $settings->new_repo_name = false;

        $settings->repositories = [];
        $settings->github_token = false;

        $settings->reset_settings = false;

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
        delete_option( Settings::SETTING_KEY );
        delete_option( Settings::UPDATED_KEY );
        delete_option( Settings::ERROR_KEY );   
    }
}
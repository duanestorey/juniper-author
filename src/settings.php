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
        if ( is_admin() ) {
            add_action( 'admin_menu', array( $this, 'setupSettingsPage' ) );

            $this->processSubmittedSettings();

            $this->settingsPages[ 'options' ] = [];
            $this->addSettingsSection( 
                $this->settingsPages[ 'options' ],
                'Signing', 
                __( 'Signing', 'juniper' ),
                array(
                        $this->addSetting( 'textarea', 'private_key', __( 'Private key for signing', 'juniper' ) ),
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

    public function processSubmittedSettings() {
        // These are our settings  
        if ( isset( $_POST[ 'wp_api_privacy_settings' ] ) ) {
            $nonce = $_POST[ 'wp_api_privacy_nonce' ];
            if ( wp_verify_nonce( $nonce, 'wpprivacy' ) && current_user_can( 'manage_options' ) ) {
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
                if ( isset( $this->settings->reset_settings ) && $this->settings->reset_settings ) {
                    delete_option( Settings::SETTING_KEY );
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
                    $config =   array(
                        "private_key_bits" => 2048,
                        "private_key_type" => OPENSSL_KEYTYPE_RSA,
                    );

                    $key = openssl_pkey_new( $config ) ;
                    if ( $key ) {
                        $details = openssl_pkey_get_details( $key) ;

                        openssl_pkey_export( $key, $str, $_POST[ 'juniper_private_pw_1' ] );

                        $this->settings->private_key = $str;
                        $this->saveSettings();
                    }               
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
                echo '<label for="wpsetting_' . esc_attr( $setting->name ) . '">' . esc_html( $setting->desc ) . '</label><br/>';
                echo '<textarea rows="10" name="wpsetting_' . esc_attr( $setting->name ) . '">';
                echo esc_html( $currentSetting );
                echo '</textarea>';
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
        }
    }

    public function getDefaultSettings() {
        $settings = new \stdClass;

        // Adding default settings
        $settings->private_key = '';
        $settings->public_key = '';

        return $settings;
    }

    public function renderReleasesPage() {
        require_once( JUNIPER_AUTHOR_MAIN_DIR . '/templates/releases.php' );
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
            'dashicons-update'
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
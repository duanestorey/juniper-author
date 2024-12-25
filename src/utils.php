<?php

namespace DuaneStorey\JuniperAuthor;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Utils {
    var $settings = false;

    public function __construct( $settings = false ) {
        $this->settings = $settings;
    }

    const USER_AGENT = 'Juniper/Author';

    public function curlGitHubRequest( $url ) {
        $headers = [];

        $headers[] = 'Authorization: Bearer ' . $this->settings->getSetting( 'github_token' );
        $headers[] = 'X-GitHub-Api-Version: 2022-11-28';  

        return $this->curlRequest( $url, $headers );
    }

    public function curlPostRequest( $url, $postFields = [], $headers = [] ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_POST, true );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $postFields );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 10000 );

        if ( $headers ) {
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        }
        
        curl_setopt( $ch, CURLOPT_USERAGENT, Utils::USER_AGENT );
        $response = curl_exec( $ch );
        
        return $response;
    }
  
    public function curlRequest( $url, $headers = false ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 10000 );

        if ( $headers ) {
            curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        }
        
        curl_setopt( $ch, CURLOPT_USERAGENT, Utils::USER_AGENT );
        $response = curl_exec( $ch );
        
        return $response;
    }

    public function curlRemoteFileExists( $url ) {
        $ch = \curl_init($url);
        
        \curl_setopt( $ch, CURLOPT_NOBODY, true );
        \curl_setopt( $ch, CURLOPT_TIMEOUT, 2500 );
        \curl_setopt( $ch, CURLOPT_USERAGENT, Utils::USER_AGENT );
        \curl_exec( $ch );

        $retcode = \curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        \curl_close( $ch );

        return ( $retcode == 200 );
    }
}
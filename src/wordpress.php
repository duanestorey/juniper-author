<?php

namespace DuaneStorey\JuniperAuthor;

// Prevent direct access
if ( ! defined( 'WPINC' ) ) {
    die;
}

class WordPress {
    protected $utils = null;

    public function __construct( $utils ) {
        $this->utils = $utils;
    }

    function parseReadmeHeader( $readmeContents ) {
        $headers = [];
        $repoInfo = false;

        if ( preg_match_all( '/(.*): (.*)/', $readmeContents, $matches ) ) {
            $repoInfo = new \stdClass;

            foreach( $matches[ 1 ] as $key => $value ) {
                $headers[ strtolower( trim( str_replace( ' * ', '', $value ) ) ) ] = trim( $matches[ 2 ][ $key ] );
            }

            if ( isset( $headers[ 'theme name' ] ) ) {
                $repoInfo->type = 'theme';
            } else if ( isset( $headers[ 'plugin name' ] ) ) {
                $repoInfo->type = 'plugin';
            } else {
                return false;
            }

            $repoInfo->requiresPHP = '';
            $repoInfo->authorUrl = '';
            $repoInfo->testedUpTo = '';
            $repoInfo->signingAuthority = '';
            $repoInfo->requiresAtLeast = '';

            $mapping = array(
                'plugin name' => 'pluginName',
                'theme name' => 'themeName',
                'stable' => 'stableVersion',
                'version' => 'version',
                'description' => 'description',
                'author' => 'author',
                'author uri' => 'authorUrl',
                'requires php' => 'requiresPHP',
                'requires at least' => 'requiresAtLeast',
                'tested up to' => 'testedUpTo',
                'authority' => 'signingAuthority'
            );

            foreach( $mapping as $key => $value ) {
                if ( isset( $headers[ $key ] ) ) {
                    $repoInfo->$value = $headers[ $key ];
                }
            } 

            if ( empty( $repoInfo->stableVersion ) ) {
                $repoInfo->stableVersion = $repoInfo->version;
            }
        }

        return $repoInfo;  
    }
}
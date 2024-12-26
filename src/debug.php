<?php
/* 
    Copyright (C) 2024 by Duane Storey - All Rights Reserved
    You may use, distribute and modify this code under the
    terms of the GPLv3 license.
 */

namespace DuaneStorey\JuniperAuthor;

class DebugLog {
    var $enable = false;
    var $fileHandle = null;
    var $debugDir = null;
    var $debugFilename = null;

    private static $instance = null;

    protected function __construct() {
        $this->debugDir = JUNIPER_AUTHOR_MAIN_DIR . '/debug';
        @mkdir( $this->debugDir );
    }

    static function instance() {
        if( !isset( self::$instance ) ) {
            self::$instance = new DebugLog();
        }

        return self::$instance;
    }

    public function enable( $enable ) {
        $this->enable = $enable;

        if ( $enable ) {
            $this->debugFilename = md5( home_url() . AUTH_KEY ) . '.txt';
            $this->fileHandle = fopen( $this->debugDir . '/' . $this->debugFilename, 'a+t' );
        }
    }

    public function write( $str ) {
        if ( $this->debugFilename  ) {
            fprintf( $this->fileHandle, "[%12s]: %s\n", time(), $str );
        }
    }
}

function DEBUG_LOG($str) { DebugLog::instance()->write( $str ); }
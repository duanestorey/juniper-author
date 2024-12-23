let mix = require('laravel-mix');

mix.js( 'src/js/juniper-server.js', 'dist' ).setPublicPath( 'dist' )
mix.sass( 'src/scss/juniper-server.scss', 'dist' );

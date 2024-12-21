let mix = require('laravel-mix');

mix.js( 'assets/juniper.js', 'dist' ).setPublicPath( 'dist' )
mix.sass( 'assets/juniper.scss', 'dist' );
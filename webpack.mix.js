let mix = require('laravel-mix');

mix.js( 'src/js/juniper-author.js', 'dist' ).setPublicPath( 'dist' )
mix.sass( 'src/scss/juniper-author.scss', 'dist' );

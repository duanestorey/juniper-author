function juniperAjax( specificAction, additionalParams, callback ) {
	var data = {
		'action': 'handle_ajax',
		'juniper_action': specificAction,
		'juniper_nonce': Juniper.nonce
	};

	// Add our parameters to the primary AJAX ones
	for ( var key in additionalParams ) {
	    if ( additionalParams.hasOwnProperty( key  )) {
	    	data[ key ] = additionalParams[ key ];
	    }
	}	

	// We can also pass the url value separately from ajaxurl for front end AJAX implementations
	jQuery.post( Juniper.ajax_url, data, function( response ) {
		callback( response );
	});
}

function hideSigningForm() {
    jQuery( '.sign-form' ).css( 'display', 'none' );
}

function showProgressBar() {
    jQuery( '.progress' ).css( 'display', 'block' );
}

function setProgressBarPercent( percent ) {
    jQuery( '.juniper .bar' ).css( 'width', percent + '%' ).html( percent.toFixed( 0 ) + '%' );
}

function juniperUpdateDebugBox( newText ) {
    var oldText = jQuery( '#repo_debug' ).val();
    oldText = oldText + newText + "\n";

    jQuery( '#repo_debug' ).val( oldText );
}

function juniperAjaxRefreshDone() {
    juniperUpdateDebugBox( 'Update process finished, refreshing page in 2 second' );

    setTimeout( function() {
            location.href = location.href;
        }, 2000
    );
}

function juniperAjaxRefreshRepos( startStage ) {
    jQuery( "#debug-area" ).show();

    jQuery( '#repo_debug' ).val( '' );
    juniperUpdateDebugBox( 'Starting update process' );

    var params = {
        // we will break up the update process ito stages
        stage: startStage 
    }

    juniperAjax( 'ajax_refresh', params, function( response ) { 
        var decodedResponse = jQuery.parseJSON( response );
        juniperUpdateDebugBox( decodedResponse.msg );

        if ( decodedResponse.pass ) {
            // stage 1, figuring out if they are valid plugins or themes
            params.stage = decodedResponse.next_stage;
            juniperAjax( 'ajax_refresh', params, function( response ) { 
                decodedResponse = jQuery.parseJSON( response );
                juniperUpdateDebugBox( decodedResponse.msg );

                if ( decodedResponse.pass ) {
                    // stage 2, loading plugin data & readmes
                    params.stage = decodedResponse.next_stage;
                    juniperAjax( 'ajax_refresh', params, function( response ) { 
                        decodedResponse = jQuery.parseJSON( response );
                        juniperUpdateDebugBox( decodedResponse.msg );

                        if ( decodedResponse.pass && !decodedResponse.done) {
                            // stage 3, loading issues
                            params.stage = decodedResponse.next_stage;
                            juniperAjax( 'ajax_refresh', params, function( response ) { 
                                decodedResponse = jQuery.parseJSON( response );
                                juniperUpdateDebugBox( decodedResponse.msg );

                                if ( decodedResponse.pass && !decodedResponse.done ) {
                                    // stage 4, loading releases
                                    params.stage = decodedResponse.next_stage;

                                    juniperAjax( 'ajax_refresh', params, function( response ) { 
                                        decodedResponse = jQuery.parseJSON( response );

                                        juniperUpdateDebugBox( decodedResponse.msg );

                                        if ( decodedResponse.done == 1 ) {
                                            juniperAjaxRefreshDone();
                                        }
                                    });
                                }

                                if ( decodedResponse.done == 1) {
                                    juniperAjaxRefreshDone();
                                }
                            });
                        }

                        if ( decodedResponse.done == 1) {
                            juniperAjaxRefreshDone();
                        }
                    });
                }
            });
        }
    });
}

function juniperBegin() {
    jQuery( 'a.digitally-sign' ).click( function( e ) {
        e.preventDefault();

        var params = {
            pw: jQuery( '#juniper_private_pw_1' ).val()
        }

        juniperAjax( 'test_key', params, function( response ) { 
            var decodedResponse = jQuery.parseJSON( response );
            if( !decodedResponse.key_valid ) {
                alert( 'Unable to load private key - possible passphrase error' );
            } else {
                setProgressBarPercent( 0 );
                showProgressBar();
                hideSigningForm();

                var allReleases = jQuery( 'tr.unsigned' );
                var releaseCount = allReleases.size();
                var currentItem = 0;
                if ( releaseCount ) {
                    allReleases.each( function() {
                        var thisItem = jQuery( this );

                        params = {
                            repo: jQuery( this ).attr( 'data-repo' ),
                            tag: jQuery( this ).attr( 'data-tag' ),
                            pw: jQuery( '#juniper_private_pw_1' ).val()
                        };

                        juniperAjax( 'sign_release', params, function( response ) { 
                            //alert( response );
                            decodedResponse = jQuery.parseJSON( response );
                            currentItem++;
                            
                            thisItem.find( 'td.yesno' ).html( '<span class="green">' + decodedResponse.signed_text + '</span>' );
                            thisItem.find( 'td.package' ).html( decodedResponse.package );
                            setProgressBarPercent( currentItem * 100 / ( releaseCount ) );
                        });
                    });
                }

                setProgressBarPercent( 100 );
            }
        });
    });

    jQuery( 'a.verify' ).click( function( e ) {
        e.preventDefault();

        var params = {
            package: jQuery( this ).attr( 'data-package' )
        };

        juniperAjax( 'verify_package', params, function( response ) { 
            var decodedResponse = jQuery.parseJSON( response );
            var str = "Package: " + decodedResponse.verify.package + "\n\n";
            if ( decodedResponse.verify.signature_valid == 1 ) {
                str = str + "Signature: VALID\n"
            } else {
                str = str + "Signature: INVALID\n"
            }

            if ( decodedResponse.verify.file_valid == 1 ) {
                str = str  + "File Integrity: VALID\n"
            } else {
                str = str + "File Integrity: INVALID\n"
            }

            alert( str );
        });
    });

    jQuery( 'a.do-ajax' ).click( function( e ) {
        e.preventDefault();

        var stage = jQuery( this ).attr( 'data-stage' );

        juniperAjaxRefreshRepos( stage );
    });

    jQuery( '.setting a.remove' ).click( function( e ) {
        var params = {
            image: jQuery( this ).attr( 'data-image' )
        }

        if ( confirm( 'This will delete this banner image permanently, are you sure?' ) ) {
            var thisLink = jQuery( this );
            juniperAjax( 'remove_image', params, function( response ) {
                location.href = location.href;
            }); 
        }

        e.preventDefault();
    });
}

jQuery( document ).ready( function() {
    juniperBegin();
});

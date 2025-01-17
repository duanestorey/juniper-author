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

function showSigningForm() {
    jQuery( '.sign-form' ).css( 'display', 'block' );
}

function showProgressBar() {
    jQuery( '.progress' ).css( 'display', 'block' );
}

function hideProgressBar() {
    jQuery( '.progress' ).css( 'display', 'none' );
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

function handleAjaxRefreshResponse( response ) {
    var decodedResponse = jQuery.parseJSON( response );
    juniperUpdateDebugBox( decodedResponse.result.msg );

    if ( decodedResponse.result.pass ) {
        if ( decodedResponse.result.done ) {
             juniperAjaxRefreshDone();
        } else {
            var params = {
                stage: decodedResponse.result.next_stage 
            }

            juniperAjax( 'ajax_refresh', params, handleAjaxRefreshResponse );
        }
    }
}

function juniperAjaxRefreshRepos( startStage ) {
    jQuery( "#debug-area" ).show();

    jQuery( '#repo_debug' ).val( '' );
    juniperUpdateDebugBox( 'Starting update process' );
    juniperUpdateDebugBox( '...this may take a while so please do not refresh the page until finished' );

    var params = {
        // we will break up the update process ito stages
        stage: startStage 
    }

    juniperAjax( 'ajax_refresh', params, handleAjaxRefreshResponse );
}

function juniperBegin() {
    jQuery( 'a.digitally-sign' ).click( function( e ) {
        e.preventDefault();

        var params = {
            pw: jQuery( '#juniper_private_pw_1' ).val()
        }

        var button = jQuery( this );
        juniperAjax( 'test_key', params, function( response ) { 
            var decodedResponse = jQuery.parseJSON( response );
            if( !decodedResponse.key_valid ) {
                alert( 'Unable to load private key - possible passphrase error' );
            } else {

                var allReleases;
                
                if ( button.attr( 'data-type' ) == 'new' ) {
                    allReleases = jQuery( 'tr.one-release.unsigned' );
                } else if ( button.attr( 'data-type' ) == 'all' ) { 
                    allReleases = jQuery( 'tr.one-release' );
                    allReleases.find( '.yesno' ).html( '' );
                }

                var releaseCount = allReleases.size();
                var currentItem = 0;
                if ( releaseCount ) {
                    setProgressBarPercent( 0 );
                    showProgressBar();
                    hideSigningForm();

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

                            if ( currentItem ==  releaseCount ) {
                                setTimeout( 
                                    function() {
                                        hideProgressBar();
                                        setProgressBarPercent( 0 );
                                        showSigningForm();
                                    },
                                    1000
                                );
                            }
                        });
                    });
                }
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

    jQuery( 'a.remove-repo' ).click( function( e ) {
        e.preventDefault();

        var params = {
            'repo': jQuery( this ).attr( 'data-repo' )
        };

        var item = jQuery( this );
        juniperAjax( 'remove_repo', params ,function( response ) {
            item.parent().parent().remove();
        });
    });

    jQuery( 'a.restore-repo' ).click( function( e ) {
        e.preventDefault();

        var params = {
            'repo': jQuery( this ).attr( 'data-repo' )
        };

        var item = jQuery( this );
        juniperAjax( 'restore_repo', params ,function( response ) {
            location.href = location.href;
        });
    });
}

jQuery( document ).ready( function() {
    juniperBegin();
});

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

function juniperBegin() {
    jQuery( 'a.digitally-sign' ).click( function( e ) {
        e.preventDefault();

        setProgressBarPercent( 0 );
        showProgressBar();
        hideSigningForm();

        var allReleases = jQuery( 'tr.unsigned' );
        var releaseCount = allReleases.size();
        var currentItem = 0;
        if ( releaseCount ) {
            allReleases.each( function() {
                var thisItem = jQuery( this );

                var params = {
                    repo: jQuery( this ).attr( 'data-repo' ),
                    tag: jQuery( this ).attr( 'data-tag' ),
                    pw: jQuery( '#juniper_private_pw_1' ).val()
                };

                juniperAjax( 'sign_release', params, function( response ) { 
                    //alert( response );
                    var decodedResponse = jQuery.parseJSON( response );
                    currentItem++;
                   // alert( decodedResponse.signed_text );
                    
                    thisItem.find( 'td.yesno' ).html( '<span class="green">' + decodedResponse.signed_text + '</span>' );
                    thisItem.find( 'td.package' ).html( decodedResponse.package );
                    setProgressBarPercent( currentItem * 100 / ( releaseCount ) );
                });
            });
        }
    });
}

jQuery( document ).ready( function() {
    juniperBegin();
});

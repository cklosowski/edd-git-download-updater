jQuery( document ).ready( function ( $ ) {

	var eddGit = {
		gitHub: {
			authorize: function() {
				if ( this.checkKeys() ) { // Make sure that our Client ID and Client Key aren't empty
					// Remove any errors we have already set.
					$( '.git-edd-error' ).remove();
					$( '#edd-git-github-spinner' ).show();
					var client_id = $( '#edd_settings\\[gh_clientid\\]' ).val();
					var client_secret = $( '#edd_settings\\[gh_clientsecret\\]' ).val();
					this.requestToken( client_id, client_secret );
				}
			},
			checkKeys: function() { 
				var ret = true;
				if ( $( '#edd_settings\\[gh_clientid\\]' ).val() == '' ) {
					var error = '<span class="git-edd-error">Please enter a valid Client ID</span>';
					$( '#edd_settings\\[gh_clientid\\]' ).after( error );
					ret = false;
				}

				if ( $( '#edd_settings\\[gh_clientsecret\\]' ).val() == '' ) {
					var error = '<span class="git-edd-error">Please enter a valid Client Secret</span>';
					$( '#edd_settings\\[gh_clientsecret\\]' ).after( error );
					ret = false;
				}

				return ret;
			},
			requestToken: function( client_id, client_secret ) {
				$.post( ajaxurl, { client_id: client_id, client_secret: client_secret, action:'edd_git_gh_request_token' }, function( response ) {
					window.location = response;
				} );
			},
			disconnect: function() {
				$( '#edd-git-github-spinner' ).show();
				$.post( ajaxurl, { action:'edd_git_gh_disconnect' }, function( response ) {
					$( '#edd-git-github-spinner' ).hide();
					$( '.edd-git-github-connected' ).hide();
					$( '.edd-git-github-disconnected' ).show();
				} );
			}
		}

	};

	$( document ).on( 'click', '#edd-github-auth', function( e ) {
		e.preventDefault();
		// Authorize GitHub
		eddGit.gitHub.authorize();
	} );

	$( document ).on( 'click', '#edd-github-disconnect', function( e ) {
		e.preventDefault();
		// Authorize GitHub
		eddGit.gitHub.disconnect();
	} );



} );
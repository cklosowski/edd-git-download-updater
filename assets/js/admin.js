jQuery.fn.eddGitAdminModal = function( action, options ) {
	if ( 'object' === typeof action ) {
		options = action;
	}

	var defaults = { 'title' : '', 'buttons' : false };

	if ( 'undefined' === typeof options ) {
		options = jQuery( this ).data( 'eddGitAdminModal' );
		if ( 'undefined' === typeof options ) {
			// Merge our default options with the options sent
			options = jQuery.extend( defaults, options );
		}
	} else {
		// Merge our default options with the options sent
		options = jQuery.extend( defaults, options );
	}

	// Set our data with the current options
	jQuery( this ).data( 'eddGitAdminModal', options );

	jQuery( this ).hide();
	jQuery( '#edd-git-admin-modal-content' ).html( this.html() );

	jQuery( '#edd-git-modal-title' ).html( options.title );

	if ( options.buttons ) {
		jQuery( options.buttons ).hide();
		var buttons = jQuery( options.buttons ).html();
		jQuery( '#modal-contents-wrapper' ).find( '.submitbox' ).html( buttons );
		jQuery( '#edd-git-admin-modal-content' ).addClass( 'admin-modal-inside' );
		jQuery( '#modal-contents-wrapper' ).find( '.submitbox' ).show();
	} else {
		jQuery( '#edd-git-admin-modal-content' ).removeClass( 'admin-modal-inside' );
		jQuery( '#modal-contents-wrapper' ).find( '.submitbox' ).hide();
	}

	if ( 'close' == action ) {
		jQuery.fn.eddGitAdminModal.close();
	} else if ( 'open' == action ) {
		jQuery.fn.eddGitAdminModal.open();
	}

	jQuery( document ).on( 'click', '.modal-close', function( e ) {
		e.preventDefault();
		jQuery.fn.eddGitAdminModal.close();
	} );

};

jQuery.fn.eddGitAdminModal.close = function() {
	jQuery( '#edd-git-admin-modal-backdrop' ).hide();
	jQuery( '#edd-git-admin-modal-wrap' ).hide();
	jQuery( document ).triggerHandler( 'eddGitAdminModalClose' );
}

jQuery.fn.eddGitAdminModal.open = function() {
	jQuery( '#edd-git-admin-modal-backdrop' ).show();
	jQuery( '#edd-git-admin-modal-wrap' ).show();
	jQuery( document ).triggerHandler( 'eddGitAdminModalOpen' );
}


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
		},
		editDownload: {
			useGit: gitUpdater.useGit,
			currentTag: gitUpdater.currentTag,
			currentGitUrl: gitUpdater.currentGitUrl,
			fetchStatus: 'clean',
			init: function() {
				var that = this;
				$( '.git-repo' ).select2( {
					templateResult: that.formatRepo,
					templateSelection: that.formatRepo
				});

				$( '.git-tag' ).select2();

				if ( 1 == this.useGit ) {
					$( '.edd_add_repeatable' ).hide();
					$( '.edd_remove_repeatable' ).hide();
					this.setUpgradeFile();
				}
				$( '.edd-git-fetch-prompt' ).eddGitAdminModal( { title: '', buttons: '.edd-git-fetch-prompt-buttons' } );
			},
			formatRepo: function( repo ) {
				if ( ! repo.id ) { return repo.text; }
				var source = $( repo.element ).data( 'source' );
				var test = $( '<span><img style="width:18px;height:18px;margin-top:-10px;" align="middle" src="' + gitUpdater.pluginURL + 'assets/images/' + source +'.png" class="img-flag" /> ' + repo.text + '</span>' );
				return test;
			},
			changeFetchStatus: function( status ) {
				this.fetchStatus = status;
				if ( 'dirty' == status ) {
					$( '.git-update-none' ).hide();
					$( '.git-update-check' ).hide();
					$( '.git-update-text' ).show();
					$( '.edd-git-update' ).attr( 'disabled', false );
					$( '.edd-git-update' ).removeClass( 'button-secondary' );
					$( '.edd-git-update' ).addClass( 'button-primary' );
				} else if ( 'clean' == status ) {
					$( '.git-update-none' ).hide();
					$( '.git-update-text' ).hide();
					$( '.git-update-check' ).show();
					$( '.edd-git-update' ).attr( 'disabled', false );
					$( '.edd-git-update' ).addClass( 'button-secondary' );
					$( '.edd-git-update' ).removeClass( 'button-primary' );
				} else if ( 'none' == status ) {
					$( '.git-update-text' ).hide();
					$( '.git-update-check' ).hide();
					$( '.git-update-none' ).show();
					$( '.edd-git-update' ).attr( 'disabled', true );
					$( '.edd-git-update' ).addClass( 'button-secondary' );
					$( '.edd-git-update' ).removeClass( 'button-primary' );
				}
			},
			changeTag: function( tag ) {
				var repo = $( '.git-repo option:selected' ).data( 'slug' );

				this.changeFilenamePlaceholder( repo, tag );
				this.changeFoldernamePlaceholder( repo );

				if ( '' == tag || null == tag ) {
					this.changeFetchStatus( 'none' );
					return false;
				}
				if ( this.currentTag == tag && this.currentGitUrl == $( '.git-repo' ).val() ) {
					this.changeFetchStatus( 'clean' );
				} else {
					this.changeFetchStatus( 'dirty' );
				}

			},
			changeRepo: function( el ) {
				if ( '' == $( el ).val() || null == $( el ).val() ) {
					$( '.git-tag' ).find( 'option' ).remove();
					$( '.git-tag' ).trigger( 'change' );
					return false;
				}
				var repo = $( el ).val();
				var that = this;
				var slug = $( el ).find( 'option:selected' ).data( 'slug' );

				$( '.git-tag-div' ).hide();
				$( '.git-tag-spinner' ).fadeIn('fast');

				$.post( ajaxurl, { action: 'edd_git_get_tags', repo: repo }, function( response ) {
					if ( 'undefined' == typeof response.error ) {
						var tag_select = $( '.git-tag' );
						$( tag_select ).find( 'option' ).remove();
						_.each( response, function( tag ) {
							$( tag_select ).append( '<option value="' + tag + '">' + tag + '</option>' );
						} );
						$( tag_select ).trigger( 'change' );
					}
					$( '.git-tag-spinner' ).hide();
					$( '.git-tag-div' ).fadeIn('fast');
					var status = 'dirty';
					// If we have returned to the original repo AND original tag, we're clean.
					if ( repo == that.currentGitUrl && that.currentTag == $( '.git-tag' ).val() ) {
						status = 'clean';
					}
					// If we don't have any tag selected, we're none.
					if ( null == $( '.git-tag' ).val() || '' == $( '.git-tag' ).val() ) {
						status = 'none';
					}

					that.changeFetchStatus( status );
				} );
			},
			changeFilenamePlaceholder: function( slug, tag ) {

				if ( null == slug || 'undefined' == typeof slug ) {
					slug = '';
				}
				if ( null == tag || 'undefined' == typeof tag ) {
					tag = '';
				}
				
				if ( '' != slug && '' != tag ) {
					var placeholder = slug + '-' + tag + '.zip'
				} else {
					var placeholder = '';
				}
				$( '.git-file-name' ).attr( 'placeholder', placeholder );
			},
			changeFoldernamePlaceholder: function( slug ) {
				if ( 'undefined' != typeof slug || null == slug ) {
					var placeholder = slug;
				} else {
					var placeholder = '';
				}
				$( '.git-folder-name' ).attr( 'placeholder', placeholder );
			},
			changeUseGit: function( el ) {
				var checked = el.checked ? 1 : 0;
				var that = this;
				$( '#edd_file_fields .edd_repeatable_table' ).parent().html( '<span class="spinner" style="display:block; float:left;"></span>&nbsp;' );
				$.post( ajaxurl, { action: 'edd_change_use_git', post_id: edd_vars.post_id, checked: checked }, function( response ) {
					$( el ).parent().parent().html( response.html );
					if ( null == $( '.git-repo' ).val() || '' == $( '.git-repo' ).val() ) {
						$( '.git-file-name' ).val( '' );
					}
					if ( 1 == checked ) {
						that.useGit = 1;
						that.init();
						that.setUpgradeFile();
					} else {
						that.useGit = 0;
						that.unsetUpgradeFile();
					}
				} );
			},
			fetchFile: function( e ) {
				var repo_url = $( '.git-repo' ).val();
				var folder_name = $( '.git-folder-name' ).val();
				var tag = $( '.git-tag' ).val();
				var key = $( this ).parent().parent().parent().data( 'key' );
				var file_name = $( '.git-file-name' ).val();
				var condition = $( '.git-condition' ).val();
				var that = this;
				$( '.git-update-spinner' ).show();
				$( '.git-update-spinner' ).css( 'visibility', 'visible' );
				$.post( ajaxurl, { action: 'edd_git_update_file', post_id: edd_vars.post_id, condition: condition, file_name: file_name, key:key, version: tag, folder_name: folder_name, repo_url: repo_url }, function( response ) {
					$( '.git-update-spinner' ).hide();

					if ( null == response.errors && 'object' == typeof response ) { // No errors
						that.currentGitUrl = repo_url;
						that.currentTag = tag;
						that.changeFetchStatus( 'clean' );
						that.setUpgradeFile();
						if ( 'checked' == $( '#edd_license_enabled' ).attr( 'checked' ) ) {
							$( '#edd_sl_version' ).val( response.sl_version );
							if ( response.changelog ) {	
								tinyMCE.get( 'edd_sl_changelog' ).setContent( response.changelog );
							}
						}
						$( '#edd_git_file' ).val( response.file );	
						$( document ).trigger( 'eddGitFileFetched' );
					} else if ( 'undefined' != typeof response.errors ) { // We had an errors
						$( '#edd_git_error' ).html( response.errors );
						$( '.git-update-check' ).hide();
						$( '.git-update-text' ).hide();
						$( '.git-update-none' ).show();
					} else {
						$( '.git-update-check' ).hide();
						$( '.git-update-text' ).hide();
						$( '.git-update-none' ).show();
						console.log( response );
					}

				} );
			},
			updateDownload: function( e ) {
				if ( $( '#_edd_download_use_git' ).attr( 'checked' ) ) {
					this.setUpgradeFile;
					if ( 'dirty' == this.fetchStatus ) {
						e.preventDefault();
						$( '.edd-git-fetch-prompt' ).eddGitAdminModal( 'open' );
						this.targetButton = e.target;
					}
				}
			},
			fetchAndContinue: function( e ) {
				var that = this;
				$( '.edd-git-fetch-prompt' ).eddGitAdminModal( 'close' );
				$( document ).on( 'eddGitFileFetched', function() {
					$( that.targetButton ).click();
				} );
				this.fetchFile( e );
			},
			fetchRepos: function( e ) {
				$( e.target ).hide();
				$( e.target ).next( '.spinner' ).show();
				$( e.target ).next( '.spinner' ).css( 'visibility', 'visible' );
				var current_repo = $( '.git-repo' ).val();
				$.post( ajaxurl, { action: 'edd_git_fetch_repos', current_repo: current_repo }, function( response ) {
					var options = response.options_html;
					$( '.git-repo' ).select2( 'data', null );
					$( '.git-repo' ).find( 'option' ).remove();
					$( '.git-repo' ).find( 'optgroup' ).remove();
					$( '.git-repo' ).trigger( 'change' );
					$( '.git-repo' ).append( options );
					$( '.git-repo' ).trigger( 'change' );
					$( e.target ).next( '.spinner' ).hide();
					$( e.target ).show();
				} );
			},
			setUpgradeFile: function( e ) {
				$( '#edd_sl_upgrade_file' ).find( 'option' ).remove();
				$( '#edd_sl_upgrade_file' ).append( '<option value="0">git</option>' );
				$( '#edd_sl_upgrade_file' ).parent().parent().hide();
			},
			unsetUpgradeFile: function( e ) {
				$( '#edd_sl_upgrade_file' ).find( 'option' ).remove();
				$( '#edd_sl_upgrade_file' ).parent().parent().show();
			}
		}
	};

	eddGit.editDownload.init();

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

	$( document ).on( 'change', '#_edd_download_use_git', function( e ) {
		eddGit.editDownload.changeUseGit( this );
	} );

	$( document ).on( 'change', '.git-tag', function( e ) {
		eddGit.editDownload.changeTag( $( this ).val() );
	} );
	
	$( document ).on( 'change', '.git-repo', function( e ) {
		eddGit.editDownload.changeRepo( this );
	} );

	$( document ).on( 'click', '.edd-git-update', function( e ) {
		e.preventDefault();
		if ( ! $( this ).attr( 'disabled' ) ) {
			eddGit.editDownload.fetchFile( e );
		}
	} );

	$( document ).on( 'click', '#submitpost input', function( e ) {
		eddGit.editDownload.updateDownload( e );
	} );

	$( document ).on( 'click', '.edd-git-fetch-continue', function( e ) {
		e.preventDefault();
		eddGit.editDownload.fetchAndContinue( e );
	} );

	$( document ).on( 'click', '.edd-git-fetch-repos', function( e ) {
		e.preventDefault();
		eddGit.editDownload.fetchRepos( e );
	} );

} );

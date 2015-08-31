<?php
/**
 * Handle Ajax requests from EDD Git Downloader
 *
 * @package EDD Git Downloader
 * @since 1.0
 */
class EDD_GIT_Download_Updater_Ajax
{

	// Holds our instance
	var $instance = '';

	function __construct( $instance )
	{

		$this->instance = $instance;

		 // Add our ajax action for updating our file download section
	    add_action( 'wp_ajax_edd_change_use_git', array( $this, 'ajax_use_git' ) );

	    // Add our ajax action for grabbing repos
	    add_action( 'wp_ajax_edd_git_fetch_repos', array( $this, 'ajax_repos' ) );

	    // Add our ajax action for grabbing repo tags
	    add_action( 'wp_ajax_edd_git_get_tags', array( $this, 'ajax_tags' ) );

	    // Add our ajax action for updating our file
	    add_action( 'wp_ajax_edd_git_update_file', array( $this, 'ajax_fetch_file' ) );

	    // Add our ajax action for requesting an inital GitHub token.
        add_action( 'wp_ajax_edd_git_gh_request_token', array( $this, 'gh_request_token' ) );

        // Add our ajax action for getting a permanent GitHub access token.
        add_action( 'wp_ajax_edd_git_gh_set_oauth_key', array( $this, 'gh_set_oauth_key' ) );

        // Add our ajax action for disconnecting from GitHub.
        add_action( 'wp_ajax_edd_git_gh_disconnect', array( $this, 'gh_disconnect' ) );

	}

	/*
     * Run all of the functions necessary to update our download.
     *
     * @param int $post_id
     * @since 1.0
     * @return void
     */
    public function ajax_fetch_file( $file ) {
        $post_id = $_REQUEST['post_id'];
        $version = $_REQUEST['version'];
        $repo_url = $_REQUEST['repo_url'];
        $key = isset( $_REQUEST['key'] ) ? $_REQUEST['key'] : 0;
        $this->instance->condition = isset ( $_REQUEST['condition'] ) ? $_REQUEST['condition'] : 'all';

        $folder_name = $_REQUEST['folder_name'];
        $file_name = $_REQUEST['file_name'];

        if ( !current_user_can( 'edit_product', $post_id ) || empty ( $post_id ) || empty ( $version ) || empty ( $repo_url ) )
            die();

        $new_zip = $this->instance->process_file->process( $post_id, $version, $repo_url, $key, $folder_name, $file_name );
        
        //Return our changelog and version.
        header("Content-type: application/json");
        echo json_encode( array( 'file' => $new_zip['url'], 'sl_version' => $this->instance->sl_version, 'changelog' => $this->instance->changelog, 'errors' => $this->instance->errors ) );
        die();
    }

    /**
     * Output our repo options
     * @since  1.0
     * @return void
     */
	public function ajax_repos() {
        delete_option( 'edd_git_repos' );
        $current_repo = $_REQUEST['current_repo'];
        $repos = $this->instance->repos->fetch_repos();

        ob_start();
        $this->instance->admin->output_repo_options( $repos, $current_repo );
        $options = ob_get_clean();

        header("Content-type: application/json");
        echo json_encode( array( 'options_html' => $options ) );
        die();
    }

    /**
     * Output our tag options
     * @since  1.0
     * @return void
     */
    public function ajax_tags() {
        $repo = $_REQUEST['repo'];
        $return_tags = $this->instance->repos->fetch_tags( $repo );

        header("Content-type: application/json");
        echo json_encode( $return_tags );
        die();
    }

    /**
     * Handle someone checking the "Use Git" checkbox by outputting our repo and tag html.
     * @since  1.0
     * @return void
     */
    public function ajax_use_git() {
        $checked = isset ( $_REQUEST['checked'] ) ? $_REQUEST['checked'] : 0;
        $post_id = isset ( $_REQUEST['post_id'] ) ? $_REQUEST['post_id'] : 0;
        if ( ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) || empty ( $post_id ) || ! is_admin() || ! current_user_can( 'edit_product', $post_id ) ) {
            die();
        }

        update_post_meta( $post_id, '_edd_download_use_git', $checked );

        require_once( EDD_PLUGIN_DIR . 'includes/admin/downloads/metabox.php' );

        if ( 1 == $checked ) {
            $this->instance->admin->register_git_section();
        } else {
            update_post_meta( $post_id, 'edd_download_files', array() );
        }

        ob_start();
        do_action( 'edd_meta_box_files_fields', $post_id );
        $html = ob_get_clean();

        header("Content-type: application/json");
        echo json_encode( array( 'html' => $html ) );

        die();
    }

    /*
     * Request our initial, temporary GitHub token.
     *
     * @since 1.1
     * @return void
     */

    public function gh_request_token() {
        $client_id = isset ( $_REQUEST['client_id'] ) ? $_REQUEST['client_id'] : '';
        $client_secret = isset ( $_REQUEST['client_secret'] ) ? $_REQUEST['client_secret'] : '';

        // Bail if we didn't recieve a client_id or client_secret
        if ( '' == $client_id || '' == $client_secret )
            die();

        // Save our client_id and client_secret
        $edd_settings = get_option( 'edd_settings' );
        $edd_settings['gh_clientid'] = $client_id;
        $edd_settings['gh_clientsecret'] = $client_secret;
        update_option( 'edd_settings',  $edd_settings );

        $redirect_uri = urlencode( admin_url( 'admin-ajax.php?action=edd_git_gh_set_oauth_key' ) );

        // Send user to GitHub for account authorization

        $query = 'https://github.com/login/oauth/authorize';
        $query_args = array(
            'scope' => 'repo',
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
        );
        $query = add_query_arg( $query_args, $query );

        echo $query;

        die();
    }

    /*
     * Finish up our GitHub oAuth. The user has just returned from GitHub.
     *
     * @since 1.1
     * @return void
     */

    public function gh_set_oauth_key() {
        // Get our client id and secret
        $edd_settings = get_option( 'edd_settings' );
        $gh_clientid = isset ( $edd_settings['gh_clientid'] ) ? $edd_settings['gh_clientid'] : '';
        $gh_clientsecret = isset ( $edd_settings['gh_clientsecret'] ) ? $edd_settings['gh_clientsecret'] : '';

        if ( isset( $_GET['code'] ) ) {
            // Receive authorized token
            $query = 'https://github.com/login/oauth/access_token';
            $query_args = array(
                'client_id' => $gh_clientid,
                'client_secret' => $gh_clientsecret,
                'code' => $_GET['code'],
            );
            $query = add_query_arg( $query_args, $query );
            $response = wp_remote_get( $query, array( 'sslverify' => false ) );
            parse_str( $response['body'] ); // populates $access_token, $token_type

            $redirect = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions' );
            if ( !empty( $access_token ) ) {
                $edd_settings['gh_access_token'] = $access_token;
                update_option( 'edd_settings', $edd_settings );
            }else {
                $redirect = add_query_arg( array( 'authorize' => 'false' ), $redirect );
            }

        }else {
            $redirect = add_query_arg( array( 'authorize'=>'false' ), $redirect );
        }

        wp_redirect( $redirect );
        die();
    }

    /*
     * Disconnect from GitHub. 
     * This will remove the token, but will NOT revoke access.
     * To fully revoke access, visit your account at github.com.
     *
     * @since 1.1
     * @return void
     */

    public function gh_disconnect() {
        $edd_settings = get_option( 'edd_settings' );
        unset( $edd_settings['gh_access_token'] );
        update_option( 'edd_settings', $edd_settings );
        die();
    }


}
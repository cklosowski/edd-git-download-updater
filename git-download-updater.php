<?php
/*
Plugin Name: Easy Digital Downloads - Git Update Downloads
Plugin URI: http://ninjaforms.com
Description: Update Download files and changelog directly from BitBucket or GitHub
Version: 1.1
Author: The WP Ninjas
Author URI: http://wpninjas.com
*/

if ( ! defined( 'EDD_GIT_PLUGIN_DIR' ) ) {
    define( 'EDD_GIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'EDD_GIT_PLUGIN_URL' ) ) {
    define( 'EDD_GIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

class EDD_GIT_Download_Updater {

    /*
     * Store our git Username. This will be used to login to the desired repo.
     */
    private $username;

    /*
     * Store our git Password. This wil be used to login to the desired repo.
     */
    private $password;

    /*
     * Store our git repo name
     */
    private $git_repo;

    /*
     * Store our desired version #.
     */
    private $version;

    /*
     * Store our download's "version" number if Licensing is installed
     */
    private $sl_version;

    /*
     * Store our git Repo URL
     */
    private $url;

    /*
     * Store our destination filename
     */
    private $file_name;

    /*
     * Store our temporary dir name
     */
    private $tmp_dir;

    /*
     * Store our newly unzipped folder name
     */
    private $sub_dir;

    /*
     * Store the id of the download we're updating
     */
    private $download_id;

    /*
     * Store our EDD upload dir information
     */
    private $edd_dir;

    /*
     * Store the current file key for our download
     */
    private $file_key;

    /*
     * Store our errors
     */
    private $errors;

    /*
     * Store our folder name
     */
    private $folder_name;

    /*
     * Store our source (either bitbucket or github)
     */
    private $source;

    /*
     * Store our changelog
     */
    private $changelog = '';

    /*
     * Get things up and running.
     *
     * @since 1.0
     * @return void
     */

    public function __construct() {
        global $post;
        // Bail if the zip extension hasn't been loaded.
        if ( ! class_exists('ZipArchive') )
            return false;

        $settings_url = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions' );
        // Set our error messages;

        // Add our settings to the EDD Extensions tab
        add_filter( 'edd_settings_extensions', array( $this, 'edd_extensions_settings' ) );
        add_action( 'edd_meta_box_files_fields', array( $this, 'output_file_checkbox' ) );

        // Add our init action that adds/removes git download boxes.
        add_action( 'admin_head', array( $this, 'init' ) );

        // Save our Use Git setting.
        add_action( 'save_post', array( $this, 'save_post' ) );

        // Add our ajax action for updating our file download section
        add_action( 'wp_ajax_edd_change_use_git', array( $this, 'ajax_use_git' ) );

        // Add our ajax action for grabbing repos
        add_action( 'wp_ajax_edd_git_fetch_repos', array( $this, 'ajax_repos' ) );

        // Add our ajax action for grabbing repo tags
        add_action( 'wp_ajax_edd_git_get_tags', array( $this, 'ajax_tags' ) );

        // Add our ajax action for updating our file
        add_action( 'wp_ajax_edd_git_update_file', array( $this, 'ajax_fetch_file' ) );

        /* GitHub */

        // Add our GitHub description hook.
        add_action( 'edd_git_gh_desc', array( $this, 'gh_desc' ) );

        // Add our GitHub Authorization button hook.
        add_action( 'edd_git_gh_authorize_button', array( $this, 'gh_authorize_button' ) );

        // Add our JS to the EDD Extensions settings page.
        add_action( 'admin_print_scripts-post.php', array( $this, 'admin_js' ) );
        add_action( 'admin_print_scripts-post-new.php', array( $this, 'admin_js' ) );

        // Add our CSS to the EDD Extensions settings page.
        add_action( 'admin_print_styles-post.php', array( $this, 'admin_css' ) );
        add_action( 'admin_print_styles-post-new.php', array( $this, 'admin_css' ) );

        // Add our ajax action for requesting an inital GitHub token.
        add_action( 'wp_ajax_edd_git_gh_request_token', array( $this, 'gh_request_token' ) );

        // Add our ajax action for getting a permanent GitHub access token.
        add_action( 'wp_ajax_edd_git_gh_set_oauth_key', array( $this, 'gh_set_oauth_key' ) );

        // Add our ajax action for disconnecting from GitHub.
        add_action( 'wp_ajax_edd_git_gh_disconnect', array( $this, 'gh_disconnect' ) );

        /* BitBucket */

        // Add our BitBucket description hook.
        add_action( 'edd_git_bb_desc', array( $this, 'bb_desc' ) );
    }

    public function init() {
        global $post;

        if ( isset ( $post ) && 1 == get_post_meta( $post->ID, '_edd_download_use_git', true ) ) {
            $this->register_git_section();
        }
    }

    public function register_git_section() {
        // Remove the default EDD file editing section.
        remove_action( 'edd_meta_box_files_fields', 'edd_render_files_field', 20 );
        remove_action( 'edd_render_file_row', 'edd_render_file_row', 10, 3 );

        // Add our settings to the download editor.
        add_action( 'edd_meta_box_files_fields', array( $this, 'edd_files_fields' ), 20 );
        add_action( 'edd_render_file_row', array( $this, 'edd_render_file_row' ), 10, 3  );
    }

    public function save_post( $post_id ) {
        // Bail if we aren't saving a download
        if ( !isset ( $_POST['post_type'] ) or $_POST['post_type'] != 'download' )
            return $post_id;

        // verify if this is an auto save routine.
        // If it is our form has not been submitted, so we dont want to do anything
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
          return $post_id;

        // Verify that this user can edit downloads
        if ( !current_user_can( 'edit_product', $post_id ) )
            return $post_id;

        update_post_meta( $post_id, '_edd_download_use_git', esc_html( $_POST['_edd_download_use_git'] ) );
    }

    /*
     * Include our requried files
     *
     * @since 1.0
     * @return void
     */

    private function includes() {
        require_once( EDD_GIT_PLUGIN_DIR . 'includes/flx-zip-archive.php' );
    }

    /*
     * Include our JS.
     *
     * @since 1.1
     * @return void
     */

    public function admin_js() {
        global $pagenow, $post, $typenow;

        if ( ( 'edit.php' == $pagenow && isset ( $_REQUEST['page'] ) && 'edd-settings' == $_REQUEST['page'] && isset ( $_REQUEST['tab'] ) && 'extensions' == $_REQUEST['tab'] ) || 'download' == $typenow ) {
            if ( is_object( $post ) ) {
                $post_id = $post->ID;
            } else {
                $post_id = '';
            }
            wp_enqueue_script( 'jquery-select2', EDD_GIT_PLUGIN_URL . 'assets/js/select2.min.js', array( 'jquery' ) );
            wp_enqueue_script( 'edd-git-updater', EDD_GIT_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ) );
            wp_localize_script( 'edd-git-updater', 'gitUpdater', array( 'pluginURL' => EDD_GIT_PLUGIN_URL, 'useGit' => get_post_meta( $post_id, '_edd_download_use_git', true ), 'currentGitUrl' => $this->get_current_repo_url( $post_id ), 'currentTag' => $this->get_current_tag( $post_id ) ) );
        }
    }

    /*
     * Include our CSS.
     *
     * @since 1.1
     * @return void
     */

    public function admin_css() {
        global $pagenow, $typenow;

        if ( ( 'edit.php' == $pagenow && isset ( $_REQUEST['page'] ) && 'edd-settings' == $_REQUEST['page'] && isset ( $_REQUEST['tab'] ) && 'extensions' == $_REQUEST['tab'] ) || 'download' == $typenow ) {
            wp_enqueue_style( 'jquery-select2', EDD_GIT_PLUGIN_URL . 'assets/css/select2.min.css' );
            wp_enqueue_style( 'edd-git-updater', EDD_GIT_PLUGIN_URL . 'assets/css/admin.css' );
        }
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
        $this->condition = isset ( $_REQUEST['condition'] ) ? $_REQUEST['condition'] : 'all';

        $folder_name = $_REQUEST['folder_name'];
        $file_name = $_REQUEST['file_name'];

        if ( !current_user_can( 'edit_product', $post_id ) || empty ( $post_id ) || empty ( $version ) || empty ( $repo_url ) )
            die();

        // OK, we're authenticated: we need to find and save the data
        $this->download_id = $post_id;
        $this->version = $version;
        $this->url = $repo_url;
        $this->repo_url = $repo_url;
        $this->file_key = $key;
        $this->original_filename = $file_name;
        $this->original_foldername = $folder_name;

        // Setup our initial variables
        $this->includes();
        $this->set_url();
        $this->set_foldername( $folder_name );
        $this->set_tmp_dir();
        $this->set_edd_dir();
        $this->set_filename( $file_name );

        // Grab our zip file.
        $zip_path = $this->fetch_zip();

        if ( ! $zip_path ) // If we bailed during the fetch_zip function, stop processing update.
            die();

        // Unzip our file to a new temporary directory.
        $new_dir = $this->unzip( $zip_path );

        $this->update_changelog( $new_dir );
        
        // Create our new zip file
        $zip = $this->zip( $new_dir, $this->tmp_dir .  $this->file_name );

        // Move our temporary zip to the proper EDD folder
        $new_zip = $this->move_zip( $zip );

        // Remove our temporary files
        $this->remove_dir( $this->tmp_dir );

        // Reset our temporary directory
        $this->set_tmp_dir();

        // Update our file with the new zip location.
        $this->update_files( $new_zip['url'] );

        //Return our changelog and version.
        header("Content-type: application/json");
        echo json_encode( array( 'file' => $new_zip['url'], 'sl_version' => $this->sl_version, 'changelog' => $this->changelog, 'errors' => $this->errors ) );
        die();
    }

    /*
     * Update the download's changelog from our grabbed readme.txt
     *
     * @param string $new_dir
     * @since 1.0
     * @return void
     */

    private function update_changelog( $new_dir ) {
        if ( file_exists( trailingslashit( $new_dir ) . 'readme.txt' ) ) {
            if( ! class_exists( 'Automattic_Readme' ) ) {
                include_once( EDD_GIT_PLUGIN_DIR . 'includes/parse-readme.php' );
            }

            $Parser = new Automattic_Readme;
            $content = $Parser->parse_readme( trailingslashit( $new_dir ) . 'readme.txt' );

            if ( isset ( $content['sections']['changelog'] ) ) {
                $changelog = wp_kses_post( $content['sections']['changelog'] );
                update_post_meta( $this->download_id, '_edd_sl_changelog', $changelog );
                $this->changelog = $changelog;
            }
        }
    }

    /*
     * Update our download files post meta
     *
     * @param string $new_zip
     * @since 1.0
     * @return void
     */

    private function update_files( $new_zip ) {
        // $files = get_post_meta( $this->download_id, 'edd_download_files', true );
        $files = array();
        $files[ $this->file_key ]['git_version']        = $this->version;
        $files[ $this->file_key ]['git_url']            = $this->repo_url;
        $files[ $this->file_key ]['git_folder_name']    = $this->original_foldername;
        $files[ $this->file_key ]['name']               = $this->original_filename;
        $files[ $this->file_key ]['file']               = $new_zip;
        $files[ $this->file_key ]['condition']          = $this->condition;
        $files[ $this->file_key ]['attachment_id']      = 0;
        update_post_meta( $this->download_id, 'edd_download_files', $files );
        if ( 0 === strpos( $this->version, 'v' ) ) {
            $this->sl_version = substr( $this->version, 1 );
        } else {
            $this->sl_version = $this->version;
        }
        update_post_meta( $this->download_id, '_edd_sl_version', $this->sl_version );
    }

    /*
     * Move our zip file to the EDD uploads directory
     *
     * @since 1.0
     * @return string $new_zip
     */

    private function move_zip( $zip ) {

        $edd_dir = trailingslashit( $this->edd_dir['path'] );
        $edd_url = trailingslashit( $this->edd_dir['url'] );
        $upload_path = apply_filters( 'edd_git_upload_path', $edd_dir . $this->file_name );
        $upload_url = apply_filters( 'edd_git_upload_url', $edd_url . $this->file_name );

        copy( $zip, $upload_path );
        unlink( $zip );
        return array( 'path' => $upload_path, 'url' => $upload_url );
    }

    /*
     * Set our temporary directory. Create it if it doesn't exist.
     *
     * @since 1.0
     * @return void
     */

    private function set_tmp_dir() {
        $tmp_dir = wp_upload_dir();
        $tmp_dir = trailingslashit( $tmp_dir['basedir'] ) . 'edd-git-tmp/';
        $tmp_dir = apply_filters( 'edd_git_zip_path', $tmp_dir );
        if ( ! is_dir( $tmp_dir ) )
            mkdir( $tmp_dir );
        // $tmp_dir will always have a trailing slash.
        $this->tmp_dir = trailingslashit( $tmp_dir );
    }

    /*
     * Set our git URL. Also sets whether we are working from GitHub or BitBucket.
     *
     * @since 1.0
     * @param array $file
     * @param string $v
     * @return void
     */

    private function set_url() {
        $edd_settings = get_option( 'edd_settings' );

        $url = trailingslashit( $this->url );

        $tmp = explode( '/', $url );

        $user = $tmp[3];
        $repo = $tmp[4];

        if ( 'bitbucket.org' == $tmp[2] ) {
            // Add an error and bail if our BB user and password aren't set
            if ( ! defined( 'EDD_GIT_BB_USER' ) || ! defined( 'EDD_GIT_BB_PASSWORD' ) ) {
                // Add errors
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                // Bail
                return false;
            }
            $url_part = 'get/' . $this->version .'.zip';
            $url .= $url_part;
            $this->source = 'bitbucket';
        } else if ( 'github.com' == $tmp[2] ) {
            $access_token = isset ( $edd_settings['gh_access_token'] ) ? $edd_settings['gh_access_token'] : '';
            if ( empty( $access_token ) ) { // If we don't have an oAuth access token, add error and bail.
                // Add Error
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                // Bail
                return false;
            } else {
                $url = 'https://api.github.com/repos/' . $user . '/' . $repo . '/zipball/' . $this->version . '?access_token=' . $access_token;
            }
            $this->source = 'github';
        } else {
            // Throw an error
            $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
            // Bail
            return false;
        }

        $this->git_repo = $tmp[4];

        $this->url = apply_filters( 'edd_git_repo_url', $url );
        return $this->url;
    }

    /*
     * Set our clean zip file name
     *
     * @since 1.0
     * @return void
     */

    private function set_filename( $file_name ) {
        $this->file_name = ! empty ( $file_name ) ? $file_name : $this->git_repo . '-' . $this->version . '.zip';
        $this->file_name = apply_filters( 'edd_git_download_file_name', $this->file_name, $this->download_id, $this->file_key );
    }

    /*
     * Set the name of our folder that should go inside our new zip.
     *
     * @since 1.0
     * @return void
     */
    private function set_foldername( $folder_name ) {
        $this->folder_name = ! empty ( $folder_name ) ? $folder_name : sanitize_title( $this->git_repo );
    }

    /*
     * Grab the zip file from git and store it in our temporary directory.
     *
     * @since 1.0
     * @return string $zip_path
     */

    public function fetch_zip() {
        $zip_path = $this->tmp_dir . $this->file_name;

        if ( 'bitbucket' == $this->source ) {
            if ( ! defined( 'EDD_GIT_BB_USER' ) || ! defined( 'EDD_GIT_BB_PASSWORD' ) ) { // If BB credentials aren't set, add error and bail.
                // Add Errors
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access zip file.', 'edd-git' ) );
                // Bail
                return false;
            }

            if ( ! function_exists( 'curl_version' ) ) {
                // Add Errors
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'cURL is not enabled. Please contact your host.', 'edd-git' ) );
                // Bail
                return false;
            }
 
            $username = EDD_GIT_BB_USER;
            $password = EDD_GIT_BB_PASSWORD;

            $fp = fopen($zip_path, "w");
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->url);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $resp = curl_exec($ch);

            try {
                $fp = fopen($zip_path, "w");
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $this->url);
                curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                $resp = curl_exec($ch);

                // validate CURL status
                if(curl_errno($ch))
                    throw new Exception(curl_error($ch), 500);

                // validate HTTP status code (user/password credential issues)
                $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($status_code != 200)
                    throw new Exception("Response with Status Code [" . $status_code . "].", 500);
            }
            catch(Exception $ex) {
                if ($ch != null) curl_close($ch);
                if ($fp != null) fclose($fp);
            }
            if ( ! isset ( $status_code ) ) {
               if ( file_exists( $zip_path ) )
                    unlink( $zip_path );
                return false;
            } else if ( $status_code != 200 ) {
                // Add an error
                if ( $status_code == 404 || $status_code == 500 ) {
                    $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                } else if ( $status_code == 403 ) {
                     $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                    return false;
                } else {
                     $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                }

                if ( file_exists( $zip_path ) )
                    unlink( $zip_path );
                return false;
            } else {
                if ($ch != null) curl_close($ch);
                if ($fp != null) fclose($fp);
            }
        } else {
            $edd_settings = get_option( 'edd_settings' );
            $gh_access_token = isset ( $edd_settings['gh_access_token'] ) ? $edd_settings['gh_access_token'] : '';

            if ( empty ( $gh_access_token ) ) { // If we don't have a GitHub oAuth access token, add error and bail.
                // Add Errors
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'Not connected to GitHub.', 'edd-git' ) );
                // Bail
                return false;
            }

            $response = wp_remote_get( $this->url, array( 'timeout' => 15000 ) );
            $content_type = isset ( $response['headers']['content-type'] ) ? $response['headers']['content-type'] : '';

            if ( 'application/zip' != $content_type )  {
                // Add error
                $this->errors[ $this->file_key ] = array( 'error' => $error, 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                // Bail
                return false;                    
            }

            $fp = fopen( $zip_path, 'w' );
            fwrite( $fp, $response['body'] );
        }

        do_action( 'edd_git_zip_fetched', $zip_path, $this->git_repo );

        return $zip_path;
    }

    /*
     * Unzip our file into a new temporary folder.
     *
     * @param string $zip_path
     * @since 1.0
     * @return string $new_dir
     */

    private function unzip( $zip_path ) {

        if ( is_dir( trailingslashit( $this->tmp_dir . $this->folder_name ) ) )
            $this->remove_dir( trailingslashit( $this->tmp_dir . $this->folder_name ) );

        $zip = new ZipArchive;
        $zip->open( $zip_path );
        $zip->extractTo( $this->tmp_dir );
        $zip->close();
        $this->set_sub_dir( $this->tmp_dir );

        $new_dir = rename( $this->tmp_dir . $this->sub_dir, $this->tmp_dir . $this->folder_name );
        if ( ! $new_dir )
            return false;
        $new_dir = $this->tmp_dir . $this->folder_name;
        $this->set_sub_dir( $this->tmp_dir );
        unlink( $this->tmp_dir . $this->file_name );

        return $new_dir;
    }

    /*
     * Zip our directory and return the path to the zip file.
     *
     * @param string $dir
     * @since 1.0
     * @return string $destination
     */

    private function zip( $dir, $destination ) {

        //Don't forget to remove the trailing slash

        $the_folder = $dir;
        $zip_file_name = $destination;

        $za = new FlxZipArchive;

        $res = $za->open($zip_file_name, ZipArchive::CREATE);

        if($res === TRUE) {
            $za->addDir($the_folder, basename($the_folder));
            $za->close();
        }
        else
            echo 'Could not create a zip archive';

        return $destination;
    }

    /*
     * Delete tmp directory and all contents
     *
     * @param string $dir
     * @since 1.0
     * @return void
     */

    private function remove_dir( $dir ) {
        foreach(scandir($dir) as $file) {
            if ('.' === $file || '..' === $file)
                continue;
            $dir = trailingslashit( $dir );
            if ( is_dir( $dir . $file ) ) {
               $this->remove_dir( $dir . $file );
            } else {
                unlink( $dir . $file );
            }
        }
        rmdir($dir);
    }

    /*
     * Get our newly unzipped subdirectory name.
     *
     * @param string $tmp_dir
     * @since 1.0
     * @return void
     */

    private function set_sub_dir( $tmp_dir ) {
        $dir_array = array();
        // Bail if we weren't sent a directory.
        if ( !is_dir( $tmp_dir ) )
            return $dir_array;

        if ( $dh = opendir( $tmp_dir ) ) {
            while ( ( $file = readdir( $dh ) ) !== false ) {
                if ($file == '.' || $file == '..') continue;
                if ( strpos( $file, $this->git_repo ) !== false ) {
                    if ( is_dir ( $tmp_dir.'/'.$file ) ) {
                        $this->sub_dir = $file;
                        break;
                    }
                }
            }
            closedir($dh);
        }
    }

    /*
     * Set our EDD uploads directory.
     *
     * @since 1.0
     * @return void
     */

    private function set_edd_dir() {
        add_filter( 'upload_dir', 'edd_set_upload_dir' );
        $upload_dir = wp_upload_dir();
        wp_mkdir_p( $upload_dir['path'] );
        $this->edd_dir = $upload_dir;
    }

    /** Add our settings to the EDD file download fields

    */

    public function edd_files_fields( $post_id = 0 ) {
        $type             = edd_get_download_type( $post_id );
        $files            = edd_get_download_files( $post_id );
        $variable_pricing = edd_has_variable_prices( $post_id );
        $display          = $type == 'bundle' ? ' style="display:none;"' : '';
        $variable_display = $variable_pricing ? '' : 'display:none;';
        ?>
            <div id="edd_download_files"<?php echo $display; ?>>
                <p>
                    <strong><?php _e( 'File Downloads:', 'edd-git' ); ?></strong>
                </p>
                <div id="edd_git_error" style="color:red;">

                </div>
                <input type="hidden" id="edd_download_files" class="edd_repeatable_upload_name_field" value=""/>

                <div id="edd_file_fields" class="edd_meta_table_wrap">
                    <table class="widefat edd_repeatable_table" width="100%" cellpadding="0" cellspacing="0">
                        <thead>
                            <tr>
                                <!--drag handle column. Disabled until we can work out a way to solve the issues raised here: https://github.com/easydigitaldownloads/Easy-Digital-Downloads/issues/1066
                                <th style="width: 20px"></th>
                                -->
                                <th style="width: 20%"><?php _e( 'Git Repo', 'edd-git' ); ?></th>
                                <th style="width: 5%"></th>
                                <th style="width: 10%;"><?php _e( 'Version Tag', 'edd-git' ); ?></th>
                                <th style="width: 20%"><?php _e( 'File Name', 'edd-git' ); ?></th>
                                <th style="width: 20%"><?php _e( 'Plugin Folder Name', 'edd-git' ); ?></th>
                                <th style="width: 10%"></th>
                                <th class="pricing" style="width: 20%; <?php echo $variable_display; ?>"><?php _e( 'Price Assignment', 'edd-git' ); ?></th>
                                <?php do_action( 'edd_download_file_table_head', $post_id ); ?>
                                <th style="width: 2%"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                            if ( ! empty( $files ) && is_array( $files ) ) :
                                $files = array_slice( $files, 0, 1 );
                                foreach ( $files as $key => $value ) :
                                    $name               = isset( $value['name'] )               ? $value['name']                    : '';
                                    $file               = isset( $value['file'] )               ? $value['file']                    : '';
                                    $condition          = isset( $value['condition'] )          ? $value['condition']               : false;
                                    $attachment_id      = isset( $value['attachment_id'] )      ? absint( $value['attachment_id'] ) : false;
                                    $git_url            = isset( $value['git_url'] )            ? $value['git_url']                 : '';
                                    $git_folder_name    = isset( $value['git_folder_name'] )    ? $value['git_folder_name']         : '';
                                    $git_version        = isset( $value['git_version'] )        ? $value['git_version']             : '';
                                    
                                    $args = apply_filters( 'edd_file_row_args', compact( 'name', 'file', 'condition', 'attachment_id', 'git_url', 'git_folder_name', 'git_version' ), $value );
                        ?>
                                <tr class="edd_repeatable_upload_wrapper edd_repeatable_row" data-key="<?php echo esc_attr( $key ); ?>">
                                    <?php do_action( 'edd_render_file_row', 0, $args, $post_id ); ?>
                                </tr>
                        <?php
                                endforeach;
                            else :
                        ?>
                            <tr class="edd_repeatable_upload_wrapper edd_repeatable_row">
                                <?php do_action( 'edd_render_file_row', 0, array(), $post_id ); ?>
                            </tr>
                        <?php endif; ?>
                            <tr>
                                <td class="submit" colspan="4" style="float: none; clear:both; background: #fff;">
                                    <a class="button-secondary edd_add_repeatable" style="margin: 6px 0 10px;"><?php _e( 'Add New File', 'edd-git' ); ?></a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php
    }

    /**
     * Render our file row
     */

    public function edd_render_file_row( $key = 0, $args = array(), $post_id ) {
        $defaults = array(
            'name'              => null,
            'file'              => null,
            'condition'         => null,
            'attachment_id'     => null,
            'git_url'           => null,
            'git_folder_name'   => null,
            'git_version'       => null
        );

        $args = wp_parse_args( $args, $defaults );

        $prices = edd_get_variable_prices( $post_id );

        $variable_pricing = edd_has_variable_prices( $post_id );
        $variable_display = $variable_pricing ? '' : ' style="display:none;"';

        $repos = $this->fetch_repos( $args );
        $current_tags = $this->fetch_tags( $args['git_url'] );

        if ( ! empty ( $args['git_url'] ) ) {
            $repo = parse_url( $args['git_url'] );
            $path = $repo['path'];
            $repo_slug = explode( '/', $path );
            $repo_slug = $repo_slug[2];
            $default_file_name = $repo_slug . '-' . $args['git_version'] . '.zip';
        } else {
            $repo_slug = '';
            $current_tags = array();
            $default_file_name = '';
        }

        ?>

        <input type="hidden" name="edd_download_files[<?php echo $key; ?>][attachment_id]" value="0">
        <input type="hidden" id="edd_git_file" name="edd_download_files[<?php echo $key; ?>][file]" value="<?php echo $args['file']; ?>">

        <td style="width: 20%">
            <div class="edd_repeatable_upload_field_container">
                <select name="edd_download_files[<?php echo $key; ?>][git_url]" class="git-repo" style="width:100%">
                    <?php
                    $this->output_repo_options( $repos, $args['git_url'] );
                    ?>
               </select>
            </div>
        </td>

        <td>
            <a href="#" class="edd-git-fetch-repos"><span class="dashicons dashicons-update"></span><span class="spinner" style="margin-left: -1px; float:left"></a>
        </td>

        <td>
            <div class="edd_repeatable_upload_field_container git-tag-div">
                <select name="edd_download_files[<?php echo $key; ?>][git_version]" style="min-width:125px;" class="git-tag">
                   <?php
                   foreach ( $current_tags as $tag ) {
                    ?>
                    <option value="<?php echo $tag; ?>" <?php selected( $tag == $args['git_version'] ); ?>><?php echo $tag; ?></option>
                    <?php
                   }
                   ?>
                </select>
            </div>
            <div class="git-tag-spinner" style="display:none;">
                <span class="spinner" style="visibility:visible;display:block;float:left;margin-bottom:2px;margin-left:15px;"></span>
            </div>
        </td>

        <td>
            <?php echo EDD()->html->text( array(
                'name'        => 'edd_download_files[' . $key . '][name]',
                'value'       => $args['name'],
                'placeholder' => $default_file_name,
                'class'       => 'edd_repeatable_name_field large-text git-file-name'
            ) ); ?>
        </td>

        <td style="width: 20%">
            <div class="edd_repeatable_upload_field_container">
                <?php echo EDD()->html->text( array(
                    'name'        => 'edd_download_files[' . $key . '][git_folder_name]',
                    'value'       => $args['git_folder_name'],
                    'placeholder' => $repo_slug,
                    'class'       => 'edd_repeatable_upload_field large-text git-folder-name'
                ) ); ?>
            </div>
        </td>

        <?php
        if ( ! empty ( $args['file'] ) ) {
            $check_style = 'style="margin-top:3px;"';
            $text_style = 'style="display:none;"';
        } else {
            $check_style = ' style="display:none;margin-top:3px;"';
            $text_style = '';
        }
        ?>

        <td>
            <div class="edd_repeatable_upload_field_container">
                <a href="#" class="button-secondary edd-git-update" style="float:left"><span class="git-update-text" <?php echo $text_style; ?>><?php _e( 'Fetch', 'edd-git' ); ?></span><span class="dashicons dashicons-yes git-update-check" <?php echo $check_style; ?>></span><span class="dashicons dashicons-no-alt git-update-none" style="margin-top:3px;display:none;"></span></a>
                <span class="spinner git-update-spinner" style="float:left;margin-top:5px;"></span> 
            </div>
        </td>

        <td class="pricing"<?php echo $variable_display; ?>>
            <?php
                $options = array();

                if ( $prices ) {
                    foreach ( $prices as $price_key => $price ) {
                        $options[ $price_key ] = $prices[ $price_key ]['name'];
                    }
                }

                echo EDD()->html->select( array(
                    'name'             => 'edd_download_files[' . $key . '][condition]',
                    'class'            => 'edd_repeatable_condition_field git-condition',
                    'options'          => $options,
                    'selected'         => $args['condition'],
                    'show_option_none' => false
                ) );
            ?>
        </td>

        <?php do_action( 'edd_download_file_table_row', $post_id, $key, $args ); ?>

        <td>
            <a href="#" class="edd_remove_repeatable" data-type="file" style="background: url(<?php echo admin_url('/images/xit.gif'); ?>) no-repeat;">&times;</a>
        </td>

        <div id="edd-git-admin-modal-backdrop" style="display: none;"></div>
        <div id="edd-git-admin-modal-wrap" class="wp-core-ui" style="display: none;">
            <div id="edd-git-admin-modal" tabindex="-1">
                <div id="admin-modal-title">
                    <span id="edd-git-modal-title"></span>
                    <button type="button" id="edd-git-admin-modal-close" class="modal-close"><span class="screen-reader-text modal-close">Close</span></button>
                </div>
                <div id="modal-contents-wrapper" style="padding:20px;">
                    <div id="edd-git-admin-modal-content" class="admin-modal-inside">
                        
                    </div>
                    <div class="submitbox" style="display:block;">
                        
                    </div>
                </div>
            </div>
        </div>


        <div class="edd-git-fetch-prompt" style="display:none;">
            <?php _e( 'The git file has not been fetched. Would you like to fetch it first?.', 'edd-git' ); ?>
        </div>
        <div class="edd-git-fetch-prompt-buttons" style="display:none;">
            <div id="edd-git-admin-modal-cancel">
                <a class="submitdelete deletion modal-close edd-git-save-cancel" href="#"><?php _e( 'Cancel', 'edd-git' ); ?></a>
            </div>
            <div id="edd-git-admin-modal-update">
                <a class="button-primary edd-git-fetch-continue" href="#"><?php _e( 'Fetch and Continue', 'edd-git' ); ?></a>
            </div>
        </div>
        <?php
    }

    public function output_repo_options( $repos, $current_repo = '' ) {
        ?>
        <option value="" data-slug=""><?php _e( 'Select a repo', 'edd-git' ); ?></option>
        <?php
        $owner = '';
        foreach ( $repos as $source => $rs ) {
            foreach ( $rs as $url => $repo ) {
                if ( is_int( $url ) ) {
                   if ( isset ( $repo['open'] ) ) {
                    $owner = $repo['open'];
                    ?>
                    <optgroup label="<?php echo $owner; ?>">
                   <?php
                   } else {
                    ?>
                    </optgroup>
                    <?php
                   }
                } else {
                    ?>
                    <option value="<?php echo $url; ?>" <?php selected( $url, $current_repo ); ?> data-source="<?php echo $source; ?>" data-slug="<?php echo $repo; ?>"><?php echo $repo; ?></option>
                    <?php
                }
            }
        }
    }

    public function fetch_repos() {
        if ( false != ( $repos = get_option( 'edd_git_repos', false ) ) ) {
            return $repos;
        } else {
            $edd_settings = edd_get_settings();
            $gh_access_token = isset ( $edd_settings['gh_access_token'] ) ? $edd_settings['gh_access_token'] : '';
            $repos = array();
            $headers = array();
            $headers['Accept'] = 'application/vnd.github.moondragon+json';
            if ( ! empty ( $gh_access_token ) ) {
                // Get a list of our GH repos
                $body = true;
                $page = 1;
                $owner = '';
                while ( ! empty( $body ) ) {
                    $url = 'https://api.github.com/user/repos?per_page=100&page=' . $page . '&access_token=' . $gh_access_token;
                    $response = wp_remote_get( $url, array( 'sslverify' => false, 'headers' => $headers ) );
                    $body = json_decode( wp_remote_retrieve_body( $response ), true ); 
                    if ( is_array( $body ) ) {
                        foreach ( $body as $repo ) {
                            if ( $owner != $repo['owner']['login'] ) {
                                if ( $owner != '' ) {
                                    $repos['gh'][] = array( 'close' );
                                }
                                $owner = $repo['owner']['login'];
                                $repos['gh'][] = array( 'open' => $owner );
                            }
                            $repos['gh'][ $repo['html_url'] ] = $repo['name'];
                        }
                    }
                    $page++;
                }
            }

            if ( defined( 'EDD_GIT_BB_USER' ) && defined( 'EDD_GIT_BB_PASSWORD' ) ) {
                $ch = curl_init();
                curl_setopt( $ch, CURLOPT_URL, 'https://bitbucket.org/api/1.0/user/repositories' );
                curl_setopt( $ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt( $ch, CURLOPT_USERPWD, EDD_GIT_BB_USER . ":" . EDD_GIT_BB_PASSWORD );
                curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
                curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
                curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                $resp = curl_exec( $ch );
                $bb_repos = json_decode( $resp, true );

                // echo "<pre>";
                // print_r( $bb_repos );
                // echo "</pre>";

                if ( ! empty ( $bb_repos ) ) {
                    $owner = '';
                    foreach ( $bb_repos as $repo ) {
                        if ( $owner != $repo['owner'] ) {
                            if ( $owner != '' ) {
                                $repos['bb'][] = array( 'close' );
                            }
                            $owner = $repo['owner'];
                            $repos['bb'][] = array( 'open' => $owner );
                        }
                        $html_url = 'https://bitbucket.org/' . $owner . '/' . $repo['slug'];
                        $repos['bb'][ $html_url ] = $repo['slug'];
                    }
                }
            }

            update_option( 'edd_git_repos', $repos );
            return $repos;
        }
    }

    public function fetch_tags( $repo_url ) {
        if ( false !== strpos( $repo_url, 'bitbucket.org' ) ) { // Bitbucket url
            $replace = 'https://bitbucket.org/';
            $function = 'bb_get_tags';
        } else { // GitHub url
            $replace = 'https://github.com/';
            $function = 'gh_get_tags';
        }

        $slug = str_replace( $replace, '', $repo_url );

        return $this->$function( $slug );
    }

    /*
     * Add our default git settings to the Extensions tab
     *
     * @since 1.0
     * @return array $extensions
     */

    public function edd_extensions_settings( $extensions ) {

        $this->admin_js();

        $extensions['gh_begin'] = array(
            'id'    => 'gh_begin',
            'name'  => __( 'GitHub Updater', 'edd-git' ),
            'desc'  => '',
            'type'  => 'header',
            'std'   => '',
        );

        $extensions['gh_desc'] = array(
            'id'    => 'git_gh_desc',
            'name'  => '',
            'desc'  => '',
            'type'  => 'hook',
            'std'   => '',
        );
        $extensions['gh_clientid'] = array(
            'id'    => 'gh_clientid',
            'name'  => __( 'Client ID', 'edd-git' ),
            'desc'  => '',
            'type'  => 'text',
            'std'   => ''
        );
        $extensions['gh_clientsecret'] = array(
            'id'    => 'gh_clientsecret',
            'name'  => __( 'Client Secret', 'edd-git' ),
            'desc'  => '',
            'type'  => 'text',
            'std'   => ''
        );        
        $extensions['gh_authorize_button'] = array(
            'id'    => 'git_gh_authorize_button',
            'name'  => '',
            'desc'  => '',
            'type'  => 'hook',
            'std'   => ''
        );
        $extensions['bb_begin'] = array(
            'id'    => 'bb_begin',
            'name'  => __( 'BitBucket Updater', 'edd-git' ),
            'desc'  => '',
            'type'  => 'header',
            'std'   => '',
        );
        $extensions['bb_desc'] = array( 
            'id'    => 'git_bb_desc',
            'name'  => '',
            'desc'  => '',
            'type'  => 'hook',
            'std'   => '',
        );

        return $extensions;
    }

    /*
     * Output our GitHub description text
     *
     * @since 1.1
     * @return void
     */

    public function gh_desc() {
        $edd_settings = edd_get_settings();
        $gh_access_token = isset ( $edd_settings['gh_access_token'] ) ? $edd_settings['gh_access_token'] : '';
        
        if ( ! empty ( $gh_access_token ) ) {
            $connected = '';
            $disconnected = 'style="display:none;"';
        } else {
            $connected = 'style="display:none;"';
            $disconnected = '';
        }

        $html = '<span class="edd-git-github-connected" ' . $connected . ' ><p>Connected to GitHub.</p></span>';
        $html .= '<span class="edd-git-github-disconnected" ' . $disconnected . ' ><p>Updating from private repositories requires a one-time application setup and authorization. These steps will not need to be repeated for other sites once you receive your access token.</p>
                <p>Follow these steps:</p>
                <ol>
                    <li><a href="https://github.com/settings/applications/new" target="_blank">Create an application</a> with the <strong>Main URL</strong> and <strong>Callback URL</strong> both set to <code>' . get_bloginfo( 'url' ) . '</code></li>
                    <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> from your <a href="https://github.com/settings/applications" target="_blank">application details</a> into the fields below.</li>
                    <li>Authorize with GitHub.</li>
                </ol></span>';
        echo $html;
    }

    /*
     * Output our GitHub Authorize Button
     *
     * @since 1.1
     * @return void
     */

    public function gh_authorize_button() {
        $edd_settings = get_option( 'edd_settings' );
        $gh_access_token = isset ( $edd_settings['gh_access_token'] ) ? $edd_settings['gh_access_token'] : '';
        
        if ( ! empty ( $gh_access_token ) ) {
            $connected = '';
            $disconnected = 'display:none;';
        } else {
            $connected = 'display:none;';
            $disconnected = '';
        }

        $html = '<a href="#" style="display:block;float:left;' . $connected . '" id="edd-github-disconnect" class="button-secondary edd-git-github-connected">' . __( 'Disconnect From GitHub', 'edd-git' ) . '</a>';
        $html .= '<a href="#" style="display:block;float:left;' . $disconnected .' " id="edd-github-auth" class="button-secondary edd-git-github-disconnected">' . __( 'Authorize With GitHub', 'edd-git' ) .'</a>';
        $html .= '<span style="float:left" class="spinner" id="edd-git-github-spinner"></span>';
        echo $html;
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

    /*
     * Display our BitBucket description
     *
     * @since 1.1
     * @return void
     */

    public function bb_desc() {
        $html = '<p>Currently, BitBucket does not support downloading files via OAuth.</p>
        <p class="howto">There is a <a href="https://bitbucket.org/site/master/issue/7592/download-source-from-private-repo-via-api">feature request thread</a> asking BitBucket to include this ability.</p>
        <p>The lack of OAuth support means that we are forced to use basic authentication using a username and password defined within your wp-config.php file.</p>
        <br>';

        if ( defined( 'EDD_GIT_BB_USER' ) && defined( 'EDD_GIT_BB_PASSWORD' ) ) {
            $html .= '<p>Username: ' . EDD_GIT_BB_USER .'</p><p>Pasword: **********</p>';
        } else {
            $html .= '<p>Please use the <code>EDD_GIT_BB_USER</code> and <code>EDD_GIT_BB_PASSWORD</code> constants within your wp-config.php file to set your BitBucket credentials.</p>';
        }

        echo $html;
    }

    public function gh_get_tags( $tag_url ) {
        $edd_settings = edd_get_settings();
        $gh_access_token = isset ( $edd_settings['gh_access_token'] ) ? $edd_settings['gh_access_token'] : '';

        $tag_url = 'https://api.github.com/repos/' . $tag_url . '/tags?access_token=' . $gh_access_token;
        // var_dump( $tag_url );
        $get_tags = wp_remote_get( $tag_url, array( 'sslverify' => false ) );
        $tags = json_decode( wp_remote_retrieve_body( $get_tags ), true );
        $return_tags = array();
        if ( is_array ( $tags ) && ! isset ( $tags['message'] ) ) {
            foreach ( $tags as $tag ) {
                // var_dump( $tag );
                $return_tags[] = $tag['name'];
            }
            usort( $return_tags, 'version_compare' );
            rsort( $return_tags );
        } else {
            $return_tags['error'] = __( 'Could not find any tags for repository.', 'edd-gi' );
        }

        return $return_tags;
    }

    public function bb_get_tags( $tag_url ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, 'https://bitbucket.org/api/1.0/repositories/' . $tag_url . '/tags/' );
        curl_setopt( $ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt( $ch, CURLOPT_USERPWD, EDD_GIT_BB_USER . ":" . EDD_GIT_BB_PASSWORD );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        $resp = curl_exec( $ch );
        $tags = json_decode( $resp, true );
        $return_tags = array();
        if ( is_array ( $tags ) ) {
            foreach ( $tags as $tag => $data ) {
                // var_dump( $tag );
                $return_tags[] = $tag;
            }
            usort( $return_tags, 'version_compare' );
            rsort( $return_tags );
        } else {
            $return_tags['error'] = __( '` any tags for repository.', 'edd-gi' );
        }

        return $return_tags;
    }

    public function ajax_repos() {
        delete_option( 'edd_git_repos' );
        $current_repo = $_REQUEST['current_repo'];
        $repos = $this->fetch_repos();
        ob_start();
        $this->output_repo_options( $repos, $current_repo );
        $options = ob_get_clean();

        header("Content-type: application/json");
        echo json_encode( array( 'options_html' => $options ) );
        die();
    }

    public function ajax_tags() {
        $repo = $_REQUEST['repo'];
        $return_tags = $this->fetch_tags( $repo );

        header("Content-type: application/json");
        echo json_encode( $return_tags );
        die();
    }

    public function output_file_checkbox( $post_id = 0 ) {
        $checked = get_post_meta( $post_id, '_edd_download_use_git', true );
        ?>
        <input type="hidden" value="0" name="_edd_download_use_git">
        <label><input type="checkbox" value="1" name="_edd_download_use_git" id="_edd_download_use_git" <?php checked( $checked, 1 ); ?>> <?php _e( 'Fetch download from a git repo.', 'edd-git' ); ?></label>
        <?php
    }

    public function ajax_use_git() {

        $checked = isset ( $_REQUEST['checked'] ) ? $_REQUEST['checked'] : 0;
        $post_id = isset ( $_REQUEST['post_id'] ) ? $_REQUEST['post_id'] : 0;
        if ( ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) || empty ( $post_id ) || ! is_admin() || ! current_user_can( 'edit_product', $post_id ) ) {
            die();
        }

        update_post_meta( $post_id, '_edd_download_use_git', $checked );

        require_once( EDD_PLUGIN_DIR . 'includes/admin/downloads/metabox.php' );

        if ( 1 == $checked ) {
            $this->register_git_section();
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

    public function get_current_tag( $download_id ) {
        $files = get_post_meta( $download_id, 'edd_download_files', true );
        if ( empty ( $files ) )
            return false;

        foreach ( $files as $file ) {
            $version = isset ( $file['git_version'] ) ? $file['git_version'] : '';
            return $version;
        }
    }

    public function get_current_repo_url( $download_id ) {
        $files = get_post_meta( $download_id, 'edd_download_files', true );
        if ( empty ( $files ) )
            return false;

        foreach ( $files as $file ) {
            $url = isset ( $file['git_url'] ) ? $file['git_url'] : '';
            return $url;
        }
    }

} // End EDD_GIT_Download_Updater class

// Get the download updater class started
function edd_git_download_updater() {
    $EDD_GIT_Download_Updater = new EDD_GIT_Download_Updater();
}

// Hook into the post save action

add_action( 'admin_init', 'edd_git_download_updater', 9 );
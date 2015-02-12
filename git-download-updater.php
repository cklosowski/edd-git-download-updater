<?php
/*
Plugin Name: Easy Digital Downloads - Git Update Downloads
Plugin URI: http://ninjaforms.com
Description: Update Download files and changelog directly from BitBucket or GitHub
Version: 1.0
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
    private $filename;

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
     * Get things up and running.
     *
     * @since 1.0
     * @return void
     */

    public function __construct() {
        // Bail if the zip extension hasn't been loaded.
        if ( ! class_exists('ZipArchive') )
            return false;

        $settings_url = admin_url( 'edit.php?post_type=download&page=edd-settings&tab=extensions' );
        // Set our error messages;
        $this->gh_error_msg = __( 'Failed: Not connected to GitHub. Please visit the <a href="' . $settings_url . '">EDD Settings</a> page to connect.', 'edd-git' );
        $this->bb_error_msg = __( 'Failed: No BitBucket credentials found. Please add EDD_GIT_BB_USER and EDD_GIT_BB_PASSWORD to your wp-config.php file.', 'edd-git' );

        // Add our settings to the EDD Extensions tab
        add_filter( 'edd_settings_extensions', array( $this, 'edd_extensions_settings' ) );

        // Add our settings to the download editor.
        add_action( 'edd_download_file_table_head', array( $this, 'edd_metabox_th' ), 11 );
        add_action( 'edd_download_file_table_row', array( $this, 'edd_metabox_td' ), 11, 3 );

        // Do something when a post is saved
        add_action( 'save_post', array( $this, 'save_post' ), 999 );

        // Add our admin notice action
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        /* GitHub */

        // Add our GitHub description hook.
        add_action( 'edd_git_gh_desc', array( $this, 'gh_desc' ) );

        // Add our GitHub Authorization button hook.
        add_action( 'edd_git_gh_authorize_button', array( $this, 'gh_authorize_button' ) );

        // Add our JS to the EDD misc settings page.
        add_action( 'admin_init', array( $this, 'admin_js' ) );

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
     * Include our JS on the EDD misc settings page.
     *
     * @since 1.1
     * @return void
     */

    public function admin_js() {
        global $pagenow;

        if ( 'edit.php' == $pagenow && isset ( $_REQUEST['page'] ) && 'edd-settings' == $_REQUEST['page'] && isset ( $_REQUEST['tab'] ) && 'misc' == $_REQUEST['tab'] ) {
            wp_enqueue_script( 'edd-git-updater', EDD_GIT_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ) );
        }
    }

    /*
     * Check to see if we are saving an EDD download. If we are, run our stuff.
     *
     * @since 1.0
     * @return void
     */

    public function save_post( $post_id ) {
        // Bail if we aren't saving a download
        if ( !isset ( $_POST['post_type'] ) or $_POST['post_type'] != 'download' )
            return $post_id;

        // verify if this is an auto save routine.
        // If it is our form has not been submitted, so we dont want to do anything
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
          return $post_id;

        if ( !current_user_can( 'edit_product', $post_id ) )
            return $post_id;

        // OK, we're authenticated: we need to find and save the data
        $this->download_id = $post_id;

        $files = get_post_meta( $this->download_id, 'edd_download_files', true );
        if ( is_array( $files ) ) {
            foreach( $files as $key => $file ) {
                if ( ! empty( $file['git_url'] ) ) {
                    $this->file_key = $key;
                    $this->update_download( $file );
                }
            }
        }

        update_post_meta( $this->download_id, 'edd_git_errors', $this->errors );
    }

    /*
     * Run all of the functions necessary to update our download.
     *
     * @param int $post_id
     * @since 1.0
     * @return void
     */

    public function update_download( $file ) {
        // Setup our initial variables
        $this->includes();
        $this->set_version( $file );

        if ( ! $this->set_url( $file ) ) // If we bailed during the set_url function, stop processing update.
            return false;

        $this->set_foldername( $file );
        $this->set_tmp_dir();
        $this->set_edd_dir();
        $this->set_filename( $file );

        // Grab our zip file.
        $zip_path = $this->fetch_zip( $file );
        if ( ! $zip_path ) // If we bailed during the fetch_zip function, stop processing update.
            return false;

        // Unzip our file to a new temporary directory.
        $new_dir = $this->unzip( $zip_path );

        // Update our changelog with the readme in the new directory but only if we are dealing with one file.
        if ( count( get_post_meta( $this->download_id, 'edd_download_files', true ) ) == 1 ) {
            $this->update_changelog( $new_dir );
        }

        // Create our new zip file
        $zip = $this->zip( $new_dir, $this->tmp_dir .  $this->filename );

        // Move our temporary zip to the proper EDD folder
        $new_zip = $this->move_zip( $zip );

        // Remove our temporary files
        $this->remove_dir( $this->tmp_dir );

        // Reset our temporary directory
        $this->set_tmp_dir();

        // Update our file with the new zip location.
        $this->update_files( $new_zip['url'] );
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
                $changelog = wp_kses( $content['sections']['changelog'] );
                update_post_meta( $this->download_id, '_edd_sl_changelog', $changelog );
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
        $files = get_post_meta( $this->download_id, 'edd_download_files', true );
        $files[$this->file_key]['file'] = $new_zip;
        update_post_meta( $this->download_id, 'edd_download_files', $files );
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
        $upload_path = apply_filters( 'edd_git_upload_path', $edd_dir . $this->filename );
        $upload_url = apply_filters( 'edd_git_upload_url', $edd_url . $this->filename );

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

    private function set_url( $file, $v = 'v' ) {
        $edd_settings = get_option( 'edd_settings' );

        if ( ! empty( $file['git_url'] ) ) {
            $this->url = $file['git_url'];
        } else {
            // Throw an error
            $error = '404';
            $msg = __( 'Repo not found. Please check your URL and version.', 'edd-git' );
            $this->errors[ 'admin_notices' ][] = $msg;
            $this->errors[ $this->file_key ] = array( 'error' => $error, 'msg' => $msg );
            return false;
        }

        $url = trailingslashit( $this->url );

        $tmp = explode( '/', $url );

        $user = $tmp[3];
        $repo = $tmp[4];

        if ( 'bitbucket.org' == $tmp[2] ) {
            // Add an error and bail if our BB user and password aren't set
            if ( ! defined( 'EDD_GIT_BB_USER' ) || ! defined( 'EDD_GIT_BB_PASSWORD' ) ) {
                // Add errors
                $this->errors['admin_notices'][] = $this->bb_error_msg;
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => $this->bb_error_msg );
                // Bail
                return false;
            }
            $url_part = 'get/' . $v . $this->version .'.zip';
            $url .= $url_part;
            $this->source = 'bitbucket';
        } else if ( 'github.com' == $tmp[2] ) {
            $access_token = isset ( $edd_settings['gh_access_token'] ) ? $edd_settings['gh_access_token'] : '';
            if ( empty( $access_token ) ) { // If we don't have an oAuth access token, add error and bail.
                // Add Error
                $this->errors['admin_notices'][] = $this->gh_error_msg;
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => $this->gh_error_msg );
                // Bail
                return false;
            } else {
                $url = 'https://api.github.com/repos/' . $user . '/' . $repo . '/zipball/' . $v . $this->version . '?access_token=' . $access_token;
            }
            $this->source = 'github';
        } else {
            // Throw an error
            $error = '404';
            $msg = __( 'Repo not found. Please check your URL and version.', 'edd-git' );
            $this->errors['admin_notices'][] = $msg;
            $this->errors[ $this->file_key ] = array( 'error' => $error, 'msg' => $msg );
            // Bail
            return false;
        }

        $this->git_repo = $tmp[4];

        $this->url = apply_filters( 'edd_git_repo_url', $url );
        return $this->url;
    }

    /*
     * Set our version
     *
     * @since 1.0
     * @return void
     */

    private function set_version( $file ) {
        if ( ! empty( $file['git_version'] ) ) {
            $this->version = $file['git_version'];
        } else {
            $sl_version = get_post_meta( $this->download_id, '_edd_sl_version', true );
            $this->version = $sl_version;
        }
    }

    /*
     * Set our clean zip file name
     *
     * @since 1.0
     * @return void
     */

    private function set_filename( $file ) {
        if ( ! empty( $file['name'] ) ) {
            $this->filename = $file['name'] . '.zip';
        } else {
            $this->filename = $this->git_repo . '.' . $this->version . '.zip';
        }

    }

    /*
     * Set the name of our folder that should go inside our new zip.
     *
     * @since 1.0
     * @return void
     */
    private function set_foldername( $file ) {
        if ( !empty ( $file['zip_foldername'] ) ) {
            $this->folder_name = $file['zip_foldername'];
        } else {
            $this->folder_name = sanitize_title( $this->git_repo );
        }

    }

    /*
     * Grab the zip file from git and store it in our temporary directory.
     *
     * @since 1.0
     * @param array $file
     * @param int $try
     * @return string $zip_path
     */

    public function fetch_zip( $file, $try = '' ) {
        $zip_path = $this->tmp_dir . $this->filename;

        if ( 'bitbucket' == $this->source ) {
            if ( ! defined( 'EDD_GIT_BB_USER' ) || ! defined( 'EDD_GIT_BB_PASSWORD' ) ) { // If BB credentials aren't set, add error and bail.
                // Add Errors
                $this->errors['admin_notices'][] = $this->bb_error_msg;
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => $this->bb_error_msg );
                // Bail
                return false;
            }

            if ( ! function_exists( 'curl_version' ) ) {
                // Add Errors
                $this->errors['admin_notices'][] = __( 'cURL is not enabled. Please contact your host.');
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => __( 'cURL is not enabled. Please contact your host.') );
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
                    if ( $try == 2 ) {
                        $error = '404';
                        $msg = __( 'Repo not found. Please check your URL and version.', 'edd-git' );
                        $this->errors['admin_notices'][] = $msg;
                        $this->errors[ $this->file_key ] = array( 'error' => $error, 'msg' => $msg );
                    } else {
                        $this->set_url( $file, '' );
                        return $this->fetch_zip( $file, 2 );
                    }

                } else if ( $status_code == 403 ) {
                    $error = '403';
                    $msg = __( 'Cannot access repo. Please check your connection.', 'edd-git' );
                    $this->errors['admin_notices'][] = $msg;
                    return false;
                } else {
                    $error = '403';
                    $msg = __( 'Cannot access repo. Please check your username and password.', 'edd-git' );
                    $this->errors['admin_notices'] = $msg;
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
                $this->errors['admin_notices'][] = $this->gh_error_msg;
                $this->errors[ $this->file_key ] = array( 'error' => '404', 'msg' => $this->gh_error_msg );
                // Bail
                return false;
            }

            $response = wp_remote_get( $this->url, array( 'timeout' => 15000 ) );
            $content_type = isset ( $response['headers']['content-type'] ) ? $response['headers']['content-type'] : '';

            if ( 'application/zip' != $content_type )  {
                if ( 2 == $try ) { // If we've tried to get the tag with and without a "v", add error and bail.
                    // Add error
                    $error = '404';
                    $msg = __( 'Failed: Repo not found. Please check your URL and version.', 'edd-git' );
                    $this->errors[ 'admin_notices' ][] = $msg;
                    $this->errors[ $this->file_key ] = array( 'error' => $error, 'msg' => $msg );
                    // Bail
                    return false;                    
                } else { // We've tried to get the tag with a "v", try again without it.
                    $this->set_url( $file, '' );
                    return $this->fetch_zip( $file, 2 );
                }

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
        unlink( $this->tmp_dir . $this->filename );

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

    /*
     * Add our settings heading to the table
     *
     * @since 1.0
     * @return void
     */

    public function edd_metabox_th( $post_id ) {
        ?>
        <th class="" width="10%" ><?php _e( 'git URL', 'edd-git' );?></th>
        <th class="" width="5%"><?php _e( 'git Version', 'edd-git' );?></th>
        <th class="" width="5%"><?php _e( 'Folder Name Inside Zip', 'edd-git' );?></th>
        <?php
    }

    /*
     * Add our settings to the download edit screen
     *
     * @since 1.0
     * @return void
     */

    public function edd_metabox_td( $post_id, $key, $args ) {
        $files = get_post_meta( $post_id, 'edd_download_files', true );
        $errors = get_post_meta( $post_id, 'edd_git_errors', true );

        if ( isset ( $files[$key]['git_url'] ) ) {
            $git_url = $files[$key]['git_url'];
        } else {
            $git_url = '';
        }

        if ( isset ( $files[$key]['git_version'] ) ) {
            $git_version = $files[$key]['git_version'];
        } else {
            $git_version = '';
        }

        if ( isset ( $files[$key]['zip_foldername'] ) ) {
            $zip_foldername = $files[$key]['zip_foldername'];
        } else {
            $zip_foldername = '';
        }

        $version_placeholder = '1.0';
        // If Software Licensing is enabled, change our $version_placeholder to say "Optional"
        if ( function_exists( 'edd_sl_textdomain' ) ) {
            $version_placeholder = __( 'Optional', 'edd-git' );
        }

        ?>
        <td>
            <input type="text" placeholder="<?php _e( 'git URL', 'edd-git' );?>" name="edd_download_files[<?php echo $key; ?>][git_url]" value="<?php echo $git_url;?>">
            <br />
            <?php
            if ( isset ( $errors[$key] ) and $errors[$key]['error'] == 404 ) {
                echo '<div style="color: red">' . $errors[$key]['msg'] . '</div>';
            }
            ?>
        </td>
        <td width="5%">
            <input type="text" placeholder="<?php echo $version_placeholder;?>" name="edd_download_files[<?php echo $key; ?>][git_version]" value="<?php echo $git_version;?>" style="width: 70px; padding: 3px 6px;">
            <br />
            <?php
            if ( isset ( $errors[$key] ) and $errors[$key]['error'] == 404 ) {
                echo '<div style="color: red">' . $errors[$key]['msg'] . '</div>';
            }
            ?>
        </td>
        <td width="5%">
            <input type="text" placeholder="<?php _e( 'Default: Repo Name' ); ?>" name="edd_download_files[<?php echo $key; ?>][zip_foldername]" value="<?php echo $zip_foldername;?>">
        </td>
    <?php
    }

    /*
     * Add our default git settings to the Misc. tab
     *
     * @since 1.0
     * @return array $extensions
     */

    public function edd_extensions_settings( $extensions ) {

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
        $edd_settings = get_option( 'edd_settings' );
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

    /*
     * Display admin error notices
     *
     * @since 1.1
     * @return void
     */

    public function admin_notices() {
        global $post, $typenow;

        // Bail if we aren't editing a download.
        if ( ! isset ( $_REQUEST['post'] ) || empty ( $_REQUEST['post'] ) || 'download' != $typenow )
            return false;

        $errors = get_post_meta( $post->ID, 'edd_git_errors', true );

        if ( isset ( $errors['admin_notices'] ) ) {
            foreach( $errors['admin_notices'] as $msg ) {
                echo '<div class="error"><p>' . $msg . '</p></div>';
            }
        }
    }

} // End EDD_GIT_Download_Updater class

// Get the download updater class started
function edd_git_download_updater() {
    $EDD_GIT_Download_Updater = new EDD_GIT_Download_Updater();
}

// Hook into the post save action

add_action( 'admin_init', 'edd_git_download_updater', 9 );

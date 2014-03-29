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

        // Add our settings to the EDD Misc tab
        add_filter( 'edd_settings_misc', array( $this, 'edd_misc_settings' ) );

        // Add our settings to the download editor.
        add_action( 'edd_meta_box_files_fields', array( $this, 'edd_metabox_settings' ) , 10 );
        add_action( 'edd_download_file_table_head', array( $this, 'edd_metabox_th' ), 11 );
        add_action( 'edd_download_file_table_row', array( $this, 'edd_metabox_td' ), 11, 3 );

        // Do something when a post is saved
        add_action( 'save_post', array( $this, 'save_post' ), 999 );

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
        if ( isset ( $_POST['edd_git_username'] ) ) {
            update_post_meta( $this->download_id, 'edd_git_username', $_POST['edd_git_username'] );
        }
        if ( isset ( $_POST['edd_git_password'] ) ) {
            update_post_meta( $this->download_id, 'edd_git_password', $_POST['edd_git_password'] );
        }

        $files = get_post_meta( $this->download_id, 'edd_download_files', true );
        if ( is_array( $files ) ) {
            foreach( $files as $key => $file ) {
                if ( isset ( $file['git_url'] ) and ! empty( $file['git_url'] ) ) {
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
        $this->set_url( $file );
        $this->set_credentials();
        $this->set_foldername( $file );
        $this->set_tmp_dir();
        $this->set_edd_dir();
        $this->set_filename( $file );

        // Grab our zip file.
        $zip_path = $this->fetch_zip( $file );
        if ( ! $zip_path )
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
                $changelog = $content['sections']['changelog'];
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
        if ( isset ( $file['git_url'] ) and ! empty( $file['git_url'] ) ) {
            $this->url = $file['git_url'];
        } else {
            // Throw an error
            $error = '404';
            $msg = __( 'Repo not found. Please check your URL and version.', 'edd-git' );
            $this->errors[$this->file_key] = array( 'error' => $error, 'msg' => $msg );
        }

        $url = trailingslashit( $this->url );

        $tmp = explode( '/', $url );

        if ( $tmp[2] == 'bitbucket.org' ) {
            $url_part = 'get/' . $v . $this->version .'.zip';
            $this->source = 'bitbucket';
        } else if ( $tmp[2] == 'github.com' ) {
            $url_part = 'archive/' . $v . $this->version . '.zip';
            $this->source = 'github';
        } else {
            // Throw an error
            $error = '404';
            $msg = __( 'Repo not found. Please check your URL and version.', 'edd-git' );
            $this->errors[$this->file_key] = array( 'error' => $error, 'msg' => $msg );
        }

        $this->git_repo = $tmp[4];

        $url = trailingslashit( $this->url ) . $url_part;
        $this->url = apply_filters( 'edd_git_repo_url', $url );
    }

    /*
     * Set our version
     *
     * @since 1.0
     * @return void
     */

    private function set_version( $file ) {
        if ( isset ( $file['git_version'] ) and ! empty( $file['git_version'] ) ) {
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
        if ( isset ( $file['name'] ) and ! empty( $file['name'] ) ) {
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
        $username = $this->username;
        $password = $this->password;

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
            $this->errors['credentials'] = array( 'error' => '403', 'msg' => __( 'Cannot access repo. Please check your username and password.', 'edd-git' ) );
            if ( file_exists( $zip_path ) )
                unlink( $zip_path );
            return false;
        } else if ( $status_code != 200 ) {
            // Add an error
            if ( $status_code == 404 ) {
                if ( $try == 2 ) {
                    $error = '404';
                    $msg = __( 'Repo not found. Please check your URL and version.', 'edd-git' );
                    $this->errors[$this->file_key] = array( 'error' => $error, 'msg' => $msg );
                } else {
                    $this->set_url( $file, '' );
                    return $this->fetch_zip( $file, 2 );
                }

            } else if ( $status_code == 403 ) {
                $error = '403';
                $msg = __( 'Cannot access repo. Please check your username and password.', 'edd-git' );
                $this->errors['credentials'] = array( 'error' => $error, 'msg' => $msg );
                return false;
            } else {
                $error = '403';
                $msg = __( 'Cannot access repo. Please check your username and password.', 'edd-git' );
                $this->errors['credentials'] = array( 'error' => $error, 'msg' => $msg );
            }

            if ( file_exists( $zip_path ) )
                unlink( $zip_path );
            return false;
        } else {
            if ($ch != null) curl_close($ch);
            if ($fp != null) fclose($fp);
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
     * Set our git username and password
     *
     * @since 1.0
     * @return void
     */

    private function set_credentials() {
        if ( $this->source == 'bitbucket' ) {
            $plugin_username = edd_get_option( 'bb_username' );
            $plugin_password = edd_get_option( 'bb_password' );
        } else {
            $plugin_username = edd_get_option( 'gh_username' );
            $plugin_password = edd_get_option( 'gh_password' );
        }

        $edd_git_username = get_post_meta( $this->download_id, 'edd_git_username', true );

        if ( ! empty( $edd_git_username ) ) {
            $this->username = $edd_git_username;
        } else {
            $this->username = $plugin_username;
        }

        $edd_git_password = get_post_meta( $this->download_id, 'edd_git_password', true );

        if ( ! empty( $edd_git_password ) ) {
            $this->password = $edd_git_password;
        } else {
            $this->password = $plugin_password;
        }
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
            if ( isset ( $this->errors[$key] ) and $this->errors[$key]['error'] == 404 ) {
                echo '<div style="color: red">' . $this->errors[$key]['msg'] . '</div>';
            }
            ?>
        </td>
        <td width="5%">
            <input type="text" placeholder="<?php echo $version_placeholder;?>" name="edd_download_files[<?php echo $key; ?>][git_version]" value="<?php echo $git_version;?>" style="width: 70px; padding: 3px 6px;">
            <br />
            <?php
            if ( isset ( $this->errors[$key] ) and $this->errors[$key]['error'] == 404 ) {
                echo '<div style="color: red">' . $this->errors[$key]['msg'] . '</div>';
            }
            ?>
        </td>
        <td width="5%">
            <input type="text" placeholder="<?php _e( 'Default: Repo Name' ); ?>" name="edd_download_files[<?php echo $key; ?>][zip_foldername]" value="<?php echo $zip_foldername;?>">
        </td>
    <?php
    }

    /*
     * Add our username and password settings to the download metabox.
     *
     * @since 1.0
     * return void
     */

    public function edd_metabox_settings( $post_id ) {
        // Add our errors if they exist
        $this->errors = get_post_meta( $post_id, 'edd_git_errors', true );
        delete_post_meta( $post_id, 'edd_git_errors' );

        $git_username = get_post_meta( $post_id, 'edd_git_username', true );
        $git_password = get_post_meta( $post_id, 'edd_git_password', true );

        ?>
        <p>
            <strong><?php _e( 'Git Repo Credentials:', 'edd-git' ); ?></strong>
        </p>
        <p>
            <label>
            <?php _e( 'Username', 'edd-git' ); ?>
            <input type="text" placeholder="<?php _e( 'Optional', 'edd-git' );?>" name="edd_git_username" value="<?php echo $git_username;?>">
            </label>
        </p>
        <p>
            <label>
            <?php _e( 'Password', 'edd-git' ); ?>
            <input type="password" placeholder="<?php _e( 'Optional', 'edd-git' );?>" name="edd_git_password" value="<?php echo $git_password;?>">
            </label>
            <br />
            <?php
            if ( isset ( $this->errors['credentials'] ) and $this->errors['credentials']['error'] == 403 ) {
                echo '<div style="color: red">' . $this->errors['credentials']['msg'] . '</div>';
            }
            ?>
        </p>
        <?php
    }

    /*
     * Add our default git settings to the Misc. tab
     *
     * @since 1.0
     * @return array $misc
     */

    public function edd_misc_settings( $misc ) {
        $misc['bb_username'] = array(
            'id' => 'bb_username',
            'name' => __( 'BitBucket Username', 'edd-git' ),
            'desc' => __( 'Default BitBucket Username.', 'edd-git' ),
            'type' => 'text',
            'std' => ''
        );
        $misc['bb_password'] = array(
            'id' => 'bb_password',
            'name' => __( 'BitBucket Password', 'edd-git' ),
            'desc' => __( 'Default BitBucket Password.', 'edd-git' ),
            'type' => 'password',
            'std' => ''
        );
        $misc['gh_username'] = array(
            'id' => 'gh_username',
            'name' => __( 'GitHub Username', 'edd-git' ),
            'desc' => __( 'Default GitHub Username.', 'edd-git' ),
            'type' => 'text',
            'std' => ''
        );
        $misc['gh_password'] = array(
            'id' => 'gh_password',
            'name' => __( 'GitHub Password', 'edd-git' ),
            'desc' => __( 'Default GitHub Password.', 'edd-git' ),
            'type' => 'password',
            'std' => ''
        );
        return $misc;
    }

} // End EDD_GIT_Download_Updater class

// Get the download updater class started
function edd_git_download_updater() {
    $EDD_GIT_Download_Updater = new EDD_GIT_Download_Updater();
}

// Hook into the post save action

add_action( 'admin_init', 'edd_git_download_updater', 9 );

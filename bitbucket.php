<?php
/*
Plugin Name: EDD - BitBucket Update Downloads
Plugin URI: http://ninjaforms.com
Description: Update Download files and readme.txt directly from BitBucket
Version: 1.0
Author: The WP Ninjas
Author URI: http://wpninjas.com
*/

if ( ! defined( 'EDD_BB_PLUGIN_DIR' ) ) {
    define( 'EDD_BB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'EDD_BB_PLUGIN_URL' ) ) {
    define( 'EDD_BB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

class EDD_BB_Download_Updater {

    /*
     * Store our BitBucket Username. This will be used to login to the desired repo.
     */
    private $username;

    /*
     * Store our BitBucket Password. This wil be used to login to the desired repo.
     */
    private $password;

    /*
     * Store our BitBucket repo name
     */
    private $bb_repo;

    /*
     * Store our desired version #.
     */
    private $version; 

    /*
     * Store our download's "version" number if Licensing is installed
     */
    private $sl_version;

    /*
     * Store our BitBucket Repo URL
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
        add_action( 'edd_download_file_table_head', array( $this, 'edd_metabox_th' ), 11 );
        add_action( 'edd_download_file_table_row', array( $this, 'edd_metabox_settings' ), 11, 3 );

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
        require_once( EDD_BB_PLUGIN_DIR . 'includes/flx-zip-archive.php' );
    }

    /*
     * Check to see if we are saving an EDD download. If we are, run our stuff.
     *
     * @since 1.0
     * @return void
     */

    public function save_post( $post_id ) {
        // Bail if we aren't saving a download
        if ( $_POST['post_type'] != 'download' )
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
                if ( isset ( $file['bb_url'] ) and ! empty( $file['bb_url'] ) ) {
                    //var_dump( get_post_meta( $this->download_id, 'edd_bb_ninja-forms-conditionals_version_1.2.2', true ) );
                    //if ( empty( get_post_meta( $this->download_id, 'edd_bb_' . $file['name'] . '_version_' . $this->version, true ) ) ) {
                        $this->file_key = $key;
                        $this->update_download( $file );
                        update_post_meta( $this->download_id, 'edd_bb_' . $file['name'] . '_version_' . $this->version, 1 );
                    //}
                }
            }
        }
        
        update_post_meta( $this->download_id, 'edd_bb_errors', $this->errors );
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
        $this->set_filename();
        $this->set_tmp_dir();
        $this->set_edd_dir();
        $this->set_credentials( $file );

        // Grab our zip file.
        $zip_path = $this->fetch_zip();
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
                include_once( EDD_BB_PLUGIN_DIR . 'includes/parse-readme.php' );
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
        if ( is_array( $files ) ) {
            for ( $i = 0; $i < count( $files ); $i++ ) { 
                if ( $files[$i]['name'] == $this->bb_repo ) {
                    $files[$i]['file'] = $new_zip;
                }
            }
        }
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
        $upload_path = apply_filters( 'edd_bb_upload_path', $edd_dir . $this->filename );
        $upload_url = apply_filters( 'edd_bb_upload_url', $edd_url . $this->filename );

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
        $tmp_dir = trailingslashit( $tmp_dir['basedir'] ) . 'edd-bb-tmp/';
        $tmp_dir = apply_filters( 'edd_bb_zip_path', $tmp_dir );        
        if ( ! is_dir( $tmp_dir ) )
            mkdir( $tmp_dir );
        // $tmp_dir will always have a trailing slash.
        $this->tmp_dir = trailingslashit( $tmp_dir );
    }

    /*
     * Set our BitBucket URL
     *
     * @since 1.0
     * @return void
     */

    private function set_url( $file ) {
        if ( isset ( $file['bb_url'] ) and ! empty( $file['bb_url'] ) ) {
            $this->url = $file['bb_url'];
        } else {
            // Throw an error
        }

        $url = trailingslashit( $this->url );

        $tmp = explode( '/', $url );

        $this->bb_repo = $tmp[4];

        $url = trailingslashit( $this->url ) . 'get/' . 'v' . $this->version .'.zip';
        $this->url = apply_filters( 'edd_bb_repo_url', $url );
    }

    /*
     * Set our version
     *
     * @since 1.0
     * @return void
     */

    private function set_version( $file ) {
        if ( isset ( $file['bb_version'] ) and ! empty( $file['bb_version'] ) ) {
            $this->version = $file['bb_version'];
        } else {
            $sl_version = get_post_meta( $this->download_id, '_edd_sl_version', true );
            $this->version = $sl_version;            
        }
    }

    /*
     * Get our clean zip file name
     *
     * @since 1.0
     * @return void
     */

    private function set_filename() {
        $this->filename = $this->bb_repo . '.' . $this->version . '.zip';
    }

    /*
     * Grab the zip file from BitBucket and store it in our temporary directory.
     *
     * @since 1.0
     * @return string $zip_path
     */

    public function fetch_zip() {
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
            $this->errors[$this->file_key] = array( 'error' => '403', 'msg' => __( 'Cannot access repo. Please check your username and password.', 'edd-bb' ) );
            if ( file_exists( $zip_path ) )
                unlink( $zip_path );
            return false;
        } else if ( $status_code != 200 ) {
            // Add an error
            if ( $status_code == 404 ) {
                $error = '404';
                $msg = __( 'Repo not found. Please check your URL and version.', 'edd-bb' );
            } else if ( $status_code == 403 ) {
                $error = '403';
                $msg = __( 'Cannot access repo. Please check your username and password.', 'edd-bb' );
            } else {
                $error = '0';
                $msg = __( 'BitBucket Error. Check BitBucket settings and try again', 'edd-bb' );
            }
            $this->errors[$this->file_key] = array( 'error' => $error, 'msg' => $msg );
            if ( file_exists( $zip_path ) )
                unlink( $zip_path );
            return false;
        } else {
            if ($ch != null) curl_close($ch);
            if ($fp != null) fclose($fp); 
        }

        do_action( 'edd_bb_zip_fetched', $zip_path, $this->bb_repo );

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

        if ( is_dir( trailingslashit( $this->tmp_dir . $this->bb_repo ) ) )
            $this->remove_dir( trailingslashit( $this->tmp_dir . $this->bb_repo ) );

        $zip = new ZipArchive;
        $zip->open( $zip_path );
        $zip->extractTo( $this->tmp_dir );
        $zip->close();
        $this->set_sub_dir( $this->tmp_dir );

        $new_dir = rename( $this->tmp_dir . $this->sub_dir, $this->tmp_dir . $this->bb_repo );
        if ( ! $new_dir )
            return false;
        $new_dir = $this->tmp_dir . $this->bb_repo;
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
                if ( strpos( $file, $this->bb_repo ) ) {
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
     * Set our BitBucket username and password
     *
     * @since 1.0
     * @return void
     */

    private function set_credentials( $file ) {
        if ( isset ( $file['bb_username'] ) and ! empty( $file['bb_username'] ) ) {
            $this->username = $file['bb_username'];
        } else {
            $this->username = edd_get_option( 'bb_username' );
        }

        if ( isset ( $file['bb_password'] ) and ! empty( $file['bb_password'] ) ) {
            $this->password = $file['bb_password'];
        } else {
            $this->password = edd_get_option( 'bb_password' );
        }
    }

    /*
     * Add our settings heading to the table
     *
     * @since 1.0
     * @return void
     */

    public function edd_metabox_th( $post_id ) {
        // Add our errors if they exist
        $this->errors = get_post_meta( $post_id, 'edd_bb_errors', true );
        delete_post_meta( $post_id, 'edd_bb_errors' );
        ?>
        <th class="" width="10%" ><?php _e( 'BitBucket URL', 'edd-bb' );?></th>
        <th class="" width="10%"><?php _e( 'BitBucket Username', 'edd-bb' );?></th>
        <th class="" width="10%"><?php _e( 'BitBucket Password', 'edd-bb' );?></th>
        <th class="" width="5%"><?php _e( 'Version', 'edd-bb' );?></th>
        <?php
    }

    /*
     * Add our settings to the download edit screen
     *
     * @since 1.0
     * @return void
     */

    public function edd_metabox_settings( $post_id, $key, $args ) {
        $files = get_post_meta( $post_id, 'edd_download_files', true );

        if ( isset ( $files[$key]['bb_url'] ) ) {
            $bb_url = $files[$key]['bb_url'];
        } else {
            $bb_url = '';
        }        

        if ( isset ( $files[$key]['bb_username'] ) ) {
            $bb_username = $files[$key]['bb_username'];
        } else {
            $bb_username = '';
        }

        if ( isset ( $files[$key]['bb_password'] ) ) {
            $bb_password = $files[$key]['bb_password'];
        } else {
            $bb_password = '';
        }

        if ( isset ( $files[$key]['bb_version'] ) ) {
            $bb_version = $files[$key]['bb_version'];
        } else {
            $bb_version = '';
        }

        ?>
        <td>
            <input type="text" placeholder="<?php _e( 'BitBucket URL', 'edd-bb' );?>" name="edd_download_files[<?php echo $key; ?>][bb_url]" value="<?php echo $bb_url;?>">
            <br />
            <?php
            if ( isset ( $this->errors[$key] ) and $this->errors[$key]['error'] == 404 ) {
                echo '<div style="color: red">' . $this->errors[$key]['msg'] . '</div>';   
            }
            ?>
        </td>
        <td>
            <input type="text" placeholder="<?php _e( 'Optional', 'edd-bb' );?>" name="edd_download_files[<?php echo $key; ?>][bb_username]" value="<?php echo $bb_username;?>">
            <br />
            <?php
            if ( isset ( $this->errors[$key] ) and $this->errors[$key]['error'] == 403 ) {
                echo '<div style="color: red">' . $this->errors[$key]['msg'] . '</div>';   
            }
            ?>
        </td>
        <td>
            <input type="password" placeholder="<?php _e( 'Optional', 'edd-bb' );?>" name="edd_download_files[<?php echo $key; ?>][bb_password]" value="<?php echo $bb_password;?>">
            <br />
            <?php
            if ( isset ( $this->errors[$key] ) and $this->errors[$key]['error'] == 403 ) {
                echo '<div style="color: red">' . $this->errors[$key]['msg'] . '</div>';   
            }
            ?>
        </td>        
        <td width="5%">
            <input type="text" placeholder="1.0" name="edd_download_files[<?php echo $key; ?>][bb_version]" value="<?php echo $bb_version;?>">
            <br />
            <?php
            if ( isset ( $this->errors[$key] ) and $this->errors[$key]['error'] == 404 ) {
                echo '<div style="color: red">' . $this->errors[$key]['msg'] . '</div>';   
            }
            ?>
        </td>
    <?php
    }

    /*
     * Add our default BitBucket settings to the Misc. tab
     *
     * @since 1.0
     * @return array $misc
     */

    public function edd_misc_settings( $misc ) {
        $misc['bb_username'] = array(
            'id' => 'bb_username',
            'name' => __( 'BitBucket Username', 'edd-bb' ),
            'desc' => __( 'Default BitBucket Username.', 'edd-bb' ),
            'type' => 'text',
            'std' => ''
        );
        $misc['bb_password'] = array(
            'id' => 'bb_password',
            'name' => __( 'BitBucket Password', 'edd-bb' ),
            'desc' => __( 'Default BitBucket Password.', 'edd-bb' ),
            'type' => 'password',
            'std' => ''
        );
        return $misc;
    }

} // End EDD_BB_Download_Updater class

// Get the download updater class started
function edd_bb_download_updater() {
    $EDD_BB_Download_Updater = new EDD_BB_Download_Updater();
}

// Hook into the post save action

add_action( 'admin_init', 'edd_bb_download_updater', 9 );
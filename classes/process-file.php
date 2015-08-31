<?php
/**
 * Process our fetched file
 *
 * @package EDD Git Download Updater
 * @since  1.0
 */

class EDD_GIT_Download_Updater_Process_File
{
	var $instance;

	function __construct( $instance )
	{
		$this->instance = $instance;
	}

	/**
	 * Process our file
	 * @since  1.0
	 * @return void
	 */
	public function process( $post_id, $version, $repo_url, $key, $folder_name, $file_name )
	{
		// OK, we're authenticated: we need to find and save the data
        $this->instance->download_id = $post_id;
        $this->instance->version = $version;
        $this->instance->url = $repo_url;
        $this->instance->repo_url = $repo_url;
        $this->instance->file_key = $key;
        $this->instance->original_filename = $file_name;
        $this->instance->original_foldername = $folder_name;

        // Include our zip archiver
        require_once( EDD_GIT_PLUGIN_DIR . 'includes/flx-zip-archive.php' );
        // Setup our initial variables
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
        $zip = $this->zip( $new_dir, $this->instance->tmp_dir .  $this->instance->file_name );

        // Move our temporary zip to the proper EDD folder
        $new_zip = $this->move_zip( $zip );

        // Remove our temporary files
        $this->remove_dir( $this->instance->tmp_dir );

        // Reset our temporary directory
        $this->set_tmp_dir();

        // Update our file with the new zip location.
        $this->update_files( $new_zip['url'] );

        return $new_zip;
	}

	/*
     * Update the download's changelog from our grabbed readme.txt
     *
     * @param string $new_dir
     * @since 1.0
     * @return void
     */
    public function update_changelog( $new_dir ) {
        if ( file_exists( trailingslashit( $new_dir ) . 'readme.txt' ) ) {
            if( ! class_exists( 'Automattic_Readme' ) ) {
                include_once( EDD_GIT_PLUGIN_DIR . 'includes/parse-readme.php' );
            }

            $Parser = new Automattic_Readme;
            $content = $Parser->parse_readme( trailingslashit( $new_dir ) . 'readme.txt' );

            if ( isset ( $content['sections']['changelog'] ) ) {
                $changelog = wp_kses_post( $content['sections']['changelog'] );
                update_post_meta( $this->instance->download_id, '_edd_sl_changelog', $changelog );
                $this->instance->changelog = $changelog;
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
    public function update_files( $new_zip ) {
        // $files = get_post_meta( $this->instance->download_id, 'edd_download_files', true );
        $files = array();
        $files[ $this->instance->file_key ]['git_version']        = $this->instance->version;
        $files[ $this->instance->file_key ]['git_url']            = $this->instance->repo_url;
        $files[ $this->instance->file_key ]['git_folder_name']    = $this->instance->original_foldername;
        $files[ $this->instance->file_key ]['name']               = $this->instance->original_filename;
        $files[ $this->instance->file_key ]['file']               = $new_zip;
        $files[ $this->instance->file_key ]['condition']          = $this->instance->condition;
        $files[ $this->instance->file_key ]['attachment_id']      = 0;
        update_post_meta( $this->instance->download_id, 'edd_download_files', $files );
        if ( 0 === strpos( $this->instance->version, 'v' ) ) {
            $this->instance->sl_version = substr( $this->instance->version, 1 );
        } else {
            $this->instance->sl_version = $this->instance->version;
        }
        update_post_meta( $this->instance->download_id, '_edd_sl_version', $this->instance->sl_version );
    }

    /*
     * Move our zip file to the EDD uploads directory
     *
     * @since 1.0
     * @return string $new_zip
     */

    public function move_zip( $zip ) {
        $edd_dir = trailingslashit( $this->instance->edd_dir['path'] );
        $edd_url = trailingslashit( $this->instance->edd_dir['url'] );
        $upload_path = apply_filters( 'edd_git_upload_path', $edd_dir . $this->instance->file_name );
        $upload_url = apply_filters( 'edd_git_upload_url', $edd_url . $this->instance->file_name );

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
    public function set_tmp_dir() {
        $tmp_dir = wp_upload_dir();
        $tmp_dir = trailingslashit( $tmp_dir['basedir'] ) . 'edd-git-tmp/';
        $tmp_dir = apply_filters( 'edd_git_zip_path', $tmp_dir );
        if ( ! is_dir( $tmp_dir ) )
            mkdir( $tmp_dir );
        // $tmp_dir will always have a trailing slash.
        $this->instance->tmp_dir = trailingslashit( $tmp_dir );
    }

    /*
     * Set our git URL. Also sets whether we are working from GitHub or BitBucket.
     *
     * @since 1.0
     * @param array $file
     * @param string $v
     * @return void
     */
    public function set_url() {
        $edd_settings = get_option( 'edd_settings' );

        $url = trailingslashit( $this->instance->url );

        $tmp = explode( '/', $url );

        $user = $tmp[3];
        $repo = $tmp[4];

        if ( 'bitbucket.org' == $tmp[2] ) {
            // Add an error and bail if our BB user and password aren't set
            if ( ! defined( 'EDD_GIT_BB_USER' ) || ! defined( 'EDD_GIT_BB_PASSWORD' ) ) {
                // Add errors
                $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                // Bail
                return false;
            }
            $url_part = 'get/' . $this->instance->version .'.zip';
            $url .= $url_part;
            $this->instance->source = 'bitbucket';
        } else if ( 'github.com' == $tmp[2] ) {
            $access_token = isset ( $edd_settings['gh_access_token'] ) ? $edd_settings['gh_access_token'] : '';
            if ( empty( $access_token ) ) { // If we don't have an oAuth access token, add error and bail.
                // Add Error
                $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                // Bail
                return false;
            } else {
                $url = 'https://api.github.com/repos/' . $user . '/' . $repo . '/zipball/' . $this->instance->version . '?access_token=' . $access_token;
            }
            $this->instance->source = 'github';
        } else {
            // Throw an error
            $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
            // Bail
            return false;
        }

        $this->instance->git_repo = $tmp[4];

        $this->instance->url = apply_filters( 'edd_git_repo_url', $url );
        return $this->instance->url;
    }

    /*
     * Set our clean zip file name
     *
     * @since 1.0
     * @return void
     */
    public function set_filename( $file_name ) {
        $this->instance->file_name = ! empty ( $file_name ) ? $file_name : $this->instance->git_repo . '-' . $this->instance->version . '.zip';
        $this->instance->file_name = apply_filters( 'edd_git_download_file_name', $this->instance->file_name, $this->instance->download_id, $this->instance->file_key );
    }

    /*
     * Set the name of our folder that should go inside our new zip.
     *
     * @since 1.0
     * @return void
     */
    public function set_foldername( $folder_name ) {
        $this->instance->folder_name = ! empty ( $folder_name ) ? $folder_name : sanitize_title( $this->instance->git_repo );
    }

    /*
     * Grab the zip file from git and store it in our temporary directory.
     *
     * @since 1.0
     * @return string $zip_path
     */
    public function fetch_zip() {
        $zip_path = $this->instance->tmp_dir . $this->instance->file_name;

        if ( 'bitbucket' == $this->instance->source ) {
            if ( ! defined( 'EDD_GIT_BB_USER' ) || ! defined( 'EDD_GIT_BB_PASSWORD' ) ) { // If BB credentials aren't set, add error and bail.
                // Add Errors
                $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access zip file.', 'edd-git' ) );
                // Bail
                return false;
            }

            if ( ! function_exists( 'curl_version' ) ) {
                // Add Errors
                $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'cURL is not enabled. Please contact your host.', 'edd-git' ) );
                // Bail
                return false;
            }
 
            $username = EDD_GIT_BB_USER;
            $password = EDD_GIT_BB_PASSWORD;

            $fp = fopen($zip_path, "w");
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->instance->url);
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
                curl_setopt($ch, CURLOPT_URL, $this->instance->url);
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
                    $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                } else if ( $status_code == 403 ) {
                     $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                    return false;
                } else {
                     $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
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
                $this->instance->errors[ $this->instance->file_key ] = array( 'error' => '404', 'msg' => __( 'Not connected to GitHub.', 'edd-git' ) );
                // Bail
                return false;
            }

            $response = wp_remote_get( $this->instance->url, array( 'timeout' => 15000 ) );
            $content_type = isset ( $response['headers']['content-type'] ) ? $response['headers']['content-type'] : '';

            if ( 'application/zip' != $content_type )  {
                // Add error
                $this->instance->errors[ $this->instance->file_key ] = array( 'error' => $error, 'msg' => __( 'Cannot access repo.', 'edd-git' ) );
                // Bail
                return false;                    
            }

            $fp = fopen( $zip_path, 'w' );
            fwrite( $fp, $response['body'] );
        }

        do_action( 'edd_git_zip_fetched', $zip_path, $this->instance->git_repo );

        return $zip_path;
    }

    /*
     * Unzip our file into a new temporary folder.
     *
     * @param string $zip_path
     * @since 1.0
     * @return string $new_dir
     */

    public function unzip( $zip_path ) {
        if ( is_dir( trailingslashit( $this->instance->tmp_dir . $this->instance->folder_name ) ) )
            $this->remove_dir( trailingslashit( $this->instance->tmp_dir . $this->instance->folder_name ) );

        $zip = new ZipArchive;
        $zip->open( $zip_path );
        $zip->extractTo( $this->instance->tmp_dir );
        $zip->close();
        $this->set_sub_dir( $this->instance->tmp_dir );

        $new_dir = rename( $this->instance->tmp_dir . $this->instance->sub_dir, $this->instance->tmp_dir . $this->instance->folder_name );
        if ( ! $new_dir )
            return false;
        $new_dir = $this->instance->tmp_dir . $this->instance->folder_name;
        $this->set_sub_dir( $this->instance->tmp_dir );
        unlink( $this->instance->tmp_dir . $this->instance->file_name );

        return $new_dir;
    }

    /*
     * Zip our directory and return the path to the zip file.
     *
     * @param string $dir
     * @since 1.0
     * @return string $destination
     */
    public function zip( $dir, $destination ) {

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
    public function remove_dir( $dir ) {
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
    public function set_sub_dir( $tmp_dir ) {
        $dir_array = array();
        // Bail if we weren't sent a directory.
        if ( !is_dir( $tmp_dir ) )
            return $dir_array;

        if ( $dh = opendir( $tmp_dir ) ) {
            while ( ( $file = readdir( $dh ) ) !== false ) {
                if ($file == '.' || $file == '..') continue;
                if ( strpos( $file, $this->instance->git_repo ) !== false ) {
                    if ( is_dir ( $tmp_dir.'/'.$file ) ) {
                        $this->instance->sub_dir = $file;
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
    public function set_edd_dir() {
        add_filter( 'upload_dir', 'edd_set_upload_dir' );
        $upload_dir = wp_upload_dir();
        wp_mkdir_p( $upload_dir['path'] );
        $this->instance->edd_dir = $upload_dir;
    }
}
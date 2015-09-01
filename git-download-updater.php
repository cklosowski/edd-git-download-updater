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
    var $username;

    /*
     * Store our git Password. This wil be used to login to the desired repo.
     */
    var $password;

    /*
     * Store our git repo name
     */
    var $git_repo;

    /*
     * Store our desired version #.
     */
    var $version;

    /*
     * Store our download's "version" number if Licensing is installed
     */
    var $sl_version;

    /*
     * Store our git Repo URL
     */
    var $url;

    /*
     * Store our destination filename
     */
    var $file_name;

    /*
     * Store our temporary dir name
     */
    var $tmp_dir;

    /*
     * Store our newly unzipped folder name
     */
    var $sub_dir;

    /*
     * Store the id of the download we're updating
     */
    var $download_id;

    /*
     * Store our EDD upload dir information
     */
    var $edd_dir;

    /*
     * Store the current file key for our download
     */
    var $file_key;

    /*
     * Store our errors
     */
    var $errors;

    /*
     * Store our folder name
     */
    var $folder_name;

    /*
     * Store our source (either bitbucket or github)
     */
    var $source;

    /*
     * Store our changelog
     */
    var $changelog = '';

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

        // Include our ajax class.
        require_once( EDD_GIT_PLUGIN_DIR . 'classes/ajax.php' );
        $this->ajax = new EDD_GIT_Download_Updater_Ajax( $this );

        // Include our admin class.
        require_once( EDD_GIT_PLUGIN_DIR . 'classes/admin.php' );
        $this->admin = new EDD_GIT_Download_Updater_Admin( $this );

        // Include our file processing class.
        require_once( EDD_GIT_PLUGIN_DIR . 'classes/process-file.php' );
        $this->process_file = new EDD_GIT_Download_Updater_Process_File( $this );

        // Include our repo interaction class.
        require_once( EDD_GIT_PLUGIN_DIR . 'classes/repos.php' );
        $this->repos = new EDD_GIT_Download_Updater_Repos( $this );
    }

} // End EDD_GIT_Download_Updater class

// Get the download updater class started
function edd_git_download_updater() {
    $EDD_GIT_Download_Updater = new EDD_GIT_Download_Updater();
}

// Instantiate our main class
add_action( 'admin_init', 'edd_git_download_updater', 9 );
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
    public $username;

    /*
     * Store our git Password. This wil be used to login to the desired repo.
     */
    public $password;

    /*
     * Store our git repo name
     */
    public $git_repo;

    /*
     * Store our desired version #.
     */
    public $version;

    /*
     * Store our download's "version" number if Licensing is installed
     */
    public $sl_version;

    /*
     * Store our git Repo URL
     */
    public $url;

    /*
     * Store our destination filename
     */
    public $file_name;

    /*
     * Store our temporary dir name
     */
    public $tmp_dir;

    /*
     * Store our newly unzipped folder name
     */
    public $sub_dir;

    /*
     * Store the id of the download we're updating
     */
    public $download_id;

    /*
     * Store our EDD upload dir information
     */
    public $edd_dir;

    /*
     * Store the current file key for our download
     */
    public $file_key;

    /*
     * Store our errors
     */
    public $errors;

    /*
     * Store our folder name
     */
    public $folder_name;

    /*
     * Store our source (either bitbucket or github)
     */
    public $source;

    /*
     * Store our changelog
     */
    public $changelog = '';

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
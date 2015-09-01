<?php
/**
 * Outputs our admin page HTML
 * 
 * @package EDD Git Downloader
 * @since  1.0
 */

class EDD_GIT_Download_Updater_Admin
{
	var $instance = '';

	function __construct( $instance )
	{

		$this->instance = $instance;
		 // Add our settings to the EDD Extensions tab
        add_filter( 'edd_settings_extensions', array( $this, 'edd_extensions_settings' ) );
        add_action( 'edd_meta_box_files_fields', array( $this, 'output_file_checkbox' ) );

        // Add our init action that adds/removes git download boxes.
        add_action( 'admin_head', array( $this, 'init' ) );

        // Save our Use Git setting.
        add_action( 'save_post', array( $this, 'save_post' ) );
       
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

        /* BitBucket */

        // Add our BitBucket description hook.
        add_action( 'edd_git_bb_desc', array( $this, 'bb_desc' ) );
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

    /**
     * Output our "Use Git" checkbox.
     * @since  1.0
     * @param  integer $post_id
     * @return void
     */
    public function output_file_checkbox( $post_id = 0 ) {
        $checked = get_post_meta( $post_id, '_edd_download_use_git', true );
        ?>
        <input type="hidden" value="0" name="_edd_download_use_git">
        <label><input type="checkbox" value="1" name="_edd_download_use_git" id="_edd_download_use_git" <?php checked( $checked, 1 ); ?>> <?php _e( 'Fetch download from a git repo.', 'edd-git' ); ?></label>
        <?php
    }

    /**
     * Check to see if our field metabox sections should be removed.
     * @since  1.0
     * @return void
     */
    public function init() {
        global $post;

        if ( isset ( $post ) && 1 == get_post_meta( $post->ID, '_edd_download_use_git', true ) ) {
            $this->register_git_section();
        }
    }

    /**
     * Remove default field metabox sections if we are using Git.
     * @since  1.0
     * @return void
     */
    public function register_git_section() {
        // Remove the default EDD file editing section.
        remove_action( 'edd_meta_box_files_fields', 'edd_render_files_field', 20 );
        remove_action( 'edd_render_file_row', 'edd_render_file_row', 10, 3 );

        // Add our settings to the download editor.
        add_action( 'edd_meta_box_files_fields', array( $this, 'edd_files_fields' ), 20 );
        add_action( 'edd_render_file_row', array( $this, 'edd_render_file_row' ), 10, 3  );
    }

    /**
     * Save our "Use Git" checkbox setting.
     * @since  1.0
     * @param  int  $post_id
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

        // Verify that this user can edit downloads
        if ( !current_user_can( 'edit_product', $post_id ) )
            return $post_id;

        update_post_meta( $post_id, '_edd_download_use_git', esc_html( $_POST['_edd_download_use_git'] ) );
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
            wp_localize_script( 'edd-git-updater', 'gitUpdater', array( 'pluginURL' => EDD_GIT_PLUGIN_URL, 'useGit' => get_post_meta( $post_id, '_edd_download_use_git', true ), 'currentGitUrl' => $this->instance->repos->get_current_repo_url( $post_id ), 'currentTag' => $this->instance->repos->get_current_tag( $post_id ) ) );
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

    /**
     * Output our field field metabox sections
     * @since  1.0
     * @param  integer $post_id
     * @return void
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
                                <th class="pricing" style="width: 10%; <?php echo $variable_display; ?>"><?php _e( 'Price Assignment', 'edd-git' ); ?></th>
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
     *
     * @since   1.0
     * @return  void
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

        $repos = $this->instance->repos->fetch_repos( $args );
        $current_tags = $this->instance->repos->fetch_tags( $args['git_url'] );

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
            <a href="#" class="edd-git-fetch-repos"><span class="dashicons dashicons-update"></span><span class="spinner" style="margin-left: -1px; float:left; display:none;"></a>
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
            <?php 
                $tmp = explode( '/', $args['git_url'] );
                $git_repo = $tmp[4];
                $tag = $args['git_version'];
                $default_name = $git_repo. '-' . $tag . '.zip';

                if ( $args['name'] == $default_name ) {
                    $name = '';
                } else {
                    $name = $args['name'];
                }

                echo EDD()->html->text( array(
                'name'        => 'edd_download_files[' . $key . '][name]',
                'value'       => $name,
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
                <span class="spinner git-update-spinner" style="float:left;margin-top:5px;display:none;"></span> 
            </div>
        </td>

        <td class="pricing"<?php echo $variable_display; ?> width="10%">
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

    /**
     * Take our repo array and return our HTML options
     * @since  1.0
     * @param  array  $repos
     * @param  string  $current_repo URL of our current repo.
     * @return void
     */
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

}
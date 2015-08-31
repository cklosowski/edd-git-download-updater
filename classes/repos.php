<?php
/**
 * Grab repos and tags from GitHub and BitBucket
 *
 * @package EDD Git Download Updater
 * @since  1.0
 */

class EDD_GIT_Download_Updater_Repos
{
	/**
     * Fetch our repos from either our cache or BB/GH
     * @since  1.0
     * @return array $repos
     */
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

    /**
     * Grab out tags from GH or BB
     * @since  1.0
     * @param  string  $repo_url URL of our repo
     * @return array $tags
     */
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

    /**
     * Fetch our tags from GitHub
     * @since  1.0
     * @param  string  $tag_url URL of our repo
     * @return array   $tags
     */
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

    /**
     * Fetch our tags from BitBucket
     * @since  1.0
     * @param  string  $tag_url URL of our repo
     * @return array $tags
     */
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

    /**
     * Return our current tag
     * @since  1.0
     * @param  integer  $download_id
     * @return string   $version
     */
    public function get_current_tag( $download_id ) {
        $files = get_post_meta( $download_id, 'edd_download_files', true );
        if ( empty ( $files ) )
            return false;

        foreach ( $files as $file ) {
            $version = isset ( $file['git_version'] ) ? $file['git_version'] : '';
            return $version;
        }
    }

    /**
     * Return our current repo URL
     * @since  1.0
     * @param  integer  $download_id
     * @return string $url
     */
    public function get_current_repo_url( $download_id ) {
        $files = get_post_meta( $download_id, 'edd_download_files', true );
        if ( empty ( $files ) )
            return false;

        foreach ( $files as $file ) {
            $url = isset ( $file['git_url'] ) ? $file['git_url'] : '';
            return $url;
        }
    }

}
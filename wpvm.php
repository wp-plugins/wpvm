<?php
/*
Plugin Name: WPVM
Plugin URI: https://github.com/gnotaras/wordpress-varnish-modified
Description: WPVM (WordPress Varnish Modified) purges pages from Varnish caching servers either automatically as content is updated or on demand.
Version: 2.0.0
Author: George Notaras
Author URI: http://www.g-loaded.eu/
License: GPLv3
Text Domain: wpvm
Domain Path: /languages/
*/

/**
 *  This file is part of the WPVM distribution package.
 *
 *  WPVM is an extension for the WordPress publishing platform.
 *
 *  WPVM is a fork and complete rewrite of WP-Varnish 0.8.
 *
 *  WP-Varnish 0.8 (https://github.com/pkhamre/wp-varnish)
 *  Copyright 2010 Pal-Kristian Hamre and others.
 *
 *  WPVM (WordPress Varnish Modified)
 *  Copyright 2013-2015 George Notaras <gnot@g-loaded.eu>, CodeTRAX.org
 *
 *  WPVM Homepage:
 *  - http://wordpress.org/plugins/wpvm/
 *  WPVM Development Web Site and Bug Tracker:
 *  - http://www.codetrax.org/projects/wpvm
 *  WPVM Main Source Code Repository (Mercurial):
 *  - https://bitbucket.org/gnotaras/wordpress-wpvm
 *  WPVM Mirror repository (Git):
 *  - https://github.com/gnotaras/wordpress-wpvm
 *
 *  Licensing Information
 *
 *  All contributors are the copyright holders of their contributions.
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *  
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *  
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 *  
 */


// Store plugin directory
define('WPVM_DIR', dirname(__FILE__));

// Import modules
require_once( join( DIRECTORY_SEPARATOR, array( WPVM_DIR, 'wpvm-settings.php' ) ) );
require_once( join( DIRECTORY_SEPARATOR, array( WPVM_DIR, 'wpvm-admin-panel.php' ) ) );
require_once( join( DIRECTORY_SEPARATOR, array( WPVM_DIR, 'wpvm-utils.php' ) ) );


/*
 * Translation Domain
 *
 * Translation files are searched in: wp-content/plugins
 */
load_plugin_textdomain('wpvm', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/');


/**
 * Settings Link in the ``Installed Plugins`` page
 */
function wpvm_plugin_actions( $links, $file ) {
    if( $file == plugin_basename(__FILE__) && function_exists( "admin_url" ) ) {
        $settings_link = '<a href="' . admin_url( 'options-general.php?page=wpvm-options' ) . '">' . __('Settings') . '</a>';
        // Add the settings link before other links
        array_unshift( $links, $settings_link );
    }
    return $links;
}
add_filter( 'plugin_action_links', 'wpvm_plugin_actions', 10, 2 );



// Purge all cache for current website. Caution. This may stress the webserver until content is cached.
function wpvm_purge_all_cache() {
    wpvm_purge_url('/.*');
}


// Purge the user-defined group of URLs
function wpvm_purge_url_group() {
    $options = get_option('wpvm_opts');
    $url_group = $options['purge_url_group'];
    $urls = preg_split('#\r?\n#', $url_group, -1, PREG_SPLIT_NO_EMPTY);
    foreach ( $urls as $url ) {
        wpvm_purge_url( $url );
    }
}


// Purge term archive
function wpvm_purge_term_archive( $term_id, $tt_id, $taxonomy ) {
    /**
      * @param int $term_id Term ID.
      * @param int $tt_id Term taxonomy ID.
      * @param string $taxonomy Taxonomy slug.
      */
    $term = get_term_by( 'id', $term_id, $taxonomy );
    // Policy for archive purging
    $archive_pattern = wpvm_get_archive_pattern();

    if ( $taxonomy == 'category' ) {
        $taxonomy_slug = get_option('category_base');
        if ( empty($taxonomy_slug) ) {
            $taxonomy_slug = 'category';
        }
    } elseif ( $taxonomy == 'post_tag' ) {
        $taxonomy_slug = get_option('tag_base');
        if ( empty($taxonomy_slug) ) {
            $taxonomy_slug = 'tag';
        }
    } else {
        $taxonomy_slug = $taxonomy;
    }

    // Purge Term Archive
    wpvm_purge_url( sprintf("/%s/%s/%s", $taxonomy_slug, $term->slug, $archive_pattern ) );
}


//wrapper on WPVMPurgePost for transition_post_status
function wpvm_purge_post_status($new, $old, $post) {
    if ( $old == 'publish' || $new == 'publish' ) {
        wpvm_purge_post( $post->ID, $post );
        if ( $old == 'publish' && $new == 'publish' ) {
            // Do not purge common objects unless ``extended_object_purge`` is enabled.
            $options = get_option('wpvm_opts');
            if ( absint($options['extended_object_purge']) ) {
                wpvm_purge_related_objects( $post->ID, $post );
            }
        } else {
            wpvm_purge_related_objects( $post->ID, $post );
        }
    }
}


// Purge a post object
function wpvm_purge_post($post_id, $post, $purge_comments=false) {

    // We need a post object, so we perform a few checks.
    // Supported posts, pages, attachment pages, custom post types.
    $supported_builtin_post_types = array('post', 'page', 'attachment');
    $supported_post_types = array_merge( $supported_builtin_post_types, get_post_types( array('public'=>true, '_builtin'=>false) ) );
    if ( ! in_array( get_post_type($post), $supported_post_types ) ) {
        return;
    }

    //$wpv_url = get_permalink($post->ID);
    // Here we do not use ``get_permalink()`` to get the post object's permalink,
    // because this function generates a permalink only for published posts.
    // So, for example, there is a problem when a post transitions from
    // status 'publish' to status 'draft', because ``get_permalink`` would
    // return a URL of the form, ``?p=123``, which does not exist in the cache.
    // For this reason, the following workaround is used:
    //   http://wordpress.stackexchange.com/a/42988/14743
    // It creates a clone of the post object and pretends it's published and
    // then it generates the permalink for it.
    if (in_array($post->post_status, array('draft', 'pending', 'auto-draft'))) {
        $my_post = clone $post;
        $my_post->post_status = 'published';
        $my_post->post_name = sanitize_title($my_post->post_name ? $my_post->post_name : $my_post->post_title, $my_post->ID);
        $wpv_url = get_permalink($my_post);
    } else {
        $wpv_url = get_permalink($post->ID);
    }

    // Purge post comments feed and comment pages, if requested, before
    // adding multipage support.
    if ( $purge_comments === true ) {
        // Post comments feed
        wpvm_purge_url( $wpv_url . 'feed/(?:(atom|rdf)/)?$' );
        // For paged comments
        if ( intval(get_option('page_comments', 0)) == 1 ) {
            if ( get_option($this->wpv_update_commentnavi_optname) == 1 ) {
                wpvm_purge_url( $wpv_url . 'comment-page-[\d]+/(?:#comments)?$' );
            }
        }
    }

    // Add support for multipage content for posts, pages and custom post types (attachment pages are excluded).
    $supported_builtin_post_types = array('post', 'page');
    $supported_post_types = array_merge( $supported_builtin_post_types, get_post_types( array('public'=>true, '_builtin'=>false) ) );
    if ( in_array( get_post_type($post), $supported_post_types ) ) {
        $wpv_url .= '([\d]+/)?$';
    }
    // Purge object permalink
    wpvm_purge_url($wpv_url);

    // For attachments, also purge the parent post, if it is published,
    // and also the links to the actual media files.
    if ( get_post_type($post) == 'attachment' ) {
        // Purge permalink of parent post (where applicable)
        if ( $post->post_parent > 0 ) {
            $parent_post = get_post( $post->post_parent );
            if ( $parent_post->post_status == 'publish' ) {
                // If the parent post is published, then purge its permalink
                wpvm_purge_url( get_permalink($parent_post->ID) . '([\d]+/)?$' );
            }
        }

        // Purge links to media files
        $mime_type = get_post_mime_type( $post->ID );
        $attachment_type = preg_replace( '#/[^/]*$#', '', $mime_type );

        if ( 'image' == $attachment_type ) {
            $available_sizes = get_intermediate_image_sizes();
            foreach ( $available_sizes as $size ) {
                $size_meta = wp_get_attachment_image_src( $post->ID, $size );
                wpvm_purge_url( $size_meta[0] );
            }
        } elseif ( 'video' == $attachment_type ) {
            wpvm_purge_url( wp_get_attachment_url($post->ID) );
        } elseif ( 'audio' == $attachment_type ) {
            wpvm_purge_url( wp_get_attachment_url($post->ID) );
        }
    }
}


// Purge related objects
function wpvm_purge_related_objects($post_id, $post) {

    // We need a post object in order to generate the archive URLs which are
    // related to the post. We perform a few checks to make sure we have a
    // post object.
    // Static pages and attachments are not supported when purging common objects.
    $supported_builtin_post_types = array('post');
    $supported_post_types = array_merge( $supported_builtin_post_types, get_post_types( array('public'=>true, '_builtin'=>false) ) );
    if ( ! in_array( get_post_type($post), $supported_post_types ) ) {
        // Do nothing for pages, attachment pages (they are purged when the related.
        return;
    }

    // Policy for archive purging
    $archive_pattern = wpvm_get_archive_pattern();

    // Delete related objects

    // Front page (latest posts OR static front page)
    wpvm_purge_url( '/' . $archive_pattern );

    // Feeds (ALREADY COVERED BY FRONT PAGE)
    //wpvm_purge_url( '/feed/(?:(atom|rdf)/)?$' );

    // Static latest posts page (Added only if a static page used as the 'latest posts page')
    if ( get_option('show_on_front', 'posts') == 'page' && intval(get_option('page_for_posts', 0)) > 0 ) {
        $posts_page_url = get_permalink(intval(get_option('page_for_posts')));
        wpvm_purge_url( $posts_page_url . $archive_pattern );
    }

    // Category, Tag, Custom Taxonomy, Author and Date Archives

    // We get the URLs of the category and tag archives, only for
    // those categories and tags which have been attached to the post.

    // Category Archive
    $category_slugs = array();
    $categories = get_the_category($post->ID);
    if ( ! empty($categories) ) {
        foreach( $categories as $cat ) {
            $category_slugs[] = $cat->slug;
        }
    }
    if ( ! empty($category_slugs) ) {
        if ( count($category_slugs) > 1 ) {
            $cat_slug_pattern = '(' . implode('|', $category_slugs) . ')';
        } else {
            $cat_slug_pattern = implode('', $category_slugs);
        }
        $cat_base = get_option('category_base');
        if ( empty($cat_base) ) {
            $cat_base = 'category';
        }
        wpvm_purge_url( '/' . $cat_base . '/' . $cat_slug_pattern . '/' . $archive_pattern );
    }

    // Tag Archive
    $tag_slugs = array();
    $tags = get_the_tags($post->ID);
    if ( ! empty($tags) ) {
        foreach( $tags as $tag ) {
            $tag_slugs[] = $tag->slug;
        }
    }
    if ( ! empty($tag_slugs) ) {
        if ( count($tag_slugs) > 1 ) {
            $tag_slug_pattern = '(' . implode('|', $tag_slugs) . ')';
        } else {
            $tag_slug_pattern = implode('', $tag_slugs);
        }
        $tag_base = get_option('tag_base');
        if ( empty($tag_base) ) {
            $tag_base = 'tag';
        }
        wpvm_purge_url( '/' . $tag_base . '/' . $tag_slug_pattern . '/' . $archive_pattern );
    }

    // Custom Taxonomy Archive

    // Get the custom taxonomy names.
    // Arguments in order to retrieve all public custom taxonomies
    // (excluding the builtin categories, tags and post formats.)
    $args = array(
        'public'   => true,
        '_builtin' => false
    );
    $output = 'names'; // or objects
    $operator = 'and'; // 'and' or 'or'
    $taxonomies = get_taxonomies( $args, $output, $operator );

    // Get the terms of each taxonomy and store in $term_slugs for each custom taxonomy.
    foreach ( $taxonomies as $taxonomy ) {
        $term_slugs = array();
        $terms = get_the_terms( $post->ID, $taxonomy );
        if ( $terms && is_array($terms) && ! empty($terms) ) {
            foreach ( $terms as $term ) {
                $term_slugs[] = $term->slug;
            }
        }

        if ( ! empty($term_slugs) ) {
            if ( count($term_slugs) > 1 ) {
                $term_slug_pattern = '(' . implode('|', $term_slugs) . ')';
            } else {
                $term_slug_pattern = implode('', $term_slugs);
            }
            wpvm_purge_url( '/' . $taxonomy . '/' . $term_slug_pattern . '/' . $archive_pattern );
        }

    }


    // Author Archive
    wpvm_purge_url( get_author_posts_url($post->post_author) . $archive_pattern );

    // Date based archives
    $archive_year = mysql2date('Y', $post->post_date);
    $archive_month = mysql2date('m', $post->post_date);
    $archive_day = mysql2date('d', $post->post_date);
    // Yearly Archive
    wpvm_purge_url( get_year_link( $archive_year ) . $archive_pattern );
    // Monthly Archive
    wpvm_purge_url( get_month_link( $archive_year, $archive_month ) . $archive_pattern );
    // Daily Archive
    wpvm_purge_url( get_day_link( $archive_year, $archive_month, $archive_day ) . $archive_pattern );

    // Sitemap
    wpvm_purge_url( '/(sitemap(_index)?\.xml(\.gz)?|[a-z0-9_\-]+-sitemap([0-9]+)?\.xml(\.gz)?)$' );
    // Also consider these shorter patterns, which btw do not cover all cases:
    // ([a-z0-9_\-]*?)sitemap([a-z0-9_\-]*)?\.xml(\.gz)?
    // sitemap\.xml\.gz
}


// wrapper on wpvm_purge_post_comments() for comment status changes
function wpvm_purge_post_comments_status($comment_id, $new_comment_status) {
    wpvm_purge_post_comments($comment_id);
}

// WPVMPurgePostComments - Purge all comments pages from a post
function wpvm_purge_post_comments($comment_id) {
    $comment = get_comment($comment_id);
    $post = get_post( $comment->comment_post_ID );

    // Comments feed
    wpvm_purge_url( '/comments/feed/(?:(atom|rdf)/)?$' );

    // Purge post page, post comments feed and post comments pages
    wpvm_purge_post($post->ID, $post, $purge_comments=true);

    // Popup comments
    // See:
    // - http://codex.wordpress.org/Function_Reference/comments_popup_link
    // - http://codex.wordpress.org/Template_Tags/comments_popup_script
    wpvm_purge_url( '/.*comments_popup=' . $post->ID . '.*' );

}

















// ex WPVMPurgeObject
// wpvm_purge_url
// Adds the URL to the URL pool. Accepts relative and absolute URLs.
// If $url is a relative URL, then it is converted to an absolute URL.
function wpvm_purge_url( $url ) {

    // The URL pool is saved as transient data in the WordPress database.
    // The name of the data slug is ``username_wpvm_urls``.
    $current_user = wp_get_current_user();
    $transient_name = sprintf( '%s_wpvm_urls', $current_user->user_login );
    $wpvm_url_pool = get_site_transient( $transient_name );
    if ( $wpvm_url_pool === false ) {
        $wpvm_url_pool = array();
    }

    // Check if this is a relative URL
    $pattern = '#^https?://.*#i';
    preg_match( $pattern, $url, $matches );
    if ($matches) {
        // Add the URL as is.
        array_push( $wpvm_url_pool, $url );
    } else {
        // Convert it to absolute
        $wpv_url_abs = site_url( $url );
        // On Network installations (Multisite), we first try to use the
        // ``domain_mapping_siteurl()`` function, if available.
        //if ( is_multisite() && function_exists('domain_mapping_siteurl') ) {
        //    // check for domain mapping plugin by donncha
        //    $site_url = domain_mapping_siteurl('NA');
        //}
        array_push( $wpvm_url_pool, $wpv_url_abs );
    }

    // Save the transient data.
    set_site_transient( $transient_name, $wpvm_url_pool, 60 );
}



// Purges all URLs in the pool.
// ex WPVMPurgePool
function wpvm_process_url_queue() {

    // The URL pool is saved as transient data in the WordPress database.
    // The name of the data slug is ``username_wpvm_urls``.
    $current_user = wp_get_current_user();
    $transient_name = sprintf( '%s_wpvm_urls', $current_user->user_login );
    $wpvm_url_pool = get_site_transient( $transient_name );
    if ( $wpvm_url_pool === false ) {
        return;
    }

    $options = get_option('wpvm_opts');

    // Make the contents of the URL pool unique
    $url_pool = array_unique($wpvm_url_pool);

    $wpv_purgeaddr = $options['varnish_addresses'];
    $wpv_purgeport = $options['varnish_ports'];
    $wpv_secret = $options['varnish_secrets'];

    // Gather connection settings
    $wpv_timeout = absint($options['varnish_connection_timeout']);
    $wpv_use_adminport = absint($options['varnish_use_adminport']);
    $wpv_vversion_optval = absint($options['varnish_version']);

    // Process URL pool and purge
    foreach ($url_pool as $wpv_url_abs) {

        //wpvm_log( sprintf('Purging %s URL(s) on varnish server on: %s:%s', count($url_pool), $wpv_purgeaddr[$i], $wpv_purgeport[$i] ) );
        $wpv_url_pattern = '#^https?://([^/]+)(.*)#i';
        $wpv_host = preg_replace($wpv_url_pattern, "$1", $wpv_url_abs);
        $wpv_url = preg_replace($wpv_url_pattern, "$2", $wpv_url_abs);

        // Create varnish socket for each varnish ip:post and purge URLS
        for ( $i = 0; $i < count($wpv_purgeaddr); $i++ ) {

            $varnish_sock = fsockopen( $wpv_purgeaddr[$i], $wpv_purgeport[$i], $errno, $errstr, $wpv_timeout );
            if ( ! $varnish_sock ) {
                error_log("wpvm error: $errstr ($errno) on server $wpv_purgeaddr[$i]:$wpv_purgeport[$i]");
                continue;
            }

            // If admin port is used, authentication is required.
            if ( $wpv_use_adminport ) {
                $buf = fread ($varnish_sock, 1024 );
                if ( preg_match('/(\w+)\s+Authentication required./', $buf, $matches) ) {
                    # get the secret
                    $secret = $wpv_secret[$i];
                    fwrite( $varnish_sock, "auth " . wpvm_varnish_authentication_hash($matches[1], $secret) . "\n" );
                    $buf = fread( $varnish_sock, 1024 );
                    if ( ! preg_match('/^200/', $buf) ) {
                        error_log("wpvm error: authentication failed using admin port on server $wpv_purgeaddr[$i]:$wpv_purgeport[$i]");
                        fclose( $varnish_sock );
                        continue;
                    }
                }
            }

            if ( $wpv_use_adminport ) {
                if ($wpv_vversion_optval == 3) {
                    $out = "ban req.url ~ ^$wpv_url$ && req.http.host == $wpv_host\n";
                } else {
                    $out = "purge req.url ~ ^$wpv_url && req.http.host == $wpv_host\n";
                }
            } else {
                $out = "BAN $wpv_url HTTP/1.0\r\n";
                $out .= "Host: $wpv_host\r\n";
                $out .= "User-Agent: WPVM WordPress plugin\r\n";
                $out .= "Connection: Close\r\n\r\n";
            }

            fwrite( $varnish_sock, $out );
            fclose( $varnish_sock );
            wpvm_log( $wpv_url );
        }
    }

/* FOR HTTP PIPELINING (NOT SUPPORTED FOR CUSTOM REUQEST METHODS? http://www.w3.org/Protocols/rfc2616/rfc2616-sec8.html#sec8.1.2.2

    // Create varnish socket for each varnish ip:post and purge URLS
    for ($i = 0; $i < count($wpv_purgeaddr); $i++) {
        $varnish_sock = fsockopen($wpv_purgeaddr[$i], $wpv_purgeport[$i], $errno, $errstr, $wpv_timeout);
        if (!$varnish_sock) {
            error_log("wpvm error: $errstr ($errno) on server $wpv_purgeaddr[$i]:$wpv_purgeport[$i]");
            continue;
        }

        // If admin port is used, authentication is required.
        if($wpv_use_adminport) {
            $buf = fread($varnish_sock, 1024);
            if(preg_match('/(\w+)\s+Authentication required./', $buf, $matches)) {
                # get the secret
                $secret = $wpv_secret[$i];
                fwrite($varnish_sock, "auth " . wpvm_varnish_authentication_hash($matches[1], $secret) . "\n");
                $buf = fread($varnish_sock, 1024);
                if(!preg_match('/^200/', $buf)) {
                    error_log("wpvm error: authentication failed using admin port on server $wpv_purgeaddr[$i]:$wpv_purgeport[$i]");
                    fclose($varnish_sock);
                    continue;
                }
            }
        }

        wpvm_log( sprintf('Purging %s URL(s) on varnish server on: %s:%s', count($url_pool), $wpv_purgeaddr[$i], $wpv_purgeport[$i] ) );

        // Process URL pool and purge
        //foreach ($url_pool as $wpv_url_abs) {
        for ( $q = 0; $q < count($url_pool); $q++ ) {

            $wpv_url_abs = $url_pool[$q];

            $wpv_url_pattern = '#^https?://([^/]+)(.*)#i';
            $wpv_host = preg_replace($wpv_url_pattern, "$1", $wpv_url_abs);
            $wpv_url = preg_replace($wpv_url_pattern, "$2", $wpv_url_abs);

            if($wpv_use_adminport) {
                if ($wpv_vversion_optval == 3) {
                    $out = "ban req.url ~ ^$wpv_url$ && req.http.host == $wpv_host\n";
                } else {
                    $out = "purge req.url ~ ^$wpv_url && req.http.host == $wpv_host\n";
                }
            } else {
                $out = "BAN $wpv_url HTTP/1.0\r\n";
                $out .= "Host: $wpv_host\r\n";
                $out .= "User-Agent: WPVM WordPress plugin\r\n";
                if ( count($url_pool) > $q + 1 ) {
                    $out .= "Connection: Keep-Alive\r\n\r\n";
                } else {
                    $out .= "Connection: Close\r\n\r\n";
                }
            }

            wpvm_log( $wpv_url );
            fwrite( $varnish_sock, $out );
        }

        fclose( $varnish_sock );
    }
*/

    // Finally delete transient data
    delete_site_transient( $transient_name );
}



// When an attachment is updated.
add_action('edit_attachment', 'wpvm_purge_post', 10, 2);
// When post status is changed.
add_action('transition_post_status', 'wpvm_purge_post_status', 10, 3);
// When taxonomy term is edited.
add_action('edit_term', 'wpvm_purge_term_archive', 10, 3);


// When comments are made, edited or deleted
// See: http://codex.wordpress.org/Plugin_API/Action_Reference#Comment.2C_Ping.2C_and_Trackback_Actions
add_action('comment_post', 'wpvm_purge_post_comments', 10);
add_action('edit_comment', 'wpvm_purge_post_comments', 10);
add_action('deleted_comment', 'wpvm_purge_post_comments', 10);
add_action('trashed_comment', 'wpvm_purge_post_comments', 10);
add_action('pingback_post', 'wpvm_purge_post_comments', 10);
add_action('trackback_post', 'wpvm_purge_post_comments', 10);
add_action('wp_set_comment_status', 'wpvm_purge_post_comments_status', 10);

// When Theme is changed, Thanks dupuis
add_action('switch_theme', 'wpvm_purge_all_cache', 10);

// Perform the actual purging on 'shutdown' hook.
add_action('shutdown', 'wpvm_process_url_queue', 10);


?>
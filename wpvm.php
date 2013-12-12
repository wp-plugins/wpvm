<?php
/*
Plugin Name: WPVM
Plugin URI: https://github.com/gnotaras/wordpress-varnish-modified
Description: Invalidate the varnish cache automatically when content is updated or on-demand.
Version: 1.0.1
Author: George Notaras
Author URI: http://www.g-loaded.eu/
License: GPLv2+
Text Domain: wpvm
Domain Path: /languages/

WPVM (WordPress Varnish Modified) is based on WP-Varnish (https://github.com/pkhamre/wp-varnish)

Copyright 2010 Pål-Kristian Hamre  (email : post_at_pkhamre_dot_com)
Copyright 2013 George Notaras <gnot@g-loaded.eu>, CodeTRAX.org
All other contributors are the copyright holders of their contributions.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA

*/

class WPVM {
    public $wpv_addr_optname;
    public $wpv_port_optname;
    public $wpv_secret_optname;
    public $wpv_timeout_optname;
    public $wpv_update_pagenavi_optname;
    public $wpv_update_commentnavi_optname;

    // Store all URLs to be purged in this array. This is used in order to avoid
    // duplicate purges/bans.
    public $purge_url_pool = array();

    function WPVM() {
        global $post;

        $this->wpv_addr_optname = "wpvm_addr";
        $this->wpv_port_optname = "wpvm_port";
        $this->wpv_secret_optname = "wpvm_secret";
        $this->wpv_timeout_optname = "wpvm_timeout";
        $this->wpv_update_pagenavi_optname = "wpvm_update_pagenavi";
        $this->wpv_update_commentnavi_optname = "wpvm_update_commentnavi";
        $this->wpv_use_adminport_optname = "wpvm_use_adminport";
        $this->wpv_vversion_optname = "wpvm_vversion";
        $wpv_addr_optval = array ("127.0.0.1");
        $wpv_port_optval = array (80);
        $wpv_secret_optval = array ("");
        $wpv_timeout_optval = 5;
        $wpv_update_pagenavi_optval = 0;
        $wpv_update_commentnavi_optval = 0;
        $wpv_use_adminport_optval = 0;
        $wpv_vversion_optval = 2;

        if ( (get_option($this->wpv_addr_optname) == FALSE) ) {
            add_option($this->wpv_addr_optname, $wpv_addr_optval, '', 'yes');
        }

        if ( (get_option($this->wpv_port_optname) == FALSE) ) {
            add_option($this->wpv_port_optname, $wpv_port_optval, '', 'yes');
        }

        if ( (get_option($this->wpv_secret_optname) == FALSE) ) {
            add_option($this->wpv_secret_optname, $wpv_secret_optval, '', 'yes');
        }

        if ( (get_option($this->wpv_timeout_optname) == FALSE) ) {
            add_option($this->wpv_timeout_optname, $wpv_timeout_optval, '', 'yes');
        }

        if ( (get_option($this->wpv_update_pagenavi_optname) == FALSE) ) {
            add_option($this->wpv_update_pagenavi_optname, $wpv_update_pagenavi_optval, '', 'yes');
        }

        if ( (get_option($this->wpv_update_commentnavi_optname) == FALSE) ) {
            add_option($this->wpv_update_commentnavi_optname, $wpv_update_commentnavi_optval, '', 'yes');
        }

        if ( (get_option($this->wpv_use_adminport_optname) == FALSE) ) {
            add_option($this->wpv_use_adminport_optname, $wpv_use_adminport_optval, '', 'yes');
        }

        if ( (get_option($this->wpv_vversion_optname) == FALSE) ) {
            add_option($this->wpv_vversion_optname, $wpv_vversion_optval, '', 'yes');
        }

        // Localization init
        add_action('init', array($this, 'WPVMLocalization'));

        // Add Administration Interface
        add_action('admin_menu', array($this, 'WPVMAdminMenu'));

        // Add Purge Links to Admin Bar
        add_action('admin_bar_menu', array($this, 'WPVMAdminBarLinks'), 100);

        // When posts/pages are published, edited or deleted
        // 'edit_post' is not used as it is also executed when a comment is changed,
        // causing the plugin to purge several URLs (WPVMPurgeCommonObjects)
        // that do not need purging.

        // When a post or custom post type is published, or if it is edited and its status is "published".
        add_action('publish_post', array($this, 'WPVMPurgePost'), 99);
        add_action('publish_post', array($this, 'WPVMPurgeCommonObjects'), 99);
        // When a page is published, or if it is edited and its status is "published".
        add_action('publish_page', array($this, 'WPVMPurgePost'), 99);
        add_action('publish_page', array($this, 'WPVMPurgeCommonObjects'), 99);
        // When an attachment is updated.
        add_action('edit_attachment', array($this, 'WPVMPurgePost'), 99);
        add_action('edit_attachment', array($this, 'WPVMPurgeCommonObjects'), 99);
        // Runs just after a post is added via email.
        add_action('publish_phone', array($this, 'WPVMPurgePost'), 99);
        add_action('publish_phone', array($this, 'WPVMPurgeCommonObjects'), 99);
        // Runs when a post is published via XMLRPC request, or if it is edited via XMLRPC and its status is "published".
        add_action('xmlrpc_publish_post', array($this, 'WPVMPurgePost'), 99);
        add_action('xmlrpc_publish_post', array($this, 'WPVMPurgeCommonObjects'), 99);
        // Runs when a future post or page is published.
        add_action('publish_future_post', array($this, 'WPVMPurgePost'), 99);
        add_action('publish_future_post', array($this, 'WPVMPurgeCommonObjects'), 99);
        // When post status is changed
        add_action('transition_post_status', array($this, 'WPVMPurgePostStatus'), 99, 3);
        add_action('transition_post_status', array($this, 'WPVMPurgeCommonObjectsStatus'), 99, 3);
        // When posts, pages, attachments are deleted
        add_action('deleted_post', array($this, 'WPVMPurgePost'), 99);
        add_action('deleted_post', array($this, 'WPVMPurgeCommonObjects'), 99);

        // When comments are made, edited or deleted
        // See: http://codex.wordpress.org/Plugin_API/Action_Reference#Comment.2C_Ping.2C_and_Trackback_Actions
        add_action('comment_post', array($this, 'WPVMPurgePostComments'),99);
        add_action('edit_comment', array($this, 'WPVMPurgePostComments'),99);
        add_action('deleted_comment', array($this, 'WPVMPurgePostComments'),99);
        add_action('trashed_comment', array($this, 'WPVMPurgePostComments'),99);
        add_action('pingback_post', array($this, 'WPVMPurgePostComments'),99);
        add_action('trackback_post', array($this, 'WPVMPurgePostComments'),99);
        add_action('wp_set_comment_status', array($this, 'WPVMPurgePostCommentsStatus'),99);

        // When Theme is changed, Thanks dupuis
        add_action('switch_theme',array($this, 'WPVMPurgeAll'), 99);

        // Perform the actual purging on 'shutdown' hook.
        add_action('shutdown', array($this, 'WPVMPurgePool'), 99);

    }

    function WPVMLocalization() {
        load_plugin_textdomain('wpvm', false, dirname(plugin_basename( __FILE__ ) ) . '/languages/');
    }

    // WPVMPurgeAll - Using a regex, clear all blog cache. Use carefully.
    function WPVMPurgeAll() {
        $this->WPVMPurgeObject('/.*');
    }

    // WPVMPurgeURL - Using a URL, clear the cache
    function WPVMPurgeURL($wpv_purl) {
        $wpv_purl = preg_replace( '#^https?://[^/]+#i', '', $wpv_purl );
        $this->WPVMPurgeObject($wpv_purl);
    }

    //wrapper on WPVMPurgeCommonObjects for transition_post_status
    function WPVMPurgeCommonObjectsStatus($old, $new, $post) {
        if ( $old != $new ) {
            if ( $old == 'publish' || $new == 'publish' ) {
                $this->WPVMPurgeCommonObjects($post->ID);
            }
        }
    }

    // Purge related objects
    function WPVMPurgeCommonObjects($post_id) {

        $post = get_post($post_id);
        // We need a post object in order to generate the archive URLs which are
        // related to the post. We perform a few checks to make sure we have a
        // post object.
        if ( ! is_object($post) || ! isset($post->post_type) || ! in_array( get_post_type($post), array('post') ) ) {
            // Do nothing for pages, attachments.
            return;
        }
        
        // NOTE: Policy for archive purging
        // By default, only the first page of the archives is purged. If
        // 'wpv_update_pagenavi_optname' is checked, then all the pages of each
        // archive are purged.
        if ( get_option($this->wpv_update_pagenavi_optname) == 1 ) {
            // Purge all pages of the archive.
            $archive_pattern = '(?:page/[\d]+/)?$';
        } else {
            // Only first page of the archive is purged.
            $archive_pattern = '$';
        }

        // Front page (latest posts OR static front page)
        $this->WPVMPurgeObject( '/' . $archive_pattern );

        // Static Posts page (Added only if a static page used as the 'posts page')
        if ( get_option('show_on_front', 'posts') == 'page' && intval(get_option('page_for_posts', 0)) > 0 ) {
            $posts_page_url = preg_replace( '#^https?://[^/]+#i', '', get_permalink(intval(get_option('page_for_posts'))) );
            $this->WPVMPurgeObject( $posts_page_url . $archive_pattern );
        }

        // Feeds
        $this->WPVMPurgeObject( '/feed/(?:(atom|rdf)/)?$' );

        // Category, Tag, Author and Date Archives

        // We get the URLs of the category and tag archives, only for
        // those categories and tags which have been attached to the post.

        // Category Archive
        $category_slugs = array();
        foreach( get_the_category($post->ID) as $cat ) {
            $category_slugs[] = $cat->slug;
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
            $this->WPVMPurgeObject( '/' . $cat_base . '/' . $cat_slug_pattern . '/' . $archive_pattern );
        }

        // Tag Archive
        $tag_slugs = array();
        foreach( get_the_tags($post->ID) as $tag ) {
            $tag_slugs[] = $tag->slug;
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
            $this->WPVMPurgeObject( '/' . $tag_base . '/' . $tag_slug_pattern . '/' . $archive_pattern );
        }

        // Author Archive
        $author_archive_url = preg_replace('#^https?://[^/]+#i', '', get_author_posts_url($post->post_author) );
        $this->WPVMPurgeObject( $author_archive_url . $archive_pattern );

        // Date based archives
        $archive_year = mysql2date('Y', $post->post_date);
        $archive_month = mysql2date('m', $post->post_date);
        $archive_day = mysql2date('d', $post->post_date);
        // Yearly Archive
        $archive_year_url = preg_replace('#^https?://[^/]+#i', '', get_year_link( $archive_year ) );
        $this->WPVMPurgeObject( $archive_year_url . $archive_pattern );
        // Monthly Archive
        $archive_month_url = preg_replace('#^https?://[^/]+#i', '', get_month_link( $archive_year, $archive_month ) );
        $this->WPVMPurgeObject( $archive_month_url . $archive_pattern );
        // Daily Archive
        $archive_day_url = preg_replace('#^https?://[^/]+#i', '', get_day_link( $archive_year, $archive_month, $archive_day ) );
        $this->WPVMPurgeObject( $archive_day_url . $archive_pattern );
    }

    //wrapper on WPVMPurgePost for transition_post_status
    function WPVMPurgePostStatus($old, $new, $post) {
        if ( $old != $new ) {
            if ( $old == 'publish' || $new == 'publish' ) {
                $this->WPVMPurgePost($post->ID);
            }
        }
    }

    // WPVMPurgePost - Purges a post object
    function WPVMPurgePost($post_id, $purge_comments=false) {

        $post = get_post($post_id);
        // We need a post object, so we perform a few checks.
        if ( ! is_object($post) || ! isset($post->post_type) || ! in_array( get_post_type($post), array('post', 'page', 'attachment') ) ) {
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

        $wpv_url = preg_replace( '#^https?://[^/]+#i', '', $wpv_url );

        // Purge post comments feed and comment pages, if requested, before
        // adding multipage support.
        if ( $purge_comments === true ) {
            // Post comments feed
            $this->WPVMPurgeObject( $wpv_url . 'feed/(?:(atom|rdf)/)?$' );
            // For paged comments
            if ( intval(get_option('page_comments', 0)) == 1 ) {
                if ( get_option($this->wpv_update_commentnavi_optname) == 1 ) {
                    $this->WPVMPurgeObject( $wpv_url . 'comment-page-[\d]+/(?:#comments)?$' );
                }
            }
        }

        // Add support for multipage content for posts and pages
        if ( in_array( get_post_type($post), array('post', 'page') ) ) {
            $wpv_url .= '([\d]+/)?$';
        }
        // Purge object permalink
        $this->WPVMPurgeObject($wpv_url);

        // For attachments, also purge the parent post, if it is published.
        if ( get_post_type($post) == 'attachment' ) {
            if ( $post->post_parent > 0 ) {
                $parent_post = get_post( $post->post_parent );
                if ( $parent_post->post_status == 'publish' ) {
                    // If the parent post is published, then purge its permalink
                    $wpv_url = preg_replace( '#^https?://[^/]+#i', '', get_permalink($parent_post->ID) );
                    $this->WPVMPurgeObject( $wpv_url );
                }
            }
        }
    }

    // wrapper on WPVMPurgePostComments for comment status changes
    function WPVMPurgePostCommentsStatus($comment_id, $new_comment_status) {
        $this->WPVMPurgePostComments($comment_id);
    }

    // WPVMPurgePostComments - Purge all comments pages from a post
    function WPVMPurgePostComments($comment_id) {
        $comment = get_comment($comment_id);
        $post = get_post( $comment->comment_post_ID );

        // Comments feed
        $this->WPVMPurgeObject( '/comments/feed/(?:(atom|rdf)/)?$' );

        // Purge post page, post comments feed and post comments pages
        $this->WPVMPurgePost($post->ID, $purge_comments=true);

        // Popup comments
        // See:
        // - http://codex.wordpress.org/Function_Reference/comments_popup_link
        // - http://codex.wordpress.org/Template_Tags/comments_popup_script
        $this->WPVMPurgeObject( '/.*comments_popup=' . $post->ID . '.*' );

    }

    // Adds the 'Varnish' menu to the admin bar
    function WPVMAdminBarLinks($admin_bar){

        // Only administrators may purge the cache on demand.
        if ( ! current_user_can('manage_options') ) {
            return;
        }

        // Add 'Varnish' menu to the admin bar
        $admin_bar->add_menu( array(
            'id'    => 'wpvm',
            'title' => __('Varnish', 'wpvm'),
            'href' => admin_url('admin.php?page=WPVM')
        ));

        // Gather query arguments.
        //
        // 'next': stores the current REQUEST_URI. This is used to redirect the
        // user back after the purging is complete. It is also used as the purge
        // URL if a post object is not available on the current page.
        //
        // 'post_id': stores the post object ID, if a post object is available
        // on the current page.
        //
        // 'protocol': the protocol used http or https.
        //
        $next = urlencode( $_SERVER['REQUEST_URI'] );
        $post_id = 0;
        if ( is_singular() ) {
            // We have an object
            $post = get_queried_object();
            $post_id = $post->ID;
        }
        $protocol = 'http';
        if ( is_ssl() ) {
            $protocol = 'https';
        }

        // Submenu - Purge All Cache
        $admin_bar->add_menu( array(
            'id'    => 'clear-all-cache',
            'parent' => 'wpvm',
            'title' => 'Purge All Cache',
            'href'  => admin_url( wp_nonce_url('admin.php?page=WPVM&wpvm_clear_blog_cache&protocol=' . $protocol . '&next=' . $next, 'wpvm') )
        ));

        // Submenu - Purge the current page
        // This works in two ways:
        // 1) If a post_id is available, then ``WPVMPurgePost()`` is used.
        // 2) If a post object is not available the URL of the ``next`` argument
        //    is purged.
        $admin_bar->add_menu( array(
            'id'    => 'clear-single-cache',
            'parent' => 'wpvm',
            'title' => 'Purge This Page',
            'href'  => admin_url( wp_nonce_url('admin.php?page=WPVM&wpvm_clear_post&protocol=' . $protocol . '&post_id=' . $post_id . '&next=' . $next, 'wpvm') )
        ));
    }

    function WPVMAdminMenu() {
        if (!defined('VARNISH_HIDE_ADMINMENU')) {
            add_options_page(__('WordPress Varnish Modified Configuration','wpvm'), 'Varnish', 'manage_options', 'WPVM', array($this, 'WPVMAdmin'));
        }
    }

    // WPVMAdmin - Draw the administration interface.
    function WPVMAdmin() {
        if ($_SERVER["REQUEST_METHOD"] == "GET") {
            if (current_user_can('manage_options')) {

                // Gather settings from query arguments
                $nonce = null;
                if ( isset($_GET['_wpnonce']) ) {
                    $nonce = $_GET['_wpnonce'];
                }
                $next = '';
                if ( isset($_GET['next']) ) {
                    $next = urldecode($_GET['next']);
                }
                $post_id = 0;
                if ( isset($_GET['post_id']) ) {
                    $post_id = intval($_GET['post_id']);
                }
                if ( isset($_GET['protocol']) ) {
                    $protocol = $_GET['protocol'];
                    if ( ! in_array( $protocol, array('http', 'https') ) ) {
                        $protocol = 'http';
                    }
                }
                $location = '';

                // Purge All Cache
                if (isset($_GET['wpvm_clear_blog_cache']) && wp_verify_nonce( $nonce, 'wpvm' )) {
                    $this->WPVMPurgeAll();
                    // Determine redirect URL
                    $location = site_url( $next . '?wpvm=purged_all_cache' );
                    // Set the protocol of the original page
                    $location = preg_replace('#^https?://#i', $protocol . '://', $location );
                }

                // Purge Current Page or Post Object
                if (isset($_GET['wpvm_clear_post']) && wp_verify_nonce( $nonce, 'wpvm' )) {
                    if ( $post_id > 0 ) {
                        $this->WPVMPurgePost($post_id);
                        // Determine redirect URL
                        $location = site_url( $next . '?wpvm=purged_object_' . $post_id );
                        // Set the protocol of the original page
                        $location = preg_replace('#^https?://#i', $protocol . '://', $location );
                    } else {
                        $this->WPVMPurgeURL($next);
                        // Determine redirect URL
                        $location = site_url( $next . '?wpvm=purged_current_page' );
                        // Set the protocol of the original page
                        $location = preg_replace('#^https?://#i', $protocol . '://', $location );
                    }
                }

                // Use this workaround to redirect.
                if ( ! empty( $location ) ) {
                    echo '<script type="text/javascript"> window.location="' . $location . '"; </script>';
                }
            }

        } elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
            if (current_user_can('manage_options')) {
                if (isset($_POST['wpvm_admin'])) {
                    cleanSubmittedData('wpvm_port', '/[^0-9]/');
                    cleanSubmittedData('wpvm_addr', '/[^0-9.]/');
                    if (!empty($_POST["$this->wpv_addr_optname"])) {
                        $wpv_addr_optval = $_POST["$this->wpv_addr_optname"];
                        update_option($this->wpv_addr_optname, $wpv_addr_optval);
                    }

                    if (!empty($_POST["$this->wpv_port_optname"])) {
                        $wpv_port_optval = $_POST["$this->wpv_port_optname"];
                        update_option($this->wpv_port_optname, $wpv_port_optval);
                    }

                    if (!empty($_POST["$this->wpv_secret_optname"])) {
                        $wpv_secret_optval = $_POST["$this->wpv_secret_optname"];
                        update_option($this->wpv_secret_optname, $wpv_secret_optval);
                    }

                    if (!empty($_POST["$this->wpv_timeout_optname"])) {
                        $wpv_timeout_optval = $_POST["$this->wpv_timeout_optname"];
                        update_option($this->wpv_timeout_optname, $wpv_timeout_optval);
                    }

                    if (!empty($_POST["$this->wpv_update_pagenavi_optname"])) {
                        update_option($this->wpv_update_pagenavi_optname, 1);
                    } else {
                        update_option($this->wpv_update_pagenavi_optname, 0);
                    }

                    if (!empty($_POST["$this->wpv_update_commentnavi_optname"])) {
                        update_option($this->wpv_update_commentnavi_optname, 1);
                    } else {
                        update_option($this->wpv_update_commentnavi_optname, 0);
                    }

                    if (!empty($_POST["$this->wpv_use_adminport_optname"])) {
                        update_option($this->wpv_use_adminport_optname, 1);
                    } else {
                        update_option($this->wpv_use_adminport_optname, 0);
                    }

                    if (!empty($_POST["$this->wpv_vversion_optname"])) {
                        $wpv_vversion_optval = $_POST["$this->wpv_vversion_optname"];
                        update_option($this->wpv_vversion_optname, $wpv_vversion_optval);
                    }
                }

                ?><div class="updated"><p><?php echo __('Settings Saved!','wpvm' ); ?></p></div><?php

            } else {
                ?><div class="updated"><p><?php echo __('You do not have the privileges.','wpvm' ); ?></p></div><?php
            }
        }

        $wpv_timeout_optval = get_option($this->wpv_timeout_optname);
        $wpv_update_pagenavi_optval = get_option($this->wpv_update_pagenavi_optname);
        $wpv_update_commentnavi_optval = get_option($this->wpv_update_commentnavi_optname);
        $wpv_use_adminport_optval = get_option($this->wpv_use_adminport_optname);
        $wpv_vversion_optval = get_option($this->wpv_vversion_optname);
        ?>
        <div class="wrap">
        <script type="text/javascript" src="<?php echo plugins_url('js/wpvm.js', __FILE__ ); ?>"></script>
        <h2><?php echo __("WordPress-Varnish-Modified Administration Interface",'wpvm'); ?></h2>
        <h3><?php echo __("IP address and port configuration",'wpvm'); ?></h3>
        <form method="POST" action="<?php echo $_SERVER['REQUEST_URI']; ?>">
        <?php
        // Can't be edited - already defined in wp-config.php
        global $varnish_servers;
        global $varnish_version;
        if (is_array($varnish_servers)) {
            echo "<p>" . __("These values can't be edited since there's a global configuration located in <em>wp-config.php</em>. If you want to change these settings, please update the file or contact the administrator.",'wpvm') . "</p>\n";
            // Also, if defined, show the varnish servers configured (VARNISH_SHOWCFG)
            if (defined('VARNISH_SHOWCFG')) {
                echo "<h3>" . __("Current configuration:",'wpvm') . "</h3>\n";
                echo "<ul>";
                if ( isset($varnish_version) && $varnish_version ) {
                    echo "<li>" . __("Version: ",'wpvm') . $varnish_version . "</li>";
                }
                foreach ($varnish_servers as $server) {
                    @list ($host, $port, $secret) = explode(':', $server);
                    echo "<li>" . __("Server: ",'wpvm') . $host . "<br/>" . __("Port: ",'wpvm') . $port . "</li>";
                }
                echo "</ul>";
            }
        } else {
            // If not defined in wp-config.php, use individual configuration.
            ?>
            <!-- <table class="form-table" id="form-table" width=""> -->
            <table class="form-table" id="form-table">
            <tr valign="top">
            <th scope="row"><?php echo __("Varnish Administration IP Address",'wpvm'); ?></th>
            <th scope="row"><?php echo __("Varnish Administration Port",'wpvm'); ?></th>
            <th scope="row"><?php echo __("Varnish Secret",'wpvm'); ?></th>
            </tr>
            <script>
            <?php
            $addrs = get_option($this->wpv_addr_optname);
            $ports = get_option($this->wpv_port_optname);
            $secrets = get_option($this->wpv_secret_optname);
            //echo "rowCount = $i\n";
            for ($i = 0; $i < count ($addrs); $i++) {
                // let's center the row creation in one spot, in javascript
                echo "addRow('form-table', $i, '$addrs[$i]', $ports[$i], '$secrets[$i]');\n";
            } ?>
            </script>
            </table>

            <br/>

            <table>
            <tr>
            <td colspan="3"><input type="button" class="" name="wpvm_admin" value="+" onclick="addRow ('form-table', rowCount)" /> <?php echo __("Add one more server",'wpvm'); ?></td>
            </tr>
            </table>
            <?php
        }
        ?>
        <p><?php echo __("Timeout",'wpvm'); ?>: <input class="small-text" type="text" name="wpvm_timeout" value="<?php echo $wpv_timeout_optval; ?>" /> <?php echo __("seconds",'wpvm'); ?></p>

        <p><input type="checkbox" name="wpvm_use_adminport" value="1" <?php if ($wpv_use_adminport_optval == 1) echo 'checked '?>/> <?php echo __("Use admin port instead of PURGE method.",'wpvm'); ?></p>

        <p><input type="checkbox" name="wpvm_update_pagenavi" value="1" <?php if ($wpv_update_pagenavi_optval == 1) echo 'checked '?>/> <?php echo __("Also purge all page navigation.",'wpvm'); ?></p>

        <p><input type="checkbox" name="wpvm_update_commentnavi" value="1" <?php if ($wpv_update_commentnavi_optval == 1) echo 'checked '?>/> <?php echo __("Also purge all comment navigation.",'wpvm'); ?></p>

        <p><?php echo __('Varnish Version', 'wpvm'); ?>: <select name="wpvm_vversion"><option value="2" <?php if ($wpv_vversion_optval == 2) echo 'selected '?>/> 2 </option><option value="3" <?php if ($wpv_vversion_optval == 3) echo 'selected '?>/> 3 </option></select></p>

        <p class="submit"><input type="submit" class="button-primary" name="wpvm_admin" value="<?php echo __("Save Changes",'wpvm'); ?>" /></p>

        </form>
        </div>
        <?php
    }

    // WPVMPurgeObject - Adds the URL to the URL pool.
    function WPVMPurgeObject($wpv_url) {
        array_push($this->purge_url_pool, $wpv_url);
    }

    // Purges all URLs in the pool.
	function WPVMPurgePool() {
        // Just return if we do not have any URLs to purge
		if ( empty($this->purge_url_pool) ) {
			return;
		}

        // Make the contents of the URL pool unique
        $url_pool = array_unique($this->purge_url_pool);

        // Get Varnish servers info
        global $varnish_servers;

        if (is_array($varnish_servers)) {
            foreach ($varnish_servers as $server) {
                list ($host, $port, $secret) = explode(':', $server);
                $wpv_purgeaddr[] = $host;
                $wpv_purgeport[] = $port;
                $wpv_secret[] = $secret;
            }
        } else {
            $wpv_purgeaddr = get_option($this->wpv_addr_optname);
            $wpv_purgeport = get_option($this->wpv_port_optname);
            $wpv_secret = get_option($this->wpv_secret_optname);
        }

        // Gather connection settings
        $wpv_timeout = get_option($this->wpv_timeout_optname);
        $wpv_use_adminport = get_option($this->wpv_use_adminport_optname);
        global $varnish_version;
        if ( isset($varnish_version) && in_array($varnish_version, array(2,3)) ) {
            $wpv_vversion_optval = $varnish_version;
        } else {
            $wpv_vversion_optval = get_option($this->wpv_vversion_optname);
        }

        // Process URL pool and purge
        foreach ($url_pool as $wpv_url) {

            // check for domain mapping plugin by donncha
            if (function_exists('domain_mapping_siteurl')) {
                $wpv_wpurl = domain_mapping_siteurl('NA');
            } else {
                $wpv_wpurl = get_bloginfo('url');
            }
            $wpv_replace_wpurl = '/^https?:\/\/([^\/]+)(.*)/i';
            $wpv_host = preg_replace($wpv_replace_wpurl, "$1", $wpv_wpurl);
            $wpv_blogaddr = preg_replace($wpv_replace_wpurl, "$2", $wpv_wpurl);
            $wpv_url = $wpv_blogaddr . $wpv_url;

            for ($i = 0; $i < count ($wpv_purgeaddr); $i++) {
                $varnish_sock = fsockopen($wpv_purgeaddr[$i], $wpv_purgeport[$i], $errno, $errstr, $wpv_timeout);
                if (!$varnish_sock) {
                    error_log("wpvm error: $errstr ($errno) on server $wpv_purgeaddr[$i]:$wpv_purgeport[$i]");
                    continue;
                }

                if($wpv_use_adminport) {
                    $buf = fread($varnish_sock, 1024);
                    if(preg_match('/(\w+)\s+Authentication required./', $buf, $matches)) {
                        # get the secret
                        $secret = $wpv_secret[$i];
                        fwrite($varnish_sock, "auth " . $this->WPAuth($matches[1], $secret) . "\n");
                        $buf = fread($varnish_sock, 1024);
                        if(!preg_match('/^200/', $buf)) {
                            error_log("wpvm error: authentication failed using admin port on server $wpv_purgeaddr[$i]:$wpv_purgeport[$i]");
                            fclose($varnish_sock);
                            continue;
                        }
                    }
                    if ($wpv_vversion_optval == 3) {
                        $out = "ban req.url ~ ^$wpv_url$ && req.http.host == $wpv_host\n";
                    } else {
                        $out = "purge req.url ~ ^$wpv_url && req.http.host == $wpv_host\n";
                    }
                } else {
                    $out = "BAN $wpv_url HTTP/1.0\r\n";
                    $out .= "Host: $wpv_host\r\n";
                    $out .= "User-Agent: WordPress-Varnish plugin\r\n";
                    $out .= "Connection: Close\r\n\r\n";
                }
                fwrite($varnish_sock, $out);
                fclose($varnish_sock);
            }
        }
	}

    function WPAuth($challenge, $secret) {
        $ctx = hash_init('sha256');
        hash_update($ctx, $challenge);
        hash_update($ctx, "\n");
        hash_update($ctx, $secret . "\n");
        hash_update($ctx, $challenge);
        hash_update($ctx, "\n");
        $sha256 = hash_final($ctx);

        return $sha256;
    }

}

$wpvm = new WPVM();

// Helper functions
function cleanSubmittedData($varname, $regexp) {
    // FIXME: should do this in the admin console js, not here   
    // normally I hate cleaning data and would rather validate before submit
    // but, this fixes the problem in the cleanest method for now
    foreach ($_POST[$varname] as $key=>$value) {
        $_POST[$varname][$key] = preg_replace($regexp,'',$value);
    }
}

/**
 * Settings Link in the ``Installed Plugins`` page
 */
function wpvm_plugin_actions( $links, $file ) {
    if( $file == plugin_basename(__FILE__) && function_exists( "admin_url" ) ) {
        $settings_link = '<a href="' . admin_url( 'options-general.php?page=WPVM' ) . '">' . __('Settings') . '</a>';
        // Add the settings link before other links
        array_unshift( $links, $settings_link );
    }
    return $links;
}
add_filter( 'plugin_action_links', 'wpvm_plugin_actions', 10, 2 );

?>
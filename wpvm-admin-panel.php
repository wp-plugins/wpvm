<?php
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

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
    header( 'HTTP/1.0 403 Forbidden' );
    echo 'This file should not be accessed directly!';
    exit; // Exit if accessed directly
}


function wpvm_show_info_msg($msg) {
    echo '<div id="message" class="updated fade"><p>' . $msg . '</p></div>';
}


/*
* Construct the WPVM administration panel under Settings->Varnish
*/



function wpvm_admin_init() {

    // Here we just add some dummy variables that contain the plugin name and
    // the description exactly as they appear in the plugin metadata, so that
    // they can be translated.
    $wpvm_plugin_name = __('WPVM', 'wpvm');
    $wpvm_plugin_description = __('WPVM (WordPress Varnish Modified) purges pages from Varnish caching servers either automatically as content is updated or on demand.', 'wpvm');

    // Perform automatic settings upgrade based on settings version.
    // Also creates initial default settings automatically.
    wpvm_plugin_upgrade();

    // Register scripts and styles

    /* Register our script. */
    wp_register_script( 'wpvm-server-table', plugins_url('js/wpvm.js', __FILE__ ) );
    /* Register our stylesheet. */
    // wp_register_style( 'myPluginStylesheet', plugins_url('stylesheet.css', __FILE__) );

}
add_action( 'admin_init', 'wpvm_admin_init' );


function wpvm_admin_menu() {
    /* Register our plugin page */
    add_options_page(
        __('Varnish Settings', 'wpvm'),
        __('Varnish', 'wpvm'),
        'manage_options',
        'wpvm-options',
        'wpvm_options_page'
    );
}
add_action( 'admin_menu', 'wpvm_admin_menu');


/** Enqueue scripts and styles
 *  From: http://codex.wordpress.org/Plugin_API/Action_Reference/admin_enqueue_scripts#Example:_Target_a_Specific_Admin_Page
 */
function wpvm_enqueue_admin_scripts_and_styles($hook) {
    //var_dump($hook);
    if ( 'settings_page_wpvm-options' != $hook ) {
        return;
    }
    wp_enqueue_script( 'wpvm-server-table' );
}
add_action( 'admin_enqueue_scripts', 'wpvm_enqueue_admin_scripts_and_styles' );
// Note: `admin_print_styles` should not be used to enqueue styles or scripts on the admin pages. Use `admin_enqueue_scripts` instead.


function wpvm_options_page() {
    // Permission Check
    if ( ! current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.', 'wpvm' ) );
    }

    if (isset($_POST['info_update'])) {

        wpvm_save_settings( $_POST );

    } elseif ( isset( $_POST['info_reset'] ) ) {

        wpvm_reset_settings();

    } elseif ( isset( $_POST['wpvm_purge_url_submit'] ) ) {
        // Purge single url initiated from the admin interface box
        wpvm_purge_url( $_POST['wpvm_purge_url'] );
        print('<div class="updated"><p>' . __('Successfully purged URL!', 'wpvm' ) . '</p></div>');

    } elseif ( isset( $_POST['wpvm_purge_url_group'] ) ) {
        // Purge url group
        wpvm_purge_url_group();
        print('<div class="updated"><p>' . __('Successfully purged URL group!', 'wpvm' ) . '</p></div>');

    } elseif ( isset( $_POST['wpvm_purge_robots_txt'] ) ) {
        // Purge robots.txt
        wpvm_purge_url( site_url('/robots.txt') );
        print('<div class="updated"><p>' . __('Successfully purged robots.txt!', 'wpvm' ) . '</p></div>');
    
    } elseif ( isset( $_POST['wpvm_purge_all_cache'] ) ) {
        // Purge all cache
        wpvm_purge_all_cache();
        print('<div class="updated"><p>' . __('Successfully purged all cache!','wpvm' ) . '</p></div>');

    } elseif ( $_SERVER["REQUEST_METHOD"] == "GET" ) {

        // If it gets in here, then we should have options in the database.
        $options = get_option('wpvm_opts');

        // Actions from admin bar
        
        // Gather settings from query arguments
        $nonce = null;
        if ( isset($_GET['_wpnonce']) ) {
            $nonce = $_GET['_wpnonce'];
        }
        $next = '';
        if ( isset($_GET['next']) ) {
            $next = urldecode($_GET['next']);
            // Remove any previously set wpvm parameter.
            if ( strpos($next, '?') !== false ) {
                list( $base_url, $parameters ) = explode( '?', $next );
                parse_str( $parameters, $output );
                if ( isset($output['wpvm']) ) {
                    unset( $output['wpvm'] ); // remove the 'wpvm' parameter
                }
                if ( ! empty($output) ) {
                    $next = $base_url . '?' . http_build_query($output ); // Rebuild the url
                } else {
                    $next = $base_url;
                }
            }
        }
        $post_id = 0;
        if ( isset($_GET['post_id']) ) {
            $post_id = intval($_GET['post_id']);
        }
        $protocol = 'http';
        if ( isset($_GET['protocol']) ) {
            $protocol = $_GET['protocol'];
            if ( ! in_array( $protocol, array('http', 'https') ) ) {
                $protocol = 'http';
            }
        }
        $location = '';

        // Perform actions

        // Purge All Cache
        if (isset($_GET['wpvm_clear_blog_cache']) && wp_verify_nonce( $nonce, 'wpvm' )) {
            wpvm_purge_all_cache();
            // Determine redirect URL
            $location = site_url( $next . '?wpvm=purged_all_cache' );
            
        }

        // Purge URL Group
        if (isset($_GET['wpvm_clear_url_group']) && wp_verify_nonce( $nonce, 'wpvm' )) {
            wpvm_purge_url_group();
            // Determine redirect URL
            $location = site_url( $next . '?wpvm=purged_url_group' );
        }

        // Purge Current Page or Post Object
        if (isset($_GET['wpvm_clear_post']) && wp_verify_nonce( $nonce, 'wpvm' )) {
            if ( $post_id > 0 ) {
                wpvm_purge_post( $post_id, get_post($post_id) );
                // Also purge related objects if ``extended_object_purge`` is enabled.
                $suffix = '';
                if ( absint($options['extended_object_purge']) ) {
                    wpvm_purge_related_objects( $post_id, get_post($post_id) );
                    $suffix = '_and_related';
                }
                // Determine redirect URL
                $location = site_url( $next . '?wpvm=purged_object_' . $post_id . $suffix );
            } else {
                wpvm_purge_url( $next . '$' );
                // Determine redirect URL
                $location = site_url( $next . '?wpvm=purged_current_page' );
            }
        }

        // Set the protocol of the original page
        $location = preg_replace('#^https?://#i', $protocol . '://', $location );
        // Use this workaround to redirect.
        if ( ! empty( $location ) ) {
            echo '<script type="text/javascript"> window.location="' . $location . '"; </script>';
        }

    }

    // Try to get the options from the DB.
    $options = get_option('wpvm_opts');
    //var_dump($options);

    wpvm_set_varnish_options($options);

}


function wpvm_set_varnish_options($options) {

    print('
    <div class="wrap">
        <div id="icon-options-general" class="icon32"><br /></div>
        <h2>'.__('Varnish Settings', 'wpvm').'</h2>
        <p>This is the WordPress-Varnish-Modified (WPVM) Administration Interface.</p>
    </div>

    <div class="wrap">
        <h3 class="title">'.__('Varnish Servers', 'wpvm').'</h3>
        <p>'.__('Here you can enter the connection settings, such as the IP address and port, for each of your Varnish servers. The secret is only used for authentication if you are connecting to the Varnish admin port.', 'wpvm').'</p>

        <form name="formwpvm" method="post" action="' . admin_url( 'options-general.php?page=wpvm-options' ) . '">

        <table class="varnish-table" id="varnish-table">
        <!-- If the table id is changed, also change in wpvm_add_table_rows_via_js_for_varnish_servers(). Also see below in `wpvm_admin` button. -->
            <tr valign="top">
                <th scope="row">' . __("Varnish Server IP Address", 'wpvm') . '</th>
                <th scope="row">' . __("Varnish Server Port", 'wpvm') . '</th>
                <th scope="row">' . __("Varnish Admin Socket Secret",'wpvm') . '</th>
            </tr>
            ' . wpvm_add_table_rows_via_js_for_varnish_servers( $options ) . '
        </table>

        <br/>
        <table class="form-table">
            <tr>
                <td colspan="3"><input type="button" class="" name="wpvm_admin" value="+" onclick="addRowVarnishServer (\'varnish-table\', rowCountVarnishServer)" /> ' . __("Add one more server", 'wpvm') . '</td>
            </tr>
        </table>

        <table class="form-table">
        <tbody>

            <tr valign="top">
            <th scope="row">'.__('Connection Timeout', 'wpvm').'</th>
            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('Connection Timeout', 'wpvm').'</span></legend>
                <input name="varnish_connection_timeout" type="text" id="varnish_connection_timeout" class="code" value="' . $options["varnish_connection_timeout"] . '" size="7" maxlength="7" />
                <label for="varnish_connection_timeout">
                '.__('Enter the connection timeout in seconds (Default is 5 seconds).', 'wpvm').'
                </label>
                <br />
            </fieldset>
            </td>
            </tr>

            <tr valign="top">
            <th scope="row">'.__('Use Admin Port', 'wpvm').'</th>
            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('Use Admin Port', 'wpvm').'</span></legend>
                <input id="varnish_use_adminport" type="checkbox" value="1" name="varnish_use_adminport" '. (($options["varnish_use_adminport"]=="1") ? 'checked="checked"' : '') .'" />
                <label for="varnish_use_adminport">
                '.__('Connect to the admin port of the Varnish server. Make sure the correct port and secret have been set in the connection settings above.', 'wpvm').'
                </label>
                <br />
            </fieldset>
            </td>
            </tr>

            <tr valign="top">
            <th scope="row">'.__('Extended Object Purge', 'wpvm').'</th>
            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('Extended Object Purge', 'wpvm').'</span></legend>
                <input id="extended_object_purge" type="checkbox" value="1" name="extended_object_purge" '. (($options["extended_object_purge"]=="1") ? 'checked="checked"' : '') .'" />
                <label for="extended_object_purge">
                '.__('If selected, apart from the object page, all related pages, such as archives, feeds, homepage, sitemap, are also purged.', 'wpvm').'
                </label>
                <br />
            </fieldset>
            </td>
            </tr>

            <tr valign="top">
            <th scope="row">'.__('Extended Archive Purge', 'wpvm').'</th>
            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('Extended Archive Purge', 'wpvm').'</span></legend>
                <input id="extended_archive_purge" type="checkbox" value="1" name="extended_archive_purge" '. (($options["extended_archive_purge"]=="1") ? 'checked="checked"' : '') .'" />
                <label for="extended_archive_purge">
                '.__('If selected, all pages of the archive will be purged, otherwise only the first page of the archive is purged.', 'wpvm').'
                </label>
                <br />
            </fieldset>
            </td>
            </tr>

            <tr valign="top">
            <th scope="row">'.__('Extended Comment Purge', 'wpvm').'</th>
            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('Extended Comment Purge', 'wpvm').'</span></legend>
                <input id="extended_comment_purge" type="checkbox" value="1" name="extended_comment_purge" '. (($options["extended_comment_purge"]=="1") ? 'checked="checked"' : '') .'" />
                <label for="extended_comment_purge">
                '.__('If selected, all comment pages for a specific object are purged, otherwise only the first comment page is purged.', 'wpvm').'
                </label>
                <br />
            </fieldset>
            </td>
            </tr>

            <tr valign="top">
            <th scope="row">'.__('Varnish Version', 'wpvm').'</th>
            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('Varnish Version', 'wpvm').'</span></legend>
                <select name="varnish_version" id="varnish_version">
                    <option value="2" ' . (($options["varnish_version"]=="2") ? 'selected="selected"' : '') . '>2</option>
                    <option value="3" ' . (($options["varnish_version"]=="3") ? 'selected="selected"' : '') . '>3</option>
                </select>
                <br />
                <label for="varnish_version">
                '.__('Select the version of the Varnish server you use. This is only required if you connect to the admin port of your Varnish servers. In case of Varnish v2 the <code>purge</code> command is invoked. In case of Varnish v3 or newer the <code>ban</code> command is invoked.', 'wpvm').'
                </label>
                <br />
            </fieldset>
            </td>
            </tr>

        </tbody>
        </table>


        <h3 class="title">'.__('URL Group', 'wpvm').'</h3>
        <p>'.__('Here you can enter a list of custom URLs (one per line) which can be purged using the tool buttons below or from the WordPress admin bar.', 'wpvm').'</p>

        <table class="form-table">
        <tbody>

            <tr valign="top">

            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('URL Group', 'wpvm').'</span></legend>
                <label for="purge_url_group">
                    <textarea name="purge_url_group" id="purge_url_group" cols="100" rows="5" class="code">' . esc_attr( stripslashes( $options["purge_url_group"] ) ) . '</textarea>
                    <br />
                    '.__('For instance, you can enter URLs of pages not generated by WordPress or files not served by WordPress.', 'wpvm').'
                </label>
            </fieldset>
            </td>
            </tr>

        </tbody>
        </table>

        <h3 class="title">'.__('Logging', 'wpvm').'</h3>

        <table class="form-table">
        <tbody>

            <tr valign="top">
            <th scope="row">'.__('Enable logging', 'wpvm').'</th>
            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('Enable logging', 'wpvm').'</span></legend>
                <input id="enable_logging" type="checkbox" value="1" name="enable_logging" '. (($options["enable_logging"]=="1") ? 'checked="checked"' : '') .'" />
                <label for="enable_logging">
                '.__('If checked, specific events, such as purged URLs or errors, are recorded in a log file at the location specified below.', 'wpvm').'
                </label>
                <br />
            </fieldset>
            </td>
            </tr>

            <tr valign="top">
            <th scope="row">'.__('Log file path', 'wpvm').'</th>
            <td>
            <fieldset>
                <legend class="screen-reader-text"><span>'.__('Log file path', 'wpvm').'</span></legend>
                <input name="logfile_path" type="text" id="logfile_path" class="code" value="' . $options["logfile_path"] . '" size="80" maxlength="255" />
                <label for="logfile_path">
                '.__('Enter the path to the log file. If left blank, no logging activity will occur, even if logging has been enabled.', 'wpvm').'
                </label>
                <br />
            </fieldset>
            </td>
            </tr>

        </tbody>
        </table>


        <!-- Submit Buttons -->
        <table class="form-table">
        <tbody>
            <tr valign="top">
                <th scope="row">
                    <input id="submit" class="button-primary" type="submit" value="'.__('Save Changes', 'wpvm').'" name="info_update" />
                </th>
                <th scope="row">
                    <input id="reset" class="button-primary" type="submit" value="'.__('Reset to defaults', 'wpvm').'" name="info_reset" />
                </th>
                <th></th><th></th><th></th><th></th>
            </tr>
        </tbody>
        </table>

        <h2 class="title">'.__('Tools', 'wpvm').'</h2>
        <p>'.__('Use these tools for quick purges.', 'wpvm').'</p>

        <table class="form-table">
        <tbody>
            
            <h3 class="title">Purge single URL</h3>
            <p>'.__('Enter a custom URL and press the <em>Purge</em> button to purge.', 'wpvm').'</p>
            <p>
                <input type="text" name="wpvm_purge_url" value="' . trailingslashit(site_url()) . '" size="80" maxlength="255" />
                <input type="submit" class="button-primary" name="wpvm_purge_url_submit" value="' . __("Purge",'wpvm') . '" />
            </p>

            <h3 class="title">Purge URL Group</h3>
            <p>'.__('Press the <em>Purge</em> button to purge the URL group that has been defined in the settings above.', 'wpvm').'</p>
            <p><input type="submit" class="button-primary" name="wpvm_purge_url_group" value="' . __("Purge",'wpvm') . '" /></p>

            <h3 class="title">Purge robots.txt</h3>
            <p>'.__('Press the <em>Purge</em> button to purge the <code>robots.txt</code> file.', 'wpvm').'</p>
            <p><input type="submit" class="button-primary" name="wpvm_purge_robots_txt" value="' . __("Purge",'wpvm') . '" /></p>

            <h3 class="title">Purge All Cache</h3>
            <p>'.__('Press the <em>Purge</em> button to purge all cached content. In big web sites with high traffic this can cause a significant increase in the application server load, until the content is cached again.', 'wpvm').'</p>
            <p><input type="submit" class="button-primary" name="wpvm_purge_all_cache" value="' . __("Purge",'wpvm') . '" /></p>

        </tbody>
        </table>

        </form>

    </div>

    ');

}



// Adds the 'Varnish' menu to the admin bar
function wpvm_admin_bar_links( $admin_bar ){

    // Only administrators may purge the cache on demand.
    if ( ! current_user_can('manage_options') ) {
        return;
    }

    // Do not display the menu when the user is in the WP administration panel.
    if ( is_admin() ) {
        return;
    }

    // Add 'Varnish' menu to the admin bar
    $admin_bar->add_menu( array(
        'id'    => 'wpvm',
        'title' => __('Varnish', 'wpvm'),
        'href' => admin_url('admin.php?page=wpvm-options')
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
        'href'  => admin_url( wp_nonce_url('admin.php?page=wpvm-options&wpvm_clear_blog_cache&protocol=' . $protocol . '&next=' . $next, 'wpvm') )
    ));

    // Submenu - Purge URL Group
    $admin_bar->add_menu( array(
        'id'    => 'clear-url-group',
        'parent' => 'wpvm',
        'title' => 'Purge URL Group',
        'href'  => admin_url( wp_nonce_url('admin.php?page=wpvm-options&wpvm_clear_url_group&protocol=' . $protocol . '&next=' . $next, 'wpvm') )
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
        'href'  => admin_url( wp_nonce_url('admin.php?page=wpvm-options&wpvm_clear_post&protocol=' . $protocol . '&post_id=' . $post_id . '&next=' . $next, 'wpvm') )
    ));
}

// Add Purge Links to Admin Bar
add_action('admin_bar_menu', 'wpvm_admin_bar_links', 100);


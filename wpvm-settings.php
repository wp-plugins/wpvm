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


/**
 * Module containing settings related functions.
 */

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
    header( 'HTTP/1.0 403 Forbidden' );
    echo 'This file should not be accessed directly!';
    exit; // Exit if accessed directly
}


/**
 * Returns an array with the default options.
 */
function wpvm_get_default_options() {
    return array(
        "settings_version" => 1, // IMPORTANT: SETTINGS UPGRADE: Every time settings are added or removed this has to be incremented.
        "varnish_addresses" => array( '127.0.0.1' ),  // Array of varnish server addresses.
        "varnish_ports" => array( '80' ),             // Array of varnish server ports.
        "varnish_secrets"  => array(),          // Array of varnish server admin port secrets.
        "varnish_version" => "3",               // Varnish VCL version (supported 2, 3)
        "varnish_use_adminport" => "0",         // Connect to the admin port of Varnish server.
        "varnish_connection_timeout" => "5",    // Connection timeout in seconds.
        "extended_object_purge" => "1",         // Purge the object page and also (if selected) all related pages (archives, feeds, homepage, sitemap).
        "extended_archive_purge" => "0",        // Purge all pages of the archive or the first page only (if selected).
        "extended_comment_purge" => "0",        // Purge all pages of the comments or the first page only (if selected).
        "purge_url_group" => "",                // URL group.
        "logfile_path" => "",                   // Path to logfile.
        "enable_logging" => "0",                // Enable logging.
        );
}


/**
 * Performs upgrade of the plugin settings.
 */
function wpvm_plugin_upgrade() {

    // First we try to determine if this is a new installation or if the
    // current installation requires upgrade.

    // Default WPVM Settings
    $default_options = wpvm_get_default_options();

    // Try to get the current WPVM options from the database
    $stored_options = get_option("wpvm_opts");
    if ( empty($stored_options) ) {
        // This is the first run, so set our defaults.
        update_option("wpvm_opts", $default_options);
        return;
    }

    // Check the settings version

    // If the settings version of the default options matches the settings version
    // of the stored options, there is no need to upgrade.
    if (array_key_exists('settings_version', $stored_options) &&
            (intval($stored_options["settings_version"]) == intval($default_options["settings_version"])) ) {
        // Settings are up to date. No upgrade required.
        return;
    }

    // On any other case a settings upgrade is required.

    // 1) Add any missing options to the stored WPVM options
    foreach ($default_options as $opt => $value) {
        // Always upgrade the ``settings_version`` option
        if ($opt == 'settings_version') {
            $stored_options['settings_version'] = $value;
        }
        // Add missing options
        elseif ( !array_key_exists($opt, $stored_options) ) {
            $stored_options[$opt] = $value;
        }
        // Existing stored options are untouched here.
    }

    // 2) Migrate any current options to new ones.
    // Migration rules should go here.


    // 3) Clean stored options.
    foreach ($stored_options as $opt => $value) {
        if ( !array_key_exists($opt, $default_options) ) {
            // Remove any options that do not exist in the default options.
            unset($stored_options[$opt]);
        }
    }

    // Finally save the updated options.
    update_option("wpvm_opts", $stored_options);

}
add_action('plugins_loaded', 'wpvm_plugin_upgrade');


/**
 * Saves the new settings in the database.
 * Accepts the POST request data.
 */
function wpvm_save_settings($post_payload) {
    
    // Default WPVM Settings
    $default_options = wpvm_get_default_options();

    $options = array();

    foreach ($default_options as $def_key => $def_value) {

        // **Always** use the ``settings_version`` from the defaults
        if ($def_key == 'settings_version') {
            $options['settings_version'] = $def_value;
        }

        // Add options from the POST request (saved by the user)
        elseif ( array_key_exists($def_key, $post_payload) ) {

            // Validate and sanitize input before adding to 'wpvm_opts'
            if ( $def_key == 'varnish_addresses' ) {
                $options[$def_key] = wpvm_sanitize_data_regexp( $post_payload[$def_key], '#[^0-9.]#' );
            } elseif ( $def_key == 'varnish_ports' ) {
                $options[$def_key] = wpvm_sanitize_data_regexp( $post_payload[$def_key], '#[^0-9]#' );
            } elseif ( $def_key == 'varnish_secrets' ) {
                // No sanitization of varnish secrets, since they are hashed and not used anywhere else.
                $options[$def_key] = $post_payload[$def_key];
            } elseif ( $def_key == 'varnish_connection_timeout' ) {
                $options[$def_key] = wpvm_sanitize_data_regexp( stripslashes( $post_payload[$def_key] ), '#[^0-9]#' );
            } elseif ( $def_key == 'purge_url_group' ) {
                $options[$def_key] = esc_textarea( wp_kses( stripslashes( $post_payload[$def_key] ), array() ) );
            } else {
                if ( is_array( $post_payload[$def_key] ) ) {
                    for ( $i = 0; $i < count( $post_payload[$def_key] ); $i++ ) {
                        $post_payload[$def_key][$i] = sanitize_text_field( stripslashes( $post_payload[$def_key][$i] ) );
                    }
                    $options[$def_key] = $post_payload[$def_key]; // sanitized
                } else {
                    $options[$def_key] = sanitize_text_field( stripslashes( $post_payload[$def_key] ) );
                }
            }
        }
        
        // If missing (eg checkboxes), use the default value, except for the case
        // those checkbox settings whose default value is 1.
        else {

            // The following settings have a default value of 1, so they can never be
            // deactivated, unless the following check takes place.
            if (
                $def_key == 'extended_object_purge'
                // || $def_key == 'some_other_checkbox_option'
            ) {
                if( !isset($post_payload[$def_key]) ){
                    $options[$def_key] = "0";
                }
            } else {
                // Else save the default value in the db.
                $options[$def_key] = $def_value;
            }

        }
    }

    // Finally update the WPVM options.
    update_option("wpvm_opts", $options);

    //var_dump($post_payload);
    //var_dump($options);

    wpvm_show_info_msg(__('WPVM options saved', 'wpvm'));
}


/**
 * Reset settings to the defaults.
 */
function wpvm_reset_settings() {
    // Default WPVM Settings
    $default_options = wpvm_get_default_options();

    delete_option("wpvm_opts");
    update_option("wpvm_opts", $default_options);
    wpvm_show_info_msg(__('WPVM options were reset to defaults', 'wpvm'));
}


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
 *  Module containing utility functions.
 */

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
    header( 'HTTP/1.0 403 Forbidden' );
    echo 'This file should not be accessed directly!';
    exit; // Exit if accessed directly
}


/**
 *  Helper function that cleans $data according to the provided regular expression.
 */
function wpvm_sanitize_data_regexp( $data, $regexp ) {
    if ( is_array($data) ) {
        for ( $i = 0; $i < count($data); $i++ ) {
            $data[$i] = preg_replace( $regexp, '', stripslashes( $data[$i] ) );
        }
    } else {
        $data = preg_replace( $regexp, '', $data );
    }
    return $data;
}


/**
 * Helper function that returns the necessary javascript code to manage the
 * Varnish server list.
 */
function wpvm_add_table_rows_via_js_for_varnish_servers( $options ) {
    $script_arr = array();
    $script_arr[] = '<script>';
    for ( $i = 0; $i < count( $options['varnish_addresses'] ); $i++ ) {
        if ( isset( $options['varnish_addresses'][$i] ) && ! empty( $options['varnish_addresses'][$i] ) ) {
            $address = $options['varnish_addresses'][$i];
        } else {
            $address = '';
        }
        if ( isset( $options['varnish_ports'][$i] ) && ! empty( $options['varnish_ports'][$i] ) ) {
            $port = $options['varnish_ports'][$i];
        } else {
            $port = 0;
        }
        if ( isset( $options['varnish_secrets'][$i] ) && ! empty( $options['varnish_secrets'][$i] ) ) {
            $secret = $options['varnish_secrets'][$i];
        } else {
            $secret = '';
        }
        $script_arr[] = "addRowVarnishServer('varnish-table', $i, '$address', $port, '$secret');";
    }
    $script_arr[] = '</script>';
    return implode("\n", $script_arr );
}


/**
 *  WPVM Logger
 */
function wpvm_log( $data ) {
    $options = get_option('wpvm_opts');
    if ( ! empty($options['logfile_path']) && $options['enable_logging'] == 1 ) {
        $logfile = fopen( $options['logfile_path'], 'a' );
        fwrite( $logfile, sprintf("%s -- %s\n", date('c'), $data) );
        fclose( $logfile );
    }
}


/**
 *  Returns the regexp pattern to append to URLs according to the
 *  ``extended_archive_purge`` option.
 */
function wpvm_get_archive_pattern() {
    // Policy for archive purging
    // --------------------------
    // By default, only the first page and the feeds of the archives are
    // purged. If ``wpv_update_pagenavi_optname`` is checked, then all the
    // pages of each archive are purged as well.

    $options = get_option('wpvm_opts');

    // Pattern for archive feeds
    $archive_feed_pattern = '(?:feed/(?:(atom|rdf)/)?)?$';
    // Pattern for archive page
    $archive_page_pattern = '(?:page/[\d]+/)?$';
    // Determine full pattern
    if ( $options['extended_archive_purge'] == 1 ) {
        // Purge all pages of the archive and its feed.
        $archive_pattern = sprintf( '(%s|%s|$)', $archive_feed_pattern, $archive_page_pattern );
    } else {
        // Only first page of the archive and its feed are purged.
        $archive_pattern = $archive_feed_pattern;
    }
    return $archive_pattern;
}


/**
 *  Returns a hash that can be used to authenticate to varnish control port.
 */
function wpvm_varnish_authentication_hash( $challenge, $secret ) {
    $ctx = hash_init('sha256');
    hash_update($ctx, $challenge);
    hash_update($ctx, "\n");
    hash_update($ctx, $secret . "\n");
    hash_update($ctx, $challenge);
    hash_update($ctx, "\n");
    $sha256 = hash_final($ctx);

    return $sha256;
}


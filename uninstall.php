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

// Uninstallation script: Removed options from the database.
// This code is executed if the plugin is uninstalled (deleted) through the
// WordPress Plugin Management interface.

// Prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) {
    header( 'HTTP/1.0 403 Forbidden' );
    echo 'This file should not be accessed directly!';
    exit; // Exit if accessed directly
}

delete_option('wpvm_opts');

// Also delete any remnants from the code of the previous developers.
delete_option('wpvm_addr');
delete_option('wpvm_port');
delete_option('wpvm_secret');
delete_option('wpvm_timeout');
delete_option('wpvm_update_pagenavi');
delete_option('wpvm_update_commentnavi');
delete_option('wpvm_use_adminport');
delete_option('wpvm_vversion');
delete_option('wpvm_url_group');

?>
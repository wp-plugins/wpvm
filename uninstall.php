<?php

// Uninstallation script: Removed options from the database.
// This code is executed if the plugin is uninstalled (deleted) through the
// WordPress Plugin Management interface.

if ( !defined( 'ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') ) {
    exit();
 }

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
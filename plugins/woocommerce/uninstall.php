<?php
/**
 * Removes everything Rega stored in this site when the plugin is deleted.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'rega_access_token' );
delete_option( 'rega_settings' );

global $wpdb;

// Failed-attempt counters and one-time token hand-offs are short-lived transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_rega\\_%' OR option_name LIKE '\\_transient\\_timeout\\_rega\\_%'" ); // phpcs:ignore WordPress.DB

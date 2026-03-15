<?php
/**
 * Uninstall handler — cleans up Pro plugin data on deletion.
 *
 * @package BeltoftSmartDiscountsPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bsdisc_pro_options = get_option( 'bsdisc_pro_options', array() );

if ( ! is_array( $bsdisc_pro_options )
	|| empty( $bsdisc_pro_options['cleanup_on_uninstall'] )
	|| '1' !== $bsdisc_pro_options['cleanup_on_uninstall']
) {
	return;
}

/* Drop tables */
global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bsdisc_pro_rule_conditions" );

/* Delete options */
delete_option( 'bsdisc_pro_options' );
delete_option( 'bsdisc_pro_licenses' );
delete_option( 'bsdisc_pro_db_version' );

/* Remove crons */
wp_clear_scheduled_hook( 'bsdisc_pro_license_check' );
wp_clear_scheduled_hook( 'bsdisc_pro_weekly_report' );

<?php
/**
 * Database tables and option seeding for Pro.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Support;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscountsPro\Licensing\License;

class Installer {

	/**
	 * Run on activation (and upgrade).
	 */
	public static function activate() {
		self::create_tables();
		self::seed_options();
		self::schedule_cron();

		/* Re-activate license on the remote server if a key exists. */
		$key = Options::get( 'license_key' );
		if ( ! empty( $key ) ) {
			License::activate( $key );
		}
	}

	/**
	 * Run on deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'bsdisc_pro_license_check' );
		wp_clear_scheduled_hook( 'bsdisc_pro_weekly_report' );

		/* Free remote activation slot. */
		License::remote_deactivate();
	}

	/**
	 * Run DB upgrades when version changes.
	 */
	public static function maybe_upgrade() {
		$current = get_option( 'bsdisc_pro_db_version', '0.0.0' );
		if ( version_compare( $current, BSDISC_PRO_DB_VERSION, '>=' ) ) {
			return;
		}
		self::activate();
	}

	/**
	 * Create Pro plugin tables via dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . 'bsdisc_pro_rule_conditions';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_id bigint(20) unsigned NOT NULL,
			condition_type varchar(50) NOT NULL DEFAULT '',
			condition_value longtext DEFAULT NULL,
			operator varchar(20) NOT NULL DEFAULT 'equals',
			PRIMARY KEY  (id),
			KEY rule_id (rule_id),
			KEY condition_type (condition_type),
			KEY rule_condition_type (rule_id, condition_type)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'bsdisc_pro_db_version', BSDISC_PRO_DB_VERSION );
	}

	/**
	 * Seed default options if not set.
	 */
	private static function seed_options() {
		if ( false === get_option( Options::OPTION ) ) {
			add_option( Options::OPTION, Options::defaults() );
		}
	}

	/**
	 * Schedule cron events.
	 */
	private static function schedule_cron() {
		if ( ! wp_next_scheduled( 'bsdisc_pro_license_check' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'bsdisc_pro_license_check' );
		}

		$schedules         = wp_get_schedules();
		$weekly_recurrence = isset( $schedules['weekly'] ) ? 'weekly' : 'daily';
		$first_run         = 'weekly' === $weekly_recurrence ? time() + WEEK_IN_SECONDS : time() + DAY_IN_SECONDS;
		if ( ! wp_next_scheduled( 'bsdisc_pro_weekly_report' ) ) {
			wp_schedule_event( $first_run, $weekly_recurrence, 'bsdisc_pro_weekly_report' );
		}
	}
}

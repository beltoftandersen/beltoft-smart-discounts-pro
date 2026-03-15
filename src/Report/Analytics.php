<?php
/**
 * SQL queries for discount rule analytics.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Report;

defined( 'ABSPATH' ) || exit;

class Analytics {

	public static function get_rule_stats( $rule_id, $days = 30 ) {
		global $wpdb;
		$start = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS times_applied, COALESCE(SUM(discount_amount),0) AS total_savings, COALESCE(AVG(discount_amount),0) AS avg_discount FROM {$wpdb->prefix}bsdisc_usage_log WHERE rule_id = %d AND created_at >= %s",
				$rule_id, $start
			),
			ARRAY_A
		);
		return array(
			'times_applied' => (int) ( isset( $row['times_applied'] ) ? $row['times_applied'] : 0 ),
			'total_savings' => (float) ( isset( $row['total_savings'] ) ? $row['total_savings'] : 0 ),
			'avg_discount'  => (float) ( isset( $row['avg_discount'] ) ? $row['avg_discount'] : 0 ),
		);
	}

	public static function get_all_stats( $days = 30 ) {
		global $wpdb;
		$start = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.title, r.type, COUNT(u.id) AS times_applied, COALESCE(SUM(u.discount_amount),0) AS total_savings, COALESCE(AVG(u.discount_amount),0) AS avg_discount FROM {$wpdb->prefix}bsdisc_rules r INNER JOIN {$wpdb->prefix}bsdisc_usage_log u ON u.rule_id = r.id WHERE u.created_at >= %s GROUP BY r.id, r.title, r.type ORDER BY total_savings DESC",
				$start
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map(
			function ( $row ) {
				return array(
					'id'             => (int) $row['id'],
					'title'          => $row['title'],
					'type'           => $row['type'],
					'times_applied'  => (int) $row['times_applied'],
					'total_savings'  => (float) $row['total_savings'],
					'avg_discount'   => (float) $row['avg_discount'],
				);
			},
			$rows
		);
	}

	public static function get_summary( $days = 30 ) {
		global $wpdb;
		$start = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total_applications, COALESCE(SUM(discount_amount),0) AS total_savings, COALESCE(AVG(discount_amount),0) AS avg_discount FROM {$wpdb->prefix}bsdisc_usage_log WHERE created_at >= %s",
				$start
			),
			ARRAY_A
		);
		return array(
			'total_applications' => (int) ( isset( $row['total_applications'] ) ? $row['total_applications'] : 0 ),
			'total_savings'      => (float) ( isset( $row['total_savings'] ) ? $row['total_savings'] : 0 ),
			'avg_discount'       => (float) ( isset( $row['avg_discount'] ) ? $row['avg_discount'] : 0 ),
		);
	}
}

<?php
/**
 * CSV exporter for discount rules.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\ImportExport;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscountsPro\Licensing\License;

class Exporter {

	public static function init() {
		add_action( 'wp_ajax_bsdisc_pro_export_rules', array( __CLASS__, 'handle_export' ) );
	}

	public static function handle_export() {
		check_ajax_referer( 'bsdisc_pro_export', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'beltoft-smart-discounts-pro' ) );
		}
		if ( ! License::is_active() ) {
			wp_die( esc_html__( 'An active Pro license is required.', 'beltoft-smart-discounts-pro' ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rules = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}bsdisc_rules ORDER BY id ASC",
			ARRAY_A
		);
		if ( ! is_array( $rules ) ) {
			$rules = array();
		}

		$filename = 'smart-discounts-rules-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
			$output,
			array(
				'id', 'title', 'type', 'discount_value', 'applies_to', 'product_ids',
				'category_ids', 'exclude_product_ids', 'bulk_tiers', 'cart_tiers',
				'min_quantity', 'max_quantity', 'priority', 'stacking', 'usage_limit',
				'usage_limit_per_user', 'usage_count', 'schedule_start', 'schedule_end',
				'status', 'created_at', 'updated_at',
			)
		);

		foreach ( $rules as $rule ) {
			$row = array(
				$rule['id'], $rule['title'], $rule['type'], $rule['discount_value'],
				$rule['applies_to'], $rule['product_ids'], $rule['category_ids'],
				$rule['exclude_product_ids'], $rule['bulk_tiers'], $rule['cart_tiers'],
				$rule['min_quantity'], $rule['max_quantity'], $rule['priority'],
				$rule['stacking'], $rule['usage_limit'], $rule['usage_limit_per_user'],
				$rule['usage_count'], $rule['schedule_start'], $rule['schedule_end'],
				$rule['status'], $rule['created_at'], $rule['updated_at'],
			);
			fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
				$output,
				array_map( array( __CLASS__, 'sanitize_csv_cell' ), $row )
			);
		}
		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private static function sanitize_csv_cell( $value ) {
		$cell = (string) $value;
		if ( '' === $cell ) {
			return $cell;
		}
		$first = substr( $cell, 0, 1 );
		if ( in_array( $first, array( '=', '+', '-', '@' ), true ) ) {
			return "'" . $cell;
		}
		return $cell;
	}
}

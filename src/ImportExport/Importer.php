<?php
/**
 * CSV importer for discount rules.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\ImportExport;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Rule\Repository;
use BeltoftSmartDiscounts\Discount\Engine;
use BeltoftSmartDiscountsPro\Licensing\License;

class Importer {

	public static function init() {
		add_action( 'wp_ajax_bsdisc_pro_import_rules', array( __CLASS__, 'handle_import' ) );
	}

	public static function handle_import() {
		check_ajax_referer( 'bsdisc_pro_import', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beltoft-smart-discounts-pro' ) ) );
		}
		if ( ! License::is_active() ) {
			wp_send_json_error( array( 'message' => __( 'An active Pro license is required.', 'beltoft-smart-discounts-pro' ) ) );
		}
		if ( empty( $_FILES['import_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'beltoft-smart-discounts-pro' ) ) );
		}

		$file = $_FILES['import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$tmp_path = isset( $file['tmp_name'] ) ? wp_unslash( $file['tmp_name'] ) : '';
		if ( empty( $tmp_path ) || ! is_uploaded_file( $tmp_path ) || ! file_exists( $tmp_path ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload failed.', 'beltoft-smart-discounts-pro' ) ) );
		}

		$filename = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		$filetype = wp_check_filetype_and_ext( $tmp_path, $filename );
		if ( 'csv' !== $filetype['ext'] ) {
			wp_send_json_error( array( 'message' => __( 'Only CSV files are allowed.', 'beltoft-smart-discounts-pro' ) ) );
		}

		$handle = fopen( $tmp_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			wp_send_json_error( array( 'message' => __( 'Could not read file.', 'beltoft-smart-discounts-pro' ) ) );
		}

		$header = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		if ( ! $header || ! in_array( 'title', $header, true ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			wp_send_json_error( array( 'message' => __( 'Invalid CSV format. Missing "title" column.', 'beltoft-smart-discounts-pro' ) ) );
		}

		$imported = 0;
		$errors   = 0;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( count( $row ) < count( $header ) ) {
				++$errors;
				continue;
			}
			$data = array_combine( $header, $row );
			if ( ! $data ) {
				++$errors;
				continue;
			}
			$rule_data = self::sanitize_row( $data );
			if ( empty( $rule_data['title'] ) ) {
				++$errors;
				continue;
			}
			$result = Repository::insert( $rule_data );
			if ( $result ) {
				++$imported;
			} else {
				++$errors;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		Engine::flush_cache();

		wp_send_json_success(
			array(
				'message' => sprintf(
					__( 'Import complete. %1$d rule(s) imported, %2$d error(s).', 'beltoft-smart-discounts-pro' ),
					$imported,
					$errors
				),
			)
		);
	}

	private static function sanitize_row( $data ) {
		$clean = array();
		$clean['title']          = isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '';
		$clean['type']           = isset( $data['type'] ) ? sanitize_text_field( $data['type'] ) : 'percentage';
		$clean['discount_value'] = isset( $data['discount_value'] ) ? (float) $data['discount_value'] : 0;
		$clean['applies_to']     = isset( $data['applies_to'] ) ? sanitize_text_field( $data['applies_to'] ) : 'all_products';
		$clean['priority']       = isset( $data['priority'] ) ? absint( $data['priority'] ) : 10;
		$clean['stacking']       = isset( $data['stacking'] ) ? sanitize_text_field( $data['stacking'] ) : 'stackable';
		$clean['status']         = isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'active';
		$clean['usage_limit']          = isset( $data['usage_limit'] ) ? absint( $data['usage_limit'] ) : 0;
		$clean['usage_limit_per_user'] = isset( $data['usage_limit_per_user'] ) ? absint( $data['usage_limit_per_user'] ) : 0;
		$clean['min_quantity']         = isset( $data['min_quantity'] ) ? absint( $data['min_quantity'] ) : 0;
		$clean['max_quantity']         = isset( $data['max_quantity'] ) ? absint( $data['max_quantity'] ) : 0;
		$json_cols = array( 'product_ids', 'category_ids', 'exclude_product_ids', 'bulk_tiers', 'cart_tiers' );
		foreach ( $json_cols as $col ) {
			if ( isset( $data[ $col ] ) && ! empty( $data[ $col ] ) ) {
				$decoded = json_decode( $data[ $col ], true );
				$clean[ $col ] = is_array( $decoded ) ? $decoded : array();
			} else {
				$clean[ $col ] = array();
			}
		}
		$clean['schedule_start'] = ! empty( $data['schedule_start'] ) ? sanitize_text_field( $data['schedule_start'] ) : null;
		$clean['schedule_end']   = ! empty( $data['schedule_end'] ) ? sanitize_text_field( $data['schedule_end'] ) : null;
		return $clean;
	}
}

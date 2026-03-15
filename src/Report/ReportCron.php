<?php
/**
 * Weekly email report with discount performance summary.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Report;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscountsPro\Support\Options;

class ReportCron {

	public static function init() {
		add_action( 'bsdisc_pro_weekly_report', array( __CLASS__, 'send_report' ) );
	}

	public static function send_report() {
		if ( '1' !== Options::get( 'report_email_enabled' ) ) {
			return;
		}
		$recipients = Options::get( 'report_email_address' );
		if ( empty( $recipients ) ) {
			$recipients = get_option( 'admin_email' );
		}
		$summary    = Analytics::get_summary( 7 );
		$rule_stats = Analytics::get_all_stats( 7 );

		$subject = sprintf(
			__( '[%s] Weekly Discount Performance Report', 'beltoft-smart-discounts-pro' ),
			get_bloginfo( 'name' )
		);

		$body  = '<html><body>';
		$body .= '<h2>' . esc_html__( 'Weekly Discount Performance Report', 'beltoft-smart-discounts-pro' ) . '</h2>';
		$body .= '<p>' . esc_html(
			sprintf(
				__( 'Report period: Last 7 days (%s)', 'beltoft-smart-discounts-pro' ),
				wp_date( 'M j', time() - ( 7 * DAY_IN_SECONDS ) ) . ' - ' . wp_date( 'M j, Y' )
			)
		) . '</p>';

		$body .= '<table style="border-collapse:collapse;width:100%;max-width:600px;">';
		$body .= '<tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">' . esc_html__( 'Total Discounts Applied', 'beltoft-smart-discounts-pro' ) . '</td>';
		$body .= '<td style="padding:8px;border:1px solid #ddd;">' . esc_html( number_format_i18n( $summary['total_applications'] ) ) . '</td></tr>';
		$body .= '<tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">' . esc_html__( 'Total Savings', 'beltoft-smart-discounts-pro' ) . '</td>';
		$body .= '<td style="padding:8px;border:1px solid #ddd;">' . wp_strip_all_tags( wc_price( $summary['total_savings'] ) ) . '</td></tr>';
		$body .= '<tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold;">' . esc_html__( 'Average Discount', 'beltoft-smart-discounts-pro' ) . '</td>';
		$body .= '<td style="padding:8px;border:1px solid #ddd;">' . wp_strip_all_tags( wc_price( $summary['avg_discount'] ) ) . '</td></tr>';
		$body .= '</table>';

		if ( ! empty( $rule_stats ) ) {
			$body .= '<h3>' . esc_html__( 'Top Rules', 'beltoft-smart-discounts-pro' ) . '</h3>';
			$body .= '<table style="border-collapse:collapse;width:100%;max-width:600px;">';
			$body .= '<tr style="background:#f7f7f7;">';
			$body .= '<th style="padding:8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Rule', 'beltoft-smart-discounts-pro' ) . '</th>';
			$body .= '<th style="padding:8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Applied', 'beltoft-smart-discounts-pro' ) . '</th>';
			$body .= '<th style="padding:8px;border:1px solid #ddd;text-align:left;">' . esc_html__( 'Total Savings', 'beltoft-smart-discounts-pro' ) . '</th>';
			$body .= '</tr>';
			foreach ( $rule_stats as $stat ) {
				$body .= '<tr>';
				$body .= '<td style="padding:8px;border:1px solid #ddd;">' . esc_html( $stat['title'] ) . '</td>';
				$body .= '<td style="padding:8px;border:1px solid #ddd;">' . esc_html( number_format_i18n( $stat['times_applied'] ) ) . '</td>';
				$body .= '<td style="padding:8px;border:1px solid #ddd;">' . wp_strip_all_tags( wc_price( $stat['total_savings'] ) ) . '</td>';
				$body .= '</tr>';
			}
			$body .= '</table>';
		}
		$body .= '</body></html>';

		$csv_path    = self::generate_csv( $rule_stats );
		$headers     = array( 'Content-Type: text/html; charset=UTF-8' );
		$attachments = array();
		if ( $csv_path && file_exists( $csv_path ) ) {
			$attachments[] = $csv_path;
		}
		wp_mail( $recipients, $subject, $body, $headers, $attachments );
		if ( $csv_path && file_exists( $csv_path ) ) {
			wp_delete_file( $csv_path );
		}
	}

	private static function generate_csv( $rule_stats ) {
		if ( empty( $rule_stats ) ) {
			return false;
		}
		$upload_dir = wp_upload_dir();
		$csv_path   = $upload_dir['basedir'] . '/bsdisc-pro-report-' . wp_generate_password( 8, false ) . '.csv';
		$handle = fopen( $csv_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}
		fputcsv( $handle, array( 'Rule', 'Type', 'Times Applied', 'Total Savings', 'Avg Discount' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		foreach ( $rule_stats as $stat ) {
			fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
				$handle,
				array(
					self::sanitize_csv_cell( $stat['title'] ),
					self::sanitize_csv_cell( $stat['type'] ),
					self::sanitize_csv_cell( $stat['times_applied'] ),
					self::sanitize_csv_cell( $stat['total_savings'] ),
					self::sanitize_csv_cell( $stat['avg_discount'] ),
				)
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv_path;
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

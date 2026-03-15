<?php
/**
 * Analytics tab with per-rule performance metrics.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Admin;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscountsPro\Report\Analytics;

class AnalyticsTab {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'bsdisc_admin_tab_analytics', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the analytics tab.
	 */
	public static function render() {
		$period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : '30'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$valid_periods = array( '7', '30', '90' );
		if ( ! in_array( $period, $valid_periods, true ) ) {
			$period = '30';
		}

		$summary    = Analytics::get_summary( (int) $period );
		$rule_stats = Analytics::get_all_stats( (int) $period );

		?>
		<h2><?php esc_html_e( 'Discount Analytics', 'beltoft-smart-discounts-pro' ); ?></h2>

		<div class="bsdisc-pro-period-filter">
			<?php
			$base_url = add_query_arg(
				array(
					'page' => 'bsdisc-discounts',
					'tab'  => 'analytics',
				),
				admin_url( 'admin.php' )
			);
			foreach ( $valid_periods as $p ) :
				$url   = add_query_arg( 'period', $p, $base_url );
				$class = $p === $period ? 'button button-primary' : 'button';
				/* translators: %s: number of days */
				$label = sprintf( __( '%s Days', 'beltoft-smart-discounts-pro' ), $p );
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</div>

		<div class="bsdisc-dashboard-cards" style="margin-top:16px;">
			<div class="bsdisc-card">
				<h3><?php esc_html_e( 'Total Discounts Applied', 'beltoft-smart-discounts-pro' ); ?></h3>
				<div class="bsdisc-card-value"><?php echo esc_html( number_format_i18n( $summary['total_applications'] ) ); ?></div>
			</div>
			<div class="bsdisc-card">
				<h3><?php esc_html_e( 'Total Savings', 'beltoft-smart-discounts-pro' ); ?></h3>
				<div class="bsdisc-card-value"><?php echo wp_kses_post( wc_price( $summary['total_savings'] ) ); ?></div>
			</div>
			<div class="bsdisc-card">
				<h3><?php esc_html_e( 'Average Discount', 'beltoft-smart-discounts-pro' ); ?></h3>
				<div class="bsdisc-card-value"><?php echo wp_kses_post( wc_price( $summary['avg_discount'] ) ); ?></div>
			</div>
		</div>

		<?php if ( ! empty( $rule_stats ) ) : ?>
		<h3><?php esc_html_e( 'Per-Rule Performance', 'beltoft-smart-discounts-pro' ); ?></h3>
		<table class="bsdisc-top-rules-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Rule', 'beltoft-smart-discounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Type', 'beltoft-smart-discounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Times Applied', 'beltoft-smart-discounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Total Savings', 'beltoft-smart-discounts-pro' ); ?></th>
					<th><?php esc_html_e( 'Avg Discount', 'beltoft-smart-discounts-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rule_stats as $stat ) : ?>
				<tr>
					<td><?php echo esc_html( $stat['title'] ); ?></td>
					<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $stat['type'] ) ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $stat['times_applied'] ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $stat['total_savings'] ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $stat['avg_discount'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p><?php esc_html_e( 'No discount usage data for this period.', 'beltoft-smart-discounts-pro' ); ?></p>
		<?php endif; ?>

		<?php
	}
}

<?php
/**
 * Countdown timer for expiring discount rules.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Frontend;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Discount\Engine;
use BeltoftSmartDiscountsPro\Support\Options;

class CountdownTimer {

	public static function init() {
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_product_countdown' ), 15 );
		add_action( 'woocommerce_before_cart_table', array( __CLASS__, 'render_cart_countdown' ) );
	}

	public static function render_product_countdown() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$expiring = self::get_expiring_rules();
		if ( empty( $expiring ) ) {
			return;
		}
		$product_id   = $product->get_id();
		$category_ids = wc_get_product_cat_ids( $product->get_parent_id() ? $product->get_parent_id() : $product_id );
		foreach ( $expiring as $rule ) {
			if ( ! \BeltoftSmartDiscounts\Rule\Validator::applies_to_product( $rule, $product_id, $category_ids ) ) {
				continue;
			}
			self::render_timer( $rule );
			return;
		}
	}

	public static function render_cart_countdown() {
		$expiring = self::get_expiring_rules();
		if ( empty( $expiring ) ) {
			return;
		}
		self::render_timer( $expiring[0] );
	}

	private static function get_expiring_rules() {
		$threshold_hours = (int) Options::get( 'countdown_threshold', 48 );
		$now             = current_datetime()->getTimestamp();
		$threshold_time  = $now + ( $threshold_hours * HOUR_IN_SECONDS );
		$rules    = Engine::get_active_rules();
		$expiring = array();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['schedule_end'] ) ) {
				continue;
			}
			$end_time = self::parse_rule_datetime( $rule['schedule_end'] );
			if ( $end_time > $now && $end_time <= $threshold_time ) {
				$rule['_bsdisc_end_ts'] = $end_time;
				$expiring[] = $rule;
			}
		}
		usort(
			$expiring,
			function ( $a, $b ) {
				return (int) ( $a['_bsdisc_end_ts'] ?? 0 ) - (int) ( $b['_bsdisc_end_ts'] ?? 0 );
			}
		);
		return $expiring;
	}

	private static function render_timer( $rule ) {
		$end_time  = isset( $rule['_bsdisc_end_ts'] ) ? (int) $rule['_bsdisc_end_ts'] : self::parse_rule_datetime( $rule['schedule_end'] );
		$remaining = $end_time - current_datetime()->getTimestamp();
		if ( $remaining <= 0 ) {
			return;
		}
		?>
		<div class="bsdisc-pro-countdown" data-end-ts="<?php echo esc_attr( (string) $end_time ); ?>">
			<div class="bsdisc-pro-countdown__header">
				<?php
				printf(
					esc_html__( '%s ends in:', 'beltoft-smart-discounts-pro' ),
					esc_html( $rule['title'] )
				);
				?>
			</div>
			<div class="bsdisc-pro-countdown__timer">
				<span class="bsdisc-pro-countdown__block">
					<span class="bsdisc-pro-countdown__number bsdisc-pro-countdown__days">0</span>
					<span class="bsdisc-pro-countdown__label"><?php esc_html_e( 'Days', 'beltoft-smart-discounts-pro' ); ?></span>
				</span>
				<span class="bsdisc-pro-countdown__separator">:</span>
				<span class="bsdisc-pro-countdown__block">
					<span class="bsdisc-pro-countdown__number bsdisc-pro-countdown__hours">0</span>
					<span class="bsdisc-pro-countdown__label"><?php esc_html_e( 'Hours', 'beltoft-smart-discounts-pro' ); ?></span>
				</span>
				<span class="bsdisc-pro-countdown__separator">:</span>
				<span class="bsdisc-pro-countdown__block">
					<span class="bsdisc-pro-countdown__number bsdisc-pro-countdown__minutes">0</span>
					<span class="bsdisc-pro-countdown__label"><?php esc_html_e( 'Min', 'beltoft-smart-discounts-pro' ); ?></span>
				</span>
				<span class="bsdisc-pro-countdown__separator">:</span>
				<span class="bsdisc-pro-countdown__block">
					<span class="bsdisc-pro-countdown__number bsdisc-pro-countdown__seconds">0</span>
					<span class="bsdisc-pro-countdown__label"><?php esc_html_e( 'Sec', 'beltoft-smart-discounts-pro' ); ?></span>
				</span>
			</div>
			<div class="bsdisc-pro-countdown__progress-wrapper">
				<div class="bsdisc-pro-countdown__progress" style="width:100%"></div>
			</div>
		</div>
		<?php
	}

	private static function parse_rule_datetime( $value ) {
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return 0;
		}
		$timezone   = wp_timezone();
		$normalized = str_replace( 'T', ' ', $raw );
		$dt         = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $normalized, $timezone );
		if ( false === $dt ) {
			$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $normalized, $timezone );
		}
		if ( false === $dt ) {
			$dt = date_create_immutable( $raw, $timezone );
		}
		if ( false === $dt ) {
			return 0;
		}
		return $dt->getTimestamp();
	}
}

<?php
/**
 * Spending goal progress bar for cart and checkout.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Frontend;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Discount\Engine;
use BeltoftSmartDiscounts\Rule\Validator;
use BeltoftSmartDiscountsPro\Discount\ConditionEvaluator;
use BeltoftSmartDiscountsPro\Support\Options;

class SpendingGoal {

	private static $has_rendered = false;
	private static $next_goal_cache = array();

	public static function init() {
		add_shortcode( 'bsdisc_spending_goal', array( __CLASS__, 'render_shortcode' ) );

		$mode = Options::get( 'spending_goal_display_mode', 'auto' );
		if ( ! in_array( $mode, array( 'auto', 'both' ), true ) ) {
			return;
		}
		$show_cart = '1' === Options::get( 'spending_goal_show_on_cart', Options::get( 'spending_goal_enabled', '0' ) );
		$show_checkout = '1' === Options::get( 'spending_goal_show_on_checkout', Options::get( 'spending_goal_enabled', '0' ) );
		if ( ! $show_cart && ! $show_checkout ) {
			return;
		}
		if ( $show_cart ) {
			add_action( 'woocommerce_before_cart_totals', array( __CLASS__, 'render' ) );
		}
		if ( $show_checkout ) {
			add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'render' ) );
		}
	}

	public static function render() {
		if ( self::is_block_cart_checkout_context() ) {
			return;
		}
		$layout = 'inline';
		if ( 'woocommerce_review_order_before_order_total' === current_action() ) {
			$layout = 'row';
		}
		$markup = self::get_markup_once( $layout );
		if ( '' === $markup ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $markup;
	}

	public static function render_shortcode( $atts ) {
		$mode = Options::get( 'spending_goal_display_mode', 'auto' );
		if ( ! in_array( $mode, array( 'shortcode', 'both' ), true ) ) {
			return '';
		}
		return self::get_markup_once( 'inline' );
	}

	public static function get_markup( $layout = 'inline' ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return '';
		}
		$layout = sanitize_key( (string) $layout );
		if ( ! in_array( $layout, array( 'inline', 'row' ), true ) ) {
			$layout = 'inline';
		}
		$subtotal = (float) WC()->cart->get_subtotal();
		$goal     = self::find_next_goal( $subtotal );
		if ( ! $goal || empty( $goal['threshold'] ) ) {
			return '';
		}
		$remaining  = max( 0, $goal['threshold'] - $subtotal );
		$percentage = min( 100, ( $subtotal / $goal['threshold'] ) * 100 );
		if ( $remaining <= 0 ) {
			return '';
		}
		$card_html = self::build_goal_card_html( $subtotal, $remaining, $goal, $percentage );
		if ( 'row' === $layout ) {
			return sprintf(
				'<tr class="bsdisc-pro-spending-goal-row"><th>%1$s</th><td>%2$s</td></tr>',
				esc_html__( 'Spending Goal', 'beltoft-smart-discounts-pro' ),
				$card_html
			);
		}
		return $card_html;
	}

	private static function get_markup_once( $layout ) {
		if ( self::$has_rendered ) {
			return '';
		}
		$markup = self::get_markup( $layout );
		if ( '' !== $markup ) {
			self::$has_rendered = true;
		}
		return $markup;
	}

	private static function build_goal_card_html( $subtotal, $remaining, $goal, $percentage ) {
		ob_start();
		?>
		<div class="bsdisc-pro-spending-goal">
			<p class="bsdisc-pro-spending-goal__text">
				<?php
				printf(
					esc_html__( 'Add %1$s more to get %2$s off!', 'beltoft-smart-discounts-pro' ),
					wp_kses_post( wc_price( $remaining ) ),
					esc_html( (string) $goal['discount_label'] )
				);
				?>
			</p>
			<div class="bsdisc-pro-spending-goal__bar-wrapper">
				<div class="bsdisc-pro-spending-goal__bar" style="width:<?php echo esc_attr( round( $percentage, 1 ) ); ?>%"></div>
			</div>
			<p class="bsdisc-pro-spending-goal__meta">
				<?php
				printf(
					esc_html__( '%1$s / %2$s', 'beltoft-smart-discounts-pro' ),
					wp_kses_post( wc_price( $subtotal ) ),
					wp_kses_post( wc_price( (float) $goal['threshold'] ) )
				);
				?>
			</p>
		</div>
		<?php
		return trim( (string) ob_get_clean() );
	}

	private static function find_next_goal( $subtotal ) {
		$cache_key = number_format( (float) $subtotal, 4, '.', '' );
		if ( array_key_exists( $cache_key, self::$next_goal_cache ) ) {
			return self::$next_goal_cache[ $cache_key ];
		}
		$rules       = Engine::get_active_rules();
		$best        = null;
		$customer_id = get_current_user_id();
		$email       = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$email = WC()->customer->get_billing_email();
		}
		foreach ( $rules as $rule ) {
			if ( 'cart_total' !== $rule['type'] ) {
				continue;
			}
			if ( ! Validator::is_within_usage_limit( $rule ) ) {
				continue;
			}
			if ( ! Validator::is_within_per_user_limit( $rule, $customer_id, $email ) ) {
				continue;
			}
			if ( ! ConditionEvaluator::evaluate( $rule ) ) {
				continue;
			}
			if ( empty( $rule['cart_tiers'] ) || ! is_array( $rule['cart_tiers'] ) ) {
				continue;
			}
			foreach ( $rule['cart_tiers'] as $tier ) {
				$min   = (float) ( isset( $tier['min'] ) ? $tier['min'] : 0 );
				$value = (float) ( isset( $tier['value'] ) ? $tier['value'] : 0 );
				if ( $min <= $subtotal ) {
					continue;
				}
				if ( null === $best || $min < $best['threshold'] ) {
					$best = array(
						'threshold'      => $min,
						'discount_label' => wp_strip_all_tags( wc_price( $value ) ),
					);
				}
			}
		}
		self::$next_goal_cache[ $cache_key ] = $best;
		return $best;
	}

	private static function is_block_cart_checkout_context() {
		$utils_class = '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils';
		if ( class_exists( $utils_class ) && method_exists( $utils_class, 'is_cart_checkout_block_page' ) ) {
			return (bool) call_user_func( array( $utils_class, 'is_cart_checkout_block_page' ) );
		}
		if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			$cart_page_id = (int) wc_get_page_id( 'cart' );
			if ( $cart_page_id > 0 && has_block( 'woocommerce/cart', get_post( $cart_page_id ) ) ) {
				return true;
			}
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			$checkout_page_id = (int) wc_get_page_id( 'checkout' );
			if ( $checkout_page_id > 0 && has_block( 'woocommerce/checkout', get_post( $checkout_page_id ) ) ) {
				return true;
			}
		}
		return false;
	}
}

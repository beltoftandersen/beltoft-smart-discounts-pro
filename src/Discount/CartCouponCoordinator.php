<?php
/**
 * Unified cart-coupon coordination for free + Pro cart-level rule types.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Discount;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Discount\CartDiscount;
use BeltoftSmartDiscounts\Discount\Engine;
use BeltoftSmartDiscounts\Rule\Validator;
use BeltoftSmartDiscountsPro\Frontend\UrlDiscount;

class CartCouponCoordinator {

	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'coordinate' ), 26 );
	}

	public static function coordinate( $cart ) {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) > 1 ) {
			return;
		}

		$customer_id = get_current_user_id();
		$email       = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$email = WC()->customer->get_billing_email();
		}

		$cart_subtotal = (float) $cart->get_subtotal();
		$rules         = Engine::get_active_rules();
		$candidates    = array();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['type'] ) ) {
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

			$type   = (string) $rule['type'];
			$amount = 0.0;
			$code   = '';

			switch ( $type ) {
				case 'cart_total':
					$amount = Engine::calculate_cart_discount( $rule, $cart_subtotal );
					$code   = CartDiscount::COUPON_PREFIX . (int) $rule['id'];
					break;
				case 'bogo':
					$amount = BogoEngine::calculate_bogo_discount( $rule );
					$code   = BogoEngine::COUPON_PREFIX . (int) $rule['id'];
					break;
				case 'bundle':
					$amount = BundleEngine::calculate_bundle_discount( $rule );
					$code   = BundleEngine::COUPON_PREFIX . (int) $rule['id'];
					break;
				case 'first_order':
					if ( ! FirstOrderEngine::is_first_order_customer() ) {
						continue 2;
					}
					$amount = FirstOrderEngine::calculate_discount( $rule );
					$code   = FirstOrderEngine::COUPON_PREFIX . (int) $rule['id'];
					break;
				case 'cart_count':
					$amount = CartCountEngine::calculate_discount( $rule );
					$code   = CartCountEngine::COUPON_PREFIX . (int) $rule['id'];
					break;
				default:
					continue 2;
			}

			if ( $amount <= 0 || '' === $code ) {
				continue;
			}

			$candidates[] = array(
				'rule_id'         => (int) $rule['id'],
				'discount_amount' => (float) $amount,
				'rule'            => $rule,
				'coupon_code'     => $code,
			);
		}

		$selected = Engine::apply_stacking( $candidates );

		$should_apply = array();
		foreach ( $selected as $row ) {
			if ( ! empty( $row['coupon_code'] ) ) {
				$should_apply[ (string) $row['coupon_code'] ] = true;
			}
		}

		$prefixes = self::managed_prefixes();
		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( self::has_managed_prefix( $code, $prefixes ) && ! isset( $should_apply[ $code ] ) ) {
				$cart->remove_coupon( $code );
			}
		}

		foreach ( $should_apply as $code => $true_value ) {
			if ( ! $cart->has_discount( $code ) ) {
				$cart->apply_coupon( $code );
			}
		}
	}

	private static function managed_prefixes() {
		return array(
			CartDiscount::COUPON_PREFIX,
			BogoEngine::COUPON_PREFIX,
			BundleEngine::COUPON_PREFIX,
			CartCountEngine::COUPON_PREFIX,
			FirstOrderEngine::COUPON_PREFIX,
			UrlDiscount::COUPON_PREFIX,
		);
	}

	private static function has_managed_prefix( $code, $prefixes ) {
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $code, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}

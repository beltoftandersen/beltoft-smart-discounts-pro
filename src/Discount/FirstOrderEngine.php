<?php
/**
 * First-order discount engine — automatic discount for new customers.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Discount;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Discount\Engine;
use BeltoftSmartDiscounts\Rule\Repository;
use BeltoftSmartDiscounts\Rule\Validator;

class FirstOrderEngine {

	const COUPON_PREFIX = 'bsdisc-pro-first-order-';

	public static function init() {
		add_filter( 'bsdisc_rule_types', array( __CLASS__, 'register_type' ) );
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'virtual_coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'suppress_coupon_error' ), 10, 3 );
	}

	public static function register_type( $types ) {
		$types['first_order'] = __( 'First Order Discount', 'beltoft-smart-discounts-pro' );
		return $types;
	}

	public static function is_first_order_customer() {
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			$email = '';
			if ( function_exists( 'WC' ) && WC()->customer ) {
				$email = sanitize_email( WC()->customer->get_billing_email() );
			}
			if ( '' === $email ) {
				return true;
			}
			$orders = wc_get_orders(
				array(
					'return'   => 'ids',
					'customer' => $email,
					'status'   => array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-completed' ),
					'limit'    => 1,
				)
			);
			return empty( $orders );
		}
		$order_count = wc_get_customer_order_count( $customer_id );
		return 0 === $order_count;
	}

	private static function is_rule_active_now( $rule ) {
		if ( isset( $rule['status'] ) && 'active' !== $rule['status'] ) {
			return false;
		}
		$now = current_time( 'timestamp' );
		if ( ! empty( $rule['schedule_start'] ) && strtotime( (string) $rule['schedule_start'] ) > $now ) {
			return false;
		}
		if ( ! empty( $rule['schedule_end'] ) && strtotime( (string) $rule['schedule_end'] ) < $now ) {
			return false;
		}
		return true;
	}

	private static function is_rule_eligible( $rule ) {
		if ( ! self::is_rule_active_now( $rule ) ) {
			return false;
		}
		if ( ! self::is_first_order_customer() ) {
			return false;
		}
		$customer_id = get_current_user_id();
		$email       = '';
		if ( WC()->customer ) {
			$email = WC()->customer->get_billing_email();
		}
		if ( ! Validator::is_within_usage_limit( $rule ) ) {
			return false;
		}
		if ( ! Validator::is_within_per_user_limit( $rule, $customer_id, $email ) ) {
			return false;
		}
		return ConditionEvaluator::evaluate( $rule );
	}

	public static function virtual_coupon_data( $data, $coupon_code ) {
		if ( strpos( $coupon_code, self::COUPON_PREFIX ) !== 0 ) {
			return $data;
		}
		$rule_id = (int) str_replace( self::COUPON_PREFIX, '', $coupon_code );
		if ( $rule_id <= 0 ) {
			return $data;
		}
		$rule = Repository::get_rule( $rule_id );
		if ( ! $rule || 'first_order' !== $rule['type'] ) {
			return $data;
		}
		if ( ! self::is_rule_eligible( $rule ) ) {
			return $data;
		}
		$amount = self::calculate_discount( $rule );
		if ( $amount <= 0 ) {
			return $data;
		}
		return array(
			'id' => 0, 'amount' => $amount, 'discount_type' => 'fixed_cart',
			'individual_use' => false, 'usage_limit' => 0, 'usage_count' => 0,
			'date_created' => '', 'date_modified' => '', 'date_expires' => null,
			'product_ids' => array(), 'excluded_product_ids' => array(),
			'product_categories' => array(), 'excluded_product_categories' => array(),
			'minimum_amount' => '', 'maximum_amount' => '', 'email_restrictions' => array(),
			'virtual' => true,
		);
	}

	public static function apply_first_order( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) > 1 ) {
			return;
		}
		if ( ! self::is_first_order_customer() ) {
			foreach ( $cart->get_applied_coupons() as $code ) {
				if ( strpos( $code, self::COUPON_PREFIX ) === 0 ) {
					$cart->remove_coupon( $code );
				}
			}
			return;
		}
		$rules        = Engine::get_active_rules();
		$customer_id  = get_current_user_id();
		$email        = '';
		if ( WC()->customer ) {
			$email = WC()->customer->get_billing_email();
		}
		$should_apply = array();
		foreach ( $rules as $rule ) {
			if ( 'first_order' !== $rule['type'] ) {
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
			$code                  = self::COUPON_PREFIX . $rule['id'];
			$should_apply[ $code ] = true;
		}
		$applied = $cart->get_applied_coupons();
		foreach ( $applied as $code ) {
			if ( strpos( $code, self::COUPON_PREFIX ) === 0 && ! isset( $should_apply[ $code ] ) ) {
				$cart->remove_coupon( $code );
			}
		}
		foreach ( $should_apply as $code => $v ) {
			if ( ! $cart->has_discount( $code ) ) {
				$cart->apply_coupon( $code );
			}
		}
	}

	public static function calculate_discount( $rule ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}
		$cart_subtotal = (float) WC()->cart->get_subtotal();
		$percent       = (float) $rule['discount_value'];
		$percent       = min( $percent, 100 );
		return round( $cart_subtotal * ( $percent / 100 ), wc_get_price_decimals() );
	}

	public static function coupon_label( $label, $coupon ) {
		$code = $coupon->get_code();
		if ( strpos( $code, self::COUPON_PREFIX ) !== 0 ) {
			return $label;
		}
		$rule_id = (int) str_replace( self::COUPON_PREFIX, '', $code );
		$rule    = Repository::get_rule( $rule_id );
		if ( $rule && ! empty( $rule['title'] ) ) {
			return sprintf( esc_html__( 'Discount: %s', 'beltoft-smart-discounts-pro' ), esc_html( $rule['title'] ) );
		}
		return $label;
	}

	public static function suppress_coupon_error( $error, $err_code, $coupon ) {
		if ( is_object( $coupon ) && method_exists( $coupon, 'get_code' ) ) {
			$code = $coupon->get_code();
			if ( strpos( $code, self::COUPON_PREFIX ) === 0 ) {
				return '';
			}
		}
		return $error;
	}
}

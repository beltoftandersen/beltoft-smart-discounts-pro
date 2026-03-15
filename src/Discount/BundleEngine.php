<?php
/**
 * Bundle discount engine — buy product combo for a discount.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Discount;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Discount\Engine;
use BeltoftSmartDiscounts\Rule\Repository;
use BeltoftSmartDiscounts\Rule\Validator;

class BundleEngine {

	const COUPON_PREFIX = 'bsdisc-pro-bundle-';

	public static function init() {
		add_filter( 'bsdisc_rule_types', array( __CLASS__, 'register_type' ) );
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'virtual_coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'suppress_coupon_error' ), 10, 3 );
	}

	public static function register_type( $types ) {
		$types['bundle'] = __( 'Bundle Discount', 'beltoft-smart-discounts-pro' );
		return $types;
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
		if ( ! $rule || 'bundle' !== $rule['type'] ) {
			return $data;
		}

		if ( ! self::is_rule_eligible( $rule ) ) {
			return $data;
		}

		$amount = self::calculate_bundle_discount( $rule );
		if ( $amount <= 0 ) {
			return $data;
		}

		return array(
			'id'                          => 0,
			'amount'                      => $amount,
			'discount_type'               => 'fixed_cart',
			'individual_use'              => false,
			'usage_limit'                 => 0,
			'usage_count'                 => 0,
			'date_created'                => '',
			'date_modified'               => '',
			'date_expires'                => null,
			'product_ids'                 => array(),
			'excluded_product_ids'        => array(),
			'product_categories'          => array(),
			'excluded_product_categories' => array(),
			'minimum_amount'              => '',
			'maximum_amount'              => '',
			'email_restrictions'          => array(),
			'virtual'                     => true,
		);
	}

	public static function apply_bundle( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( did_action( 'woocommerce_before_calculate_totals' ) > 1 ) {
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
			if ( 'bundle' !== $rule['type'] ) {
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
			$discount = self::calculate_bundle_discount( $rule );
			if ( $discount > 0 ) {
				$code                  = self::COUPON_PREFIX . $rule['id'];
				$should_apply[ $code ] = true;
			}
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

	public static function calculate_bundle_discount( $rule ) {
		if ( ! WC()->cart || empty( $rule['product_ids'] ) ) {
			return 0;
		}

		$required_ids = array_map( 'intval', $rule['product_ids'] );
		$cart_counts  = array();
		$bundle_prices = array();
		$bundle_sets  = 0;

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( ! $product ) {
				continue;
			}
			$pid       = $product->get_id();
			$parent_id = $product->get_parent_id() ? $product->get_parent_id() : $pid;

			if ( in_array( $pid, $required_ids, true ) || in_array( $parent_id, $required_ids, true ) ) {
				$match_id = in_array( $pid, $required_ids, true ) ? $pid : $parent_id;
				$qty      = (int) $item['quantity'];
				if ( ! isset( $cart_counts[ $match_id ] ) ) {
					$cart_counts[ $match_id ] = 0;
				}
				$cart_counts[ $match_id ] += $qty;
				$line_price = (float) $product->get_regular_price();
				if ( ! isset( $bundle_prices[ $match_id ] ) ) {
					$bundle_prices[ $match_id ] = $line_price;
				} else {
					$bundle_prices[ $match_id ] = min( $bundle_prices[ $match_id ], $line_price );
				}
			}
		}

		foreach ( $required_ids as $rid ) {
			if ( empty( $cart_counts[ $rid ] ) ) {
				return 0;
			}
			$bundle_sets = 0 === $bundle_sets ? (int) $cart_counts[ $rid ] : min( $bundle_sets, (int) $cart_counts[ $rid ] );
		}

		if ( $bundle_sets <= 0 ) {
			return 0;
		}

		$percent = (float) $rule['discount_value'];
		$percent = min( $percent, 100 );

		$bundle_unit_total = 0;
		foreach ( $required_ids as $rid ) {
			if ( ! isset( $bundle_prices[ $rid ] ) ) {
				return 0;
			}
			$bundle_unit_total += (float) $bundle_prices[ $rid ];
		}

		$eligible_total = $bundle_unit_total * $bundle_sets;
		return round( $eligible_total * ( $percent / 100 ), wc_get_price_decimals() );
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

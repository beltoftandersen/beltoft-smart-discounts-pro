<?php
/**
 * Buy One Get One (BOGO) discount engine.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Discount;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Discount\Engine;
use BeltoftSmartDiscounts\Rule\Repository;
use BeltoftSmartDiscounts\Rule\Validator;

class BogoEngine {

	/**
	 * Coupon code prefix for BOGO rules.
	 */
	const COUPON_PREFIX = 'bsdisc-pro-bogo-';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'bsdisc_rule_types', array( __CLASS__, 'register_type' ) );
		add_filter( 'woocommerce_get_shop_coupon_data', array( __CLASS__, 'virtual_coupon_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 10, 2 );
		add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'suppress_coupon_error' ), 10, 3 );
	}

	/**
	 * Register the BOGO rule type.
	 *
	 * @param array $types Existing types.
	 * @return array
	 */
	public static function register_type( $types ) {
		$types['bogo'] = __( 'Buy One Get One (BOGO)', 'beltoft-smart-discounts-pro' );
		return $types;
	}

	/**
	 * Supply virtual coupon data for BOGO coupon codes.
	 *
	 * @param mixed  $data        Existing coupon data.
	 * @param string $coupon_code Coupon code.
	 * @return mixed
	 */
	public static function virtual_coupon_data( $data, $coupon_code ) {
		if ( strpos( $coupon_code, self::COUPON_PREFIX ) !== 0 ) {
			return $data;
		}

		$rule_id = (int) str_replace( self::COUPON_PREFIX, '', $coupon_code );
		if ( $rule_id <= 0 ) {
			return $data;
		}

		$rule = Repository::get_rule( $rule_id );
		if ( ! $rule || 'bogo' !== $rule['type'] ) {
			return $data;
		}

		if ( ! self::is_rule_eligible( $rule ) ) {
			return $data;
		}

		$amount = self::calculate_bogo_discount( $rule );
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

	/**
	 * Auto-apply BOGO coupons based on cart contents.
	 *
	 * @param \WC_Cart $cart Cart object.
	 */
	public static function apply_bogo( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( did_action( 'woocommerce_before_calculate_totals' ) > 1 ) {
			return;
		}

		$rules       = Engine::get_active_rules();
		$bogo_rules  = array();

		$customer_id = get_current_user_id();
		$email       = '';
		if ( WC()->customer ) {
			$email = WC()->customer->get_billing_email();
		}

		foreach ( $rules as $rule ) {
			if ( 'bogo' !== $rule['type'] ) {
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
			$bogo_rules[] = $rule;
		}

		/* Build map of rule IDs that should be applied */
		$should_apply = array();
		foreach ( $bogo_rules as $rule ) {
			$discount = self::calculate_bogo_discount( $rule );
			if ( $discount > 0 ) {
				$code                  = self::COUPON_PREFIX . $rule['id'];
				$should_apply[ $code ] = true;
			}
		}

		/* Remove stale BOGO coupons */
		$applied = $cart->get_applied_coupons();
		foreach ( $applied as $code ) {
			if ( strpos( $code, self::COUPON_PREFIX ) === 0 && ! isset( $should_apply[ $code ] ) ) {
				$cart->remove_coupon( $code );
			}
		}

		/* Apply matching BOGO coupons */
		foreach ( $should_apply as $code => $v ) {
			if ( ! $cart->has_discount( $code ) ) {
				$cart->apply_coupon( $code );
			}
		}
	}

	/**
	 * Calculate BOGO discount amount based on cart contents.
	 *
	 * Buy X items, get the cheapest free. For each set of (buy_qty + 1)
	 * items, the cheapest item in the set is free.
	 *
	 * @param array $rule Decoded rule.
	 * @return float
	 */
	public static function calculate_bogo_discount( $rule ) {
		if ( ! WC()->cart ) {
			return 0;
		}

		$buy_qty    = max( 1, (int) ( $rule['min_quantity'] ? $rule['min_quantity'] : 1 ) );
		$discount   = (float) $rule['discount_value'];
		$discount   = min( $discount, 100 );

		/* Collect qualifying items with their prices */
		$item_prices = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( ! $product ) {
				continue;
			}

			$product_id   = $product->get_id();
			$category_ids = wc_get_product_cat_ids( $product->get_parent_id() ? $product->get_parent_id() : $product_id );

			if ( ! Validator::applies_to_product( $rule, $product_id, $category_ids ) ) {
				continue;
			}

			$price = (float) $product->get_regular_price();
			if ( $price <= 0 ) {
				continue;
			}

			for ( $i = 0; $i < (int) $item['quantity']; $i++ ) {
				$item_prices[] = $price;
			}
		}

		if ( empty( $item_prices ) ) {
			return 0;
		}

		/* Sort ascending so cheapest items are first */
		sort( $item_prices );

		$total_items = count( $item_prices );
		$max_qty     = max( 0, (int) ( $rule['max_quantity'] ?? 0 ) );
		if ( $max_qty > 0 && $total_items > $max_qty ) {
			return 0;
		}
		$set_size    = $buy_qty + 1;
		$sets        = (int) floor( $total_items / $set_size );
		$total_off   = 0;

		/* For each complete set, the cheapest item gets discounted */
		for ( $s = 0; $s < $sets; $s++ ) {
			$cheapest  = $item_prices[ $s * $set_size ];
			$total_off += round( $cheapest * ( $discount / 100 ), wc_get_price_decimals() );
		}

		return $total_off;
	}

	/**
	 * Check whether a rule is currently active by status/schedule.
	 *
	 * @param array $rule Rule data.
	 * @return bool
	 */
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

	/**
	 * Check rule eligibility for the current customer/session context.
	 *
	 * @param array $rule Rule data.
	 * @return bool
	 */
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

	/**
	 * Show friendly coupon label.
	 *
	 * @param string     $label  Current label.
	 * @param \WC_Coupon $coupon Coupon object.
	 * @return string
	 */
	public static function coupon_label( $label, $coupon ) {
		$code = $coupon->get_code();
		if ( strpos( $code, self::COUPON_PREFIX ) !== 0 ) {
			return $label;
		}

		$rule_id = (int) str_replace( self::COUPON_PREFIX, '', $code );
		$rule    = Repository::get_rule( $rule_id );

		if ( $rule && ! empty( $rule['title'] ) ) {
			/* translators: %s: discount rule title */
			return sprintf( esc_html__( 'Discount: %s', 'beltoft-smart-discounts-pro' ), esc_html( $rule['title'] ) );
		}

		return $label;
	}

	/**
	 * Suppress coupon errors for our virtual coupons.
	 *
	 * @param string $error    Error message.
	 * @param int    $err_code Error code.
	 * @param object $coupon   Coupon object.
	 * @return string
	 */
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

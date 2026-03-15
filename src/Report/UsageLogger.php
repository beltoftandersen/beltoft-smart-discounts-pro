<?php
/**
 * Usage logging for Pro virtual coupon rules.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Report;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Rule\Repository;
use BeltoftSmartDiscountsPro\Discount\BogoEngine;
use BeltoftSmartDiscountsPro\Discount\BundleEngine;
use BeltoftSmartDiscountsPro\Discount\CartCountEngine;
use BeltoftSmartDiscountsPro\Discount\FirstOrderEngine;
use BeltoftSmartDiscountsPro\Frontend\UrlDiscount;

class UsageLogger {

	const APPLIED_RULES_META = '_bsdisc_pro_applied_rules';
	const USAGE_LOGGED_META  = '_bsdisc_pro_usage_logged';

	public static function init() {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'store_order_meta' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'finalize_usage_log' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'finalize_usage_log' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'finalize_usage_log' ) );
	}

	public static function store_order_meta( $order ) {
		$applied_rules = array();
		foreach ( $order->get_coupon_codes() as $code ) {
			$rule_id = self::extract_rule_id( $code );
			if ( $rule_id > 0 ) {
				$applied_rules[ $code ] = $rule_id;
			}
		}
		if ( empty( $applied_rules ) ) {
			return;
		}
		$order->update_meta_data( self::APPLIED_RULES_META, $applied_rules );
		$order->save();
	}

	public static function finalize_usage_log( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( $order->get_meta( self::USAGE_LOGGED_META ) ) {
			return;
		}
		$applied_rules = $order->get_meta( self::APPLIED_RULES_META );
		if ( empty( $applied_rules ) || ! is_array( $applied_rules ) ) {
			return;
		}
		$customer_id = $order->get_customer_id();
		$email       = $order->get_billing_email();
		foreach ( $applied_rules as $code => $rule_id ) {
			$amount = self::get_coupon_amount_for_code( $order, (string) $code );
			if ( $amount <= 0 ) {
				continue;
			}
			Repository::log_usage( (int) $rule_id, (int) $order_id, (int) $customer_id, (string) $email, (float) $amount );
		}
		$order->update_meta_data( self::USAGE_LOGGED_META, '1' );
		$order->save();
	}

	private static function get_coupon_amount_for_code( $order, $code ) {
		foreach ( $order->get_items( 'coupon' ) as $item ) {
			if ( method_exists( $item, 'get_code' ) && $item->get_code() === $code ) {
				return (float) $item->get_discount();
			}
		}
		return 0.0;
	}

	private static function extract_rule_id( $code ) {
		$prefixes = array(
			BogoEngine::COUPON_PREFIX,
			BundleEngine::COUPON_PREFIX,
			CartCountEngine::COUPON_PREFIX,
			FirstOrderEngine::COUPON_PREFIX,
			UrlDiscount::COUPON_PREFIX,
		);
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $code, $prefix ) ) {
				return (int) str_replace( $prefix, '', $code );
			}
		}
		return 0;
	}
}

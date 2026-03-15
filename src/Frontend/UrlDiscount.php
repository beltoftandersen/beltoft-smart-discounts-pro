<?php
/**
 * URL-based discount — shareable discount links.
 *
 * Usage: ?bsdisc_discount=TOKEN
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Frontend;

defined( 'ABSPATH' ) || exit;

class UrlDiscount {

	const COUPON_PREFIX = 'bsdisc-pro-url-';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture_url_token' ) );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'remove_legacy_coupons' ), 25 );
	}

	public static function capture_url_token() {
		if ( ! isset( $_GET['bsdisc_discount'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET['bsdisc_discount'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $token ) ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		WC()->session->set( 'bsdisc_pro_url_discount', $token );
	}

	public static function remove_legacy_coupons( $cart ) {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}
		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( 0 === strpos( $code, self::COUPON_PREFIX ) ) {
				$cart->remove_coupon( $code );
			}
		}
	}
}

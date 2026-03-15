<?php
/**
 * Advanced condition evaluator for Pro rules.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Discount;

defined( 'ABSPATH' ) || exit;

class ConditionEvaluator {

	private static $conditions_cache = array();
	private static $evaluation_cache = array();

	public static function init() {
		add_filter( 'bsdisc_product_discounts', array( __CLASS__, 'filter_product_discounts' ), 20, 2 );
		add_filter( 'bsdisc_cart_discounts', array( __CLASS__, 'filter_cart_discounts' ), 20, 2 );
	}

	public static function filter_product_discounts( $results, $product_id ) {
		return self::filter_discount_rows( $results );
	}

	public static function filter_cart_discounts( $results, $cart_subtotal ) {
		return self::filter_discount_rows( $results );
	}

	private static function filter_discount_rows( $results ) {
		if ( empty( $results ) || ! is_array( $results ) ) {
			return array();
		}
		$rule_ids = array();
		foreach ( $results as $row ) {
			if ( is_array( $row ) && ! empty( $row['rule']['id'] ) ) {
				$rule_ids[] = (int) $row['rule']['id'];
			}
		}
		self::prime_conditions_cache( $rule_ids );

		$filtered = array();
		foreach ( $results as $row ) {
			if ( ! is_array( $row ) || empty( $row['rule'] ) || ! is_array( $row['rule'] ) ) {
				$filtered[] = $row;
				continue;
			}
			if ( self::evaluate( $row['rule'] ) ) {
				$filtered[] = $row;
			}
		}
		return $filtered;
	}

	public static function evaluate( $rule, $context = array() ) {
		$rule_id = isset( $rule['id'] ) ? absint( $rule['id'] ) : 0;
		if ( $rule_id <= 0 ) {
			return true;
		}
		$cacheable = empty( $context );
		if ( $cacheable && isset( self::$evaluation_cache[ $rule_id ] ) ) {
			return self::$evaluation_cache[ $rule_id ];
		}
		$conditions = self::get_conditions( $rule_id );
		if ( empty( $conditions ) ) {
			if ( $cacheable ) {
				self::$evaluation_cache[ $rule_id ] = true;
			}
			return true;
		}
		foreach ( $conditions as $condition ) {
			if ( ! self::evaluate_single( $condition, $context ) ) {
				if ( $cacheable ) {
					self::$evaluation_cache[ $rule_id ] = false;
				}
				return false;
			}
		}
		if ( $cacheable ) {
			self::$evaluation_cache[ $rule_id ] = true;
		}
		return true;
	}

	private static function get_conditions( $rule_id ) {
		if ( isset( self::$conditions_cache[ $rule_id ] ) ) {
			return self::$conditions_cache[ $rule_id ];
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$conditions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bsdisc_pro_rule_conditions WHERE rule_id = %d",
				$rule_id
			),
			ARRAY_A
		);
		self::$conditions_cache[ $rule_id ] = is_array( $conditions ) ? $conditions : array();
		return self::$conditions_cache[ $rule_id ];
	}

	private static function prime_conditions_cache( $rule_ids ) {
		$rule_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $rule_ids ) ) ) );
		if ( empty( $rule_ids ) ) {
			return;
		}
		$missing_ids = array();
		foreach ( $rule_ids as $rule_id ) {
			if ( ! isset( self::$conditions_cache[ $rule_id ] ) ) {
				self::$conditions_cache[ $rule_id ] = array();
				$missing_ids[]                      = $rule_id;
			}
		}
		if ( empty( $missing_ids ) ) {
			return;
		}
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $missing_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bsdisc_pro_rule_conditions WHERE rule_id IN ({$placeholders}) ORDER BY id ASC",
				$missing_ids
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			$rule_id = isset( $row['rule_id'] ) ? absint( $row['rule_id'] ) : 0;
			if ( $rule_id > 0 && isset( self::$conditions_cache[ $rule_id ] ) ) {
				self::$conditions_cache[ $rule_id ][] = $row;
			}
		}
	}

	private static function evaluate_single( $condition, $context ) {
		$type     = sanitize_text_field( $condition['condition_type'] );
		$value    = sanitize_text_field( $condition['condition_value'] );
		$operator = sanitize_text_field( $condition['operator'] );

		switch ( $type ) {
			case 'purchase_history':
				return self::check_purchase_history( $value, $operator );
			case 'geographic':
				return self::check_geographic( $value, $operator );
			case 'day_time':
				return self::check_day_time( $value, $operator );
			case 'payment_method':
				return self::check_payment_method( $value, $operator );
			case 'cart_contains':
				return self::check_cart_contains( $value, $operator );
			case 'user_role':
				return self::check_user_role( $value, $operator );
			case 'url_token':
				return self::check_url_token( $value, $operator );
			default:
				return apply_filters( 'bsdisc_pro_evaluate_condition', true, $condition, $context );
		}
	}

	private static function check_purchase_history( $value, $operator ) {
		$customer_id = get_current_user_id();
		if ( ! $customer_id ) {
			return 'equals' === $operator && '0' === $value;
		}
		$order_count = wc_get_customer_order_count( $customer_id );
		return self::compare( $order_count, (int) $value, $operator );
	}

	private static function check_geographic( $value, $operator ) {
		$country = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$country = WC()->customer->get_billing_country();
		}
		if ( 'not_equals' === $operator ) {
			return strtoupper( $country ) !== strtoupper( $value );
		}
		return strtoupper( $country ) === strtoupper( $value );
	}

	private static function check_day_time( $value, $operator ) {
		$today      = (int) current_time( 'N' );
		$valid_days = array_map( 'intval', explode( ',', $value ) );
		$match = in_array( $today, $valid_days, true );
		if ( 'not_equals' === $operator ) {
			return ! $match;
		}
		return $match;
	}

	private static function check_payment_method( $value, $operator ) {
		$chosen = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$chosen = WC()->session->get( 'chosen_payment_method', '' );
		}
		if ( 'not_equals' === $operator ) {
			return $chosen !== $value;
		}
		return $chosen === $value;
	}

	private static function check_cart_contains( $value, $operator ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		$required_ids = array_map( 'intval', explode( ',', $value ) );
		$cart_ids     = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( $product ) {
				$cart_ids[] = $product->get_id();
				if ( $product->get_parent_id() ) {
					$cart_ids[] = $product->get_parent_id();
				}
			}
		}
		$cart_ids = array_unique( $cart_ids );
		$all_in   = empty( array_diff( $required_ids, $cart_ids ) );
		if ( 'not_equals' === $operator ) {
			return ! $all_in;
		}
		return $all_in;
	}

	private static function check_user_role( $value, $operator ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return 'not_equals' === $operator;
		}
		$has_role = in_array( $value, (array) $user->roles, true );
		if ( 'not_equals' === $operator ) {
			return ! $has_role;
		}
		return $has_role;
	}

	private static function check_url_token( $value, $operator ) {
		$current_token = '';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$current_token = (string) WC()->session->get( 'bsdisc_pro_url_discount', '' );
		}
		if ( '' === $current_token && isset( $_GET['bsdisc_discount'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$current_token = sanitize_text_field( wp_unslash( $_GET['bsdisc_discount'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( 'not_equals' === $operator ) {
			return $current_token !== $value;
		}
		return '' !== $current_token && $current_token === $value;
	}

	private static function compare( $actual, $expected, $operator ) {
		switch ( $operator ) {
			case 'equals':
				return $actual === $expected;
			case 'not_equals':
				return $actual !== $expected;
			case 'greater_than':
				return $actual > $expected;
			case 'less_than':
				return $actual < $expected;
			case 'greater_equal':
				return $actual >= $expected;
			case 'less_equal':
				return $actual <= $expected;
			default:
				return $actual === $expected;
		}
	}
}

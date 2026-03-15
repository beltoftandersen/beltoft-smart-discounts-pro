<?php
/**
 * User role-based discount engine.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Discount;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscounts\Discount\Engine;
use BeltoftSmartDiscounts\Rule\Validator;

class RoleEngine {

	private static $required_roles_cache = array();

	public static function init() {
		add_filter( 'bsdisc_rule_types', array( __CLASS__, 'register_type' ) );
		add_filter( 'bsdisc_product_discounts', array( __CLASS__, 'add_role_discounts' ), 10, 2 );
	}

	public static function register_type( $types ) {
		$types['role'] = __( 'User Role Discount', 'beltoft-smart-discounts-pro' );
		return $types;
	}

	public static function add_role_discounts( $results, $product_id ) {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return $results;
		}

		$user_roles  = (array) $user->roles;
		$rules       = Engine::get_active_rules();
		$customer_id = $user->ID;
		$email       = $user->user_email;
		$role_ids    = array();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['type'] ) || empty( $rule['id'] ) ) {
				continue;
			}
			if ( 'role' === $rule['type'] ) {
				$role_ids[] = (int) $rule['id'];
			}
		}

		self::prime_required_roles_cache( $role_ids );

		foreach ( $rules as $rule ) {
			if ( empty( $rule['type'] ) || 'role' !== $rule['type'] ) {
				continue;
			}
			if ( ! Validator::is_within_usage_limit( $rule ) ) {
				continue;
			}
			if ( ! Validator::is_within_per_user_limit( $rule, $customer_id, $email ) ) {
				continue;
			}
			if ( ! self::user_has_required_role( (int) $rule['id'], $user_roles ) ) {
				continue;
			}
			$category_ids = wc_get_product_cat_ids( $product_id );
			if ( ! Validator::applies_to_product( $rule, $product_id, $category_ids ) ) {
				continue;
			}
			if ( ! ConditionEvaluator::evaluate( $rule ) ) {
				continue;
			}
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$price = (float) $product->get_price( 'edit' );
			if ( $price <= 0 ) {
				$price = (float) $product->get_regular_price();
			}
			if ( $price <= 0 ) {
				continue;
			}
			$percent = (float) $rule['discount_value'];
			$percent = min( $percent, 100 );
			$amount  = round( $price * ( $percent / 100 ), wc_get_price_decimals() );
			if ( $amount > 0 ) {
				$results[] = array(
					'rule_id'         => (int) $rule['id'],
					'discount_amount' => $amount,
					'rule'            => $rule,
				);
			}
		}
		return $results;
	}

	private static function user_has_required_role( $rule_id, $user_roles ) {
		$required_roles = self::get_required_roles( $rule_id );
		if ( empty( $required_roles ) ) {
			return false;
		}
		foreach ( $required_roles as $required_role ) {
			if ( in_array( $required_role, $user_roles, true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function get_required_roles( $rule_id ) {
		$rule_id = (int) $rule_id;
		if ( $rule_id <= 0 ) {
			return array();
		}
		if ( ! isset( self::$required_roles_cache[ $rule_id ] ) ) {
			self::prime_required_roles_cache( array( $rule_id ) );
		}
		return isset( self::$required_roles_cache[ $rule_id ] ) ? self::$required_roles_cache[ $rule_id ] : array();
	}

	private static function prime_required_roles_cache( $rule_ids ) {
		$rule_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $rule_ids ) ) ) );
		if ( empty( $rule_ids ) ) {
			return;
		}
		$missing_ids = array();
		foreach ( $rule_ids as $rule_id ) {
			if ( ! isset( self::$required_roles_cache[ $rule_id ] ) ) {
				self::$required_roles_cache[ $rule_id ] = array();
				$missing_ids[]                          = $rule_id;
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
				"SELECT rule_id, condition_value FROM {$wpdb->prefix}bsdisc_pro_rule_conditions WHERE condition_type = 'user_role' AND rule_id IN ({$placeholders})",
				$missing_ids
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! empty( $rows ) && is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$rule_id = isset( $row['rule_id'] ) ? (int) $row['rule_id'] : 0;
				if ( $rule_id <= 0 || ! isset( self::$required_roles_cache[ $rule_id ] ) ) {
					continue;
				}
				$role = sanitize_key( (string) $row['condition_value'] );
				if ( '' !== $role ) {
					self::$required_roles_cache[ $rule_id ][] = $role;
				}
			}
		}
		foreach ( $missing_ids as $rule_id ) {
			if ( ! empty( self::$required_roles_cache[ $rule_id ] ) ) {
				self::$required_roles_cache[ $rule_id ] = array_values( array_unique( self::$required_roles_cache[ $rule_id ] ) );
			}
		}
	}
}

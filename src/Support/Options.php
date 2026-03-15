<?php
/**
 * Single-option store for Pro plugin settings.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Support;

defined( 'ABSPATH' ) || exit;

class Options {

	const OPTION = 'bsdisc_pro_options';

	/**
	 * Static cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
			return array(
			/* License */
			'license_key'              => '',
			'license_status'           => '',
			'license_expires'          => '',
			'license_last_checked'     => '',
			'license_remote_version'   => '',
			'license_max_activations'  => '',

			/* Countdown Timer */
			'countdown_threshold'  => '48',

				/* Spending Goal */
				'spending_goal_display_mode' => 'auto',
				'spending_goal_enabled' => '0',
				'spending_goal_show_on_cart' => '0',
				'spending_goal_show_on_checkout' => '0',

			/* Report Email */
			'report_email_enabled' => '0',
			'report_email_address' => '',

			/* Cleanup */
			'cleanup_on_uninstall' => '0',
		);
	}

	/**
	 * Get all options merged with defaults.
	 *
	 * @return array
	 */
	public static function get_all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		if ( ! array_key_exists( 'spending_goal_show_on_cart', $saved ) ) {
			$saved['spending_goal_show_on_cart'] = ! empty( $saved['spending_goal_enabled'] ) ? '1' : '0';
		}

		if ( ! array_key_exists( 'spending_goal_show_on_checkout', $saved ) ) {
			$saved['spending_goal_show_on_checkout'] = ! empty( $saved['spending_goal_enabled'] ) ? '1' : '0';
		}

		if ( ! array_key_exists( 'spending_goal_display_mode', $saved ) ) {
			$saved['spending_goal_display_mode'] = ( ! empty( $saved['spending_goal_show_on_cart'] ) || ! empty( $saved['spending_goal_show_on_checkout'] ) ) ? 'auto' : 'shortcode';
		}

		self::$cache = array_merge( self::defaults(), $saved );
		return self::$cache;
	}

	/**
	 * Get a single option value or all options.
	 *
	 * @param string|null $key     Option key. Null returns all.
	 * @param mixed       $default Fallback value.
	 * @return mixed
	 */
	public static function get( $key = null, $default = null ) {
		$all = self::get_all();

		if ( null === $key ) {
			return $all;
		}

		return isset( $all[ $key ] ) ? $all[ $key ] : $default;
	}

	/**
	 * Update a single key or merge an array.
	 *
	 * @param string|array $key   Option key or array of key-value pairs.
	 * @param mixed        $value Value when $key is a string.
	 */
	public static function set( $key, $value = null ) {
		$opts = self::get_all();

		if ( is_array( $key ) ) {
			$opts = array_merge( $opts, $key );
		} else {
			$opts[ $key ] = $value;
		}

		/*
		 * Bypass the registered sanitize callback for internal writes.
		 *
		 * `sanitize()` intentionally blocks license fields from request-driven updates.
		 * Activation/deactivation also writes through this helper, so running the
		 * sanitize filter here would discard those license updates.
		 */
		$sanitize_hook = 'sanitize_option_' . self::OPTION;
		$sanitize_cb   = array( __CLASS__, 'sanitize' );
		$priority      = has_filter( $sanitize_hook, $sanitize_cb );

		if ( false !== $priority ) {
			remove_filter( $sanitize_hook, $sanitize_cb, (int) $priority );
		}

		update_option( self::OPTION, $opts );

		if ( false !== $priority ) {
			add_filter( $sanitize_hook, $sanitize_cb, (int) $priority );
		}

		self::$cache = null;
	}

	/**
	 * Sanitize settings on save.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return self::defaults();
		}

		$clean = self::get_all();

		/* Checkboxes */
		$booleans = array(
			'spending_goal_show_on_cart',
			'spending_goal_show_on_checkout',
			'report_email_enabled',
			'cleanup_on_uninstall',
		);
		foreach ( $booleans as $field ) {
			$clean[ $field ] = ! empty( $input[ $field ] ) ? '1' : '0';
		}

		$clean['spending_goal_enabled'] = ( '1' === $clean['spending_goal_show_on_cart'] || '1' === $clean['spending_goal_show_on_checkout'] ) ? '1' : '0';

		$clean['spending_goal_display_mode'] = isset( $input['spending_goal_display_mode'] ) && in_array( $input['spending_goal_display_mode'], array( 'auto', 'shortcode', 'both' ), true )
			? $input['spending_goal_display_mode']
			: 'auto';

		if ( 'shortcode' === $clean['spending_goal_display_mode'] ) {
			$clean['spending_goal_show_on_cart']    = '0';
			$clean['spending_goal_show_on_checkout'] = '0';
		}

		$clean['spending_goal_enabled'] = ( '1' === $clean['spending_goal_show_on_cart'] || '1' === $clean['spending_goal_show_on_checkout'] ) ? '1' : '0';

		/* Numeric */
		if ( isset( $input['countdown_threshold'] ) ) {
			$clean['countdown_threshold'] = (string) max( 1, min( 720, absint( $input['countdown_threshold'] ) ) );
		}

		/* Email */
		if ( isset( $input['report_email_address'] ) ) {
			$emails = array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $input['report_email_address'] ) ) ) );
			$emails = array_filter( $emails, 'is_email' );
			$clean['report_email_address'] = implode( ', ', $emails );
		}

		/* License fields are managed by the License class only */

		return $clean;
	}
}

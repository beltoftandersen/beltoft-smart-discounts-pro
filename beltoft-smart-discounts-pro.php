<?php
/**
 * Plugin Name:       Beltoft Smart Discounts for WooCommerce - Pro
 * Plugin URI:        https://beltoft.net/plugins/beltoft-smart-discounts-pro/
 * Description:       Premium add-on for Beltoft Smart Discounts for WooCommerce.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            beltoft.net
 * Author URI:        https://beltoft.net/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beltoft-smart-discounts-pro
 * Domain Path:       /languages
 * Requires Plugins:  beltoft-smart-discounts, woocommerce
 *
 * WC requires at least: 8.0
 * WC tested up to:      9.6
 *
 * @package BeltoftSmartDiscountsPro
 */

defined( 'ABSPATH' ) || exit;

/* ── PSR-4 autoloader ─────────────────────────────────────────── */
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'BeltoftSmartDiscountsPro\\' ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( 'BeltoftSmartDiscountsPro\\' ) );
	$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
	$file     = plugin_dir_path( __FILE__ ) . 'src/' . $relative;
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/* ── Constants ────────────────────────────────────────────────── */
define( 'BSDISC_PRO_VER', '2.0.0' );
define( 'BSDISC_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'BSDISC_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'BSDISC_PRO_FILE', __FILE__ );
define( 'BSDISC_PRO_DB_VERSION', '1.0.1' );
define( 'BSDISC_PRO_LICENSE_SERVER', 'https://beltoft.net' );

/* ── Cron schedules ────────────────────────────────────────────── */
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'beltoft-smart-discounts-pro' ),
			);
		}

		return $schedules;
	}
);

/* ── Activation / deactivation ────────────────────────────────── */
register_activation_hook( __FILE__, function () {
	if ( ! defined( 'BSDISC_VERSION' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Beltoft Smart Discounts for WooCommerce - Pro requires the free "Beltoft Smart Discounts for WooCommerce" plugin to be installed and activated.', 'beltoft-smart-discounts-pro' ),
			'Plugin dependency check',
			array( 'back_link' => true )
		);
	}
	BeltoftSmartDiscountsPro\Support\Installer::activate();
} );

register_deactivation_hook( __FILE__, function () {
	BeltoftSmartDiscountsPro\Support\Installer::deactivate();
} );

/* ── HPOS compatibility ───────────────────────────────────────── */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
} );

/* ── Bootstrap ────────────────────────────────────────────────── */
add_action( 'plugins_loaded', function () {
	/* Check WooCommerce */
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Beltoft Smart Discounts for WooCommerce - Pro requires WooCommerce to be installed and activated.', 'beltoft-smart-discounts-pro' );
			echo '</p></div>';
		} );
		return;
	}

	/* Check free plugin */
	if ( ! defined( 'BSDISC_VERSION' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Beltoft Smart Discounts for WooCommerce - Pro requires the free "Beltoft Smart Discounts for WooCommerce" plugin to be installed and activated.', 'beltoft-smart-discounts-pro' );
			echo '</p></div>';
		} );
		return;
	}

	/* Minimum version check */
	if ( version_compare( BSDISC_VERSION, '1.0.0', '<' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'Beltoft Smart Discounts for WooCommerce - Pro requires version 1.0.0 or higher of the free plugin. Please update.', 'beltoft-smart-discounts-pro' );
			echo '</p></div>';
		} );
		return;
	}

	BeltoftSmartDiscountsPro\Support\Installer::maybe_upgrade();
	BeltoftSmartDiscountsPro\Plugin::init();
}, 20 );

/* ── Settings link on Plugins page ────────────────────────────── */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url           = admin_url( 'admin.php?page=bsdisc-discounts&tab=license' );
	$settings_link = '<a href="' . esc_url( $url ) . '">'
		. esc_html__( 'Settings', 'beltoft-smart-discounts-pro' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );

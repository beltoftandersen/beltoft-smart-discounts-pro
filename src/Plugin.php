<?php
/**
 * Central bootstrap class for Pro features.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscountsPro\Licensing\License;
use BeltoftSmartDiscountsPro\Licensing\Updater;
use BeltoftSmartDiscountsPro\Admin\SettingsPage;
use BeltoftSmartDiscountsPro\Admin\AnalyticsTab;
use BeltoftSmartDiscountsPro\Admin\RuleConditions;
use BeltoftSmartDiscountsPro\Discount\BogoEngine;
use BeltoftSmartDiscountsPro\Discount\BundleEngine;
use BeltoftSmartDiscountsPro\Discount\RoleEngine;
use BeltoftSmartDiscountsPro\Discount\FirstOrderEngine;
use BeltoftSmartDiscountsPro\Discount\CartCountEngine;
use BeltoftSmartDiscountsPro\Discount\CartCouponCoordinator;
use BeltoftSmartDiscountsPro\Discount\ConditionEvaluator;
use BeltoftSmartDiscountsPro\Frontend\SpendingGoal;
use BeltoftSmartDiscountsPro\Frontend\UrlDiscount;
use BeltoftSmartDiscountsPro\Frontend\CountdownTimer;
use BeltoftSmartDiscountsPro\Report\ReportCron;
use BeltoftSmartDiscountsPro\Report\UsageLogger;
use BeltoftSmartDiscountsPro\ImportExport\Exporter;
use BeltoftSmartDiscountsPro\ImportExport\Importer;

class Plugin {

	/**
	 * Boot the Pro plugin.
	 */
	public static function init() {
		License::init();
		Updater::init();
		UsageLogger::init();

		if ( is_admin() ) {
			SettingsPage::init();
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		}

		Exporter::init();
		Importer::init();

		/* Pro features require an active license */
		if ( ! License::is_active() ) {
			return;
		}

		/* Respect free plugin global toggle for storefront discount logic. */
		if ( class_exists( '\BeltoftSmartDiscounts\Support\Options' ) && ! \BeltoftSmartDiscounts\Support\Options::get( 'enabled' ) ) {
			return;
		}

		/* RuleConditions provides admin form fields + brand filters (frontend too). */
		RuleConditions::init();

		BogoEngine::init();
		BundleEngine::init();
		RoleEngine::init();
		FirstOrderEngine::init();
		CartCountEngine::init();
		ConditionEvaluator::init();
		CartCouponCoordinator::init();
		SpendingGoal::init();
		UrlDiscount::init();
		CountdownTimer::init();
		AnalyticsTab::init();
		ReportCron::init();

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
	}

	/**
	 * Enqueue admin CSS on our settings page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook_suffix ) {
		if ( 'woocommerce_page_bsdisc-discounts' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'bsdisc-pro-admin',
			BSDISC_PRO_URL . 'assets/css/admin.css',
			array(),
			BSDISC_PRO_VER
		);
	}

	/**
	 * Enqueue frontend CSS/JS when Pro features are active.
	 */
	public static function enqueue_frontend_assets() {
		wp_enqueue_style(
			'bsdisc-pro-frontend',
			BSDISC_PRO_URL . 'assets/css/frontend.css',
			array(),
			BSDISC_PRO_VER
		);

		wp_enqueue_script(
			'bsdisc-pro-frontend',
			BSDISC_PRO_URL . 'assets/js/frontend.js',
			array( 'jquery' ),
			BSDISC_PRO_VER,
			true
		);
	}
}

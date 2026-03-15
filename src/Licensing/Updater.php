<?php
/**
 * WordPress auto-update mechanism for the Pro plugin.
 *
 * Checks for new versions via the cached license_remote_version option
 * (populated by License::cron_check). Downloads use a fresh signed URL
 * fetched at download time via upgrader_pre_download to avoid stale tokens.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Licensing;

use BeltoftSmartDiscountsPro\Support\Options;

defined( 'ABSPATH' ) || exit;

class Updater {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'get_download_package' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
	}

	/**
	 * Inject update information into the WordPress update transient.
	 *
	 * @param object $transient The update_plugins transient data.
	 * @return object
	 */
	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) || ! isset( $transient->checked ) ) {
			return $transient;
		}

		// Only offer updates when the license is actively valid.
		if ( ! License::is_valid_for_updates() ) {
			return $transient;
		}

		$remote_version = Options::get( 'license_remote_version' );
		if ( empty( $remote_version ) ) {
			return $transient;
		}

		// No update if local version is current or newer.
		if ( ! version_compare( BSDISC_PRO_VER, $remote_version, '<' ) ) {
			return $transient;
		}

		$plugin_basename = self::get_plugin_basename();

		$update               = new \stdClass();
		$update->slug         = self::get_plugin_slug();
		$update->plugin       = $plugin_basename;
		$update->new_version  = $remote_version;
		$update->url          = 'https://beltoft.net';
		$update->package      = 'bsdisc-pro-deferred-download'; // Real URL fetched in get_download_package().
		$update->requires_php = '7.4';
		$update->tested       = get_bloginfo( 'version' );

		$transient->response[ $plugin_basename ] = $update;

		return $transient;
	}

	/**
	 * Provide plugin information for the "View details" modal.
	 *
	 * @param false|object|array $result Default value.
	 * @param string             $action API action ('plugin_information').
	 * @param object             $args Query arguments.
	 * @return false|object
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::get_plugin_slug() !== $args->slug ) {
			return $result;
		}

		$remote_version = Options::get( 'license_remote_version' );

		$info = array(
			'name'         => 'Beltoft Smart Discounts for WooCommerce - Pro',
			'slug'         => self::get_plugin_slug(),
			'version'      => $remote_version ? $remote_version : BSDISC_PRO_VER,
			'author'       => '<a href="https://beltoft.net">beltoft.net</a>',
			'homepage'     => 'https://beltoft.net',
			'requires'     => '6.0',
			'tested'       => get_bloginfo( 'version' ),
			'requires_php' => '7.4',
			'sections'     => array(
				'description' => 'Premium add-on for Beltoft Smart Discounts for WooCommerce &mdash; BOGO, bundles, role pricing, analytics, and auto-updates.',
			),
		);

		return (object) $info;
	}

	/**
	 * Fetch a fresh signed download URL at the moment WordPress actually downloads.
	 *
	 * @param bool|string|\WP_Error $reply Whether to short-circuit the download. Default false.
	 * @param string                $package The package URL.
	 * @param \WP_Upgrader         $upgrader The upgrader instance.
	 * @return bool|string|\WP_Error
	 */
	public static function get_download_package( $reply, $package, $upgrader ) {
		if ( 'bsdisc-pro-deferred-download' !== $package ) {
			return $reply;
		}

		$license_key = Options::get( 'license_key' );
		if ( empty( $license_key ) ) {
			return new \WP_Error(
				'bsdisc_no_license',
				__( 'A valid license key is required to download updates.', 'beltoft-smart-discounts-pro' )
			);
		}

		// Try up to 2 times: signed URL may expire between request and download.
		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$result = self::fetch_and_download( $license_key );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			if ( 2 === $attempt ) {
				return $result;
			}
		}

		// @codeCoverageIgnoreStart - unreachable, loop always returns.
		return new \WP_Error( 'bsdisc_download_error', __( 'Download failed.', 'beltoft-smart-discounts-pro' ) );
		// @codeCoverageIgnoreEnd
	}

	/**
	 * Request a signed download URL and download the package.
	 *
	 * @param string $license_key The license key.
	 * @return string|\WP_Error Temp file path on success, WP_Error on failure.
	 */
	private static function fetch_and_download( $license_key ) {
		$response = License::api_request(
			'/license/download',
			array(
				'license_key' => $license_key,
				'domain'      => untrailingslashit( home_url() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'bsdisc_download_error',
				__( 'Could not connect to license server to download the update.', 'beltoft-smart-discounts-pro' )
			);
		}

		if ( empty( $response['download_url'] ) ) {
			$message = ! empty( $response['message'] )
				? sanitize_text_field( $response['message'] )
				: __( 'Could not retrieve download URL from license server.', 'beltoft-smart-discounts-pro' );

			return new \WP_Error( 'bsdisc_no_download_url', $message );
		}

		return download_url( esc_url_raw( $response['download_url'] ) );
	}

	/**
	 * Clear cached remote version after a successful update.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $options Update details.
	 */
	public static function after_update( $upgrader, $options ) {
		if ( 'update' !== ( isset( $options['action'] ) ? $options['action'] : '' ) || 'plugin' !== ( isset( $options['type'] ) ? $options['type'] : '' ) ) {
			return;
		}

		$plugins = isset( $options['plugins'] ) ? $options['plugins'] : array();
		if ( ! in_array( self::get_plugin_basename(), $plugins, true ) ) {
			return;
		}

		Options::set( 'license_remote_version', '' );
	}

	/**
	 * Get the plugin basename.
	 *
	 * @return string
	 */
	private static function get_plugin_basename() {
		return plugin_basename( BSDISC_PRO_FILE );
	}

	/**
	 * Get the plugin directory slug.
	 *
	 * @return string
	 */
	private static function get_plugin_slug() {
		return dirname( self::get_plugin_basename() );
	}
}

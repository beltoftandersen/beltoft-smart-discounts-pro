<?php
/**
 * Remote license validation via the license server at beltoft.net.
 *
 * License statuses:
 *   'valid'           - Active license. Features + updates enabled.
 *   'inactive'        - Expired/revoked on server. Features work, updates disabled.
 *   'domain_mismatch' - Not activated on this domain. Features disabled.
 *   'invalid_key'     - Key not found on server. Features disabled.
 *   ''                - No key entered yet. Features disabled.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Licensing;

use BeltoftSmartDiscountsPro\Support\Options;

defined( 'ABSPATH' ) || exit;

class License {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'bsdisc_pro_license_check', array( __CLASS__, 'cron_check' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

		// AJAX handlers.
		add_action( 'wp_ajax_bsdisc_pro_activate_license', array( __CLASS__, 'ajax_activate' ) );
		add_action( 'wp_ajax_bsdisc_pro_deactivate_license', array( __CLASS__, 'ajax_deactivate' ) );
	}

	/**
	 * Whether Pro features should be enabled.
	 *
	 * Returns true for 'valid' (active) and 'inactive' (expired) licenses.
	 * Returns false when there is no license or the key is invalid.
	 */
	public static function is_active() {
		return in_array( Options::get( 'license_status' ), array( 'valid', 'inactive' ), true );
	}

	/**
	 * Whether the license is eligible for updates.
	 *
	 * Only 'valid' (active, non-expired) licenses receive updates.
	 */
	public static function is_valid_for_updates() {
		return 'valid' === Options::get( 'license_status' );
	}

	/**
	 * Activate a license key via the remote license server.
	 *
	 * @param string $key License key to activate.
	 * @return array { success: bool, message: string }
	 */
	public static function activate( $key ) {
		$key = strtoupper( trim( $key ) );

		if ( empty( $key ) ) {
			return array(
				'success' => false,
				'message' => __( 'Please enter a license key.', 'beltoft-smart-discounts-pro' ),
			);
		}

		$domain   = self::get_domain();
		$response = self::api_request(
			'/license/activate',
			array(
				'license_key' => $key,
				'domain'      => $domain,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => __( 'Could not connect to license server. Please try again.', 'beltoft-smart-discounts-pro' ),
			);
		}

		$http_code = isset( $response['_http_code'] ) ? (int) $response['_http_code'] : 200;

		// 404 - distinguish "key not found" from "endpoint not found".
		if ( 404 === $http_code ) {
			if ( self::is_license_not_found_response( $response ) ) {
				return array(
					'success' => false,
					'message' => __( 'License key not found. Please check and try again.', 'beltoft-smart-discounts-pro' ),
				);
			}

			return array(
				'success' => false,
				'message' => __( 'License endpoint unavailable. Please try again in a moment.', 'beltoft-smart-discounts-pro' ),
			);
		}

		// 403 - not active, expired, or domain mismatch.
		if ( 403 === $http_code ) {
			$server_msg = ! empty( $response['message'] ) ? sanitize_text_field( $response['message'] ) : '';

			// Detect domain mismatch from server message.
			if ( false !== stripos( $server_msg, 'not activated on this domain' ) ) {
				Options::set(
					array(
						'license_key'    => $key,
						'license_status' => 'domain_mismatch',
					)
				);
			} else {
				Options::set(
					array(
						'license_key'    => $key,
						'license_status' => 'inactive',
					)
				);
			}

			return array(
				'success' => false,
				'message' => $server_msg ? $server_msg : __( 'License activation failed.', 'beltoft-smart-discounts-pro' ),
			);
		}

		if ( empty( $response['success'] ) ) {
			return array(
				'success' => false,
				'message' => ! empty( $response['message'] )
					? sanitize_text_field( $response['message'] )
					: __( 'License activation failed.', 'beltoft-smart-discounts-pro' ),
			);
		}

		// Activation succeeded - fetch full details via validate.
		$validate = self::api_request(
			'/license/validate',
			array(
				'license_key' => $key,
				'domain'      => $domain,
			)
		);

		$license_data = array(
			'license_key'          => $key,
			'license_status'       => 'valid',
			'license_last_checked' => current_time( 'mysql' ),
		);

		if ( ! is_wp_error( $validate ) && ! empty( $validate['valid'] ) ) {
			$license_data['license_expires']         = ! empty( $validate['expires_at'] ) ? sanitize_text_field( $validate['expires_at'] ) : '';
			$license_data['license_remote_version']  = ! empty( $validate['current_version'] ) ? sanitize_text_field( $validate['current_version'] ) : '';
			$license_data['license_max_activations'] = isset( $validate['max_activations'] ) ? absint( $validate['max_activations'] ) : '';
		}

		Options::set( $license_data );

		return array(
			'success' => true,
			'message' => __( 'License activated successfully.', 'beltoft-smart-discounts-pro' ),
		);
	}

	/**
	 * Deactivate the license on the remote server.
	 *
	 * Keeps license_key in options so re-activation is seamless.
	 *
	 * @return array { success: bool, message: string }
	 */
	public static function deactivate() {
		$key = Options::get( 'license_key' );
		if ( empty( $key ) ) {
			return array(
				'success' => false,
				'message' => __( 'No license key to deactivate.', 'beltoft-smart-discounts-pro' ),
			);
		}

		$response = self::api_request(
			'/license/deactivate',
			array(
				'license_key' => $key,
				'domain'      => self::get_domain(),
			)
		);

		$remote_unconfirmed = false;
		if ( is_wp_error( $response ) ) {
			$remote_unconfirmed = true;
		} else {
			$http_code = isset( $response['_http_code'] ) ? (int) $response['_http_code'] : 0;
			if ( 0 === $http_code || 429 === $http_code || $http_code >= 500 ) {
				$remote_unconfirmed = true;
			}
		}

		// Clear activation state but keep the key for easy re-activation.
		Options::set(
			array(
				'license_status'          => '',
				'license_expires'         => '',
				'license_last_checked'    => '',
				'license_remote_version'  => '',
				'license_max_activations' => '',
			)
		);

		return array(
			'success' => true,
			'message' => $remote_unconfirmed
				? __( 'License deactivated locally. Remote deactivation could not be confirmed right now.', 'beltoft-smart-discounts-pro' )
				: __( 'License deactivated successfully.', 'beltoft-smart-discounts-pro' ),
		);
	}

	/**
	 * Deactivate on the remote server only (used during plugin deactivation).
	 *
	 * Frees the activation slot without clearing local options.
	 */
	public static function remote_deactivate() {
		$key = Options::get( 'license_key' );
		if ( empty( $key ) ) {
			return;
		}

		self::api_request(
			'/license/deactivate',
			array(
				'license_key' => $key,
				'domain'      => self::get_domain(),
			)
		);
	}

	/**
	 * Check license status (called daily by cron).
	 *
	 * Handles HTTP status codes:
	 *   200        - valid response, update status from body
	 *   403        - expired/inactive/domain mismatch
	 *   404        - key not found
	 *   429 / 5xx  - transient error, keep last known state
	 *   WP_Error   - network failure, keep last known state
	 */
	public static function cron_check() {
		$key = Options::get( 'license_key' );
		if ( empty( $key ) ) {
			return;
		}

		$response = self::api_request(
			'/license/validate',
			array(
				'license_key' => $key,
				'domain'      => self::get_domain(),
			)
		);

		// Network failure - keep cached status.
		if ( is_wp_error( $response ) ) {
			Options::set( 'license_last_checked', current_time( 'mysql' ) );
			return;
		}

		$http_code = isset( $response['_http_code'] ) ? (int) $response['_http_code'] : 200;

		// 429 / 5xx - transient, keep last known state.
		if ( 429 === $http_code || $http_code >= 500 ) {
			Options::set( 'license_last_checked', current_time( 'mysql' ) );
			return;
		}

		// 404 - key not found on server (not endpoint-not-found).
		if ( 404 === $http_code ) {
			if ( ! self::is_license_not_found_response( $response ) ) {
				// Treat endpoint-level 404s as transient infrastructure issues.
				Options::set( 'license_last_checked', current_time( 'mysql' ) );
				return;
			}

			Options::set(
				array(
					'license_status'       => 'invalid_key',
					'license_last_checked' => current_time( 'mysql' ),
				)
			);
			return;
		}

		// 403 - expired, inactive, or domain mismatch.
		if ( 403 === $http_code ) {
			$server_msg = ! empty( $response['message'] ) ? $response['message'] : '';

			if ( false !== stripos( $server_msg, 'not activated on this domain' ) ) {
				$new_status = 'domain_mismatch';
			} else {
				$new_status = 'inactive';
			}

			Options::set(
				array(
					'license_status'       => $new_status,
					'license_last_checked' => current_time( 'mysql' ),
				)
			);
			return;
		}

		// 200 - check response body.
		if ( ! empty( $response['valid'] ) && ! empty( $response['license_active'] ) ) {
			// License is valid and active.
			Options::set(
				array(
					'license_status'          => 'valid',
					'license_expires'         => ! empty( $response['expires_at'] ) ? sanitize_text_field( $response['expires_at'] ) : '',
					'license_last_checked'    => current_time( 'mysql' ),
					'license_remote_version'  => ! empty( $response['current_version'] ) ? sanitize_text_field( $response['current_version'] ) : '',
					'license_max_activations' => isset( $response['max_activations'] ) ? absint( $response['max_activations'] ) : '',
				)
			);
		} elseif ( ! empty( $response['valid'] ) ) {
			// Valid key but not active (expired/suspended on server).
			Options::set(
				array(
					'license_status'       => 'inactive',
					'license_last_checked' => current_time( 'mysql' ),
				)
			);
		} else {
			// Invalid response.
			Options::set(
				array(
					'license_status'       => 'inactive',
					'license_last_checked' => current_time( 'mysql' ),
				)
			);
		}
	}

	/**
	 * Show admin notices for license issues.
	 */
	public static function admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$status = Options::get( 'license_status' );
		$key    = Options::get( 'license_key' );
		$url    = admin_url( 'admin.php?page=bsdisc-discounts&tab=license' );

		// No key entered.
		if ( empty( $key ) && empty( $status ) ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				wp_kses(
					/* translators: %s: license settings page URL */
					__( 'Smart Discounts Pro: Please <a href="%s">enter your license key</a> to enable Pro features.', 'beltoft-smart-discounts-pro' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $url )
			);
			echo '</p></div>';
			return;
		}

		// Key exists, but it's not activated on this site yet.
		if ( ! empty( $key ) && empty( $status ) ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				wp_kses(
					/* translators: %s: license settings page URL */
					__( 'Smart Discounts Pro: License is not activated. <a href="%s">Activate your license</a> to enable Pro features and updates.', 'beltoft-smart-discounts-pro' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $url )
			);
			echo '</p></div>';
			return;
		}

		// Inactive (expired/revoked) - features work, no updates.
		if ( 'inactive' === $status ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				wp_kses(
					/* translators: %s: license settings page URL */
					__( 'Smart Discounts Pro: License inactive. <a href="%s">Renew your license</a> to receive updates.', 'beltoft-smart-discounts-pro' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $url )
			);
			echo '</p></div>';
			return;
		}

		// Domain mismatch.
		if ( 'domain_mismatch' === $status ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				wp_kses(
					/* translators: %s: license settings page URL */
					__( 'Smart Discounts Pro: License is not activated on this domain. <a href="%s">Deactivate your old domain</a> and reactivate here.', 'beltoft-smart-discounts-pro' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $url )
			);
			echo '</p></div>';
			return;
		}

		// Invalid key.
		if ( 'invalid_key' === $status ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				wp_kses(
					/* translators: %s: license settings page URL */
					__( 'Smart Discounts Pro: License key not found. Please <a href="%s">check your license key</a>.', 'beltoft-smart-discounts-pro' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( $url )
			);
			echo '</p></div>';
		}
	}

	/**
	 * AJAX: Activate license.
	 */
	public static function ajax_activate() {
		check_ajax_referer( 'bsdisc_pro_license', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beltoft-smart-discounts-pro' ) ) );
		}

		$key = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		if ( empty( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a license key.', 'beltoft-smart-discounts-pro' ) ) );
		}

		$result = self::activate( $key );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX: Deactivate license.
	 */
	public static function ajax_deactivate() {
		check_ajax_referer( 'bsdisc_pro_license', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'beltoft-smart-discounts-pro' ) ) );
		}

		$result = self::deactivate();
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Send a request to the license server API.
	 *
	 * @param string $endpoint API endpoint path (e.g., '/license/validate').
	 * @param array  $body Request body.
	 * @return array|\WP_Error Decoded JSON response with '_http_code', or WP_Error on failure.
	 */
	public static function api_request( $endpoint, $body ) {
		return self::send_api_request( $endpoint, $body );
	}

	/**
	 * Perform the HTTP request and normalize response metadata.
	 *
	 * @param string $endpoint API endpoint path.
	 * @param array  $body Request body.
	 * @return array|\WP_Error
	 */
	private static function send_api_request( $endpoint, $body ) {
		$body['plugin_slug'] = dirname( plugin_basename( BSDISC_PRO_FILE ) );

		$url = BSDISC_PRO_LICENSE_SERVER . $endpoint;

		$response = wp_remote_post(
			$url,
			array(
				'body'    => wp_json_encode( $body ),
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_body = (string) wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $raw_body, true );

		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		$decoded['_http_code'] = (int) wp_remote_retrieve_response_code( $response );
		$decoded['_raw_body']  = $raw_body;

		return $decoded;
	}

	/**
	 * Check whether a 404 corresponds to a missing license key (not a missing route).
	 *
	 * @param array $response Decoded response payload from api_request().
	 * @return bool
	 */
	private static function is_license_not_found_response( $response ) {
		$http_code = isset( $response['_http_code'] ) ? (int) $response['_http_code'] : 0;
		if ( 404 !== $http_code ) {
			return false;
		}

		$message = self::extract_response_message( $response );

		return false !== strpos( $message, 'license not found' )
			|| false !== strpos( $message, 'license key not found' );
	}

	/**
	 * Extract a lower-cased text message from decoded API response.
	 *
	 * @param array $response Decoded response payload.
	 * @return string
	 */
	private static function extract_response_message( $response ) {
		$parts = array();

		if ( isset( $response['message'] ) && is_scalar( $response['message'] ) ) {
			$parts[] = (string) $response['message'];
		}

		if ( isset( $response['error'] ) && is_scalar( $response['error'] ) ) {
			$parts[] = (string) $response['error'];
		}

		if ( isset( $response['_raw_body'] ) && is_string( $response['_raw_body'] ) ) {
			$parts[] = $response['_raw_body'];
		}

		return strtolower( trim( implode( ' ', $parts ) ) );
	}

	/**
	 * Get the normalized site domain for activation tracking.
	 *
	 * @return string
	 */
	private static function get_domain() {
		return untrailingslashit( home_url() );
	}
}

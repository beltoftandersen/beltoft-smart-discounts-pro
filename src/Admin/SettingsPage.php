<?php
/**
 * Admin settings tabs for Pro features.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Admin;

defined( 'ABSPATH' ) || exit;

use BeltoftSmartDiscountsPro\Support\Options;
use BeltoftSmartDiscountsPro\Licensing\License;

class SettingsPage {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'bsdisc_admin_tabs', array( __CLASS__, 'add_tabs' ) );
		add_action( 'bsdisc_admin_tab_pro_settings', array( __CLASS__, 'render_pro_settings' ) );
		add_action( 'bsdisc_admin_tab_license', array( __CLASS__, 'render_license' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_pro_settings_save' ) );
	}

	/**
	 * Add Pro tabs to the admin page.
	 *
	 * @param array $tabs Existing tabs.
	 * @return array
	 */
	public static function add_tabs( $tabs ) {
		$tabs['pro_settings'] = __( 'Pro Settings', 'beltoft-smart-discounts-pro' );
		if ( License::is_active() ) {
			$tabs['analytics'] = __( 'Analytics', 'beltoft-smart-discounts-pro' );
		}
		$tabs['license'] = __( 'License', 'beltoft-smart-discounts-pro' );
		return $tabs;
	}

	/**
	 * Register the Pro settings group.
	 */
	public static function register_settings() {
		register_setting(
			'bsdisc_pro_settings_group',
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize' ),
				'default'           => Options::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Handle Pro settings form submission.
	 */
	public static function handle_pro_settings_save() {
		if ( ! isset( $_POST['bsdisc_pro_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsdisc_pro_settings_nonce'] ) ), 'bsdisc_pro_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$input = isset( $_POST[ Options::OPTION ] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST[ Options::OPTION ] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$clean = Options::sanitize( $input );
		Options::set( $clean );

		add_settings_error( 'bsdisc_pro_settings', 'settings_updated', __( 'Pro settings saved.', 'beltoft-smart-discounts-pro' ), 'success' );
	}

	/**
	 * Render the Pro settings tab.
	 */
	public static function render_pro_settings() {
		$opts = Options::get_all();

		settings_errors( 'bsdisc_pro_settings' );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( 'bsdisc_pro_save_settings', 'bsdisc_pro_settings_nonce' ); ?>

			<h2><?php esc_html_e( 'Countdown Timer', 'beltoft-smart-discounts-pro' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="bsdisc-pro-countdown-threshold"><?php esc_html_e( 'Countdown Threshold (hours)', 'beltoft-smart-discounts-pro' ); ?></label></th>
					<td>
						<input type="number" id="bsdisc-pro-countdown-threshold" name="<?php echo esc_attr( Options::OPTION ); ?>[countdown_threshold]" value="<?php echo esc_attr( $opts['countdown_threshold'] ); ?>" min="1" max="720" step="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Show countdown timer when a rule expires within this many hours.', 'beltoft-smart-discounts-pro' ); ?></p>
					</td>
				</tr>
			</table>

				<h2><?php esc_html_e( 'Spending Goal', 'beltoft-smart-discounts-pro' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="bsdisc-pro-spending-goal-mode"><?php esc_html_e( 'Display Mode', 'beltoft-smart-discounts-pro' ); ?></label></th>
						<td>
							<select id="bsdisc-pro-spending-goal-mode" name="<?php echo esc_attr( Options::OPTION ); ?>[spending_goal_display_mode]">
								<option value="auto" <?php selected( $opts['spending_goal_display_mode'], 'auto' ); ?>><?php esc_html_e( 'Automatic only', 'beltoft-smart-discounts-pro' ); ?></option>
								<option value="shortcode" <?php selected( $opts['spending_goal_display_mode'], 'shortcode' ); ?>><?php esc_html_e( 'Shortcode only', 'beltoft-smart-discounts-pro' ); ?></option>
								<option value="both" <?php selected( $opts['spending_goal_display_mode'], 'both' ); ?>><?php esc_html_e( 'Automatic + shortcode', 'beltoft-smart-discounts-pro' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Use "Shortcode only" for page builders/blocks to avoid duplicate progress bars.', 'beltoft-smart-discounts-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="bsdisc-pro-spending-goal-cart"><?php esc_html_e( 'Show Progress Bar on Cart Page', 'beltoft-smart-discounts-pro' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="bsdisc-pro-spending-goal-cart" name="<?php echo esc_attr( Options::OPTION ); ?>[spending_goal_show_on_cart]" value="1" <?php checked( $opts['spending_goal_show_on_cart'], '1' ); ?> />
								<?php esc_html_e( 'Automatically show spending goal progress on the cart page.', 'beltoft-smart-discounts-pro' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><label for="bsdisc-pro-spending-goal-checkout"><?php esc_html_e( 'Show Progress Bar on Checkout Page', 'beltoft-smart-discounts-pro' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="bsdisc-pro-spending-goal-checkout" name="<?php echo esc_attr( Options::OPTION ); ?>[spending_goal_show_on_checkout]" value="1" <?php checked( $opts['spending_goal_show_on_checkout'], '1' ); ?> />
								<?php esc_html_e( 'Automatically show spending goal progress on the checkout page.', 'beltoft-smart-discounts-pro' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Spending Goal Shortcode', 'beltoft-smart-discounts-pro' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'To display the spending goal in a custom location, use the shortcode:', 'beltoft-smart-discounts-pro' ); ?></p>
							<code>[bsdisc_spending_goal]</code>
							<p class="description"><?php esc_html_e( 'Render the spending goal progress bar as inline markup for page builders or custom templates.', 'beltoft-smart-discounts-pro' ); ?></p>
							<p class="description"><?php esc_html_e( 'Uncheck the automatic options above or switch display mode to "Shortcode only" to avoid compatibility issues.', 'beltoft-smart-discounts-pro' ); ?></p>
						</td>
					</tr>
				</table>

			<h2><?php esc_html_e( 'Weekly Report Email', 'beltoft-smart-discounts-pro' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="bsdisc-pro-report-enabled"><?php esc_html_e( 'Enable Report', 'beltoft-smart-discounts-pro' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="bsdisc-pro-report-enabled" name="<?php echo esc_attr( Options::OPTION ); ?>[report_email_enabled]" value="1" <?php checked( $opts['report_email_enabled'], '1' ); ?> />
							<?php esc_html_e( 'Send weekly discount performance summary via email', 'beltoft-smart-discounts-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="bsdisc-pro-report-email"><?php esc_html_e( 'Recipient Email', 'beltoft-smart-discounts-pro' ); ?></label></th>
					<td>
						<input type="text" id="bsdisc-pro-report-email" name="<?php echo esc_attr( Options::OPTION ); ?>[report_email_address]" value="<?php echo esc_attr( $opts['report_email_address'] ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Comma-separated email addresses. Defaults to site admin email.', 'beltoft-smart-discounts-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Advanced', 'beltoft-smart-discounts-pro' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="bsdisc-pro-cleanup"><?php esc_html_e( 'Uninstall', 'beltoft-smart-discounts-pro' ); ?></label></th>
					<td>
						<label>
							<input type="checkbox" id="bsdisc-pro-cleanup" name="<?php echo esc_attr( Options::OPTION ); ?>[cleanup_on_uninstall]" value="1" <?php checked( $opts['cleanup_on_uninstall'], '1' ); ?> />
							<?php esc_html_e( 'Delete all Pro data when plugin is uninstalled', 'beltoft-smart-discounts-pro' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'WARNING: This will permanently delete Pro tables, conditions, and license data.', 'beltoft-smart-discounts-pro' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Import / Export Rules', 'beltoft-smart-discounts-pro' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Export Rules', 'beltoft-smart-discounts-pro' ); ?></th>
					<td>
						<?php
						$export_url = wp_nonce_url(
							admin_url( 'admin-ajax.php?action=bsdisc_pro_export_rules' ),
							'bsdisc_pro_export',
							'nonce'
						);
						?>
						<a class="button" href="<?php echo esc_url( $export_url ); ?>">
							<?php esc_html_e( 'Download CSV', 'beltoft-smart-discounts-pro' ); ?>
						</a>
					</td>
				</tr>
				<tr>
					<th><label for="bsdisc-pro-import-file"><?php esc_html_e( 'Import Rules', 'beltoft-smart-discounts-pro' ); ?></label></th>
					<td>
						<input type="file" id="bsdisc-pro-import-file" accept=".csv,text/csv" />
						<button type="button" id="bsdisc-pro-import-rules" class="button"><?php esc_html_e( 'Import CSV', 'beltoft-smart-discounts-pro' ); ?></button>
						<p class="description"><?php esc_html_e( 'Upload a CSV exported from Smart Discounts.', 'beltoft-smart-discounts-pro' ); ?></p>
						<span id="bsdisc-pro-import-message" class="bsdisc-pro-license-message"></span>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
		wp_enqueue_script(
			'bsdisc-pro-import',
			BSDISC_PRO_URL . 'assets/js/import.js',
			array( 'jquery' ),
			BSDISC_PRO_VER,
			true
		);
		wp_localize_script(
			'bsdisc-pro-import',
			'bsdiscProImport',
			array(
				'nonce'       => wp_create_nonce( 'bsdisc_pro_import' ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'i18n'        => array(
					'chooseFile'    => __( 'Please choose a CSV file.', 'beltoft-smart-discounts-pro' ),
					'importing'     => __( 'Importing...', 'beltoft-smart-discounts-pro' ),
					'importFailed'  => __( 'Import failed.', 'beltoft-smart-discounts-pro' ),
					'requestFailed' => __( 'Request failed. Please try again.', 'beltoft-smart-discounts-pro' ),
				),
			)
		);
	}

	// =========================================================================
	// TAB: License
	// =========================================================================

	/**
	 * Render the license tab.
	 */
	public static function render_license() {
		$opts    = Options::get_all();
		$status  = $opts['license_status'];
		$key     = $opts['license_key'];
		$expires = $opts['license_expires'];

		/* Mask key: show bullets + last 4 characters when activated. */
		$masked_key = '';
		if ( $key ) {
			$masked_key = str_repeat( "\u{2022}", max( 0, strlen( $key ) - 4 ) ) . substr( $key, -4 );
		}
		?>
		<div class="bsdisc-pro-license-section" style="margin-top:16px;max-width:700px;">
			<h2><?php esc_html_e( 'License Key', 'beltoft-smart-discounts-pro' ); ?></h2>

			<div class="bsdisc-pro-license-status-wrap" style="margin-bottom:16px;">
				<?php if ( 'valid' === $status ) : ?>
					<span class="bsdisc-pro-license-badge bsdisc-pro-license-badge--active"><?php esc_html_e( 'Active', 'beltoft-smart-discounts-pro' ); ?></span>
					<?php if ( $expires && 'lifetime' !== $expires ) : ?>
						<span class="bsdisc-pro-license-expires">
							<?php
							printf(
								/* translators: %s: license expiration date */
								esc_html__( 'Expires: %s', 'beltoft-smart-discounts-pro' ),
								esc_html( date_i18n( get_option( 'date_format' ), strtotime( $expires ) ) )
							);
							?>
						</span>
					<?php elseif ( 'lifetime' === $expires ) : ?>
						<span class="bsdisc-pro-license-expires"><?php esc_html_e( 'Lifetime', 'beltoft-smart-discounts-pro' ); ?></span>
					<?php endif; ?>
				<?php elseif ( 'expired' === $status || 'inactive' === $status ) : ?>
					<span class="bsdisc-pro-license-badge bsdisc-pro-license-badge--expired"><?php esc_html_e( 'Inactive', 'beltoft-smart-discounts-pro' ); ?></span>
				<?php else : ?>
					<span class="bsdisc-pro-license-badge bsdisc-pro-license-badge--inactive"><?php esc_html_e( 'Inactive', 'beltoft-smart-discounts-pro' ); ?></span>
				<?php endif; ?>
			</div>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="bsdisc-pro-license-key"><?php esc_html_e( 'License Key', 'beltoft-smart-discounts-pro' ); ?></label>
					</th>
					<td>
						<input type="text"
							   id="bsdisc-pro-license-key"
							   class="regular-text"
							   value="<?php echo esc_attr( $key ? $masked_key : '' ); ?>"
							   <?php echo $key ? 'readonly' : ''; ?>
							   placeholder="<?php esc_attr_e( 'Enter your license key', 'beltoft-smart-discounts-pro' ); ?>"
							   style="margin-bottom:8px;" />

						<?php if ( $key ) : ?>
							<button type="button" id="bsdisc-pro-deactivate-license" class="button">
								<?php esc_html_e( 'Deactivate', 'beltoft-smart-discounts-pro' ); ?>
							</button>
						<?php else : ?>
							<button type="button" id="bsdisc-pro-activate-license" class="button button-primary">
								<?php esc_html_e( 'Activate', 'beltoft-smart-discounts-pro' ); ?>
							</button>
						<?php endif; ?>

						<span id="bsdisc-pro-license-message" class="bsdisc-pro-license-message"></span>
					</td>
				</tr>
			</table>

			<?php if ( $opts['license_last_checked'] ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: date and time of last license check */
						esc_html__( 'Last checked: %s', 'beltoft-smart-discounts-pro' ),
						esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $opts['license_last_checked'] ) ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		wp_enqueue_script(
			'bsdisc-pro-license',
			BSDISC_PRO_URL . 'assets/js/license.js',
			array( 'jquery' ),
			BSDISC_PRO_VER,
			true
		);
		wp_localize_script(
			'bsdisc-pro-license',
			'bsdiscProLicense',
			array(
				'nonce'   => wp_create_nonce( 'bsdisc_pro_license' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'activating'         => __( 'Activating...', 'beltoft-smart-discounts-pro' ),
					'activate'           => __( 'Activate', 'beltoft-smart-discounts-pro' ),
					'deactivating'       => __( 'Deactivating...', 'beltoft-smart-discounts-pro' ),
					'deactivate'         => __( 'Deactivate', 'beltoft-smart-discounts-pro' ),
					'confirmDeactivate'  => __( 'Are you sure you want to deactivate this license?', 'beltoft-smart-discounts-pro' ),
					'requestFailed'      => __( 'Request failed.', 'beltoft-smart-discounts-pro' ),
					'deactivationFailed' => __( 'Deactivation failed.', 'beltoft-smart-discounts-pro' ),
				),
			)
		);
	}
}

<?php
/**
 * Admin integration for Pro-only rule conditions.
 *
 * @package BeltoftSmartDiscountsPro
 */

namespace BeltoftSmartDiscountsPro\Admin;

defined( 'ABSPATH' ) || exit;

class RuleConditions {

	/**
	 * Register hooks.
	 */
	/**
	 * Per-request cache of brand IDs keyed by rule ID.
	 *
	 * @var array|null
	 */
	private static $brand_cache = null;

	public static function init() {
		/* Admin form + save hooks */
		if ( is_admin() ) {
			add_action( 'bsdisc_rule_form_fields', array( __CLASS__, 'render_fields' ), 10, 2 );
			add_action( 'bsdisc_after_save_rule', array( __CLASS__, 'save_conditions' ), 10, 2 );
			add_action( 'bsdisc_after_delete_rule', array( __CLASS__, 'delete_conditions' ) );
		}

		/* Brand support in Applies To */
		add_filter( 'bsdisc_applies_to_options', array( __CLASS__, 'add_brand_applies_to_option' ) );
		add_filter( 'bsdisc_allowed_applies_to', array( __CLASS__, 'add_brand_allowed_applies_to' ) );
		add_filter( 'bsdisc_applies_to_product', array( __CLASS__, 'check_brand_applies' ), 10, 4 );
	}

	/**
	 * Add "Specific Brands" to the Applies To dropdown when brand taxonomy exists.
	 *
	 * @param array $options Applies to options.
	 * @return array
	 */
	public static function add_brand_applies_to_option( $options ) {
		if ( taxonomy_exists( 'product_brand' ) ) {
			$options['specific_brands'] = __( 'Specific Brands', 'beltoft-smart-discounts-pro' );
		}
		return $options;
	}

	/**
	 * Allow "specific_brands" value in sanitization.
	 *
	 * @param array $allowed Allowed applies_to values.
	 * @return array
	 */
	public static function add_brand_allowed_applies_to( $allowed ) {
		$allowed[] = 'specific_brands';
		return $allowed;
	}

	/**
	 * Check if a rule with applies_to=specific_brands matches a product.
	 *
	 * @param bool  $applies      Current applies status.
	 * @param array $rule         Decoded rule.
	 * @param int   $product_id   Product ID.
	 * @param array $category_ids Product category IDs (unused here).
	 * @return bool
	 */
	public static function check_brand_applies( $applies, $rule, $product_id, $category_ids ) {
		if ( 'specific_brands' !== ( $rule['applies_to'] ?? '' ) ) {
			return $applies;
		}

		$brand_ids = self::get_brand_ids_for_rule( (int) $rule['id'] );
		if ( empty( $brand_ids ) ) {
			return false;
		}

		/* Check both product and parent (for variations) */
		$ids_to_check = array( absint( $product_id ) );
		$parent_id    = (int) wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 ) {
			$ids_to_check[] = $parent_id;
		}

		foreach ( $ids_to_check as $pid ) {
			$product_brands = wp_get_object_terms( $pid, 'product_brand', array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $product_brands ) && ! empty( array_intersect( $brand_ids, $product_brands ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render Pro condition fields on the main rule form.
	 *
	 * @param array $rule    Rule data.
	 * @param int   $rule_id Rule ID.
	 */
	public static function render_fields( $rule, $rule_id ) {
		$selected_role = '';
		$url_token     = '';
		$brand_ids     = array();

		if ( $rule_id > 0 ) {
			$selected_role = self::get_condition_value( $rule_id, 'user_role' );
			$url_token     = self::get_condition_value( $rule_id, 'url_token' );

			$brand_json = self::get_condition_value( $rule_id, 'brand_ids' );
			if ( '' !== $brand_json ) {
				$decoded = json_decode( $brand_json, true );
				if ( is_array( $decoded ) ) {
					$brand_ids = array_map( 'intval', $decoded );
				}
			}
		}

		$roles = function_exists( 'wp_roles' ) ? wp_roles()->roles : array();
		?>
		<table class="form-table">
			<?php if ( taxonomy_exists( 'product_brand' ) ) : ?>
			<tr class="bsdisc-field-row bsdisc-field-brand-ids">
				<th><label><?php esc_html_e( 'Brands', 'beltoft-smart-discounts-pro' ); ?></label></th>
				<td>
					<select name="bsdisc_pro_brand_ids[]" multiple="multiple" style="width:400px;">
						<?php
						$brands = get_terms(
							array(
								'taxonomy'   => 'product_brand',
								'hide_empty' => false,
							)
						);
						if ( is_array( $brands ) ) {
							foreach ( $brands as $brand ) {
								printf(
									'<option value="%d"%s>%s</option>',
									(int) $brand->term_id,
									selected( in_array( (int) $brand->term_id, $brand_ids, true ), true, false ),
									esc_html( $brand->name )
								);
							}
						}
						?>
					</select>
					<p class="description"><?php esc_html_e( 'Select brands when Applies To is set to "Specific Brands".', 'beltoft-smart-discounts-pro' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>

			<tr class="bsdisc-field-row bsdisc-field-pro-role-condition">
				<th><label for="bsdisc-pro-user-role"><?php esc_html_e( 'Required User Role', 'beltoft-smart-discounts-pro' ); ?></label></th>
				<td>
					<select id="bsdisc-pro-user-role" name="bsdisc_pro_user_role">
						<option value=""><?php esc_html_e( 'Any role', 'beltoft-smart-discounts-pro' ); ?></option>
						<?php foreach ( $roles as $role_key => $role_data ) : ?>
							<option value="<?php echo esc_attr( $role_key ); ?>" <?php selected( $selected_role, $role_key ); ?>>
								<?php echo esc_html( isset( $role_data['name'] ) ? $role_data['name'] : $role_key ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Used by User Role Discount rules.', 'beltoft-smart-discounts-pro' ); ?></p>
				</td>
			</tr>
			<tr class="bsdisc-field-row bsdisc-field-pro-url-token">
				<th><label for="bsdisc-pro-url-token"><?php esc_html_e( 'URL Discount Token', 'beltoft-smart-discounts-pro' ); ?></label></th>
				<td>
					<input
						type="text"
						id="bsdisc-pro-url-token"
						name="bsdisc_pro_url_token"
						value="<?php echo esc_attr( $url_token ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'vip-launch-2026', 'beltoft-smart-discounts-pro' ); ?>"
					/>
					<p class="description"><?php esc_html_e( 'When set, customers can activate this rule via ?bsdisc_discount=TOKEN.', 'beltoft-smart-discounts-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save Pro conditions after a rule is saved.
	 *
	 * @param int   $rule_id Rule ID.
	 * @param array $rule    Sanitized rule data.
	 */
	public static function save_conditions( $rule_id, $rule ) {
		$rule_id = absint( $rule_id );
		if ( $rule_id <= 0 ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bsdisc_pro_rule_conditions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE rule_id = %d AND condition_type IN ('user_role', 'url_token', 'brand_ids')",
				$rule_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$role = isset( $_POST['bsdisc_pro_user_role'] ) ? sanitize_key( wp_unslash( $_POST['bsdisc_pro_user_role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in RulesTab::handle_actions
		if ( 'role' === ( $rule['type'] ?? '' ) && '' !== $role ) {
			self::insert_condition( $rule_id, 'user_role', $role, 'equals' );
		}

		$token_raw = isset( $_POST['bsdisc_pro_url_token'] ) ? sanitize_text_field( wp_unslash( $_POST['bsdisc_pro_url_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in RulesTab::handle_actions
		$token     = preg_replace( '/[^a-zA-Z0-9_-]/', '', $token_raw );
		$token     = is_string( $token ) ? $token : '';
		if ( '' !== $token ) {
			self::insert_condition( $rule_id, 'url_token', $token, 'equals' );
		}

		/* Brand IDs */
		$brand_ids_raw = isset( $_POST['bsdisc_pro_brand_ids'] ) ? array_map( 'absint', (array) $_POST['bsdisc_pro_brand_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in RulesTab::handle_actions
		$brand_ids_raw = array_filter( $brand_ids_raw );
		if ( ! empty( $brand_ids_raw ) ) {
			self::insert_condition( $rule_id, 'brand_ids', wp_json_encode( array_values( $brand_ids_raw ) ), 'in' );
		}
	}

	/**
	 * Delete all Pro conditions for a deleted rule.
	 *
	 * @param int $rule_id Rule ID.
	 */
	public static function delete_conditions( $rule_id ) {
		$rule_id = absint( $rule_id );
		if ( $rule_id <= 0 ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bsdisc_pro_rule_conditions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE rule_id = %d",
				$rule_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Read a single condition value for a rule.
	 *
	 * @param int    $rule_id        Rule ID.
	 * @param string $condition_type Condition type.
	 * @return string
	 */
	private static function get_condition_value( $rule_id, $condition_type ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bsdisc_pro_rule_conditions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT condition_value FROM {$table} WHERE rule_id = %d AND condition_type = %s ORDER BY id ASC LIMIT 1",
				$rule_id,
				$condition_type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Load brand IDs for a rule from the conditions table.
	 *
	 * @param int $rule_id Rule ID.
	 * @return array
	 */
	public static function get_brand_ids_for_rule( $rule_id ) {
		if ( null === self::$brand_cache ) {
			self::$brand_cache = array();

			global $wpdb;
			$table = $wpdb->prefix . 'bsdisc_pro_rule_conditions';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				"SELECT rule_id, condition_value FROM {$table} WHERE condition_type = 'brand_ids'",
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$decoded = json_decode( $row['condition_value'], true );
					if ( is_array( $decoded ) ) {
						self::$brand_cache[ (int) $row['rule_id'] ] = array_map( 'intval', $decoded );
					}
				}
			}
		}

		return isset( self::$brand_cache[ $rule_id ] ) ? self::$brand_cache[ $rule_id ] : array();
	}

	/**
	 * Insert one condition row.
	 *
	 * @param int    $rule_id Rule ID.
	 * @param string $type    Condition type.
	 * @param string $value   Condition value.
	 * @param string $op      Operator.
	 */
	private static function insert_condition( $rule_id, $type, $value, $op = 'equals' ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'bsdisc_pro_rule_conditions',
			array(
				'rule_id'         => (int) $rule_id,
				'condition_type'  => (string) $type,
				'condition_value' => (string) $value,
				'operator'        => (string) $op,
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}
}

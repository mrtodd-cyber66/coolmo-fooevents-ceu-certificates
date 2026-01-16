<?php
namespace CoolMo\FooEventsCEU;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CoolMo CEU — Frontend behavior
 *
 * Responsibilities:
 * - Render required CEU Yes/No option on product page when CEUs are enabled
 * - Render optional JPA Member checkbox if allowed for this event
 * - Determine pricing status (member / nonmember) based on:
 *     1) JPA checkbox (if checked, always member)
 *     2) MemberPress membership (MeprUtils::is_user_active)
 *     3) Member roles (including administrator + custom member roles via filter)
 * - Add CEU add-on price to the line item when “Yes” is selected
 * - Adjust cart item price by a member discount DELTA, preserving other add-ons
 * - Persist CEU and JPA metadata into the order for reporting
 */

class CEU_Frontend {

	/* =========================================================
	   SECTION 1 — BOOTSTRAP
	========================================================= */
	public static function init() {

		// Product page controls
		add_action( 'woocommerce_before_add_to_cart_button', [ __CLASS__, 'render_ceu_controls' ], 25 );

		// Capture CEU & JPA choices when item added to cart
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'add_cart_item_data' ], 10, 3 );

		// Show CEU info in cart/checkout UI
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_cart_item_data' ], 10, 2 );

		// Adjust cart item prices (base member/JPA + CEU add-on)
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'maybe_adjust_cart_item_prices' ], 10 );

		// Save CEU/JPA metadata on order items
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'add_order_item_meta' ], 10, 4 );
	}

	/* =========================================================
	   SECTION 2 — LOW-LEVEL HELPERS
	========================================================= */

	protected static function product_has_ceu( $product_id ) {
		$enabled = get_post_meta( $product_id, '_coolmo_ceu_enabled', true );
		return ( $enabled === 'yes' );
	}

	protected static function product_allows_jpa( $product_id ) {
		$flag = get_post_meta( $product_id, '_coolmo_jpa_allowed', true );
		return ( $flag === 'yes' );
	}

	protected static function is_memberpress_active_member( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}

		if ( ! class_exists( '\MeprUtils' ) ) {
			return false;
		}

		if ( ! method_exists( '\MeprUtils', 'is_user_active' ) ) {
			return false;
		}

		try {
			return (bool) \MeprUtils::is_user_active( $user_id );
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Check if user has a "member-ish" role.
	 * Includes administrator so admin-members still get member pricing.
	 * Roles are filterable.
	 */
	protected static function user_has_member_role( $user_id ) {

		if ( ! $user_id ) {
			return false;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		$user_roles   = (array) $user->roles;
		$member_roles = apply_filters(
			'coolmo_ceu_member_roles',
			[
				'administrator',        // admins should get member pricing
				'regular_members',
				'sustaining_members',
				'student_members',
				'student_senior_member',
				'lifetime',
				'senior_members',
				// Add or adjust via filter for site-specific roles
			]
		);

		foreach ( $member_roles as $role ) {
			if ( in_array( $role, $user_roles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Master "am I a member for pricing?" decision.
	 * Priority:
	 *   1) JPA checkbox (per-cart-item)
	 *   2) MemberPress active membership
	 *   3) Member roles (including admin)
	 */
	protected static function user_is_member_for_pricing( $user_id, $jpa_member_flag ) {

		// JPA checkbox overrides everything for this cart line.
		if ( 'yes' === $jpa_member_flag ) {
			return true;
		}

		// Real MemberPress membership?
		if ( self::is_memberpress_active_member( $user_id ) ) {
			return true;
		}

		// Member-ish roles (including administrators).
		if ( self::user_has_member_role( $user_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Returns array with CEU add-on prices:
	 * [ 'member' => float, 'nonmember' => float ]
	 */
	protected static function get_ceu_prices( $product_id ) {
		$member    = get_post_meta( $product_id, '_coolmo_ceu_price_members', true );
		$nonmember = get_post_meta( $product_id, '_coolmo_ceu_price_nonmembers', true );

		$member    = ( $member    !== '' ) ? (float) $member    : 0.0;
		$nonmember = ( $nonmember !== '' ) ? (float) $nonmember : 0.0;

		return [
			'member'    => $member,
			'nonmember' => $nonmember,
		];
	}

	/**
	 * Member status for display (MemberPress only).
	 * JPA override is per-cart-item and not used for initial display.
	 */
	protected static function current_member_status_for_display() {
		$user_id = get_current_user_id();
		return self::is_memberpress_active_member( $user_id ) ? 'member' : 'nonmember';
	}

	/* =========================================================
	   SECTION 3 — PRODUCT PAGE CONTROLS
	   (CEU radios only if CEUs enabled; JPA checkbox can show independently)
	========================================================= */
	public static function render_ceu_controls() {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$product_id     = $product->get_id();
		$has_ceu        = self::product_has_ceu( $product_id );
		$jpa_is_allowed = self::product_allows_jpa( $product_id );

		// If no CEUs and no JPA override, nothing to show.
		if ( ! $has_ceu && ! $jpa_is_allowed ) {
			return;
		}

		$member_ceu_label    = '';
		$nonmember_ceu_label = '';

		if ( $has_ceu ) {
			$prices              = self::get_ceu_prices( $product_id );
			$member_ceu          = $prices['member'];
			$nonmember_ceu       = $prices['nonmember'];
			$member_ceu_label    = wc_price( $member_ceu );
			$nonmember_ceu_label = wc_price( $nonmember_ceu );
		}

		?>
		<div class="coolmo-ceu-controls" style="margin:1em 0;">

			<?php if ( $has_ceu ) : ?>
				<p><strong><?php esc_html_e( 'Would you like to add CEUs?', 'coolmo-fooevents-ceu-certificates' ); ?></strong></p>

				<label style="display:block;margin-bottom:4px;">
					<input type="radio"
					       name="coolmo_ceu_add"
					       value="yes"
					       required />
					<?php
					printf(
						/* translators: 1: member CEU price, 2: non-member CEU price */
						esc_html__( 'Yes, add CEUs (Member %1$s, Non-member %2$s)', 'coolmo-fooevents-ceu-certificates' ),
						wp_kses_post( $member_ceu_label ),
						wp_kses_post( $nonmember_ceu_label )
					);
					?>
				</label>

				<label style="display:block;">
					<input type="radio"
					       name="coolmo_ceu_add"
					       value="no"
					       required />
					<?php esc_html_e( 'No, do not add CEUs', 'coolmo-fooevents-ceu-certificates' ); ?>
				</label>
			<?php endif; ?>

			<?php if ( $jpa_is_allowed ) : ?>
				<div class="coolmo-jpa-wrap" style="margin-top:0.75em;">
					<label>
						<input type="checkbox"
						       name="coolmo_jpa_member"
						       value="yes" />
						<?php esc_html_e( 'JPA Member', 'coolmo-fooevents-ceu-certificates' ); ?>
					</label>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =========================================================
	   SECTION 4 — CART ITEM DATA
	   Capture CEU & JPA selections and effective pricing status
	========================================================= */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {

		// CEU Yes/No (only present when CEUs are enabled; default to "no").
		$choice = isset( $_POST['coolmo_ceu_add'] )
			? sanitize_text_field( wp_unslash( $_POST['coolmo_ceu_add'] ) )
			: 'no';

		// JPA checkbox (optional; may be present even if no CEUs).
		$jpa_member = ! empty( $_POST['coolmo_jpa_member'] ) ? 'yes' : 'no';

		// Compute base pricing status at time of add-to-cart.
		$user_id         = get_current_user_id();
		$is_member       = self::user_is_member_for_pricing( $user_id, $jpa_member );
		$pricing_status  = $is_member ? 'member' : 'nonmember';

		// Store JPA override flag and pricing status for this line item.
		$cart_item_data['coolmo_jpa_member']     = $jpa_member;
		$cart_item_data['coolmo_pricing_status'] = $pricing_status;

		// CEU-specific data.
		if ( 'yes' === $choice ) {
			$prices    = self::get_ceu_prices( $product_id );
			$ceu_price = ( 'member' === $pricing_status ) ? $prices['member'] : $prices['nonmember'];

			$cart_item_data['coolmo_ceu'] = [
				'selected'      => 'yes',
				'price'         => $ceu_price,
				'member_status' => $pricing_status,
			];
		} else {
			// Record that CEUs were not added (or not applicable).
			$cart_item_data['coolmo_ceu'] = [
				'selected'      => 'no',
				'price'         => 0.0,
				'member_status' => $pricing_status,
			];
		}

		return $cart_item_data;
	}

	/* =========================================================
	   SECTION 5 — DISPLAY CART ITEM DATA (CEU & JPA)
	========================================================= */
	public static function display_cart_item_data( $item_data, $cart_item ) {

		// Show CEU choice if selected.
		if ( ! empty( $cart_item['coolmo_ceu'] ) && 'yes' === $cart_item['coolmo_ceu']['selected'] ) {
			$ceu_price = isset( $cart_item['coolmo_ceu']['price'] )
				? (float) $cart_item['coolmo_ceu']['price']
				: 0.0;

			$item_data[] = [
				'name'  => __( 'CEUs Added', 'coolmo-fooevents-ceu-certificates' ),
				'value' => wc_price( $ceu_price ),
			];
		}

		// Show JPA info if flag present.
		if ( ! empty( $cart_item['coolmo_jpa_member'] ) && 'yes' === $cart_item['coolmo_jpa_member'] ) {
			$item_data[] = [
				'name'  => __( 'JPA Member', 'coolmo-fooevents-ceu-certificates' ),
				'value' => __( 'Yes', 'coolmo-fooevents-ceu-certificates' ),
			];
		}

		return $item_data;
	}

	/* =========================================================
	   SECTION 6 — CART PRICE ADJUSTMENT
	   Apply base member/JPA pricing + CEU add-on per item
	   IMPORTANT: Uses a DELTA so Woo Add-Ons and other modifiers still apply.
	========================================================= */
	public static function maybe_adjust_cart_item_prices( $cart ) {

		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {

			// Avoid double-adjusting if totals are recalculated.
			if ( ! empty( $cart_item['coolmo_price_adjusted'] ) ) {
				continue;
			}

			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof \WC_Product ) {
				continue;
			}

			/** @var \WC_Product $product */
			$product    = $cart_item['data'];
			$product_id = $product->get_id();

			// Determine pricing status stored at add-to-cart time (member / nonmember).
			$pricing_status = isset( $cart_item['coolmo_pricing_status'] )
				? $cart_item['coolmo_pricing_status']
				: 'nonmember';

			// WooCommerce base regular price (no add-ons, no CEU).
			$regular_price = (float) $product->get_regular_price();

			// Member base price (if defined).
			$member_base_price_meta = get_post_meta( $product_id, '_coolmo_member_price', true );
			$member_base_price      = ( $member_base_price_meta !== '' ) ? (float) $member_base_price_meta : null;

			// Start from current price, which may already include:
			// - regular price
			// - Woo Add-Ons / fees / other modifiers
			$current_price = (float) $product->get_price();
			$new_price     = $current_price;

			// Apply member discount as a DELTA so we preserve other add-ons.
			if ( 'member' === $pricing_status && null !== $member_base_price && $regular_price > 0 ) {

				$delta = $regular_price - $member_base_price; // positive when member price is cheaper

				if ( $delta > 0 ) {
					$new_price = max( 0, $current_price - $delta );
				}
			}

			// CEU add-on (if selected) — this is ONLY our CEU fee, not other add-ons.
			$ceu_addon = 0.0;
			if ( ! empty( $cart_item['coolmo_ceu'] ) && 'yes' === $cart_item['coolmo_ceu']['selected'] ) {
				$ceu_addon = isset( $cart_item['coolmo_ceu']['price'] )
					? (float) $cart_item['coolmo_ceu']['price']
					: 0.0;
			}

			$new_price += $ceu_addon;

			// Apply final price per item.
			$product->set_price( $new_price );

			// Mark as adjusted to avoid double-processing on recalculations.
			$cart_item['coolmo_price_adjusted'] = true;
		}
	}

	/* =========================================================
	   SECTION 7 — ORDER ITEM META
	========================================================= */
	public static function add_order_item_meta( $item, $cart_item_key, $values, $order ) {

		// CEU info.
		if ( ! empty( $values['coolmo_ceu'] ) ) {
			$ceu = $values['coolmo_ceu'];

			$item->add_meta_data(
				'_coolmo_ceu_selected',
				isset( $ceu['selected'] ) ? $ceu['selected'] : 'no',
				true
			);
			$item->add_meta_data(
				'_coolmo_ceu_price',
				isset( $ceu['price'] ) ? (float) $ceu['price'] : 0.0,
				true
			);
			$item->add_meta_data(
				'_coolmo_ceu_member_status_at_purchase',
				isset( $ceu['member_status'] ) ? $ceu['member_status'] : 'nonmember',
				true
			);
		}

		// JPA override flag.
		if ( ! empty( $values['coolmo_jpa_member'] ) && 'yes' === $values['coolmo_jpa_member'] ) {
			$item->add_meta_data( '_coolmo_jpa_member', 'yes', true );
		} else {
			$item->add_meta_data( '_coolmo_jpa_member', 'no', true );
		}

		// Pricing status snapshot.
		if ( ! empty( $values['coolmo_pricing_status'] ) ) {
			$item->add_meta_data(
				'_coolmo_pricing_status_at_purchase',
				$values['coolmo_pricing_status'],
				true
			);
		}
	}
}

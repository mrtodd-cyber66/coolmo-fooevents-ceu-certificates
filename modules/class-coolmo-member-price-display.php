<?php
namespace CoolMo\FooEventsCEU;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CoolMo — Member Price Display
 *
 * Always shows the member price line on product pages
 * when the field _coolmo_member_price is defined.
 *
 * Does NOT affect cart totals — CEU_Frontend handles pricing.
 */
class Member_Price_Display {

	public static function init() {
		add_filter( 'woocommerce_get_price_html', [ __CLASS__, 'filter_price_html' ], 20, 2 );
	}

	/**
	 * Append "Member Price: $XX.XX" to WooCommerce price output
	 * ONLY ONCE per product per request.
	 */
	public static function filter_price_html( $price_html, $product ) {

		if ( ! $product instanceof \WC_Product ) {
			return $price_html;
		}

		$product_id = (int) $product->get_id();
		if ( ! $product_id ) {
			return $price_html;
		}

		// ✅ Guard: Woo/Elementor may call price_html multiple times per request
		// with the ORIGINAL $price_html each time. This prevents double-append.
		static $already_appended = [];
		if ( isset( $already_appended[ $product_id ] ) ) {
			return $price_html;
		}

		$member_price_meta = get_post_meta( $product_id, '_coolmo_member_price', true );
		if ( $member_price_meta === '' || $member_price_meta === null ) {
			return $price_html;
		}

		$already_appended[ $product_id ] = true;

		$member_price_html = wc_price( (float) $member_price_meta );

		$member_line =
			'<br><small class="coolmo-member-price" style="color:#064;">' .
			esc_html__( 'Member Price:', 'coolmo-fooevents-ceu-certificates' ) . ' ' .
			$member_price_html .
			'</small>';

		return $price_html . $member_line;
	}
}

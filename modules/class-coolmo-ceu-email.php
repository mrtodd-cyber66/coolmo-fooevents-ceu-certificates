<?php
namespace CoolMo\FooEventsCEU;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEU_Email {

	public static function init() {
		add_action( 'fooevents_checkin', array( __CLASS__, 'on_checkin' ), 10, 4 );
	}

	/**
	 * Triggered when a ticket is checked in.
	 * Sends CEU Evaluation email with event details and license number.
	 */
	public static function on_checkin( $attendee_id, $order_id, $event_id, $product_id ) {
		$attendee_email = self::get_attendee_email( $attendee_id, $order_id );
		if ( ! $attendee_email ) {
			return;
		}

		/* ============================================================
		   HARD STOP — MUST HAVE CEU SELECTED ON ORDER ITEM
		   Meta: _coolmo_ceu_selected = yes/1/true
		============================================================ */
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( intval( $order_id ) );
			if ( ! $order ) {
				return;
			}

			$has_ceu = false;
			foreach ( $order->get_items() as $item ) {
				if ( intval( $item->get_product_id() ) !== intval( $product_id ) ) {
					continue;
				}
				$val = $item->get_meta( '_coolmo_ceu_selected', true );
				if ( $val && in_array( strtolower( (string) $val ), array( 'yes', '1', 'true' ), true ) ) {
					$has_ceu = true;
					break;
				}
			}

			if ( ! $has_ceu ) {
				return;
			}
		} else {
			return;
		}


		$attendee_name = self::get_attendee_name( $attendee_id, $order_id );
		$event_title   = get_post_meta( $product_id, '_coolmo_event_title', true ) ?: get_the_title( $product_id );

		// Corrected event date logic for FooEvents recurring / single-day
		$event_date = get_post_meta( $attendee_id, 'WooCommerceEventsBookingDate', true )
					?: get_post_meta( $attendee_id, 'WooCommerceEventsBookingDateMySQLFormat', true )
					?: get_post_meta( $product_id, 'WooCommerceEventsDate', true )
					?: get_post_meta( $product_id, 'WooCommerceEventsDateMySQLFormat', true );

		$meta = \coolmo_ceu_get_meta( (int) $product_id );

		// Build base evaluation URL
		$base_url = trailingslashit( home_url( '/ceu-evaluation-form/' ) );
		$eval_url = \coolmo_ceu_build_eval_url( $base_url, (int) $product_id );

		// License number lookup
		$license_number = '';
		$user_id = 0;

		if ( function_exists( 'email_exists' ) ) {
			$user_id = email_exists( $attendee_email );
		} else {
			$user = get_user_by( 'email', $attendee_email );
			if ( $user ) {
				$user_id = $user->ID;
			}
		}

		if ( $user_id ) {
			$license_number = get_user_meta( $user_id, 'coolmo_license', true );
		}

		if ( ! empty( $license_number ) ) {
			$separator = ( strpos( $eval_url, '?' ) !== false ) ? '&' : '?';
			$eval_url .= $separator . 'license=' . rawurlencode( $license_number );
		}

		// Compose subject
		$subject = sprintf( __( 'CEU Evaluation for %s', 'coolmo-fooevents-ceu-certificates' ), $event_title );

		// Start HTML message
		$message  = '<p>' . sprintf( __( 'Hello %s,', 'coolmo-fooevents-ceu-certificates' ), esc_html( $attendee_name ) ) . '</p>';

		if ( $event_date ) {
			$message .= '<p>' . sprintf(
				__( 'Thank you for attending <strong>%1$s</strong> on %2$s.', 'coolmo-fooevents-ceu-certificates' ),
				esc_html( $event_title ),
				esc_html( $event_date )
			) . '</p>';
		} else {
			$message .= '<p>' . sprintf(
				__( 'Thank you for attending <strong>%s</strong>.', 'coolmo-fooevents-ceu-certificates' ),
				esc_html( $event_title )
			) . '</p>';
		}

		$message .= '<p>' . __( 'Please complete your CEU Evaluation using the link below:', 'coolmo-fooevents-ceu-certificates' ) . '</p>';
		$message .= '<p><a href="' . esc_url( $eval_url ) . '">' . esc_html( $eval_url ) . '</a></p>';

		if ( ! empty( $meta['_coolmo_ceu_number'] ) ) {
			$message .= '<p><strong>' . __( 'Number of CEUs:', 'coolmo-fooevents-ceu-certificates' ) . '</strong> ' . esc_html( $meta['_coolmo_ceu_number'] ) . '</p>';
		}
		if ( ! empty( $meta['_coolmo_instructor_name'] ) ) {
			$message .= '<p><strong>' . __( 'Instructor:', 'coolmo-fooevents-ceu-certificates' ) . '</strong> ' . esc_html( $meta['_coolmo_instructor_name'] ) . '</p>';
		}

		$message .= '<p>— The Jung Society of Seattle</p>';

		// Send HTML email
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $attendee_email, $subject, $message, $headers );
	}

	/**
	 * Get attendee email from ticket or order data.
	 */
	protected static function get_attendee_email( $attendee_id, $order_id ) {
		foreach ( [ 'WooCommerceEventsAttendeeEmail', 'WooCommerceEventsPurchaserEmail' ] as $key ) {
			$email = $attendee_id ? get_post_meta( $attendee_id, $key, true ) : '';
			if ( $email ) {
				return sanitize_email( $email );
			}
		}

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				return sanitize_email( $order->get_billing_email() );
			}
		}

		return '';
	}

	/**
	 * Get attendee name from ticket or order data.
	 */
	protected static function get_attendee_name( $attendee_id, $order_id ) {
		foreach ( [ 'WooCommerceEventsAttendeeName', 'WooCommerceEventsFirstName', 'WooCommerceEventsPurchaserFirstName' ] as $key ) {
			$val = $attendee_id ? get_post_meta( $attendee_id, $key, true ) : '';
			if ( $val ) {
				return sanitize_text_field( $val );
			}
		}

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				return sanitize_text_field(
					trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
				);
			}
		}

		return '';
	}
}
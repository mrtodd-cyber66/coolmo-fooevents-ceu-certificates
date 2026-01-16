<?php
/*
 * Module: CoolMo CEU Corrections
 * Version: 1.0.0
 *
 * Purpose:
 * - Admin-only tool to retroactively mark whether an attendee purchased CEUs
 *   when admins accidentally used the old add-on process.
 * - This bypass is intentional and is meant to be rare.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ===========================================================
   SECTION 1 — ADMIN MENU SETUP
=========================================================== */
add_action( 'admin_menu', function() {
	global $admin_page_hooks;

	if ( isset( $admin_page_hooks['coolmo-assistant'] ) ) {
		add_submenu_page(
			'coolmo-assistant',
			'CEU Corrections',
			'CEU Corrections',
			'manage_woocommerce',
			'coolmo-ceu-corrections',
			'coolmo_ceu_corrections_page'
		);
	}
}, 16 );

/* ===========================================================
   SECTION 2 — CEU EVENT DISCOVERY (PRODUCT META)
   Meta key: _coolmo_ceu_enabled = 'yes'
=========================================================== */
function coolmo_ceu_corrections_get_events() {
	return function_exists( 'coolmo_event_checkin_get_ceu_events' )
		? coolmo_event_checkin_get_ceu_events()
		: array();
}

/* ===========================================================
   SECTION 3 — PAGE WRAPPER
=========================================================== */
function coolmo_ceu_corrections_page() {

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'coolmo' ) );
	}

	/* ---------- SAVE HANDLER ---------- */
	if ( isset( $_POST['coolmo_ceu_corrections_update'] ) ) {
		check_admin_referer( 'coolmo_ceu_corrections_action', 'coolmo_ceu_corrections_nonce' );

		$event_id = intval( $_POST['coolmo_event_id'] ?? 0 );
		$checked  = array_map( 'intval', (array) ( $_POST['ticket_ids_with_ceu'] ?? array() ) );

		$result = coolmo_ceu_corrections_apply( $event_id, $checked );

		echo "<div class='updated'><p>" . esc_html( $result ) . "</p></div>";
	}

	echo '<div class="wrap"><h1>CEU Corrections</h1>';
	echo '<p>Select an event, load ticket holders, then check/uncheck CEU for each person and click Update.</p>';

	$events = coolmo_ceu_corrections_get_events();

	if ( empty( $events ) ) {
		echo '<p><em>No products with CEUs enabled were found.</em></p>';
		echo '</div>';
		return;
	}

	$current_event = isset( $_GET['coolmo_event_id'] ) ? intval( $_GET['coolmo_event_id'] ) : 0;

	echo '<form method="get" style="margin-bottom:12px;">';
	echo '<input type="hidden" name="page" value="coolmo-ceu-corrections">';
	echo '<label><strong>Select Event:</strong></label> ';
	echo '<select name="coolmo_event_id"><option value="">— Select —</option>';

	foreach ( $events as $ev ) {
		$sel = selected( $current_event, $ev->ID, false );
		echo "<option value='" . esc_attr( $ev->ID ) . "' {$sel}>" . esc_html( $ev->post_title ) . "</option>";
	}

	submit_button( 'Load Attendees', 'secondary', '', false );
	echo '</form>';

	if ( $current_event ) {
		coolmo_ceu_corrections_render_table( $current_event );
	}

	echo '</div>';
}

/* ===========================================================
   SECTION 4 — LOAD ALL TICKETS FOR AN EVENT (NO CEU FILTER)
=========================================================== */
function coolmo_ceu_corrections_get_tickets_for_event( $event_id ) {
	global $wpdb;

	$event_id = intval( $event_id );
	if ( ! $event_id ) {
		return array();
	}

	$sql = "
		SELECT 
			t.ID AS ticket_id,

			-- Attendee info
			pm_att_name.meta_value   AS attendee_name,
			pm_att_email.meta_value  AS attendee_email,

			-- Purchaser info
			pm_pur_first.meta_value  AS purchaser_first_name,
			pm_pur_last.meta_value   AS purchaser_last_name,
			pm_pur_email.meta_value  AS purchaser_email,

			-- Order ID
			pm_order.meta_value      AS order_id,

			-- Booking date (purchase date-ish)
			pm_booking_date.meta_value AS booking_date

		FROM {$wpdb->posts} t

		JOIN {$wpdb->postmeta} pm_product 
			ON pm_product.post_id = t.ID
			AND pm_product.meta_key = 'WooCommerceEventsProductID'
			AND pm_product.meta_value = %d

		LEFT JOIN {$wpdb->postmeta} pm_booking_date 
			ON pm_booking_date.post_id = t.ID
			AND pm_booking_date.meta_key = 'WooCommerceEventsBookingDateMySQLFormat'

		LEFT JOIN {$wpdb->postmeta} pm_att_name 
			ON pm_att_name.post_id = t.ID AND pm_att_name.meta_key = 'WooCommerceEventsAttendeeName'
		LEFT JOIN {$wpdb->postmeta} pm_att_email 
			ON pm_att_email.post_id = t.ID AND pm_att_email.meta_key = 'WooCommerceEventsAttendeeEmail'

		LEFT JOIN {$wpdb->postmeta} pm_pur_first 
			ON pm_pur_first.post_id = t.ID AND pm_pur_first.meta_key = 'WooCommerceEventsPurchaserFirstName'
		LEFT JOIN {$wpdb->postmeta} pm_pur_last 
			ON pm_pur_last.post_id = t.ID AND pm_pur_last.meta_key = 'WooCommerceEventsPurchaserLastName'
		LEFT JOIN {$wpdb->postmeta} pm_pur_email 
			ON pm_pur_email.post_id = t.ID AND pm_pur_email.meta_key = 'WooCommerceEventsPurchaserEmail'

		LEFT JOIN {$wpdb->postmeta} pm_order 
			ON pm_order.post_id = t.ID AND pm_order.meta_key = 'WooCommerceEventsOrderID'

		WHERE t.post_type = 'event_magic_tickets'
		ORDER BY pm_booking_date.meta_value, t.ID
	";

	return $wpdb->get_results( $wpdb->prepare( $sql, $event_id ) );
}

/* ===========================================================
   SECTION 5 — IS CEU SELECTED? (ORDER ITEM META + TICKET META)
=========================================================== */
function coolmo_ceu_corrections_ticket_has_ceu( $ticket_id, $order_id, $event_id ) {

	// 1) Ticket post meta (fast)
	$tmeta = get_post_meta( $ticket_id, '_coolmo_ceu_selected', true );
	if ( $tmeta && in_array( strtolower( (string) $tmeta ), array( 'yes','1','true' ), true ) ) {
		return true;
	}

	// 2) Order item meta (source of truth for your CEU gate)
	if ( ! function_exists( 'wc_get_order' ) || ! $order_id ) {
		return false;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return false;
	}

	foreach ( $order->get_items() as $item ) {
		if ( intval( $item->get_product_id() ) !== intval( $event_id ) ) {
			continue;
		}

		$val = $item->get_meta( '_coolmo_ceu_selected', true );
		if ( $val && in_array( strtolower( (string) $val ), array( 'yes','1','true' ), true ) ) {
			return true;
		}
	}

	return false;
}

/* ===========================================================
   SECTION 6 — TABLE RENDERING
=========================================================== */
function coolmo_ceu_corrections_render_table( $event_id ) {

	$rows = coolmo_ceu_corrections_get_tickets_for_event( $event_id );

	echo '<hr><h2>Ticket Holders for: <em>' . esc_html( get_the_title( $event_id ) ) . '</em></h2>';

	if ( empty( $rows ) ) {
		echo '<p><em>No tickets found for this event.</em></p>';
		return;
	}

	echo '<form method="post">';
	wp_nonce_field( 'coolmo_ceu_corrections_action', 'coolmo_ceu_corrections_nonce' );

	echo '<input type="hidden" name="coolmo_event_id" value="' . esc_attr( $event_id ) . '">';

	echo '<p><button type="submit" class="button-primary" name="coolmo_ceu_corrections_update">Update CEU Flags</button></p>';

	echo '<table class="widefat striped">';
	echo '<thead><tr>
		<th style="width:40px;">CEU</th>
		<th>Name</th>
		<th>Email</th>
		<th>Event Date</th>
		<th>Order ID</th>
		<th>Ticket ID</th>
	</tr></thead><tbody>';

	foreach ( $rows as $row ) {

		$ticket_id = intval( $row->ticket_id );
		$order_id  = intval( $row->order_id );

		$name = trim( (string) $row->attendee_name );
		if ( ! $name ) {
			$name = trim( (string) $row->purchaser_first_name . ' ' . (string) $row->purchaser_last_name );
		}

		$email = $row->attendee_email ?: $row->purchaser_email;
		$date  = $row->booking_date ?: '—';

		$has_ceu = coolmo_ceu_corrections_ticket_has_ceu( $ticket_id, $order_id, $event_id );
		$checked = $has_ceu ? 'checked' : '';

		echo '<tr>';
		echo '<td style="text-align:center;"><input type="checkbox" name="ticket_ids_with_ceu[]" value="' . esc_attr( $ticket_id ) . '" ' . $checked . '></td>';
		echo '<td>' . esc_html( $name ?: '—' ) . '</td>';
		echo '<td>' . esc_html( $email ?: '—' ) . '</td>';
		echo '<td>' . esc_html( $date ) . '</td>';
		echo '<td>' . esc_html( $order_id ?: '—' ) . '</td>';
		echo '<td>' . esc_html( $ticket_id ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	echo '<p><button type="submit" class="button-primary" name="coolmo_ceu_corrections_update">Update CEU Flags</button></p>';
	echo '</form>';

	echo '<p style="margin-top:12px;"><em>Note:</em> This tool updates BOTH the order item meta and the ticket meta so the correction is reversible.</p>';
}

/* ===========================================================
   SECTION 7 — APPLY UPDATES
   Behavior:
     - For each ticket in the event:
         - If ticket ID is in the checked list: set CEU yes
         - Else: remove CEU
     - Updates both:
         a) ticket postmeta: _coolmo_ceu_selected
         b) order item meta: _coolmo_ceu_selected
=========================================================== */
function coolmo_ceu_corrections_apply( $event_id, array $checked_ticket_ids ) {

	$event_id = intval( $event_id );
	if ( ! $event_id ) {
		return 'No event selected.';
	}

	$checked_ticket_ids = array_values( array_unique( array_map( 'intval', $checked_ticket_ids ) ) );
	$checked_map        = array_flip( $checked_ticket_ids );

	$tickets = coolmo_ceu_corrections_get_tickets_for_event( $event_id );
	if ( empty( $tickets ) ) {
		return 'No tickets found to update.';
	}

	$updated_yes = 0;
	$updated_no  = 0;

	foreach ( $tickets as $t ) {

		$ticket_id = intval( $t->ticket_id );
		$order_id  = intval( $t->order_id );

		$should_have_ceu = isset( $checked_map[ $ticket_id ] );

		// Update ticket post meta
		if ( $should_have_ceu ) {
			update_post_meta( $ticket_id, '_coolmo_ceu_selected', 'yes' );
		} else {
			delete_post_meta( $ticket_id, '_coolmo_ceu_selected' );
		}

		// Update order item meta
		if ( function_exists( 'wc_get_order' ) && $order_id ) {

			$order = wc_get_order( $order_id );
			if ( $order ) {

				$changed = false;

				foreach ( $order->get_items() as $item ) {
					if ( intval( $item->get_product_id() ) !== $event_id ) {
						continue;
					}

					if ( $should_have_ceu ) {
						$item->update_meta_data( '_coolmo_ceu_selected', 'yes' );
					} else {
						$item->delete_meta_data( '_coolmo_ceu_selected' );
					}

					$changed = true;
				}

				if ( $changed ) {
					$order->save();
				}
			}
		}

		if ( $should_have_ceu ) {
			$updated_yes++;
		} else {
			$updated_no++;
		}
	}

	return "Updated: {$updated_yes} set to CEU=YES, {$updated_no} cleared.";
}

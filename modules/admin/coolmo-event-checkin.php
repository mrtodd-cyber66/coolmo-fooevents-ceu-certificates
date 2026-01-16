<?php
/*
 * Module: CoolMo Event Check-In (JungSeattle)
 * Version: 1.3.11
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ===========================================================
   SECTION 1 — ADMIN MENU SETUP
   Purpose: Add "Event Check-In – CEU Evaluations" under CoolMo Assistant
=========================================================== */
add_action( 'admin_menu', function() {
	global $admin_page_hooks;

	if ( isset( $admin_page_hooks['coolmo-assistant'] ) ) {
		add_submenu_page(
			'coolmo-assistant',
			'Event Check-In – CEU Evaluations',
			'Event Check-In – CEU Evaluations',
			'manage_woocommerce',
			'coolmo-event-checkin',
			'coolmo_event_checkin_page'
		);
	}
}, 15 );

/* ===========================================================
   SECTION 2 — CEU EVENT DISCOVERY
   Purpose: Show ALL WooCommerce "event products" that have
            the CEU flag enabled on the PRODUCT.
   Meta key: _coolmo_ceu_enabled = 'yes' | 'no'
=========================================================== */
function coolmo_event_checkin_get_ceu_events() {
	global $wpdb;

	$sql = "
		SELECT 
			p.ID,
			p.post_title
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pm
			ON pm.post_id = p.ID
			AND pm.meta_key = '_coolmo_ceu_enabled'
			AND pm.meta_value = 'yes'
		WHERE p.post_type = 'product'
		  AND p.post_status = 'publish'
		ORDER BY p.post_title ASC
	";

	return $wpdb->get_results( $sql );
}


/* ===========================================================
   SECTION 3 — PAGE WRAPPER
   Purpose:
     - Handle bulk check-in + CEU emails
     - Render event selector (only products with CEU enabled)
     - Delegate table rendering to SECTION 4
=========================================================== */
function coolmo_event_checkin_page() {

	/* ---------- BULK CHECK-IN HANDLER ---------- */
	if ( isset( $_POST['coolmo_bulk_checkin'] ) ) {
		check_admin_referer( 'coolmo_event_checkin_action', 'coolmo_event_checkin_nonce' );

		$tickets  = array_map( 'intval', (array) ( $_POST['ticket_ids'] ?? array() ) );
		$checked  = 0;
		$sent     = 0;

		foreach ( $tickets as $ticket_id ) {

			if ( 'event_magic_tickets' !== get_post_type( $ticket_id ) ) {
				continue;
			}

			// Mark checked in
			update_post_meta( $ticket_id, 'WooCommerceEventsStatus', 'Checked In' );
			update_post_meta( $ticket_id, 'WooCommerceEventsCheckedIn', 'yes' );
			update_post_meta( $ticket_id, 'WooCommerceEventsCheckIn', 'yes' );
			$checked++;

			// Skip if CEU email already sent
			if ( get_post_meta( $ticket_id, '_coolmo_ceu_email_sent', true ) ) {
				continue;
			}

			$order_id   = intval( get_post_meta( $ticket_id, 'WooCommerceEventsOrderID', true ) );
			$product_id = intval( get_post_meta( $ticket_id, 'WooCommerceEventsProductID', true ) );

			if ( class_exists( '\\CoolMo\\FooEventsCEU\\CEU_Email' ) ) {
				\CoolMo\FooEventsCEU\CEU_Email::on_checkin( $ticket_id, $order_id, 0, $product_id );
				update_post_meta( $ticket_id, '_coolmo_ceu_email_sent', time() );
				$sent++;
			}
		}

		echo "<div class='updated'><p>" . esc_html( "{$checked} checked in, {$sent} CEU emails sent." ) . "</p></div>";
	}

	/* ---------- Event selector ---------- */
	echo '<div class="wrap"><h1>Event Check-In – CEU Evaluations</h1>';
	echo '<p>Select an event to manage attendee check-ins and CEU emails.</p>';

	$events = coolmo_event_checkin_get_ceu_events();

	if ( empty( $events ) ) {
		echo '<p><em>No products with CEUs enabled were found.</em></p>';
		echo '</div>';
		return;
	}

	echo '<form method="get">';
	echo '<input type="hidden" name="page" value="coolmo-event-checkin">';
	echo '<label><strong>Select Event:</strong></label> ';
	echo '<select name="coolmo_event_id"><option value="">— Select —</option>';

	$current_event = isset( $_GET['coolmo_event_id'] ) ? intval( $_GET['coolmo_event_id'] ) : 0;

	foreach ( $events as $ev ) {
		$sel = selected( $current_event, $ev->ID, false );
		echo "<option value='" . esc_attr( $ev->ID ) . "' {$sel}>" . esc_html( $ev->post_title ) . "</option>";
	}

	submit_button( 'Load Attendees', 'secondary', '', false );
	echo '</form>';

	if ( $current_event ) {
		coolmo_render_attendee_table( $current_event );
	}

	echo '</div>';
}

/* ===========================================================
   SECTION 4 — ATTENDEE TABLE + FILTERING
   NOTE:
   - Only tickets that have CEU selected are shown.
   - Detection:
       a) Ticket meta: _coolmo_ceu_selected = yes/1/true
       b) Legacy variations: WooCommerceEventsVariations contains
          attribute_pa_add-ceu-credit / attribute_add-ceu-credit = yes
   - Fully scoped by WooCommerceEventsProductID == $event_id
=========================================================== */
function coolmo_render_attendee_table( $event_id ) {
	global $wpdb;

	$event_id = intval( $event_id );
	if ( ! $event_id ) {
		echo '<p>No event selected.</p>';
		return;
	}

	$like1 = '%attribute_pa_add-ceu-credit";s:%"yes"%';
	$like2 = '%attribute_add-ceu-credit";s:%"yes"%';

	$sql = "
		SELECT 
			t.ID AS ticket_id,
			t.post_title AS ticket_title,

			-- Attendee info
			pm_att_name.meta_value   AS attendee_name,
			pm_att_email.meta_value  AS attendee_email,

			-- Purchaser info
			pm_pur_first.meta_value  AS purchaser_first_name,
			pm_pur_last.meta_value   AS purchaser_last_name,
			pm_pur_email.meta_value  AS purchaser_email,

			-- Ticket info
			pm_order.meta_value      AS order_id,
			pm_ticket_number.meta_value AS ticket_number,
			pm_status.meta_value     AS ticket_status,

			-- CEU fields
			pm_coolmo.meta_value     AS ceu_selected,
			pm_variations.meta_value AS variations_raw,

			-- Booking date
			pm_booking_date.meta_value AS booking_date

		FROM {$wpdb->posts} t

		-- Product
		JOIN {$wpdb->postmeta} pm_product 
			ON pm_product.post_id = t.ID
			AND pm_product.meta_key = 'WooCommerceEventsProductID'
			AND pm_product.meta_value = %d

		-- Booking date
		LEFT JOIN {$wpdb->postmeta} pm_booking_date 
			ON pm_booking_date.post_id = t.ID
			AND pm_booking_date.meta_key = 'WooCommerceEventsBookingDateMySQLFormat'

		-- Attendee meta
		LEFT JOIN {$wpdb->postmeta} pm_att_name 
			ON pm_att_name.post_id = t.ID AND pm_att_name.meta_key = 'WooCommerceEventsAttendeeName'
		LEFT JOIN {$wpdb->postmeta} pm_att_email 
			ON pm_att_email.post_id = t.ID AND pm_att_email.meta_key = 'WooCommerceEventsAttendeeEmail'

		-- Purchaser meta
		LEFT JOIN {$wpdb->postmeta} pm_pur_first 
			ON pm_pur_first.post_id = t.ID AND pm_pur_first.meta_key = 'WooCommerceEventsPurchaserFirstName'
		LEFT JOIN {$wpdb->postmeta} pm_pur_last 
			ON pm_pur_last.post_id = t.ID AND pm_pur_last.meta_key = 'WooCommerceEventsPurchaserLastName'
		LEFT JOIN {$wpdb->postmeta} pm_pur_email 
			ON pm_pur_email.post_id = t.ID AND pm_pur_email.meta_key = 'WooCommerceEventsPurchaserEmail'

		-- Order ID
		LEFT JOIN {$wpdb->postmeta} pm_order 
			ON pm_order.post_id = t.ID AND pm_order.meta_key = 'WooCommerceEventsOrderID'

		-- Ticket number/status
		LEFT JOIN {$wpdb->postmeta} pm_ticket_number 
			ON pm_ticket_number.post_id = t.ID AND pm_ticket_number.meta_key = 'WooCommerceEventsTicketNumber'
		LEFT JOIN {$wpdb->postmeta} pm_status 
			ON pm_status.post_id = t.ID AND pm_status.meta_key = 'WooCommerceEventsStatus'

		-- CEU fields
		LEFT JOIN {$wpdb->postmeta} pm_coolmo 
			ON pm_coolmo.post_id = t.ID AND pm_coolmo.meta_key = '_coolmo_ceu_selected'

		LEFT JOIN {$wpdb->postmeta} pm_variations 
			ON pm_variations.post_id = t.ID AND pm_variations.meta_key = 'WooCommerceEventsVariations'

		WHERE t.post_type = 'event_magic_tickets'

		  AND (
				( pm_coolmo.meta_value IS NOT NULL AND LOWER(pm_coolmo.meta_value) IN ('yes','1','true') )
				OR ( pm_variations.meta_value IS NOT NULL AND pm_variations.meta_value LIKE %s )
				OR ( pm_variations.meta_value IS NOT NULL AND pm_variations.meta_value LIKE %s )
		      )

		ORDER BY pm_booking_date.meta_value, t.ID
	";

	$prepared = $wpdb->prepare( $sql, $event_id, $like1, $like2 );
	$rows     = $wpdb->get_results( $prepared );

	echo '<hr><h2>Attendees for: <em>' . esc_html( get_the_title( $event_id ) ) . '</em></h2>';

	if ( empty( $rows ) ) {
		echo '<p>No CEU-eligible attendees found.</p>';
		return;
	}

	$resend_nonce = wp_create_nonce( 'coolmo_resend_ceu_email' );

	echo '<form method="post" id="coolmo-attendee-form">';
	wp_nonce_field( 'coolmo_event_checkin_action', 'coolmo_event_checkin_nonce' );

	echo '<p><button type="submit" class="button-primary" name="coolmo_bulk_checkin">Mark Selected as Attended &amp; Send CEU Emails</button></p>';

	echo '<table class="widefat striped" id="coolmo-attendee-table">';
	echo '<thead><tr>
		<th class="check-column"><input type="checkbox" id="coolmo-checkall"></th>
		<th>Name</th>
		<th>Email</th>
		<th>Event Date</th>
		<th>Order ID</th>
		<th>Ticket ID</th>
		<th>Status</th>
		<th>Actions</th>
	</tr></thead><tbody>';

	foreach ( $rows as $row ) {

		$tid      = intval( $row->ticket_id );
		$order_id = $row->order_id;
		$email    = $row->attendee_email;

		$fname = $row->attendee_name;
		$lname = $row->purchaser_last_name;

		$name = trim( (string) $fname . ' ' . (string) $lname );
		if ( ! $name ) {
			$name = trim( (string) $row->purchaser_first_name . ' ' . (string) $row->purchaser_last_name );
		}

		$status     = (string) $row->ticket_status;
		$event_date = $row->booking_date ? (string) $row->booking_date : '—';

		$has_sent = get_post_meta( $tid, '_coolmo_ceu_email_sent', true );

		$action_btn = $has_sent
			? "<button type='button' class='button small coolmo-resend-btn' data-ticket='" . esc_attr( $tid ) . "' data-nonce='" . esc_attr( $resend_nonce ) . "'>Resend CEU Email</button>"
			: '—';

		$status_badge = ( 'Checked In' === $status )
			? "<span class='coolmo-status checked'>Checked In</span>"
			: "<span class='coolmo-status notchecked'>Not Checked In</span>";

		echo "<tr>
			<th class='check-column'><input type='checkbox' name='ticket_ids[]' value='" . esc_attr( $tid ) . "'></th>
			<td>" . esc_html( $name ?: '—' ) . "</td>
			<td>" . esc_html( $email ?: '—' ) . "</td>
			<td>" . esc_html( $event_date ) . "</td>
			<td>" . esc_html( $order_id ?: '—' ) . "</td>
			<td>" . esc_html( $row->ticket_number ?: '—' ) . "</td>
			<td>{$status_badge}</td>
			<td>{$action_btn}</td>
		</tr>";
	}

	echo '</tbody></table>';

	echo '<p><button type="submit" class="button-primary" name="coolmo_bulk_checkin">Mark Selected as Attended &amp; Send CEU Emails</button></p>';
	echo '</form>';

	echo '<style>
		.coolmo-status{padding:3px 6px;border-radius:4px;color:#fff;font-size:12px;}
		.coolmo-status.checked{background:#3b7a57;}
		.coolmo-status.notchecked{background:#777;}
	</style>';

	?>
	<script>
	document.addEventListener("DOMContentLoaded", () => {
		const master = document.getElementById("coolmo-checkall");
		if (master) {
			master.addEventListener("change", () => {
				document.querySelectorAll('input[name="ticket_ids[]"]').forEach(cb => cb.checked = master.checked);
			});
		}

		document.querySelectorAll(".coolmo-resend-btn").forEach(btn => {
			btn.addEventListener("click", () => {
				const fd = new FormData();
				fd.append("action", "coolmo_resend_ceu_email");
				fd.append("ticket_id", btn.dataset.ticket);
				fd.append("nonce", btn.dataset.nonce);

				btn.disabled = true;
				btn.textContent = "Resending…";

				fetch(ajaxurl, { method: "POST", body: fd })
					.then(r => r.json())
					.then(d => {
						btn.textContent = (d && d.success) ? "Resent" : "Failed";
					})
					.catch(() => { btn.textContent = "Failed"; })
					.finally(() => {
						setTimeout(() => {
							btn.disabled = false;
							btn.textContent = "Resend CEU Email";
						}, 1400);
					});
			});
		});
	});
	</script>
	<?php
}

/* ===========================================================
   SECTION 5 — AJAX RESEND HANDLER
=========================================================== */
add_action( 'wp_ajax_coolmo_resend_ceu_email', function() {

	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_send_json_error( array( 'message' => 'Permission denied' ), 403 );
	}

	if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'coolmo_resend_ceu_email' ) ) {
		wp_send_json_error( array( 'message' => 'Bad nonce' ), 400 );
	}

	$ticket_id = intval( $_POST['ticket_id'] ?? 0 );
	if ( ! $ticket_id || 'event_magic_tickets' !== get_post_type( $ticket_id ) ) {
		wp_send_json_error( array( 'message' => 'Bad ticket' ), 400 );
	}

	$order_id   = intval( get_post_meta( $ticket_id, 'WooCommerceEventsOrderID', true ) );
	$product_id = intval( get_post_meta( $ticket_id, 'WooCommerceEventsProductID', true ) );

	if ( class_exists( '\\CoolMo\\FooEventsCEU\\CEU_Email' ) ) {
		\CoolMo\FooEventsCEU\CEU_Email::on_checkin( $ticket_id, $order_id, 0, $product_id );
		update_post_meta( $ticket_id, '_coolmo_ceu_email_sent', time() );
		wp_send_json_success( array( 'message' => 'Resent' ) );
	}

	wp_send_json_error( array( 'message' => 'Email handler missing' ), 500 );
} );

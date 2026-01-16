<?php
/**
 * Prefill Forminator text field {text-3} with the logged-in user's license number.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'forminator_field_text_value', function( $value, $field, $data, $form_id ) {

    // Match the field ID from Forminator (case-sensitive)
    if ( isset( $field['element_id'] ) && '{text-3}' === $field['element_id'] ) {

        if ( is_user_logged_in() ) {
    $user_id = get_current_user_id();

    // Primary key used by CEU_Email
    $license = get_user_meta( $user_id, 'coolmo_license', true );

    // Backwards-compat: also honor older 'license_number' if set
    if ( '' === $license ) {
        $license = get_user_meta( $user_id, 'license_number', true );
    }

    if ( ! empty( $license ) ) {
        $value = $license;
    }
}

    }

    return $value;
}, 10, 4 );

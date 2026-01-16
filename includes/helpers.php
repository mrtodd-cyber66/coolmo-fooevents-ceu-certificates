<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Return CEU-related meta for a product as an associative array.
 */
function coolmo_ceu_get_meta( int $product_id ): array {
    $keys = array(
        '_coolmo_event_title',
        '_coolmo_ceu_number',
        '_coolmo_certificate_date',
        '_coolmo_event_timeframe',
        '_coolmo_instructor_name',
        '_coolmo_signer_full_name',
        '_coolmo_certificate_signer',
        '_coolmo_learning_obj_1',
        '_coolmo_learning_obj_2',
        '_coolmo_learning_obj_3',
    );

    $out = array();
    foreach ( $keys as $k ) {
        $out[ $k ] = get_post_meta( $product_id, $k, true );
    }

    return $out;
}

/**
 * Build a prefilled evaluation URL for a product.
 *
 * @param string $base_url   Optional base URL. If empty, falls back to home_url('/ceu-evaluation-form/').
 * @param int    $product_id Product ID used to retrieve prefilled meta values.
 * @return string Prefilled evaluation URL (safe-encoded via http_build_query).
 */
function coolmo_ceu_build_eval_url( string $base_url = '', int $product_id ): string {
    // Use provided base if given, otherwise fallback to canonical path.
    $base = $base_url ? untrailingslashit( $base_url ) : untrailingslashit( home_url( '/ceu-evaluation-form/' ) );

    $meta = coolmo_ceu_get_meta( $product_id );

    // Ensure all params are strings (http_build_query will encode them).
    $params = array(
        'ceus'       => (string) ( $meta['_coolmo_ceu_number'] ?? '' ),
        'signer'     => (string) ( $meta['_coolmo_certificate_signer'] ?? '' ),
        'date'       => (string) ( $meta['_coolmo_certificate_date'] ?? '' ),
        'instructor' => (string) ( $meta['_coolmo_instructor_name'] ?? '' ),
        'title'      => (string) ( $meta['_coolmo_event_title'] ?? '' ),
        'signer_full'=> (string) ( $meta['_coolmo_signer_full_name'] ?? '' ),
        'lo1'        => (string) ( $meta['_coolmo_learning_obj_1'] ?? '' ),
        'lo2'        => (string) ( $meta['_coolmo_learning_obj_2'] ?? '' ),
        'lo3'        => (string) ( $meta['_coolmo_learning_obj_3'] ?? '' ),
        'timeframe'  => (string) ( $meta['_coolmo_event_timeframe'] ?? '' ),
        'product_id' => (string) $product_id,
    );

    $query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

    return trailingslashit( $base ) . '?' . $query;
}

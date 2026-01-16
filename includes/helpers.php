<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

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
        $out[$k] = get_post_meta( $product_id, $k, true );
    }
    return $out;
}

function coolmo_ceu_build_eval_url( string $base_url, int $product_id ): string {
    // Hardcoded global evaluation form URL
    //$base = 'https://jungseattle.org/staging/ceu-evaluation-form/';
	$base = home_url( '/ceu-evaluation-form/' );
    $meta = coolmo_ceu_get_meta( $product_id );

    $params = array(
        'ceus'       => $meta['_coolmo_ceu_number'] ?? '',
        'signer'     => $meta['_coolmo_certificate_signer'] ?? '',
        'date'       => $meta['_coolmo_certificate_date'] ?? '',
        'instructor' => $meta['_coolmo_instructor_name'] ?? '',
        'title'      => $meta['_coolmo_event_title'] ?? '',
        'signer_full'=> $meta['_coolmo_signer_full_name'] ?? '',
        'lo1'        => $meta['_coolmo_learning_obj_1'] ?? '',
        'lo2'        => $meta['_coolmo_learning_obj_2'] ?? '',
        'lo3'        => $meta['_coolmo_learning_obj_3'] ?? '',
        'timeframe'  => $meta['_coolmo_event_timeframe'] ?? '',
        'product_id' => $product_id,
    );

    $query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
    return trailingslashit( $base ) . '?' . $query;
}

<?php
/**
 * Frontend shortcode for CEU data (read-only, param-based).
 *
 * Usage in Forminator HTML fields:
 *   [coolmo_param name="obj1"]
 *   [coolmo_param name="obj2"]
 *   [coolmo_param name="obj3"]
 *   (also supports: title, instructor, ceus, date)
 *
 * @package CoolMo\FooEventsCEU
 */

namespace CoolMo\FooEventsCEU;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEU_Shortcodes {

    public static function init() {
        // Single-value shortcode used inside Forminator HTML fields
        add_shortcode( 'coolmo_param', array( __CLASS__, 'render_param' ) );
    }

    /**
     * Render individual CEU/event parameters from product meta.
     * Example: [coolmo_param name="obj1"]
     */
    public static function render_param( $atts ) {
        $atts = shortcode_atts( array(
            'name' => '',
        ), $atts, 'coolmo_param' );

        if ( empty( $_GET['product_id'] ) ) {
            return '';
        }

        $product_id = (int) $_GET['product_id'];
        if ( ! $product_id ) {
            return '';
        }

        $name = strtolower( sanitize_text_field( $atts['name'] ) );

        // Supported mappings
        $map = array(
            'obj1'       => '_coolmo_learning_obj_1',
            'obj2'       => '_coolmo_learning_obj_2',
            'obj3'       => '_coolmo_learning_obj_3',
            'title'      => '_coolmo_event_title',
            'instructor' => '_coolmo_instructor_name',
            'ceus'       => '_coolmo_ceu_number',
            'date'       => '_coolmo_certificate_date',
            'timeframe'  => '_coolmo_event_timeframe',
            'signer'     => '_coolmo_certificate_signer',
            'signer_full'=> '_coolmo_signer_full_name',
        );

        if ( isset( $map[ $name ] ) ) {
            $val = get_post_meta( $product_id, $map[ $name ], true );
            return esc_html( (string) $val );
        }

        return '';
    }
}

CEU_Shortcodes::init();

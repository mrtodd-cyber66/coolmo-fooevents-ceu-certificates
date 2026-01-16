<?php
namespace CoolMo\FooEventsCEU;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEU_Certificates {

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_fields' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'maybe_render_eval_url_helper' ) );
	}

	/* -----------------------------------------------
	   TAB
	----------------------------------------------- */
	public static function add_tab( $tabs ) {
		$tabs['coolmo_ceu_certificate'] = array(
			'label'    => __( 'CEU Certificate', 'coolmo-fooevents-ceu-certificates' ),
			'target'   => 'coolmo_ceu_certificate_data',
			'priority' => 55,
		);
		return $tabs;
	}

	/* -----------------------------------------------
	   PANEL
	----------------------------------------------- */
	public static function render_panel() {

		echo '<div id="coolmo_ceu_certificate_data" class="panel woocommerce_options_panel">';
		echo '<h2>' . esc_html__( 'CEU Certificate Data', 'coolmo-fooevents-ceu-certificates' ) . '</h2>';
		echo '<hr />';

		// ✅ — CEU ENABLE TOGGLE
		\woocommerce_wp_checkbox( array(
			'id'          => '_coolmo_ceu_enabled',
			'label'       => __( 'Enable CEUs for this Event', 'coolmo-fooevents-ceu-certificates' ),
			'description' => __( 'Required for CEU check-in, corrections, and certificates.', 'coolmo-fooevents-ceu-certificates' ),
		) );

		$fields = array(
			'_coolmo_event_title'        => "Event Title for Certificate",
			'_coolmo_ceu_number'         => "Number of CEU's",
			'_coolmo_certificate_date'   => "Completion Date for Certificate",
			'_coolmo_event_timeframe'    => "Timeframe for Event",
			'_coolmo_instructor_name'    => "Instructor / Speaker",
			'_coolmo_signer_full_name'   => "Signer's Full Name with Credentials",
			'_coolmo_certificate_signer' => "Who is Signing the Certificate",
			'_coolmo_learning_obj_1'     => "Learning Objective 1",
			'_coolmo_learning_obj_2'     => "Learning Objective 2",
			'_coolmo_learning_obj_3'     => "Learning Objective 3",
		);

		foreach ( $fields as $id => $label ) {
			\woocommerce_wp_text_input( array(
				'id'    => $id,
				'label' => esc_html__( $label, 'coolmo-fooevents-ceu-certificates' ),
			) );
		}

		echo '</div>';
	}

	/* -----------------------------------------------
	   SAVE
	----------------------------------------------- */
	public static function save_fields( $product ) {

		// persist CEU enabled flag
		$product->update_meta_data(
			'_coolmo_ceu_enabled',
			isset( $_POST['_coolmo_ceu_enabled'] ) ? 'yes' : 'no'
		);

		$fields = array(
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

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$product->update_meta_data(
					$field,
					sanitize_text_field( wp_unslash( $_POST[ $field ] ) )
				);
			}
		}
	}

	/* -----------------------------------------------
	   PREFILLED EVAL URL
	----------------------------------------------- */
	public static function maybe_render_eval_url_helper() {
		global $post;
		if ( ! $post ) return;

		$url = \coolmo_ceu_build_eval_url( '', (int) $post->ID );
		if ( ! $url ) return;

		echo '<div class="options_group">';
		echo '<p><strong>' . esc_html__( 'Prefilled Evaluation URL', 'coolmo-fooevents-ceu-certificates' ) . '</strong></p>';
		echo '<input type="text" id="coolmo_ceu_prefilled_url" value="' . esc_attr( $url ) . '" readonly style="width:100%;" />';
		echo '<button type="button" class="button" id="coolmo_ceu_copy_url">' . esc_html__( 'Copy URL', 'coolmo-fooevents-ceu-certificates' ) . '</button>';
		echo '</div>';
	}
}

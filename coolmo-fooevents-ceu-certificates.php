<?php
/**
 * Plugin Name:  CoolMo FooEvents CEU Certificates
 * Description:  Handles CEU evaluation emails, certificates, and admin tools for FooEvents.
 * Author:       CoolMo Design
 * Version:      1.3.7
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
   REGISTER LEGACY FOOEVENTS POST TYPE (CRITICAL FIX)
========================================================= */
add_action( 'init', function() {
	if ( ! post_type_exists( 'event_magic_tickets' ) ) {
		register_post_type( 'event_magic_tickets', array(
			'label'       => 'FooEvents Tickets (Legacy)',
			'public'      => false,
			'show_ui'     => false,
			'supports'    => array( 'title' ),
			'rewrite'     => false,
			'query_var'   => true,
		) );
	}
}, 1 );

/* -------------------------------------------------------------
   SECTION 1 — CONSTANTS
------------------------------------------------------------- */
define( 'COOLMO_CEU_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'COOLMO_CEU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* -------------------------------------------------------------
   SECTION 2 — HELPERS
------------------------------------------------------------- */
require_once COOLMO_CEU_PLUGIN_PATH . 'includes/helpers.php';

/* -------------------------------------------------------------
   SECTION 3 — MODULES
------------------------------------------------------------- */
require_once COOLMO_CEU_PLUGIN_PATH . 'modules/class-coolmo-ceu-email.php';
require_once COOLMO_CEU_PLUGIN_PATH . 'modules/class-coolmo-ceu-certificates.php';
require_once COOLMO_CEU_PLUGIN_PATH . 'modules/class-coolmo-ceu-shortcodes.php';
require_once COOLMO_CEU_PLUGIN_PATH . 'modules/class-coolmo-ceu-frontend.php';
//require_once COOLMO_CEU_PLUGIN_PATH . 'modules/class-coolmo-member-price-display.php';
require_once COOLMO_CEU_PLUGIN_PATH . 'modules/class-coolmo-forminator-prefill.php';
require_once COOLMO_CEU_PLUGIN_PATH . 'modules/admin/coolmo-event-checkin.php';
require_once COOLMO_CEU_PLUGIN_PATH . 'modules/admin/coolmo-ceu-corrections.php';

/* -------------------------------------------------------------
   SECTION 4 — INITIALIZE MODULES
------------------------------------------------------------- */
add_action( 'plugins_loaded', function() {

	if ( class_exists( '\CoolMo\FooEventsCEU\CEU_Certificates' ) ) {
		\CoolMo\FooEventsCEU\CEU_Certificates::init();
	}

	if ( class_exists( '\CoolMo\FooEventsCEU\CEU_Email' ) ) {
		\CoolMo\FooEventsCEU\CEU_Email::init();
	}

	if ( class_exists( '\CoolMo\FooEventsCEU\CEU_Shortcodes' ) ) {
		\CoolMo\FooEventsCEU\CEU_Shortcodes::init();
	}

	// ✅ FIX: frontend CEU logic
	if ( class_exists( '\CoolMo\FooEventsCEU\CEU_Frontend' ) ) {
		\CoolMo\FooEventsCEU\CEU_Frontend::init();
	}

	// ✅ FIX: member price display
//	if ( class_exists( '\CoolMo\FooEventsCEU\Member_Price_Display' ) ) {
//		\CoolMo\FooEventsCEU\Member_Price_Display::init();
//	}

}, 20 );

/* -------------------------------------------------------------
   SECTION 5 — ADMIN ASSETS
------------------------------------------------------------- */
add_action( 'admin_enqueue_scripts', function( $hook ) {

	if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
		return;
	}

	$screen = get_current_screen();
	if ( ! $screen || $screen->post_type !== 'product' ) {
		return;
	}

	wp_enqueue_script(
		'coolmo-ceu-admin-js',
		COOLMO_CEU_PLUGIN_URL . 'assets/admin.js',
		[ 'jquery' ],
		file_exists( COOLMO_CEU_PLUGIN_PATH . 'assets/admin.js' )
			? filemtime( COOLMO_CEU_PLUGIN_PATH . 'assets/admin.js' )
			: '1.0.0',
		true
	);
});

/* -------------------------------------------------------------
   SECTION 6 — ADMIN MENU ROOT
------------------------------------------------------------- */
if ( is_admin() ) {
	add_action( 'admin_menu', function() {
		global $admin_page_hooks;

		if ( ! isset( $admin_page_hooks['coolmo-assistant'] ) ) {
			add_menu_page(
				'CoolMo Assistant',
				'CoolMo Assistant',
				'manage_options',
				'coolmo-assistant',
				function() {
					echo '<div class="wrap"><h1>CoolMo Assistant</h1><p>Select a section from the submenu.</p></div>';
				},
				'dashicons-admin-generic',
				3
			);
		}
	}, 9 );
}
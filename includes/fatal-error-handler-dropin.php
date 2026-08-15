<?php
/**
 * Plugin Name: Fatal Plugin Auto Deactivator (Drop-in)
 * Description: This drop-in is part of the Fatal Plugin Auto Deactivator plugin. It automatically deactivates plugins that cause fatal errors.
 * Plugin URI: https://wordpress.org/plugins/fatal-plugin-auto-deactivator/
 * Author: Linkon Miyan
 * Author URI: https://profiles.wordpress.org/rudlinkon/
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// Define constants needed for WordPress if they're not already defined
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( dirname( __FILE__ ) ) . '/' );
}

// Define the path to the plugin directory
if ( ! defined( 'FPAD_PLUGIN_DIR' ) ) {
	// The drop-in is in wp-content, so the plugins directory is wp-content/plugins
	define( 'FPAD_PLUGIN_DIR', dirname( __FILE__ ) . '/plugins/fatal-plugin-auto-deactivator/' );
}

// Reserve a memory buffer the handler frees on entry, so logging and alerts
// still work when the fatal is an out-of-memory error.
$GLOBALS['fpad_reserved_memory'] = str_repeat( 'x', 256 * 1024 );

// When PHP is set to print errors to the page, it prints the fatal itself —
// paths and stack trace — BEFORE any shutdown handler runs. Once that reaches
// the client the response is stuck at HTTP 200 and the handler's page can only
// be appended to a raw trace. Owning an output buffer from here (the earliest
// point WordPress gives us) lets the handler discard that and send its own 500.
// Deliberately skipped when errors are not displayed, so the common production
// configuration keeps its current, unbuffered behavior.
$fpad_ini_display  = strtolower( (string) ini_get( 'display_errors' ) );
$fpad_shows_errors = ! in_array( $fpad_ini_display, array( '', '0', 'off', 'false', 'stderr' ), true );
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	$fpad_shows_errors = ! defined( 'WP_DEBUG_DISPLAY' ) || false !== WP_DEBUG_DISPLAY;
}
if ( $fpad_shows_errors ) {
	ob_start();
}
unset( $fpad_ini_display, $fpad_shows_errors );

// Include the fatal error handler class
if ( ! class_exists( 'FPAD_Fatal_Error_Handler' ) ) {
	require_once FPAD_PLUGIN_DIR . 'includes/class-fatal-error-handler.php';
}

define( 'QM_DISABLE_ERROR_HANDLER', true );

// fpad-dropin-version: 3 — bump this token (here AND in the generator inside
// class-dropin-manager.php) whenever the drop-in's contents change, so deploy
// paths that never fire the upgrader hook still get their stale copy refreshed.

// Return an instance of our custom error handler
return new FPAD_Fatal_Error_Handler();

<?php
/**
 * Plugin Name: Tuft
 * Description: Soft on clients. Sharp on the details. Visual feedback with click-to-annotate and screenshots.
 * Version: 1.1.0
 * Author: George Stephanis
 * Author URI: https://github.com/georgestephanis
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: tuft
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Tuft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TUFT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TUFT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'TUFT_VERSION', '1.1.0' );

require_once TUFT_PLUGIN_DIR . 'includes/class-tuft-post-type.php';
require_once TUFT_PLUGIN_DIR . 'includes/class-tuft-settings.php';
require_once TUFT_PLUGIN_DIR . 'includes/class-tuft-rest-controller.php';
require_once TUFT_PLUGIN_DIR . 'includes/class-tuft-alpaca-bridge.php';
require_once TUFT_PLUGIN_DIR . 'includes/class-tuft-notifications.php';

/**
 * Enqueue frontend CSS and JS for the feedback widget.
 */
function tuft_enqueue_frontend_scripts() {
	$visibility = Tuft_Settings::get_visibility();
	if ( 'everyone' === $visibility ) {
		// No restriction — show to all visitors.
	} elseif ( 'logged_in' === $visibility ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
	} elseif ( 'editors' === $visibility ) {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
	} elseif ( 'admins' === $visibility ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
	}

	wp_enqueue_style(
		'tuft',
		TUFT_PLUGIN_URL . 'assets/css/feedback.css',
		array(),
		TUFT_VERSION
	);

	// html2canvas bundled locally — no CDN dependency.
	wp_enqueue_script(
		'html2canvas',
		TUFT_PLUGIN_URL . 'assets/js/vendor/html2canvas.min.js',
		array(),
		'1.4.1',
		true
	);

	wp_enqueue_script(
		'tuft',
		TUFT_PLUGIN_URL . 'assets/js/feedback.js',
		array( 'html2canvas' ),
		TUFT_VERSION,
		true
	);

	$current_user = wp_get_current_user();

	wp_localize_script(
		'tuft',
		'tuftSettings',
		array(
			'restUrl'    => rest_url( 'tuft/v1' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'pageUrl'    => esc_url_raw( home_url( add_query_arg( array() ) ) ),
			'pageTitle'  => wp_title( '|', false, 'right' ) . get_bloginfo( 'name' ),
			'isLoggedIn' => is_user_logged_in(),
			'userName'   => (string) $current_user->display_name,
			'userEmail'  => (string) $current_user->user_email,
			'buttonImg'  => TUFT_PLUGIN_URL . 'assets/img/fab-button.png',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'tuft_enqueue_frontend_scripts' );

<?php
/**
 * Plugin Name: Design Feedback
 * Description: Collect visual design feedback with click-to-annotate and screenshots.
 * Version: 1.0.0
 * Author: George Stephanis
 * Author URI: https://github.com/georgestephanis
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: design-feedback
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Design_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DF_VERSION', '1.0.0' );

require_once DF_PLUGIN_DIR . 'includes/class-df-svg-annotation.php';
require_once DF_PLUGIN_DIR . 'includes/class-df-post-type.php';
require_once DF_PLUGIN_DIR . 'includes/class-df-rest-controller.php';
require_once DF_PLUGIN_DIR . 'includes/class-df-alpaca-bridge.php';

/**
 * Enqueue frontend CSS and JS for the feedback widget.
 */
function df_enqueue_frontend_scripts() {
	wp_enqueue_style(
		'design-feedback',
		DF_PLUGIN_URL . 'assets/css/feedback.css',
		array(),
		DF_VERSION
	);

	// html2canvas bundled locally — no CDN dependency.
	wp_enqueue_script(
		'html2canvas',
		DF_PLUGIN_URL . 'assets/js/vendor/html2canvas.min.js',
		array(),
		'1.4.1',
		true
	);

	wp_enqueue_script(
		'design-feedback',
		DF_PLUGIN_URL . 'assets/js/feedback.js',
		array( 'html2canvas' ),
		DF_VERSION,
		true
	);

	$current_user = wp_get_current_user();

	wp_localize_script(
		'design-feedback',
		'dfSettings',
		array(
			'restUrl'    => rest_url( 'design-feedback/v1' ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'pageUrl'    => esc_url_raw( home_url( add_query_arg( array() ) ) ),
			'pageTitle'  => wp_title( '|', false, 'right' ) . get_bloginfo( 'name' ),
			'isLoggedIn' => is_user_logged_in(),
			'userName'   => (string) $current_user->display_name,
			'userEmail'  => (string) $current_user->user_email,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'df_enqueue_frontend_scripts' );

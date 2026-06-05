<?php
/**
 * WordPress Playground demo setup for Tuft.
 *
 * @package Tuft
 */

/**
 * WordPress Playground demo setup for Tuft.
 *
 * Creates a sample design page with a variety of elements to annotate,
 * sets it as the site front page, and seeds a few demo feedback entries
 * so the admin list is not empty on first load.
 *
 * Loaded by the runPHP step in blueprint.json.
 */

require_once '/wordpress/wp-load.php';

/**
 * Run all Playground demo setup steps.
 */
function tuft_playground_setup() {

	// ── Demo front page ──────────────────────────────────────────────────────

	$page_id = wp_insert_post(
		array(
			'post_title'   => 'Demo',
			'post_name'    => 'demo',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|50","bottom":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} --><div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)"><!-- wp:heading {"level":1} --><h1>Welcome to the Tuft demo</h1><!-- /wp:heading --><!-- wp:paragraph --><p>Click the <strong>coral button</strong> on the right to leave feedback on any element. Try clicking the headline, a button, or the two-column section below.</p><!-- /wp:paragraph --><!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#">Primary CTA</a></div><!-- /wp:button --><!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="#">Secondary CTA</a></div><!-- /wp:button --></div><!-- /wp:buttons --></div><!-- /wp:group --><!-- wp:separator /--><!-- wp:columns {"style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|40"}}}} --><div class="wp-block-columns" style="padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40)"><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3>Feature one</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Annotate any part of the design. The plugin captures the exact element, its position in the viewport, and an in-browser screenshot.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:heading {"level":3} --><h3>Feature two</h3><!-- /wp:heading --><!-- wp:paragraph --><p>Feedback lands in the <a href="/wp-admin/admin.php?page=project-board">Alpaca board</a> automatically — triaged, annotated, and ready to act on.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns --><!-- wp:separator /--><!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group"><!-- wp:heading {"level":2} --><h2>Review your feedback</h2><!-- /wp:heading --><!-- wp:paragraph --><p>After submitting feedback, visit the <a href="/wp-admin/admin.php?page=project-board">Alpaca board</a> in wp-admin to see how submissions appear as Kanban issues — complete with annotated screenshots.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
		)
	);

	if ( ! is_wp_error( $page_id ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
	}

	// ── Seed two demo feedback entries ───────────────────────────────────────

	$demo_entries = array(
		array(
			'title'    => 'Feedback on "Demo"',
			'feedback' => "The primary CTA button colour doesn't stand out enough against the white background. Could we try the coral from the brand palette here?",
			'page_url' => home_url( '/demo/' ),
			'selector' => '.wp-block-button__link',
			'x'        => '22.4',
			'y'        => '38.1',
			'name'     => 'Alice Client',
			'email'    => 'alice@example.com',
		),
		array(
			'title'    => 'Feedback on "Demo"',
			'feedback' => 'The two-column layout collapses a bit too early on tablet — the Feature one / Feature two cards would read better stacked at a wider breakpoint.',
			'page_url' => home_url( '/demo/' ),
			'selector' => '.wp-block-columns',
			'x'        => '51.0',
			'y'        => '62.5',
			'name'     => 'Bob Reviewer',
			'email'    => 'bob@example.com',
		),
	);

	foreach ( $demo_entries as $entry ) {
		$post_id = wp_insert_post(
			array(
				'post_title'  => $entry['title'],
				'post_status' => 'publish',
				'post_type'   => 'tuft_feedback',
			)
		);

		if ( is_wp_error( $post_id ) ) {
			continue;
		}

		update_post_meta( $post_id, '_tuft_feedback_text', $entry['feedback'] );
		update_post_meta( $post_id, '_tuft_page_url', $entry['page_url'] );
		update_post_meta( $post_id, '_tuft_page_title', 'Demo' );
		update_post_meta( $post_id, '_tuft_selector', $entry['selector'] );
		update_post_meta( $post_id, '_tuft_x_percent', $entry['x'] );
		update_post_meta( $post_id, '_tuft_y_percent', $entry['y'] );
		update_post_meta( $post_id, '_tuft_submitter_name', $entry['name'] );
		update_post_meta( $post_id, '_tuft_submitter_email', $entry['email'] );
		update_post_meta( $post_id, '_tuft_viewport_w', 1440 );
		update_post_meta( $post_id, '_tuft_viewport_h', 900 );

		// Mirror to Alpaca if active.
		do_action( 'tuft_feedback_submitted', $post_id );
	}

	// ── Create mu-plugin for WordPress Playground CORS workaround ───────────
	$mu_plugins_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	if ( ! file_exists( $mu_plugins_dir ) ) {
		wp_mkdir_p( $mu_plugins_dir );
	}

	$workaround_code = <<<'PHP'
<?php
/**
 * Plugin Name: Tuft Playground CORS Workaround
 * Description: Rewrites cross-origin asset URLs to same-origin for html2canvas inside WordPress Playground.
 * Version: 1.0.0
 * Author: Tuft Demo Setup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_print_footer_scripts', 'tuft_playground_cors_workaround', 1 );

/**
 * Output the JS snippet in the footer to rewrite stylesheet and image URLs to the same-origin scope URL.
 */
function tuft_playground_cors_workaround() {
	?>
	<script id="tuft-playground-cors-workaround">
	(function() {
		function fixPlaygroundAssets() {
			var scopeMatch = window.location.pathname.match(/^\/scope[:/]([^/]+)/);
			if (!scopeMatch) {
				return;
			}
			var scope = scopeMatch[0];
			var sameOriginBase = window.location.origin;

			// Rewrite stylesheet links to be same-origin
			document.querySelectorAll('link[rel="stylesheet"]').forEach(function(link) {
				var href = link.getAttribute('href');
				if (!href) {
					return;
				}

				if (
					href.indexOf(window.location.origin) === -1 &&
					(href.indexOf('/wp-content/') !== -1 || href.indexOf('/wp-includes/') !== -1)
				) {
					var relPath = '';
					var wpContentIdx = href.indexOf('/wp-content/');
					var wpIncludesIdx = href.indexOf('/wp-includes/');

					if (wpContentIdx !== -1) {
						relPath = href.substring(wpContentIdx);
					} else if (wpIncludesIdx !== -1) {
						relPath = href.substring(wpIncludesIdx);
					}

					if (relPath) {
						link.setAttribute('href', sameOriginBase + relPath);
					}
				}
			});

			// Rewrite images to be same-origin to prevent tainted canvas / mixed content blocks
			document.querySelectorAll('img').forEach(function(img) {
				var src = img.getAttribute('src');
				if (!src) {
					return;
				}

				if (
					src.indexOf(window.location.origin) === -1 &&
					(src.indexOf('/wp-content/') !== -1 || src.indexOf('/wp-includes/') !== -1)
				) {
					var relPath = '';
					var wpContentIdx = src.indexOf('/wp-content/');
					var wpIncludesIdx = src.indexOf('/wp-includes/');

					if (wpContentIdx !== -1) {
						relPath = src.substring(wpContentIdx);
					} else if (wpIncludesIdx !== -1) {
						relPath = src.substring(wpIncludesIdx);
					}

					if (relPath) {
						img.setAttribute('src', sameOriginBase + relPath);
					}
				}
			});
		}

		// Run on load
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fixPlaygroundAssets);
		} else {
			fixPlaygroundAssets();
		}

		// Run when targeting button is clicked
		document.addEventListener('click', function(e) {
			var trigger = e.target.closest('#tuft-trigger');
			if (trigger) {
				fixPlaygroundAssets();
			}
		}, true);
	})();
	</script>
	<?php
}
PHP;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $mu_plugins_dir . '/tuft-playground-cors.php', $workaround_code );
}

tuft_playground_setup();

<?php
/**
 * Bridge: forward design feedback submissions to Alpaca Issue Tracker.
 *
 * Hooks into `df_feedback_submitted` and creates a matching `alpaca_issue`
 * post when the Alpaca plugin is active.  All integration logic lives here so
 * the rest of the design-feedback plugin stays Alpaca-unaware.
 *
 * @package Design_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges design feedback submissions into Alpaca Issue Tracker posts.
 */
class DF_Alpaca_Bridge {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'df_feedback_submitted', array( $this, 'create_issue' ) );
	}

	/**
	 * Create an alpaca_issue mirroring the design_feedback post.
	 *
	 * @param int $df_post_id design_feedback post ID.
	 */
	public function create_issue( $df_post_id ) {
		if ( ! post_type_exists( 'alpaca_issue' ) ) {
			return;
		}

		// Read all stored meta in one shot.
		$feedback   = get_post_meta( $df_post_id, '_df_feedback_text', true );
		$page_url   = get_post_meta( $df_post_id, '_df_page_url', true );
		$page_title = get_post_meta( $df_post_id, '_df_page_title', true );
		$selector   = get_post_meta( $df_post_id, '_df_selector', true );
		$x          = get_post_meta( $df_post_id, '_df_x_percent', true );
		$y          = get_post_meta( $df_post_id, '_df_y_percent', true );
		$vw         = (int) get_post_meta( $df_post_id, '_df_viewport_w', true );
		$vh         = (int) get_post_meta( $df_post_id, '_df_viewport_h', true );
		$name       = get_post_meta( $df_post_id, '_df_submitter_name', true );
		$email      = get_post_meta( $df_post_id, '_df_submitter_email', true );
		$ua         = get_post_meta( $df_post_id, '_df_user_agent', true );

		if ( empty( $feedback ) ) {
			return;
		}

		// Build the issue body: feedback text + a context block.
		$context = $this->build_context_block( $page_url, $page_title, $selector, $x, $y, $vw, $vh, $name, $email );
		$content = $feedback . ( $context ? "\n\n" . $context : '' );

		$post_id = wp_insert_post(
			array(
				'post_type'      => 'alpaca_issue',
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id(),
				'post_title'     => wp_kses_post( wp_trim_words( $feedback, 10 ) ),
				'post_content'   => wp_kses_post( $content ),
				'comment_status' => 'open',
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}

		// Assign the lowest-score status (mirrors alpaistr_issue_callback logic).
		if ( function_exists( 'alpaistr_get_statuses' ) ) {
			$statuses = alpaistr_get_statuses();
			if ( ! empty( $statuses ) ) {
				$status_term = null;
				$min_score   = PHP_INT_MAX;

				foreach ( $statuses as $s ) {
					$score = isset( $s->term_score ) ? (int) $s->term_score : 0;
					if ( $score < $min_score ) {
						$min_score   = $score;
						$status_term = $s;
					}
				}

				/** This filter is documented in alpaca-issue-tracker/includes/api/endpoints/issues.php */
				$status_term = apply_filters( 'alpaca_default_status', $status_term, $statuses );

				if ( $status_term ) {
					$status_id = (int) $status_term->term_id;
					wp_set_post_terms( $post_id, array( $status_id ), 'alpaca_status' );

					// Keep the board issue_order in sync.
					$order = get_term_meta( $status_id, 'issue_order', true );
					$order = is_array( $order ) ? $order : array();
					$order = array_values( array_diff( $order, array( $post_id ) ) );
					array_unshift( $order, $post_id );
					update_term_meta( $status_id, 'issue_order', $order );
				}
			}
		}

		// Store URL and viewport context.
		if ( $page_url ) {
			update_post_meta( $post_id, 'alpaca_url', esc_url_raw( $page_url ) );
		}
		if ( $vw ) {
			update_post_meta( $post_id, 'alpaca_screenwidth', $vw );
		}
		if ( $vh ) {
			update_post_meta( $post_id, 'alpaca_screenheight', $vh );
		}

		// Tag with browser name derived from user-agent.
		if ( $ua ) {
			$browser = $this->detect_browser( $ua );
			if ( $browser ) {
				wp_set_post_terms( $post_id, $browser, 'alpaca_browser', true );
			}
		}

		// Label the issue so it's identifiable as coming from Design Feedback.
		wp_set_post_terms( $post_id, 'Design Feedback', 'alpaca_type', true );

		// Cross-reference both posts.
		update_post_meta( $post_id, 'alpaca_df_post_id', $df_post_id );
		update_post_meta( $df_post_id, '_df_alpaca_issue_id', $post_id );

		if ( function_exists( 'alpaistr_clear_board_cache' ) ) {
			alpaistr_clear_board_cache();
		}
	}

	/**
	 * Build a plain-text context block appended to the issue body.
	 *
	 * @param string $page_url   Source page URL.
	 * @param string $page_title Source page title.
	 * @param string $selector   CSS selector of clicked element.
	 * @param string $x          Click X as viewport percentage.
	 * @param string $y          Click Y as viewport percentage.
	 * @param int    $vw         Viewport width in pixels.
	 * @param int    $vh         Viewport height in pixels.
	 * @param string $name       Submitter name.
	 * @param string $email      Submitter email.
	 * @return string
	 */
	private function build_context_block( $page_url, $page_title, $selector, $x, $y, $vw, $vh, $name, $email ) {
		$lines = array( '---' );

		if ( $page_url ) {
			$label   = $page_title ? $page_title . ' — ' . $page_url : $page_url;
			$lines[] = 'Page: ' . $label;
		}
		if ( $selector ) {
			$lines[] = 'Element: ' . $selector;
		}
		if ( '' !== $x && '' !== $y ) {
			$lines[] = 'Click position: ' . $x . '% × ' . $y . '%';
		}
		if ( $vw && $vh ) {
			$lines[] = 'Viewport: ' . $vw . ' × ' . $vh . 'px';
		}
		if ( $name || $email ) {
			$by      = '' !== $name ? $name : '';
			$by     .= ( $name && $email ) ? ' (' . $email . ')' : $email;
			$lines[] = 'Submitted by: ' . $by;
		}

		// Only emit the block if there is something beyond the separator.
		return count( $lines ) > 1 ? implode( "\n", $lines ) : '';
	}

	/**
	 * Best-effort browser name from a user-agent string.
	 *
	 * @param string $ua User-agent string.
	 * @return string Browser name, or empty string if unrecognised.
	 */
	private function detect_browser( $ua ) {
		if ( strpos( $ua, 'Edg/' ) !== false || strpos( $ua, 'Edge/' ) !== false ) {
			return 'Edge';
		}
		if ( strpos( $ua, 'Firefox/' ) !== false ) {
			return 'Firefox';
		}
		if ( strpos( $ua, 'Chrome/' ) !== false ) {
			return 'Chrome';
		}
		if ( strpos( $ua, 'Safari/' ) !== false ) {
			return 'Safari';
		}
		if ( strpos( $ua, 'MSIE ' ) !== false || strpos( $ua, 'Trident/' ) !== false ) {
			return 'Internet Explorer';
		}
		return '';
	}
}

new DF_Alpaca_Bridge();

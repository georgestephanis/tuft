<?php
/**
 * Bridge: forward design feedback submissions to Alpaca Issue Tracker.
 *
 * Hooks into `tuft_feedback_submitted` and creates a matching `alpaca_issue`
 * post when the Alpaca plugin is active.  All integration logic lives here so
 * the rest of the design-feedback plugin stays Alpaca-unaware.
 *
 * Structure:
 *   - Issue title   → first ~10 words of the feedback text.
 *   - Issue body    → the feedback text only.
 *   - First comment → context metadata (page, element, coordinates, submitter)
 *                     + an SVG screenshot with a spotlight annotation marking
 *                     the exact click location.
 *
 * @package Tuft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges design feedback submissions into Alpaca Issue Tracker posts.
 */
class Tuft_Alpaca_Bridge {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'tuft_feedback_submitted', array( $this, 'create_issue' ) );
	}

	/**
	 * Create an alpaca_issue mirroring the tuft_feedback post.
	 *
	 * @param int $df_post_id tuft_feedback post ID.
	 */
	public function create_issue( $df_post_id ) {
		if ( ! post_type_exists( 'alpaca_issue' ) ) {
			return;
		}

		$feedback = get_post_meta( $df_post_id, '_tuft_feedback_text', true );

		if ( empty( $feedback ) ) {
			return;
		}

		$page_url = get_post_meta( $df_post_id, '_tuft_page_url', true );
		$vw       = (int) get_post_meta( $df_post_id, '_tuft_viewport_w', true );
		$vh       = (int) get_post_meta( $df_post_id, '_tuft_viewport_h', true );
		$ua       = get_post_meta( $df_post_id, '_tuft_user_agent', true );

		// Issue body is the feedback text only — context goes in the first comment.
		$post_id = wp_insert_post(
			array(
				'post_type'      => 'alpaca_issue',
				'post_status'    => 'publish',
				'post_author'    => get_current_user_id(),
				'post_title'     => wp_kses_post( wp_trim_words( $feedback, 10 ) ),
				'post_content'   => wp_kses_post( $feedback ),
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

				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party filter defined by Alpaca Issue Tracker.
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

		// Store URL and viewport context Alpaca knows how to use.
		if ( $page_url ) {
			update_post_meta( $post_id, 'alpaca_url', esc_url_raw( $page_url ) );
		}
		if ( $vw ) {
			update_post_meta( $post_id, 'alpaca_screenwidth', $vw );
		}
		if ( $vh ) {
			update_post_meta( $post_id, 'alpaca_screenheight', $vh );
		}

		// Tag with browser and type so the board can be filtered.
		if ( $ua ) {
			$browser = $this->detect_browser( $ua );
			if ( $browser ) {
				wp_set_post_terms( $post_id, $browser, 'alpaca_browser', true );
			}
		}
		wp_set_post_terms( $post_id, 'Tuft', 'alpaca_type', true );

		// Cross-reference both posts.
		update_post_meta( $post_id, 'alpaca_tuft_post_id', $df_post_id );
		update_post_meta( $df_post_id, '_tuft_alpaca_issue_id', $post_id );

		// Add the context comment (metadata + annotated screenshot).
		$this->insert_context_comment( $post_id, $df_post_id );

		if ( function_exists( 'alpaistr_clear_board_cache' ) ) {
			alpaistr_clear_board_cache();
		}
	}

	/**
	 * Insert a context comment on the alpaca_issue containing metadata and
	 * an SVG screenshot annotated with a spotlight at the click location.
	 *
	 * @param int $issue_id    alpaca_issue post ID.
	 * @param int $df_post_id  tuft_feedback post ID.
	 */
	private function insert_context_comment( $issue_id, $df_post_id ) {
		$page_url   = get_post_meta( $df_post_id, '_tuft_page_url', true );
		$page_title = get_post_meta( $df_post_id, '_tuft_page_title', true );
		$selector   = get_post_meta( $df_post_id, '_tuft_selector', true );
		$x          = get_post_meta( $df_post_id, '_tuft_x_percent', true );
		$y          = get_post_meta( $df_post_id, '_tuft_y_percent', true );
		$vw         = (int) get_post_meta( $df_post_id, '_tuft_viewport_w', true );
		$vh         = (int) get_post_meta( $df_post_id, '_tuft_viewport_h', true );
		$name       = get_post_meta( $df_post_id, '_tuft_submitter_name', true );
		$email      = get_post_meta( $df_post_id, '_tuft_submitter_email', true );
		$shot_id    = get_post_meta( $df_post_id, '_tuft_screenshot_id', true );
		$rect_raw   = get_post_meta( $df_post_id, '_tuft_rect', true );
		$rect       = $rect_raw ? json_decode( $rect_raw, true ) : null;

		// Build the metadata list.
		$items = array();

		if ( $page_url ) {
			$label   = $page_title ? $page_title : $page_url;
			$items[] = '<strong>Page:</strong> <a href="' . esc_url( $page_url ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( $selector ) {
			$items[] = '<strong>Element:</strong> <code>' . esc_html( $selector ) . '</code>';
		}
		if ( '' !== $x && '' !== $y ) {
			$items[] = '<strong>Click position:</strong> ' . esc_html( $x ) . '% &times; ' . esc_html( $y ) . '%';
		}
		if ( $vw && $vh ) {
			$items[] = '<strong>Viewport:</strong> ' . esc_html( (string) $vw ) . ' &times; ' . esc_html( (string) $vh ) . 'px';
		}
		if ( $name || $email ) {
			$by = esc_html( $name );
			if ( $email ) {
				$by .= $name ? ' (' . esc_html( $email ) . ')' : esc_html( $email );
			}
			$items[] = '<strong>Submitted by:</strong> ' . $by;
		}

		$content = '';
		if ( ! empty( $items ) ) {
			$content .= '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
		}

		// Append screenshot: SVG annotation when coordinates exist, plain img otherwise.
		if ( $shot_id ) {
			$has_coords = ( '' !== $x && '' !== $y );
			if ( $has_coords ) {
				$svg = Tuft_SVG_Annotation::build( $shot_id, (float) $x, (float) $y, $issue_id, $rect );
				if ( $svg ) {
					$content .= "\n<figure style=\"margin:12px 0;\">" . $svg . '</figure>';
				}
			} else {
				$shot_url = wp_get_attachment_url( $shot_id );
				if ( $shot_url ) {
					$content .= "\n" . '<img src="' . esc_url( $shot_url ) . '" alt="' . esc_attr__( 'Screenshot', 'tuft' ) . '" style="max-width:100%;height:auto;display:block;" />';
				}
			}
		}

		if ( empty( $content ) ) {
			return;
		}

		$user = wp_get_current_user();

		wp_insert_comment(
			array(
				'comment_post_ID'      => $issue_id,
				'comment_author'       => $user->display_name ? $user->display_name : 'Tuft',
				'comment_author_email' => $user->user_email ? $user->user_email : '',
				'comment_content'      => $content,
				'comment_type'         => 'issuecomment',
				'comment_parent'       => 0,
				'user_id'              => $user->ID,
				'comment_approved'     => 1,
			)
		);
	}

	/**
	 * Best-effort browser name from a user-agent string.
	 *
	 * @param string $ua User-agent string.
	 * @return string Browser name, or empty string if unrecognised.
	 */
	private function detect_browser( $ua ) {
		if ( false !== strpos( $ua, 'Edg/' ) || false !== strpos( $ua, 'Edge/' ) ) {
			return 'Edge';
		}
		if ( false !== strpos( $ua, 'Firefox/' ) ) {
			return 'Firefox';
		}
		if ( false !== strpos( $ua, 'Chrome/' ) ) {
			return 'Chrome';
		}
		if ( false !== strpos( $ua, 'Safari/' ) ) {
			return 'Safari';
		}
		if ( false !== strpos( $ua, 'MSIE ' ) || false !== strpos( $ua, 'Trident/' ) ) {
			return 'Internet Explorer';
		}
		return '';
	}
}

new Tuft_Alpaca_Bridge();

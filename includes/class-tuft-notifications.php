<?php
/**
 * Outbound notifications for Tuft feedback submissions.
 *
 * Hooked to `tuft_feedback_submitted`. Sends:
 *   - Email to each address in `tuft_notify_email`
 *   - HTTP POST to each URL in `tuft_webhook_urls`
 *
 * The webhook payload is generic JSON and works with any service that accepts
 * an incoming HTTP POST, including Slack incoming webhooks (the `text` field
 * is consumed directly), Discord, Teams, Zapier, Make, and custom endpoints.
 *
 * @package Tuft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends email and webhook notifications when feedback is submitted.
 */
class Tuft_Notifications {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'tuft_feedback_submitted', array( $this, 'notify' ) );
	}

	/**
	 * Dispatch all configured notifications for a submission.
	 *
	 * @param int $post_id The tuft_feedback post ID.
	 */
	public function notify( int $post_id ) {
		$payload = $this->build_payload( $post_id );
		if ( ! $payload ) {
			return;
		}

		$this->send_emails( $payload );
		$this->send_webhooks( $payload );
	}

	// ── Payload ────────────────────────────────────────────────

	/**
	 * Assemble the notification payload from post meta.
	 *
	 * Returns null if the post cannot be found.
	 *
	 * @param int $post_id The tuft_feedback post ID.
	 * @return array|null Associative payload array, or null on failure.
	 */
	private function build_payload( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$feedback   = get_post_meta( $post_id, '_tuft_feedback_text', true );
		$page_url   = get_post_meta( $post_id, '_tuft_page_url', true );
		$page_title = get_post_meta( $post_id, '_tuft_page_title', true );
		$selector   = get_post_meta( $post_id, '_tuft_selector', true );
		$name       = get_post_meta( $post_id, '_tuft_submitter_name', true );
		$email      = get_post_meta( $post_id, '_tuft_submitter_email', true );
		$admin_url  = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		// Human-readable summary line for services that accept a plain `text` field
		// (e.g. Slack incoming webhooks).
		$from    = $name ?: ( $email ?: __( 'Anonymous', 'tuft' ) );
		$on_page = $page_title ?: $page_url ?: __( 'unknown page', 'tuft' );
		$text    = sprintf(
			/* translators: 1: submitter name/email, 2: page title or URL, 3: feedback text */
			__( 'New feedback from %1$s on "%2$s": %3$s', 'tuft' ),
			$from,
			$on_page,
			$feedback
		);

		return array(
			'text'           => $text,
			'id'             => $post_id,
			'feedback'       => $feedback,
			'page_url'       => $page_url,
			'page_title'     => $page_title,
			'selector'       => $selector,
			'submitter_name' => $name,
			'submitter_email'=> $email,
			'admin_url'      => $admin_url,
			'submitted_at'   => get_post_datetime( $post, 'date', 'gmt' )->format( DATE_ATOM ),
		);
	}

	// ── Email ──────────────────────────────────────────────────

	/**
	 * Send a plain-text email to each configured address.
	 *
	 * @param array $payload Notification payload from build_payload().
	 */
	private function send_emails( array $payload ) {
		$option = get_option( 'tuft_notify_email', '' );
		if ( ! $option ) {
			return;
		}

		$addresses = array_filter( array_map( 'trim', explode( ',', $option ) ) );
		if ( ! $addresses ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: page title or URL */
			__( '[Tuft] New feedback on "%s"', 'tuft' ),
			$payload['page_title'] ?: $payload['page_url'] ?: __( 'unknown page', 'tuft' )
		);

		$lines = array( $payload['feedback'], '' );

		if ( $payload['page_url'] ) {
			$lines[] = __( 'Page:', 'tuft' ) . ' ' . $payload['page_url'];
		}
		if ( $payload['selector'] ) {
			$lines[] = __( 'Element:', 'tuft' ) . ' ' . $payload['selector'];
		}
		if ( $payload['submitter_name'] ) {
			$lines[] = __( 'From:', 'tuft' ) . ' ' . $payload['submitter_name'];
		}
		if ( $payload['submitter_email'] ) {
			$lines[] = __( 'Email:', 'tuft' ) . ' ' . $payload['submitter_email'];
		}

		$lines[] = '';
		$lines[] = __( 'View in admin:', 'tuft' ) . ' ' . $payload['admin_url'];

		wp_mail( $addresses, $subject, implode( "\n", $lines ) );
	}

	// ── Webhooks ───────────────────────────────────────────────

	/**
	 * POST the JSON payload to each configured webhook URL.
	 *
	 * Failures are logged via error_log() but never surfaced to the submitter.
	 *
	 * @param array $payload Notification payload from build_payload().
	 */
	private function send_webhooks( array $payload ) {
		$option = get_option( 'tuft_webhook_urls', '' );
		if ( ! $option ) {
			return;
		}

		$urls = array_filter( array_map( 'trim', explode( "\n", $option ) ) );
		if ( ! $urls ) {
			return;
		}

		$body = wp_json_encode( $payload );

		foreach ( $urls as $url ) {
			if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			$response = wp_remote_post(
				$url,
				array(
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => $body,
					'timeout'  => 5,
					'blocking' => false, // fire-and-forget; don't stall the response
				)
			);

			if ( is_wp_error( $response ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Tuft webhook error (' . $url . '): ' . $response->get_error_message() );
			}
		}
	}
}

new Tuft_Notifications();

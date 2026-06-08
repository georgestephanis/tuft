<?php
/**
 * REST API controller for design feedback submissions.
 *
 * @package Tuft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the /tuft/v1/submit REST endpoint.
 */
class Tuft_REST_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'tuft/v1';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the plugin's REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => array( $this, 'check_submit_permission' ),
				'args'                => array(
					'feedback'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'pageUrl'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'pageTitle' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'selector'  => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'name'      => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'     => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);
	}

	/**
	 * Check whether the current request is allowed to submit feedback,
	 * mirroring the widget visibility setting.
	 *
	 * @return true|WP_Error
	 */
	public function check_submit_permission() {
		$visibility = Tuft_Settings::get_visibility();

		if ( 'everyone' === $visibility ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'tuft_rest_forbidden',
				__( 'You must be logged in to submit feedback.', 'tuft-feedback' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( 'editors' === $visibility && ! current_user_can( 'edit_others_posts' ) ) {
			return new WP_Error(
				'tuft_rest_forbidden',
				__( 'You do not have permission to submit feedback.', 'tuft-feedback' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( 'admins' === $visibility && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'tuft_rest_forbidden',
				__( 'You do not have permission to submit feedback.', 'tuft-feedback' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Handle a feedback submission.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function submit( WP_REST_Request $request ) {
		$submitter_ip = $this->get_submitter_ip();

		// Rate limiting — count existing submissions from this IP in the past hour.
		$limit = absint( get_option( 'tuft_rate_limit', 5 ) );
		if ( $limit > 0 ) {
			$recent = new WP_Query(
				array(
					'post_type'      => 'tuft_feedback',
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'date_query'     => array(
						array( 'after' => '1 hour ago' ),
					),
					'meta_query'     => array(
						array(
							'key'   => '_tuft_submitter_ip',
							'value' => $submitter_ip,
						),
					),
				)
			);

			if ( count( $recent->posts ) >= $limit ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Too many submissions. Please try again later.', 'tuft-feedback' ),
					),
					429
				);
			}
		}

		$feedback    = $request->get_param( 'feedback' );
		$page_url    = $request->get_param( 'pageUrl' ) ?? '';
		$page_title  = $request->get_param( 'pageTitle' ) ?? '';
		$selector    = $request->get_param( 'selector' ) ?? '';
		$x_percent   = isset( $request['xPercent'] ) ? (float) $request['xPercent'] : '';
		$y_percent   = isset( $request['yPercent'] ) ? (float) $request['yPercent'] : '';
		$rect_left   = isset( $request['rectLeft'] ) ? (float) $request['rectLeft'] : null;
		$rect_top    = isset( $request['rectTop'] ) ? (float) $request['rectTop'] : null;
		$rect_width  = isset( $request['rectWidth'] ) ? (float) $request['rectWidth'] : null;
		$rect_height = isset( $request['rectHeight'] ) ? (float) $request['rectHeight'] : null;
		$viewport_w  = isset( $request['viewportWidth'] ) ? absint( $request['viewportWidth'] ) : 0;
		$viewport_h  = isset( $request['viewportHeight'] ) ? absint( $request['viewportHeight'] ) : 0;
		$user_agent  = sanitize_text_field( $request['userAgent'] ?? '' );
		$name        = $request->get_param( 'name' ) ?? '';
		$email       = $request->get_param( 'email' ) ?? '';
		$form_state  = $request['formState'] ?? null;
		$screenshot  = $request['screenshot'] ?? '';

		// Fill in logged-in user info if not provided.
		if ( is_user_logged_in() ) {
			$user  = wp_get_current_user();
			$name  = '' !== $name ? $name : $user->display_name;
			$email = '' !== $email ? $email : $user->user_email;
		}

		$path       = $page_url ? ( wp_parse_url( $page_url, PHP_URL_PATH ) ?? '/' ) : 'unknown page';
		$post_title = sprintf( 'Feedback on "%s"', $page_title ? $page_title : $path );

		$post_id = wp_insert_post(
			array(
				'post_title'  => $post_title,
				'post_status' => 'publish',
				'post_type'   => 'tuft_feedback',
				'post_author' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $post_id->get_error_message(),
				),
				500
			);
		}

		$meta_map = array(
			'_tuft_feedback_text'   => $feedback,
			'_tuft_page_url'        => $page_url,
			'_tuft_page_title'      => $page_title,
			'_tuft_selector'        => $selector,
			'_tuft_x_percent'       => $x_percent,
			'_tuft_y_percent'       => $y_percent,
			'_tuft_viewport_w'      => $viewport_w,
			'_tuft_viewport_h'      => $viewport_h,
			'_tuft_submitter_name'  => $name,
			'_tuft_submitter_email' => $email,
			'_tuft_user_agent'      => $user_agent,
			'_tuft_submitter_ip'    => $submitter_ip,
		);

		foreach ( $meta_map as $key => $value ) {
			if ( '' !== $value && 0 !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		if ( $form_state ) {
			update_post_meta( $post_id, '_tuft_form_state', wp_json_encode( $form_state ) );
		}

		if ( null !== $rect_left && null !== $rect_top && null !== $rect_width && null !== $rect_height ) {
			update_post_meta(
				$post_id,
				'_tuft_rect',
				wp_json_encode(
					array(
						'left'   => $rect_left,
						'top'    => $rect_top,
						'width'  => $rect_width,
						'height' => $rect_height,
					)
				)
			);
		}

		if ( $screenshot ) {
			$this->save_screenshot( $screenshot, $post_id );
		}

		/**
		 * Fires after a design feedback entry is fully saved.
		 *
		 * @param int $post_id The tuft_feedback post ID.
		 */
		do_action( 'tuft_feedback_submitted', $post_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $post_id,
			),
			201
		);
	}

	/**
	 * Decode a base64 data URL and attach the image to a post.
	 *
	 * @param string $data_url Base64-encoded image data URL.
	 * @param int    $post_id  The tuft_feedback post ID to attach the image to.
	 */
	private function save_screenshot( $data_url, $post_id ) {
		if ( ! preg_match( '/^data:image\/(jpeg|png|webp);base64,/', $data_url ) ) {
			return;
		}

		$base64 = preg_replace( '/^data:image\/\w+;base64,/', '', $data_url );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding screenshot data, not obfuscating code.
		$image_data = base64_decode( $base64, true );

		if ( ! $image_data ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		$filename   = 'tuft-' . $post_id . '-' . time() . '.jpg';
		$filepath   = trailingslashit( $upload_dir['path'] ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( ! file_put_contents( $filepath, $image_data ) ) {
			return;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Tuft Screenshot',
				'post_status'    => 'inherit',
			),
			$filepath,
			$post_id
		);

		if ( is_wp_error( $attachment_id ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $filepath ) );
		update_post_meta( $post_id, '_tuft_screenshot_id', $attachment_id );
	}

	/**
	 * Return the most likely real IP for the current request.
	 *
	 * Checks X-Forwarded-For first (common on load-balanced hosts) and falls
	 * back to REMOTE_ADDR. The first address in XFF is taken as the client IP;
	 * if it is not a valid IP, REMOTE_ADDR is used instead.
	 *
	 * @return string IP address string, or '0.0.0.0' as a last resort.
	 */
	private function get_submitter_ip() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
			: '';

		if ( $forwarded ) {
			$first = trim( explode( ',', $forwarded )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
	}
}

new Tuft_REST_Controller();

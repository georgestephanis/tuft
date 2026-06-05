<?php
/**
 * REST API controller for design feedback submissions.
 *
 * @package Design_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the /design-feedback/v1/submit REST endpoint.
 */
class DF_REST_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'design-feedback/v1';

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
				'permission_callback' => '__return_true',
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
	 * Handle a feedback submission.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response
	 */
	public function submit( WP_REST_Request $request ) {
		$feedback   = $request->get_param( 'feedback' );
		$page_url   = $request->get_param( 'pageUrl' ) ?? '';
		$page_title = $request->get_param( 'pageTitle' ) ?? '';
		$selector   = $request->get_param( 'selector' ) ?? '';
		$x_percent  = isset( $request['xPercent'] ) ? (float) $request['xPercent'] : '';
		$y_percent  = isset( $request['yPercent'] ) ? (float) $request['yPercent'] : '';
		$viewport_w = isset( $request['viewportWidth'] ) ? absint( $request['viewportWidth'] ) : 0;
		$viewport_h = isset( $request['viewportHeight'] ) ? absint( $request['viewportHeight'] ) : 0;
		$user_agent = sanitize_text_field( $request['userAgent'] ?? '' );
		$name       = $request->get_param( 'name' ) ?? '';
		$email      = $request->get_param( 'email' ) ?? '';
		$form_state = $request['formState'] ?? null;
		$screenshot = $request['screenshot'] ?? '';

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
				'post_type'   => 'design_feedback',
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
			'_df_feedback_text'   => $feedback,
			'_df_page_url'        => $page_url,
			'_df_page_title'      => $page_title,
			'_df_selector'        => $selector,
			'_df_x_percent'       => $x_percent,
			'_df_y_percent'       => $y_percent,
			'_df_viewport_w'      => $viewport_w,
			'_df_viewport_h'      => $viewport_h,
			'_df_submitter_name'  => $name,
			'_df_submitter_email' => $email,
			'_df_user_agent'      => $user_agent,
		);

		foreach ( $meta_map as $key => $value ) {
			if ( '' !== $value && 0 !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		if ( $form_state ) {
			update_post_meta( $post_id, '_df_form_state', wp_json_encode( $form_state ) );
		}

		if ( $screenshot ) {
			$this->save_screenshot( $screenshot, $post_id );
		}

		/**
		 * Fires after a design feedback entry is fully saved.
		 *
		 * @param int $post_id The design_feedback post ID.
		 */
		do_action( 'df_feedback_submitted', $post_id );

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
	 * @param int    $post_id  The design_feedback post ID to attach the image to.
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
		$filename   = 'design-feedback-' . $post_id . '-' . time() . '.jpg';
		$filepath   = trailingslashit( $upload_dir['path'] ) . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( ! file_put_contents( $filepath, $image_data ) ) {
			return;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Design Feedback Screenshot',
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
		update_post_meta( $post_id, '_df_screenshot_id', $attachment_id );
	}
}

new DF_REST_Controller();

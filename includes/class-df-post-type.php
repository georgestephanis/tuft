<?php
/**
 * Registers the design_feedback custom post type and its admin UI.
 *
 * @package Design_Feedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles post-type registration and all wp-admin list/detail UI for design feedback.
 */
class DF_Post_Type {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'admin_menu', array( $this, 'adjust_menu' ), 20 );
		add_action( 'admin_notices', array( $this, 'maybe_suggest_alpaca' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_filter( 'manage_design_feedback_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_design_feedback_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-design_feedback_sortable_columns', array( $this, 'sortable_columns' ) );
	}

	/**
	 * When Alpaca Issue Tracker is active, tuck Design Feedback under its board
	 * menu instead of showing it as a standalone top-level item.
	 *
	 * The standard list table and post-edit screen (the expanded detail view)
	 * remain fully functional — they just live under a different menu parent.
	 */
	public function adjust_menu() {
		if ( ! post_type_exists( 'alpaca_issue' ) ) {
			return;
		}

		remove_menu_page( 'edit.php?post_type=design_feedback' );

		add_submenu_page(
			'project-board',
			__( 'Design Feedback', 'design-feedback' ),
			__( 'Design Feedback', 'design-feedback' ),
			'edit_posts',
			'edit.php?post_type=design_feedback'
		);
	}

	/**
	 * Register the design_feedback post type.
	 */
	public function register() {
		register_post_type(
			'design_feedback',
			array(
				'labels'          => array(
					'name'               => __( 'Design Feedback', 'design-feedback' ),
					'singular_name'      => __( 'Feedback Entry', 'design-feedback' ),
					'menu_name'          => __( 'Design Feedback', 'design-feedback' ),
					'all_items'          => __( 'All Feedback', 'design-feedback' ),
					'not_found'          => __( 'No feedback submitted yet.', 'design-feedback' ),
					'not_found_in_trash' => __( 'No feedback in trash.', 'design-feedback' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-format-chat',
				'menu_position'   => 58,
				'capability_type' => 'post',
				'capabilities'    => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap'    => true,
				'supports'        => array( 'title' ),
				'show_in_rest'    => false,
			)
		);
	}

	/**
	 * Suggest installing Alpaca Issue Tracker on the design_feedback list screen
	 * when it is not already active.
	 */
	public function maybe_suggest_alpaca() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-design_feedback' !== $screen->id ) {
			return;
		}
		if ( post_type_exists( 'alpaca_issue' ) ) {
			return;
		}
		$install_url = admin_url( 'plugin-install.php?tab=plugin-information&plugin=alpaca-issue-tracker' );
		?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: 1: opening <a> tag, 2: closing </a> tag */
					esc_html__( 'Want a Kanban board to track and triage these submissions? Install %1$sAlpaca Issue Tracker%2$s — Design Feedback will automatically forward new submissions to your board.', 'design-feedback' ),
					'<a href="' . esc_url( $install_url ) . '">',
					'</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Define the columns shown on the design_feedback list table.
	 *
	 * @param array $columns Default column map.
	 * @return array Modified column map.
	 */
	public function columns( array $columns ) {
		return array(
			'cb'            => $columns['cb'],
			'title'         => __( 'Feedback', 'design-feedback' ),
			'df_page'       => __( 'Page', 'design-feedback' ),
			'df_element'    => __( 'Element', 'design-feedback' ),
			'df_submitter'  => __( 'Submitted By', 'design-feedback' ),
			'df_screenshot' => __( 'Screenshot', 'design-feedback' ),
			'date'          => $columns['date'],
		);
	}

	/**
	 * Declare which custom columns are sortable.
	 *
	 * @param array $columns Sortable column map.
	 * @return array Modified sortable column map.
	 */
	public function sortable_columns( array $columns ) {
		$columns['df_page'] = 'df_page';
		return $columns;
	}

	/**
	 * Output the cell content for each custom column on the list table.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Current post ID.
	 */
	public function render_column( string $column, int $post_id ) {
		switch ( $column ) {

			case 'title':
				$text = get_post_meta( $post_id, '_df_feedback_text', true );
				echo '<strong>' . esc_html( wp_trim_words( $text, 20, '…' ) ) . '</strong>';
				break;

			case 'df_page':
				$url   = get_post_meta( $post_id, '_df_page_url', true );
				$title = get_post_meta( $post_id, '_df_page_title', true );
				if ( $url ) {
					$label = $title ? $title : wp_parse_url( $url, PHP_URL_PATH );
					echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $label ) . '</a>';
				}
				break;

			case 'df_element':
				$selector = get_post_meta( $post_id, '_df_selector', true );
				echo $selector
					? '<code style="font-size:11px;word-break:break-all;">' . esc_html( $selector ) . '</code>'
					: '<span class="description">—</span>';
				break;

			case 'df_submitter':
				$name  = get_post_meta( $post_id, '_df_submitter_name', true );
				$email = get_post_meta( $post_id, '_df_submitter_email', true );
				if ( $name ) {
					echo esc_html( $name );
					if ( $email ) {
						echo '<br><small>' . esc_html( $email ) . '</small>';
					}
				} elseif ( $email ) {
					echo esc_html( $email );
				} else {
					echo '<span class="description">Anonymous</span>';
				}
				break;

			case 'df_screenshot':
				$id = get_post_meta( $post_id, '_df_screenshot_id', true );
				echo $id
					? wp_get_attachment_image( $id, array( 80, 55 ), false, array( 'style' => 'border:1px solid #ddd;border-radius:2px;' ) )
					: '<span class="description">—</span>';
				break;
		}
	}

	/**
	 * Register the Feedback Details meta box on the design_feedback edit screen.
	 */
	public function add_meta_box() {
		add_meta_box(
			'df-details',
			__( 'Feedback Details', 'design-feedback' ),
			array( $this, 'render_meta_box' ),
			'design_feedback',
			'normal',
			'high'
		);
	}

	/**
	 * Render the Feedback Details meta box content.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( WP_Post $post ) {
		$text       = get_post_meta( $post->ID, '_df_feedback_text', true );
		$page_url   = get_post_meta( $post->ID, '_df_page_url', true );
		$page_title = get_post_meta( $post->ID, '_df_page_title', true );
		$selector   = get_post_meta( $post->ID, '_df_selector', true );
		$x          = get_post_meta( $post->ID, '_df_x_percent', true );
		$y          = get_post_meta( $post->ID, '_df_y_percent', true );
		$vw         = get_post_meta( $post->ID, '_df_viewport_w', true );
		$vh         = get_post_meta( $post->ID, '_df_viewport_h', true );
		$name       = get_post_meta( $post->ID, '_df_submitter_name', true );
		$email      = get_post_meta( $post->ID, '_df_submitter_email', true );
		$ua         = get_post_meta( $post->ID, '_df_user_agent', true );
		$form_state = get_post_meta( $post->ID, '_df_form_state', true );
		$shot_id    = get_post_meta( $post->ID, '_df_screenshot_id', true );
		$rect_raw   = get_post_meta( $post->ID, '_df_rect', true );
		$rect       = $rect_raw ? json_decode( $rect_raw, true ) : null;
		?>
		<style>
			.df-meta-table th { width: 140px; font-weight: 600; vertical-align: top; padding: 6px 10px 6px 0; }
			.df-meta-table td { vertical-align: top; padding: 6px 0; }
			.df-feedback-text { background: #f9f9f9; border-left: 3px solid #0073aa; padding: 10px 14px; margin-bottom: 16px; font-size: 14px; line-height: 1.6; white-space: pre-wrap; }
		</style>

		<?php if ( $text ) : ?>
			<div class="df-feedback-text"><?php echo esc_html( $text ); ?></div>
		<?php endif; ?>

		<table class="form-table df-meta-table">
			<?php if ( $page_url ) : ?>
			<tr>
				<th><?php esc_html_e( 'Page', 'design-feedback' ); ?></th>
				<td>
					<a href="<?php echo esc_url( $page_url ); ?>" target="_blank"><?php echo esc_html( $page_title ? $page_title : $page_url ); ?></a><br>
					<small><?php echo esc_html( $page_url ); ?></small>
				</td>
			</tr>
			<?php endif; ?>

			<?php if ( $selector ) : ?>
			<tr>
				<th><?php esc_html_e( 'Element', 'design-feedback' ); ?></th>
				<td><code><?php echo esc_html( $selector ); ?></code></td>
			</tr>
			<?php endif; ?>

			<?php if ( '' !== $x && '' !== $y ) : ?>
			<tr>
				<th><?php esc_html_e( 'Click Position', 'design-feedback' ); ?></th>
				<td><?php echo esc_html( $x . '% × ' . $y . '%' ); ?></td>
			</tr>
			<?php endif; ?>

			<?php if ( is_array( $rect ) && isset( $rect['left'], $rect['top'], $rect['width'], $rect['height'] ) ) : ?>
			<tr>
				<th><?php esc_html_e( 'Element Bounds', 'design-feedback' ); ?></th>
				<td>
					<?php
					echo esc_html(
						round( $rect['left'], 1 ) . '%, ' . round( $rect['top'], 1 ) . '% — '
						. round( $rect['width'], 1 ) . '% × ' . round( $rect['height'], 1 ) . '%'
					);
					?>
				</td>
			</tr>
			<?php endif; ?>

			<?php if ( $vw && $vh ) : ?>
			<tr>
				<th><?php esc_html_e( 'Viewport', 'design-feedback' ); ?></th>
				<td><?php echo esc_html( $vw . ' × ' . $vh . 'px' ); ?></td>
			</tr>
			<?php endif; ?>

			<?php if ( $name || $email ) : ?>
			<tr>
				<th><?php esc_html_e( 'Submitted By', 'design-feedback' ); ?></th>
				<td>
					<?php echo esc_html( $name ); ?>
					<?php if ( $email ) : ?>
						<br><a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<?php endif; ?>

			<?php if ( $ua ) : ?>
			<tr>
				<th><?php esc_html_e( 'User Agent', 'design-feedback' ); ?></th>
				<td><small><?php echo esc_html( $ua ); ?></small></td>
			</tr>
			<?php endif; ?>

			<?php if ( $form_state ) : ?>
			<tr>
				<th><?php esc_html_e( 'Form State', 'design-feedback' ); ?></th>
				<td><pre style="margin:0;font-size:11px;white-space:pre-wrap;background:#f9f9f9;padding:8px;"><?php echo esc_html( $form_state ); ?></pre></td>
			</tr>
			<?php endif; ?>
		</table>

		<?php
		$alpaca_id = get_post_meta( $post->ID, '_df_alpaca_issue_id', true );
		if ( $alpaca_id ) :
			$board_url   = admin_url( 'admin.php?page=project-board' );
			$alpaca_post = get_post( $alpaca_id );
			?>
			<p style="margin-top:16px;">
				<strong><?php esc_html_e( 'Alpaca Issue Tracker:', 'design-feedback' ); ?></strong>
				<a href="<?php echo esc_url( $board_url ); ?>" target="_blank">
					<?php echo $alpaca_post ? esc_html( $alpaca_post->post_title ) : '#' . (int) $alpaca_id; ?>
				</a>
				<span class="description">(Issue #<?php echo (int) $alpaca_id; ?>)</span>
			</p>
		<?php endif; ?>

		<?php if ( $shot_id ) : ?>
			<h3 style="margin-top:20px;"><?php esc_html_e( 'Screenshot', 'design-feedback' ); ?></h3>
			<?php
			$shot_url   = wp_get_attachment_url( $shot_id );
			$has_coords = ( '' !== $x && '' !== $y );
			if ( $has_coords ) {
				$svg = DF_SVG_Annotation::build( $shot_id, (float) $x, (float) $y, $post->ID, $rect );
				if ( $svg ) {
					echo '<a href="' . esc_url( $shot_url ) . '" target="_blank" style="display:block;">';
					echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built entirely from trusted meta + esc_url/esc_attr calls within the method.
					echo '</a>';
				}
			} else {
				echo '<a href="' . esc_url( $shot_url ) . '" target="_blank">';
				echo wp_get_attachment_image( $shot_id, 'large', false, array( 'style' => 'max-width:100%;border:1px solid #ddd;border-radius:3px;' ) );
				echo '</a>';
			}
			?>
		<?php endif; ?>
		<?php
	}
}


new DF_Post_Type();

<?php
/**
 * Settings page for Tuft.
 *
 * Registers the Settings → Tuft Feedback admin page and the following options:
 *   tuft_visibility   — who sees the widget: everyone|logged_in|editors|admins
 *   tuft_rate_limit   — max submissions per IP per hour (0 = unlimited)
 *   tuft_notify_email — comma-separated addresses for submission emails (used by Tuft_Notifications)
 *
 * @package Tuft
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the Tuft settings page.
 */
class Tuft_Settings {

	/**
	 * Valid visibility values.
	 *
	 * @var string[]
	 */
	const VISIBILITY_OPTIONS = array( 'everyone', 'logged_in', 'editors', 'admins' );

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the Settings → Tuft Feedback page.
	 */
	public function add_page() {
		add_options_page(
			__( 'Tuft Settings', 'tuft' ),
			__( 'Tuft Feedback', 'tuft' ),
			'manage_options',
			'tuft-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields.
	 */
	public function register_settings() {
		register_setting(
			'tuft',
			'tuft_visibility',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_visibility' ),
				'default'           => 'logged_in',
			)
		);

		register_setting(
			'tuft',
			'tuft_rate_limit',
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 5,
			)
		);

		register_setting(
			'tuft',
			'tuft_notify_email',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_email_list' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'tuft_widget',
			__( 'Widget visibility', 'tuft' ),
			'__return_false',
			'tuft-settings'
		);

		add_settings_field(
			'tuft_visibility',
			__( 'Who can see the widget', 'tuft' ),
			array( $this, 'render_visibility_field' ),
			'tuft-settings',
			'tuft_widget'
		);

		add_settings_field(
			'tuft_rate_limit',
			__( 'Rate limit', 'tuft' ),
			array( $this, 'render_rate_limit_field' ),
			'tuft-settings',
			'tuft_widget'
		);

		add_settings_section(
			'tuft_notifications',
			__( 'Notifications', 'tuft' ),
			'__return_false',
			'tuft-settings'
		);

		add_settings_field(
			'tuft_notify_email',
			__( 'Notify email(s)', 'tuft' ),
			array( $this, 'render_notify_email_field' ),
			'tuft-settings',
			'tuft_notifications'
		);
	}

	// ── Field renderers ────────────────────────────────────────

	/**
	 * Render the visibility radio-button group.
	 */
	public function render_visibility_field() {
		$value  = $this->get_visibility();
		$counts = $this->get_role_counts();

		$options = array(
			'everyone'  => __( 'Everyone (logged in and logged out)', 'tuft' ),
			'logged_in' => __( 'Logged-in users only', 'tuft' ),
			/* translators: %d: approximate number of users with this access level */
			'editors'   => sprintf( __( 'Editors and above&nbsp;&nbsp;<span class="description">(%d users)</span>', 'tuft' ), $counts['editors'] ),
			/* translators: %d: approximate number of users with this access level */
			'admins'    => sprintf( __( 'Administrators only&nbsp;&nbsp;<span class="description">(%d users)</span>', 'tuft' ), $counts['admins'] ),
		);
		?>
		<fieldset>
			<legend class="screen-reader-text">
				<?php esc_html_e( 'Who can see the widget', 'tuft' ); ?>
			</legend>
			<?php foreach ( $options as $option_value => $label ) : ?>
			<label style="display:block;margin-bottom:6px;">
				<input
					type="radio"
					name="tuft_visibility"
					value="<?php echo esc_attr( $option_value ); ?>"
					<?php checked( $value, $option_value ); ?>
				/>
				<?php
				// Label contains an intentional <span class="description"> for the counts —
				// it is built from translated strings and escaped integers only.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $label;
				?>
			</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'User counts reflect standard WordPress roles. Custom roles with equivalent capabilities are not included in the count.', 'tuft' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the rate-limit number input.
	 */
	public function render_rate_limit_field() {
		$value = absint( get_option( 'tuft_rate_limit', 5 ) );
		?>
		<input
			type="number"
			name="tuft_rate_limit"
			value="<?php echo esc_attr( $value ); ?>"
			min="0"
			max="100"
			step="1"
			class="small-text"
		/>
		<span class="description">
			<?php esc_html_e( 'Maximum submissions per IP per hour. Set to 0 to disable rate limiting.', 'tuft' ); ?>
		</span>
		<?php
	}

	/**
	 * Render the notification email text input.
	 */
	public function render_notify_email_field() {
		$value = get_option( 'tuft_notify_email', '' );
		?>
		<input
			type="text"
			name="tuft_notify_email"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="you@example.com, team@example.com"
		/>
		<p class="description">
			<?php esc_html_e( 'Comma-separated list of addresses to notify when feedback is submitted. Leave blank to disable email notifications.', 'tuft' ); ?>
		</p>
		<?php
	}

	// ── Sanitisation ───────────────────────────────────────────

	/**
	 * Sanitize the visibility value, falling back to 'logged_in' for unknown values.
	 *
	 * @param string $value Raw input.
	 * @return string One of: everyone, logged_in, editors, admins.
	 */
	public function sanitize_visibility( $value ) {
		return in_array( $value, self::VISIBILITY_OPTIONS, true ) ? $value : 'logged_in';
	}

	/**
	 * Sanitize a comma-separated list of email addresses.
	 * Invalid addresses are silently dropped.
	 *
	 * @param string $value Raw input.
	 * @return string Cleaned, comma-separated valid addresses.
	 */
	public function sanitize_email_list( $value ) {
		$parts = explode( ',', (string) $value );
		$valid = array();
		foreach ( $parts as $part ) {
			$email = sanitize_email( trim( $part ) );
			if ( $email ) {
				$valid[] = $email;
			}
		}
		return implode( ', ', $valid );
	}

	// ── Helpers ────────────────────────────────────────────────

	/**
	 * Return the current visibility setting, guaranteeing a valid value.
	 *
	 * @return string
	 */
	public static function get_visibility() {
		$value = get_option( 'tuft_visibility', 'logged_in' );
		return in_array( $value, self::VISIBILITY_OPTIONS, true ) ? $value : 'logged_in';
	}

	/**
	 * Return approximate user counts for the two role-gated visibility options.
	 *
	 * Counts standard WordPress roles only:
	 *   editors — administrator + editor (both have edit_others_posts by default)
	 *   admins  — administrator (manage_options)
	 *
	 * @return array{ editors: int, admins: int }
	 */
	private function get_role_counts() {
		$counts = count_users();
		$roles  = $counts['avail_roles'];

		return array(
			'editors' => ( $roles['administrator'] ?? 0 ) + ( $roles['editor'] ?? 0 ),
			'admins'  => $roles['administrator'] ?? 0,
		);
	}

	// ── Page renderer ──────────────────────────────────────────

	/**
	 * Render the full settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'tuft' );
				do_settings_sections( 'tuft-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

new Tuft_Settings();

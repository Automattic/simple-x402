<?php
/**
 * Admin: Settings → Simple x402 page.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

use SimpleX402\Settings\SettingsRepository;

/**
 * Settings → Simple x402 admin page.
 *
 * Two sections:
 *  - Payments: receiving wallet, price per request.
 *  - What to paywall: mode (category / all-posts) and the paywall category name.
 *
 * Registered as an options page under Settings rather than its own top-level
 * menu; the Settings API handles persistence.
 */
final class SettingsPage {

	public const MENU_SLUG     = 'simple-x402';
	public const GROUP         = 'simple_x402_settings_group';
	public const SCRIPT_HANDLE = 'simple-x402-admin';

	public function __construct( private readonly SettingsRepository $settings ) {}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register admin JS for this page only.
	 *
	 * Hook suffix for a submenu added via `add_options_page` follows the
	 * `settings_page_{slug}` convention — we guard on it so the script is not
	 * loaded site-wide in the admin.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/admin-settings.js', SIMPLE_X402_FILE ),
			array(),
			SIMPLE_X402_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'simpleX402Settings',
			array(
				'option'       => SettingsRepository::OPTION_NAME,
				'modeCategory' => SettingsRepository::MODE_CATEGORY,
			)
		);
	}

	/**
	 * Add the Settings → Simple x402 menu item.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Simple x402', 'simple-x402' ),
			__( 'Simple x402', 'simple-x402' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the single option that backs both fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			SettingsRepository::OPTION_NAME,
			array(
				// Must be pure: WP calls this from inside update_option, so
				// persisting here (e.g. via SettingsRepository::save) recurses.
				'sanitize_callback' => fn ( $input ): array => $this->settings->sanitize(
					is_array( $input ) ? $input : array()
				),
			)
		);
	}

	/**
	 * Render the settings form.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$wallet  = $this->settings->wallet_address();
		$price   = $this->settings->default_price();
		$mode    = $this->settings->paywall_mode();
		$term_id = $this->settings->paywall_category_term_id();
		$option  = SettingsRepository::OPTION_NAME;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Simple x402', 'simple-x402' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Payments', 'simple-x402' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="sx402-wallet">
								<?php esc_html_e( 'Receiving wallet', 'simple-x402' ); ?>
							</label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( $option ); ?>[wallet_address]"
								id="sx402-wallet"
								type="text"
								class="regular-text"
								value="<?php echo esc_attr( $wallet ); ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'USDC is sent to this address on Base Sepolia.', 'simple-x402' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sx402-price">
								<?php esc_html_e( 'Price per request (USDC)', 'simple-x402' ); ?>
							</label>
						</th>
						<td>
							<input
								name="<?php echo esc_attr( $option ); ?>[default_price]"
								id="sx402-price"
								type="text"
								class="small-text"
								value="<?php echo esc_attr( $price ); ?>"
							/>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'What to paywall', 'simple-x402' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Which posts should be paywalled?', 'simple-x402' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input
										type="radio"
										name="<?php echo esc_attr( $option ); ?>[paywall_mode]"
										value="<?php echo esc_attr( SettingsRepository::MODE_ALL_POSTS ); ?>"
										<?php checked( $mode, SettingsRepository::MODE_ALL_POSTS ); ?>
									/>
									<?php esc_html_e( 'Every published post', 'simple-x402' ); ?>
								</label><br />
								<label>
									<input
										type="radio"
										name="<?php echo esc_attr( $option ); ?>[paywall_mode]"
										value="<?php echo esc_attr( SettingsRepository::MODE_CATEGORY ); ?>"
										<?php checked( $mode, SettingsRepository::MODE_CATEGORY ); ?>
									/>
									<?php esc_html_e( 'Only posts in a specific category:', 'simple-x402' ); ?>
								</label>
								<div style="margin: 6px 0 0 24px;">
									<?php
									$dropdown = (string) wp_dropdown_categories(
										array(
											'name'         => $option . '[paywall_category_term_id]',
											'id'           => 'sx402-category',
											'taxonomy'     => 'category',
											'hide_empty'   => false,
											'show_option_none' => false,
											'selected'     => $term_id,
											'hierarchical' => true,
											'echo'         => 0,
										)
									);
									if ( SettingsRepository::MODE_ALL_POSTS === $mode ) {
										$dropdown = preg_replace(
											'/<select\b/',
											'<select disabled="disabled"',
											$dropdown,
											1
										);
									}
									echo $dropdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated by wp_dropdown_categories.
									?>
								</div>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

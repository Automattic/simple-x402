<?php
/**
 * Admin: Settings → Simple x402 page.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

use SimpleX402\Services\FacilitatorProfile;
use SimpleX402\Settings\SettingsRepository;

/**
 * Settings → Simple x402 admin page.
 *
 * Sections:
 *  - Mode (test / live) + mode banner.
 *  - What to paywall: mode (category / all-posts) and the paywall category name.
 *  - Who to paywall: audience (everyone / bots / none).
 *  - Where to send the funds: per-mode wallet + price, and live-only
 *    facilitator URL + API key.
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
				'modeCategory' => SettingsRepository::PAYWALL_MODE_CATEGORY,
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
		$mode         = $this->settings->mode();
		$paywall_mode = $this->settings->paywall_mode();
		$audience     = $this->settings->paywall_audience();
		$term_id      = $this->settings->paywall_category_term_id();
		$test_profile = FacilitatorProfile::for_test();
		$live_profile = FacilitatorProfile::for_live();
		$option       = SettingsRepository::OPTION_NAME;
		$test_prefix  = $option . '[' . FacilitatorProfile::MODE_TEST . ']';
		$live_prefix  = $option . '[' . FacilitatorProfile::MODE_LIVE . ']';
		$stored       = get_option( SettingsRepository::OPTION_NAME, array() );
		$stored       = is_array( $stored ) ? $stored : array();
		$test_block   = is_array( $stored[ FacilitatorProfile::MODE_TEST ] ?? null ) ? $stored[ FacilitatorProfile::MODE_TEST ] : array();
		$live_block   = is_array( $stored[ FacilitatorProfile::MODE_LIVE ] ?? null ) ? $stored[ FacilitatorProfile::MODE_LIVE ] : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Simple x402', 'simple-x402' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

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
										value="<?php echo esc_attr( SettingsRepository::PAYWALL_MODE_NONE ); ?>"
										<?php checked( $paywall_mode, SettingsRepository::PAYWALL_MODE_NONE ); ?>
									/>
									<?php esc_html_e( 'No posts (paywall disabled)', 'simple-x402' ); ?>
								</label><br />
								<label>
									<input
										type="radio"
										name="<?php echo esc_attr( $option ); ?>[paywall_mode]"
										value="<?php echo esc_attr( SettingsRepository::PAYWALL_MODE_ALL_POSTS ); ?>"
										<?php checked( $paywall_mode, SettingsRepository::PAYWALL_MODE_ALL_POSTS ); ?>
									/>
									<?php esc_html_e( 'Every published post', 'simple-x402' ); ?>
								</label><br />
								<label>
									<input
										type="radio"
										name="<?php echo esc_attr( $option ); ?>[paywall_mode]"
										value="<?php echo esc_attr( SettingsRepository::PAYWALL_MODE_CATEGORY ); ?>"
										<?php checked( $paywall_mode, SettingsRepository::PAYWALL_MODE_CATEGORY ); ?>
									/>
									<?php esc_html_e( 'Only posts in a specific category:', 'simple-x402' ); ?>
								</label>
								<fieldset
									id="sx402-category-wrap"
									style="border:0; padding:0; margin: 6px 0 0 24px;"
									<?php disabled( SettingsRepository::PAYWALL_MODE_CATEGORY === $paywall_mode, false ); ?>
								>
									<?php
									echo (string) wp_dropdown_categories( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated by wp_dropdown_categories.
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
									?>
								</fieldset>
							</fieldset>
						</td>
					</tr>
				</table>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Which visitors should see the paywall?', 'simple-x402' ); ?>
						</th>
						<td>
							<fieldset>
								<label>
									<input
										type="radio"
										name="<?php echo esc_attr( $option ); ?>[paywall_audience]"
										value="<?php echo esc_attr( SettingsRepository::AUDIENCE_EVERYONE ); ?>"
										<?php checked( $audience, SettingsRepository::AUDIENCE_EVERYONE ); ?>
									/>
									<?php esc_html_e( 'Everyone (humans and bots)', 'simple-x402' ); ?>
								</label><br />
								<label>
									<input
										type="radio"
										name="<?php echo esc_attr( $option ); ?>[paywall_audience]"
										value="<?php echo esc_attr( SettingsRepository::AUDIENCE_BOTS ); ?>"
										<?php checked( $audience, SettingsRepository::AUDIENCE_BOTS ); ?>
									/>
									<?php esc_html_e( 'Only detected bots and crawlers', 'simple-x402' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Payment details', 'simple-x402' ); ?></h2>

					<div class="sx402-field">
						<span class="sx402-field-label"><?php esc_html_e( 'Mode', 'simple-x402' ); ?></span>
						<fieldset>
							<label>
								<input
									type="radio"
									name="<?php echo esc_attr( $option ); ?>[mode]"
									value="<?php echo esc_attr( FacilitatorProfile::MODE_TEST ); ?>"
									<?php checked( $mode, FacilitatorProfile::MODE_TEST ); ?>
								/>
								<?php echo esc_html( $test_profile->label ); ?>
							</label>
							<label style="margin-left:12px;">
								<input
									type="radio"
									name="<?php echo esc_attr( $option ); ?>[mode]"
									value="<?php echo esc_attr( FacilitatorProfile::MODE_LIVE ); ?>"
									<?php checked( $mode, FacilitatorProfile::MODE_LIVE ); ?>
								/>
								<?php echo esc_html( $live_profile->label ); ?>
							</label>
						</fieldset>
					</div>

					<div class="sx402-mode-columns" style="display:flex; gap:32px; flex-wrap:wrap; align-items:flex-start;">
						<div style="flex:1 1 320px; min-width:0;">
							<h3><?php esc_html_e( 'Test settings', 'simple-x402' ); ?></h3>

							<div class="sx402-field">
								<label for="sx402-test-wallet" class="sx402-field-label">
									<?php esc_html_e( 'Receiving wallet (Base Sepolia)', 'simple-x402' ); ?>
								</label>
								<input
									name="<?php echo esc_attr( $test_prefix . '[wallet_address]' ); ?>"
									id="sx402-test-wallet"
									type="text"
									class="regular-text"
									value="<?php echo esc_attr( (string) ( $test_block['wallet_address'] ?? '' ) ); ?>"
								/>
							</div>

							<div class="sx402-field">
								<label for="sx402-test-price" class="sx402-field-label">
									<?php esc_html_e( 'Price per request (USDC)', 'simple-x402' ); ?>
								</label>
								<input
									name="<?php echo esc_attr( $test_prefix . '[default_price]' ); ?>"
									id="sx402-test-price"
									type="text"
									class="big-text"
									value="<?php echo esc_attr( (string) ( $test_block['default_price'] ?? '' ) ); ?>"
								/>
							</div>
						</div>

						<div style="flex:1 1 320px; min-width:0;">
							<h3><?php esc_html_e( 'Live settings', 'simple-x402' ); ?></h3>

							<div class="sx402-field">
								<label for="sx402-live-wallet" class="sx402-field-label">
									<?php esc_html_e( 'Receiving wallet (Base mainnet)', 'simple-x402' ); ?>
								</label>
								<input
									name="<?php echo esc_attr( $live_prefix . '[wallet_address]' ); ?>"
									id="sx402-live-wallet"
									type="text"
									class="regular-text"
									value="<?php echo esc_attr( (string) ( $live_block['wallet_address'] ?? '' ) ); ?>"
								/>
							</div>

							<div class="sx402-field">
								<label for="sx402-live-price" class="sx402-field-label">
									<?php esc_html_e( 'Price per request (USDC)', 'simple-x402' ); ?>
								</label>
								<input
									name="<?php echo esc_attr( $live_prefix . '[default_price]' ); ?>"
									id="sx402-live-price"
									type="text"
									class="big-text"
									value="<?php echo esc_attr( (string) ( $live_block['default_price'] ?? '' ) ); ?>"
								/>
							</div>

							<div class="sx402-field">
								<label for="sx402-live-facilitator-url" class="sx402-field-label">
									<?php esc_html_e( 'Facilitator URL', 'simple-x402' ); ?>
								</label>
								<input
									name="<?php echo esc_attr( $live_prefix . '[facilitator_url]' ); ?>"
									id="sx402-live-facilitator-url"
									type="text"
									class="regular-text"
									value="<?php echo esc_attr( (string) ( $live_block['facilitator_url'] ?? '' ) ); ?>"
									placeholder="<?php echo esc_attr( FacilitatorProfile::LIVE_FACILITATOR_URL_DEFAULT ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Leave blank to use the Coinbase CDP default.', 'simple-x402' ); ?>
								</p>
							</div>

							<div class="sx402-field">
								<label for="sx402-live-facilitator-key" class="sx402-field-label">
									<?php esc_html_e( 'Facilitator API key', 'simple-x402' ); ?>
								</label>
								<input
									name="<?php echo esc_attr( $live_prefix . '[facilitator_api_key]' ); ?>"
									id="sx402-live-facilitator-key"
									type="password"
									class="regular-text"
									value="<?php echo esc_attr( (string) ( $live_block['facilitator_api_key'] ?? '' ) ); ?>"
									autocomplete="new-password"
								/>
							</div>
						</div>
					</div>

				<style>
					.sx402-field { margin: 0 0 14px; }
					.sx402-field-label { display: block; font-weight: 600; margin-bottom: 4px; }
					.sx402-field .regular-text { width: 100%; max-width: 400px; }
				</style>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

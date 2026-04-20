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
 * Two-field settings page: wallet address, default price.
 *
 * Registered as an options page under Settings, not its own top-level menu —
 * MVP scope is one screen, two fields, the Settings API handles persistence.
 */
final class SettingsPage {

	public const MENU_SLUG = 'simple-x402';
	public const GROUP     = 'simple_x402_settings_group';

	public function __construct( private readonly SettingsRepository $settings ) {}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
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
		$wallet = $this->settings->wallet_address();
		$price  = $this->settings->default_price();
		$option = SettingsRepository::OPTION_NAME;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Simple x402', 'simple-x402' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="sx402-wallet">
								<?php esc_html_e( 'Receiving wallet address', 'simple-x402' ); ?>
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
								<?php esc_html_e( 'Base Sepolia address that receives USDC.', 'simple-x402' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sx402-price">
								<?php esc_html_e( 'Default price (USDC)', 'simple-x402' ); ?>
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

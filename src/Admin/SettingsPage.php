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
 * Renders a mount point + JSON bootstrap; the React app in
 * assets/build/admin/index.js handles the form UI. Form submission still
 * uses the classic options.php POST flow, so the React inputs include
 * hidden <input name="..."> fields with the values WP expects.
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
		add_filter( 'admin_body_class', array( $this, 'admin_body_class' ) );
	}

	/**
	 * Tag <body> on our screen so the bundle CSS can override #wpcontent gutters.
	 */
	public function admin_body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'settings_page_' . self::MENU_SLUG === $screen->id ) {
			$classes .= ' simple-x402-screen';
		}
		return $classes;
	}

	/**
	 * Register admin JS for this page only.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_path = SIMPLE_X402_DIR . 'assets/build/index.asset.php';
		$asset      = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => SIMPLE_X402_VERSION,
			);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/build/index.js', SIMPLE_X402_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );

		$style_path = SIMPLE_X402_DIR . 'assets/build/style-index.css';
		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				plugins_url( 'assets/build/style-index.css', SIMPLE_X402_FILE ),
				array( 'wp-components' ),
				$asset['version']
			);
		}

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'simpleX402Settings',
			$this->bootstrap_data()
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
	 * Register the single option that backs the entire form.
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
	 * Render the settings page shell. The React app paints itself into #simple-x402-app.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<header class="simple-x402-page__header">
				<h1 class="simple-x402-page__header-title">
					<?php esc_html_e( 'Simple x402', 'simple-x402' ); ?>
				</h1>
				<p class="simple-x402-page__header-subtitle">
					<?php esc_html_e(
						'Configure how the x402 paywall protects your content and where payments go.',
						'simple-x402'
					); ?>
				</p>
			</header>
			<form id="simple-x402-form" method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<div id="simple-x402-app"></div>
			</form>
		</div>
		<?php
	}

	/**
	 * Build the JSON payload the React app reads on boot.
	 *
	 * @return array<string,mixed>
	 */
	public function bootstrap_data(): array {
		$test_profile = FacilitatorProfile::for_test();
		$live_profile = FacilitatorProfile::for_live();

		$stored     = get_option( SettingsRepository::OPTION_NAME, array() );
		$stored     = is_array( $stored ) ? $stored : array();
		$test_block = is_array( $stored[ FacilitatorProfile::MODE_TEST ] ?? null ) ? $stored[ FacilitatorProfile::MODE_TEST ] : array();
		$live_block = is_array( $stored[ FacilitatorProfile::MODE_LIVE ] ?? null ) ? $stored[ FacilitatorProfile::MODE_LIVE ] : array();

		$categories = array_map(
			static fn ( $term ): array => array(
				'term_id' => (int) $term->term_id,
				'name'    => (string) $term->name,
			),
			get_terms(
				array(
					'taxonomy'   => 'category',
					'hide_empty' => false,
				)
			) ?: array()
		);

		return array(
			'option' => SettingsRepository::OPTION_NAME,
			'modes'  => array(
				'paywall'     => array(
					'none'     => SettingsRepository::PAYWALL_MODE_NONE,
					'allPosts' => SettingsRepository::PAYWALL_MODE_ALL_POSTS,
					'category' => SettingsRepository::PAYWALL_MODE_CATEGORY,
				),
				'audience'    => array(
					'everyone' => SettingsRepository::AUDIENCE_EVERYONE,
					'bots'     => SettingsRepository::AUDIENCE_BOTS,
				),
				'facilitator' => array(
					'test' => FacilitatorProfile::MODE_TEST,
					'live' => FacilitatorProfile::MODE_LIVE,
				),
			),
			'labels' => array(
				'testMode' => $test_profile->label,
				'liveMode' => $live_profile->label,
			),
			'liveFacilitatorPlaceholder' => FacilitatorProfile::LIVE_FACILITATOR_URL_DEFAULT,
			'categories'                 => $categories,
			'modeCategory'               => SettingsRepository::PAYWALL_MODE_CATEGORY,
			'values'                     => array(
				'mode'                     => $this->settings->mode(),
				'paywall_mode'             => $this->settings->paywall_mode(),
				'paywall_audience'         => $this->settings->paywall_audience(),
				'paywall_category_term_id' => $this->settings->paywall_category_term_id(),
				'test'                     => array(
					'wallet_address' => (string) ( $test_block['wallet_address'] ?? '' ),
					'default_price'  => (string) ( $test_block['default_price'] ?? '' ),
				),
				'live'                     => array(
					'wallet_address'      => (string) ( $live_block['wallet_address'] ?? '' ),
					'default_price'       => (string) ( $live_block['default_price'] ?? '' ),
					'facilitator_url'     => (string) ( $live_block['facilitator_url'] ?? '' ),
					'facilitator_api_key' => (string) ( $live_block['facilitator_api_key'] ?? '' ),
				),
			),
		);
	}
}

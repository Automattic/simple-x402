<?php
/**
 * Admin: Settings → Simple x402 page.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

use Automattic\Connectors\OAuth2\Client as OAuth2Client;
use SimpleX402\Admin\OAuthRouter;
use SimpleX402\Admin\SettingsAjax;
use SimpleX402\Admin\TestConnectionAjax;
use SimpleX402\Connectors\ConnectorRegistry;
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

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly ConnectorRegistry $connectors = new ConnectorRegistry(),
	) {}

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
			<div id="simple-x402-app"></div>
		</div>
		<?php
	}

	/**
	 * Build the JSON payload the React app reads on boot.
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * Per-connector auth metadata for the React picker. For `oauth2` connectors
	 * this includes the live is_connected state plus the connect/disconnect
	 * URLs the Connect button will target.
	 *
	 * @param array<string,mixed> $connector Raw connector data.
	 * @return array<string,mixed>
	 */
	private function describe_auth( string $id, array $connector ): array {
		$method = (string) ( $connector['authentication']['method'] ?? 'none' );
		$auth   = array( 'method' => $method );

		// A connector with method=api_key is "OAuth-backed" when our OAuth2
		// library has a supplement registered for its ID. For_connector()
		// returns a Client only when both pieces are present.
		$client = OAuth2Client::for_connector( $id );
		if ( null !== $client ) {
			$auth['is_oauth']        = true;
			$auth['is_connected']    = $client->is_connected();
			$auth['connect_url']     = OAuthRouter::connect_url( $id );
			$auth['disconnect_url']  = OAuthRouter::disconnect_url( $id );
			$auth['credentials_url'] = (string) ( $connector['authentication']['credentials_url'] ?? '' );
		}
		return $auth;
	}

	public function bootstrap_data(): array {
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

		$facilitators = array();
		foreach ( $this->connectors->facilitators() as $id => $c ) {
			$facilitators[] = array(
				'id'          => $id,
				'name'        => (string) ( $c['name'] ?? $id ),
				'description' => (string) ( $c['description'] ?? '' ),
				'auth'        => $this->describe_auth( $id, $c ),
			);
		}

		return array(
			'option' => SettingsRepository::OPTION_NAME,
			'modes'  => array(
				'paywall'  => array(
					'none'     => SettingsRepository::PAYWALL_MODE_NONE,
					'allPosts' => SettingsRepository::PAYWALL_MODE_ALL_POSTS,
					'category' => SettingsRepository::PAYWALL_MODE_CATEGORY,
				),
				'audience' => array(
					'everyone' => SettingsRepository::AUDIENCE_EVERYONE,
					'bots'     => SettingsRepository::AUDIENCE_BOTS,
				),
			),
			'categories'    => $categories,
			'modeCategory'  => SettingsRepository::PAYWALL_MODE_CATEGORY,
			'facilitators'  => $facilitators,
			'ajaxUrl'        => function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '',
			'testConnection' => array(
				'action' => TestConnectionAjax::ACTION,
				'nonce'  => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( TestConnectionAjax::NONCE ) : '',
			),
			'saveSettings'   => array(
				'action' => SettingsAjax::ACTION,
				'nonce'  => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( SettingsAjax::NONCE ) : '',
			),
			'values' => array(
				'paywall_mode'             => $this->settings->paywall_mode(),
				'paywall_audience'         => $this->settings->paywall_audience(),
				'paywall_category_term_id' => $this->settings->paywall_category_term_id(),
				'selected_facilitator_id'  => $this->settings->selected_facilitator_id(),
				'facilitators'             => $this->settings->facilitator_slots(),
				'default_price'            => $this->settings->default_price(),
			),
		);
	}
}

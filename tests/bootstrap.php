<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param string|array $value Value to unslash.
	 * @return string|array
	 */
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0 ): string|false {
		return json_encode( $data, $options );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $s ): string {
		return rtrim( $s, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, $default = false ) {
		return $GLOBALS['__sx402_options'][ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, $value, $autoload = null ): bool {
		$GLOBALS['__sx402_options'][ $name ] = $value;
		return true;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ) {
		$GLOBALS['__sx402_http'] = array( 'url' => $url, 'args' => $args );
		if ( ! empty( $GLOBALS['__sx402_http_queue'] ) ) {
			return array_shift( $GLOBALS['__sx402_http_queue'] );
		}
		$next = $GLOBALS['__sx402_http_next'] ?? null;
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		return $next ?? array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, $value, ...$args ) {
		foreach ( $GLOBALS['__sx402_filters'][ $hook ] ?? array() as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['__sx402_filters'][ $hook ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'has_term' ) ) {
	function has_term( string $term, string $taxonomy, int $post_id ): bool {
		return in_array( array( $term, $taxonomy, $post_id ), $GLOBALS['__sx402_terms'] ?? array(), true );
	}
}
if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( string $term, string $taxonomy ) {
		return in_array( array( $term, $taxonomy ), $GLOBALS['__sx402_existing_terms'] ?? array(), true )
			? array( 'term_id' => 1 )
			: null;
	}
}
if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( string $term, string $taxonomy ) {
		$GLOBALS['__sx402_existing_terms'][] = array( $term, $taxonomy );
		$GLOBALS['__sx402_inserted_terms'][] = array( $term, $taxonomy );
		return array( 'term_id' => count( $GLOBALS['__sx402_existing_terms'] ) );
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( int $post_id ): string|false {
		return $GLOBALS['__sx402_posts'][ $post_id ]['post_type'] ?? false;
	}
}
if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $post_id ): string|false {
		return $GLOBALS['__sx402_posts'][ $post_id ]['post_status'] ?? false;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ) {
		$entry = $GLOBALS['__sx402_transients'][ $key ] ?? null;
		if ( null === $entry ) {
			return false;
		}
		if ( $entry['expires'] > 0 && $entry['expires'] < time() ) {
			unset( $GLOBALS['__sx402_transients'][ $key ] );
			return false;
		}
		return $entry['value'];
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, $value, int $ttl = 0 ): bool {
		$GLOBALS['__sx402_transients'][ $key ] = array(
			'value'   => $value,
			'expires' => $ttl > 0 ? time() + $ttl : 0,
		);
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', ?string $scheme = null ): string {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( int $code ): void {
		$GLOBALS['__sx402_response']['status'] = $code;
	}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {}
}
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( string $group, string $option, array $args = array() ): void {
		$GLOBALS['__sx402_registered_settings'][ $group ][ $option ] = $args;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return true;
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $text, string $domain = 'default' ): void {
		echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( string $group ): void {}
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button(): void {}
}
if ( ! function_exists( 'add_settings_error' ) ) {
	function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
		$GLOBALS['__sx402_settings_errors'][] = array(
			'setting' => $setting,
			'code'    => $code,
			'message' => $message,
			'type'    => $type,
		);
	}
}
if ( ! function_exists( 'settings_errors' ) ) {
	function settings_errors( string $setting = '' ): void {
		$GLOBALS['__sx402_settings_errors_rendered'] = true;
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, bool $echo = true ): string {
		$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $echo ) {
			echo $out;
		}
		return $out;
	}
}
$GLOBALS['__sx402_terms']           = array();
$GLOBALS['__sx402_posts']           = array();
$GLOBALS['__sx402_existing_terms']  = array();
$GLOBALS['__sx402_inserted_terms']  = array();
$GLOBALS['__sx402_settings_errors'] = array();
$GLOBALS['__sx402_response'] = array(
	'status'  => 200,
	'headers' => array(),
	'body'    => null,
	'exited'  => false,
);

// Reset global state between tests.
$GLOBALS['__sx402_options']    = array();
$GLOBALS['__sx402_transients'] = array();
$GLOBALS['__sx402_filters']    = array();
$GLOBALS['__sx402_http']       = null;

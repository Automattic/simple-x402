<?php
/**
 * Plugin Name:       Simple x402 — Jetpack companion
 * Description:       Registers a WordPress.com facilitator for Simple x402 using Jetpack Connection. Requires Simple x402 and a Jetpack-connected site.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       simple-x402-jetpack
 *
 * @package SimpleX402\Jetpack
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

// Try local vendor first (so this plugin can run standalone from its own
// composer install). When installed as part of the monorepo, the main
// plugin's autoloader covers our namespace via the composer path repository.
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

add_action( 'plugins_loaded', array( \SimpleX402\Jetpack\Plugin::class, 'boot' ), 20 );

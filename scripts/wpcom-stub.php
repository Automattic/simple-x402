<?php
/**
 * Local stand-in for WordPress.com's /wpcom/v2/x402/* endpoints, paired with
 * SIMPLE_X402_JETPACK_DEV_URL. Also serves the companion zip to wp-now's
 * blueprint. Failure shapes exposed via X-Stub-Mode: deny | scope_fail | server_err.
 *
 * @package SimpleX402\Jetpack\Dev
 */

declare(strict_types=1);

$path   = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '';
$method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );
$mode   = $_SERVER['HTTP_X_STUB_MODE'] ?? '';

// The blueprint pulls the companion plugin from this route because its
// `vfs` resource can't see through wp-now's bind-mounts into the project.
if ( 'GET' === $method && '/simple-x402-jetpack.zip' === $path ) {
	$zip = __DIR__ . '/dev-artifacts/simple-x402-jetpack.zip';
	if ( ! is_file( $zip ) ) {
		http_response_code( 404 );
		header( 'Content-Type: text/plain' );
		echo "companion zip not found at {$zip}; dev.sh should have built it.\n";
		exit;
	}
	header( 'Content-Type: application/zip' );
	header( 'Content-Length: ' . filesize( $zip ) );
	readfile( $zip );
	exit;
}

header( 'Content-Type: application/json' );
header( 'X-Stub-Server: simple-x402-jetpack' );

// Lets us flip failure modes without editing the stub. Exercise your error
// surfaces + debug output.
if ( 'server_err' === $mode ) {
	respond(
		500,
		[ 'error' => 'internal_server_error', 'error_description' => 'Simulated by X-Stub-Mode' ]
	);
}
if ( 'scope_fail' === $mode ) {
	respond(
		403,
		[ 'code' => 'unauthorized', 'message' => 'Simulated scope failure by X-Stub-Mode' ]
	);
}

$key = "{$method} {$path}";

switch ( $key ) {
	case 'GET /wpcom/v2/x402/health':
		respond( 200, [ 'ok' => true, 'stub' => true ] );

	case 'POST /wpcom/v2/x402/verify':
		$body = read_json_body();
		if ( ! isset( $body['paymentRequirements'], $body['paymentPayload'] ) ) {
			respond(
				400,
				[ 'error' => 'invalid_request', 'error_description' => 'Missing paymentRequirements or paymentPayload' ]
			);
		}
		if ( 'deny' === $mode ) {
			respond( 200, [ 'isValid' => false, 'invalidReason' => 'signature_invalid_stubbed', 'stub' => true ] );
		}
		respond( 200, [ 'isValid' => true, 'invalidReason' => null, 'stub' => true ] );

	case 'POST /wpcom/v2/x402/settle':
		$body = read_json_body();
		if ( ! isset( $body['paymentRequirements'], $body['paymentPayload'] ) ) {
			respond(
				400,
				[ 'error' => 'invalid_request', 'error_description' => 'Missing paymentRequirements or paymentPayload' ]
			);
		}
		if ( 'deny' === $mode ) {
			respond(
				200,
				[
					'success'     => false,
					'transaction' => null,
					'network'     => 'base-sepolia',
					'errorReason' => 'settle_denied_stubbed',
					'stub'        => true,
				]
			);
		}
		respond(
			200,
			[
				'success'     => true,
				'transaction' => '0xstub' . str_repeat( '0', 58 ) . '1',
				'network'     => 'base-sepolia',
				'errorReason' => null,
				'stub'        => true,
			]
		);

	default:
		respond(
			404,
			[
				'code'    => 'rest_no_route',
				'message' => 'No route was found matching the URL and request method.',
				'data'    => [ 'status' => 404 ],
			]
		);
}

/**
 * @param array<string,mixed> $body
 */
function respond( int $status, array $body ): void {
	http_response_code( $status );
	echo json_encode( $body );
	exit;
}

/**
 * @return array<string,mixed>
 */
function read_json_body(): array {
	$raw     = file_get_contents( 'php://input' );
	$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
	return is_array( $decoded ) ? $decoded : [];
}

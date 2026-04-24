# Local development

```bash
./scripts/dev.sh
```

Starts a wp-now instance running WP 7.0-RC2 + PHP 8.1 with both plugins installed and activated, the admin UI asset watcher, and a local stub standing in for WordPress.com's facilitator endpoints. Ctrl-C tears everything down.

The companion's outbound calls get routed to the stub on `localhost:9002` via the `SIMPLE_X402_JETPACK_DEV_URL` env var — no `wp-config.php` edits, no Jetpack connection required. Unset the var (or remove `dev.sh`) and the companion falls back to real Jetpack signing.

## Prerequisites

- `wp-now` — `npm i -g @wp-now/wp-now`
- Node 20+, PHP 8.1+, `zip`

## Iterating

- **Admin UI / main plugin source:** bind-mounted, edits are live on refresh.
- **Companion (`companions/simple-x402-jetpack/`):** installed from a zip rebuilt on each `dev.sh` start. Restart the script to pick up companion edits.
- **Stub behaviour:** send an `X-Stub-Mode: deny | scope_fail | server_err` header to exercise failure shapes. See `scripts/wpcom-stub.php`.

## Production safety

`SIMPLE_X402_JETPACK_DEV_URL` bypasses Jetpack auth entirely. It exists for local dev only — don't set it anywhere a production process can read it.

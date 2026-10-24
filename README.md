# Pixalink Loyalty for WooCommerce

WordPress plugin that connects a WooCommerce store to a [Pixalink](https://pixalink.io) outlet so shoppers earn
loyalty points on every order, redeem vouchers and points at checkout (Blocks and classic), and see their balance,
tier and vouchers under **My Account › Loyalty**. Order lifecycle events are delivered to Pixalink through a
plugin-side outbox (Action Scheduler) and every request is signed with a key derived from the outlet API key.
Licensed GPL-2.0-or-later; no telemetry.

## Development setup

Requirements: PHP 8.1+ with `sodium`, Composer 2, Node (version in `.nvmrc`), Docker (for `wp-env`).

```bash
composer install            # PHP dev tools (PHPUnit, PHPCS, PHPStan, Brain Monkey, ...)
npm ci                      # @wordpress/scripts toolchain, wp-env, Playwright
npm run build               # build JS bundles into build/

composer test:unit          # Brain Monkey unit tests (no WordPress needed)
composer test:contract      # JSON Schema contract tests
composer lint               # PHPCS (WordPress-Extra + WooCommerce-Core + PHPCompatibilityWP 8.1-)
composer analyse            # PHPStan level 8

npm run env:start           # wp-env: WordPress + WooCommerce on http://localhost:8888 (admin / password)
npm run test:integration    # PHPUnit integration suite inside wp-env (HPOS mode via PIXALINK_LOYALTY_HPOS=1|0)

bin/build-zip.sh selfhosted # or wporg | marketplace -> dist/pixalink-loyalty-for-woocommerce-<version>-<flavour>.zip
```

The local `wp-env` site talks to a fake Pixalink API (`tests/e2e/mu-plugins/fake-pixalink.php`) instead of production;
see `tests/contract/schemas/` for the request/response contract shared with the Pixalink server.

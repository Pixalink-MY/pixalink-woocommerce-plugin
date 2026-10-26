# Pixalink Loyalty for WooCommerce

WordPress plugin that connects a WooCommerce store to a [Pixalink](https://pixalink.io) outlet so shoppers earn
loyalty points on every order, redeem vouchers and points at checkout (Blocks and classic), and see their balance,
tier and vouchers under **My Account › Loyalty**. Order lifecycle events are sent to Pixalink at the end of the request
that recorded them, with a plugin-side outbox (Action Scheduler) as the fallback, and every request is signed with a key
derived from the outlet API key.
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
npm run test:integration    # PHPUnit integration suite inside wp-env (tables prefixed wptests_)

# Force an HPOS mode (1 = custom order tables, 0 = posts); host env vars do not reach the container:
npx wp-env run cli --env-cwd=wp-content/plugins/pixalink-loyalty-for-woocommerce -- \
  env WORDPRESS_TABLE_PREFIX=wptests_ PIXALINK_LOYALTY_HPOS=1 vendor/bin/phpunit -c phpunit-integration.xml.dist

bin/build-zip.sh selfhosted # or wporg | marketplace -> dist/pixalink-loyalty-for-woocommerce-<version>-<flavour>.zip
bin/bump-version.sh 1.0.0 --wc-tested=11.3 --wp-tested=7.2   # then tag v1.0.0 to run release.yml
```

CI resolves the WordPress/WooCommerce test matrix at run time (`node bin/ci/resolve-versions.mjs --pretty`).

The local `wp-env` site talks to a fake Pixalink API (`tests/e2e/mu-plugins/fake-pixalink.php`) instead of production;
see `tests/contract/schemas/` for the request/response contract shared with the Pixalink server (`endpoints.json` lists the
API calls and, under `hosted`, the browser-facing connect page).

Connecting locally: **WooCommerce › Settings › Pixalink Loyalty › Connect existing Pixalink account** goes to the fake hosted
page (`PIXALINK_LOYALTY_CONNECT_URL` in `.wp-env.json`), which redirects straight back with a PKCE-bound code. Make it answer
differently with `POST /wp-json/fake-pixalink/v1/hosted {"outcome":"lead"|"deny"|"invalid"}`; paste
`1|abcdefghijklmnopqrstuvwxyz0123456789ABCD` to try the API-key path.

| Constant (wp-config.php)        | Effect                                                                 |
|---------------------------------|------------------------------------------------------------------------|
| `PIXALINK_LOYALTY_API_KEY`      | Outlet key; overrides the key stored by the Connect screen             |
| `PIXALINK_LOYALTY_API_BASE`     | Commerce API base (default `https://explore.pixalink.io/api/commerce/v1`) |
| `PIXALINK_LOYALTY_CONNECT_URL`  | Hosted connect page (default: API base host + `/connect/woocommerce`)  |

# ADR 0001: Checkout discounts (vouchers + points) and holds

- **Status:** Accepted (2026-10-25), from the P02 spike
- **Evidence:** branch `spike/virtual-coupons` (throwaway, never merged). Spike code is in `src/Spike/`, `assets/js/src/spike/`,
  `tests/php/integration/Spike/` and `tests/e2e/specs/spike-checkout.spec.ts`. Final all-green CI run (PHPCS, PHPStan L8,
  8 integration jobs, Playwright on two WC versions): https://github.com/Pixalink-MY/pixalink-woocommerce-plugin/actions/runs/40113959448
- **Applies to:** W3 (P09 redemption core + classic UI, P10 Blocks UI): `Redemption\*` and `Checkout\*`
- **Tested on:** WooCommerce 11.0.1 (floor), 11.1.2 and 11.2.1 (current), plus the open RC/trunk lane. WordPress 7.0.7,
  7.1.3 and trunk. PHP 8.1, 8.3 and 8.4. HPOS on and off. The integration suite ran in all 8 matrix cells; the browser
  (Playwright, wp-env, Twenty Twenty-Five) ran on WC 11.0.1 and 11.2.1. WC 11.0+ has no `Experimental*` API changes
  relevant here: the exports are identical in 11.0.1 and 11.2.1.

## Context

Shoppers spend Pixalink vouchers and points at checkout, in both the Checkout block and the classic `[woocommerce_checkout]`
shortcode. Plan Part B fixed the direction: D1 virtual WC coupons, D2 preview in the cart and hold at submit, D3 a declarative
`PUT orders/{order_key}/redemptions`, and D6 classic also writes through the Store API. The spike's job was to show that
current WooCommerce supports that direction, to find the traps, and to pin down exact hook names and priorities. Every hook
below was checked in the WooCommerce 11.0.1 and 11.2.1 source and exercised by a test.

## Decisions

### 1. Discounts are virtual coupons, not fees

`woocommerce_get_shop_coupon_data` supplies coupon props for codes that never exist as `shop_coupon` posts:

| Code | Props |
|---|---|
| `pxl-points` | `fixed_cart`, `amount = points × value_per_point` (2 dp) |
| voucher `pxl-*` (Pixalink issues `PXL-…`) | the quote's `woo_coupon` spec. SKUs become `product_ids`/`excluded_product_ids` (`wc_get_product_id_by_sku`), slugs become `product_categories`, plus `minimum_amount`/`maximum_amount`, `limit_usage_to_x_items`, `exclude_sale_items` and `individual_use` |

Coupons are applied before tax and prorated per line by WooCommerce itself. They also land in `coupon_lines` with
`discount` and `discount_tax` split out, survive refunds and gateway line-item rules, and appear in the native UI of both
checkouts. Negative fees get none of that, so we don't use them.

**Business semantics (confirm with product):** in a tax-exclusive store, 1,000 pts = 10.00 comes off the *net* price, so the
shopper's total falls by 11.00 at 10% tax. In a tax-inclusive store the total falls by exactly 10.00. That is how WooCommerce
treats every `fixed_cart` coupon, and it is what Points & Rewards-style plugins do.

### 2. Where state lives

- **During checkout, state lives per WC session, but not inside the WC session blob.** It is keyed by
  `WC()->session->get_customer_id()` (`verified`, `customer_id`, the last `quote`, selected `points`, selected voucher `codes`).
  The spike uses a transient `pixalink_loyalty_redemption_<md5(customer id)>` with a 48 h TTL. W3 can use the same, or a small
  table. **Do not** store it with `WC()->session->set()`. The WC session is one last-writer-wins blob, and same-session
  requests overwrite it (pitfall 1, proven in the browser).
- **The verified link of a logged-in shopper is durable user meta** (`pixalink_customer_id`, `pixalink_phone_verified_at`). The
  per-session state only caches it.
- **No HTTP calls from the coupon-data filter.** It fires on *every* `new WC_Coupon( $x )`, in cart totals, order screens and
  reports alike, so it reads only this cached state. One extra option row per request is cached in-request by
  `get_transient()`.
- **The order's coupon lines are the source of truth afterwards.** At `woocommerce_checkout_create_order_coupon_item` we
  stamp `_pixalink_kind` (`points|voucher`), `_pixalink_points`, `_pixalink_voucher_id`, and `_pixalink_coupon`, a snapshot of
  the resolved props: `discount_type`, `amount`, `product_ids`, `excluded_product_ids`, `product_categories`,
  `limit_usage_to_x_items` and `exclude_sale_items`. The hold body, recalculation and refunds read these meta keys, not the
  session.
- **Without a session, the filter answers `false`.** That covers wp-admin, cron, webhooks and IPN callbacks. WooCommerce
  then rebuilds the coupon from the line (see decision 6).
- **Code namespace.** Pixalink voucher codes must start with `PXL-`. The plugin claims any `pxl-*` code before a quote exists,
  so an unverified shopper who types one gets our message instead of "does not exist". As a side effect, a merchant's own
  coupon named `pxl-…` is shadowed. Document that the prefix is reserved.

### 3. Holds are placed at submit and never during preview

Cart and checkout previews call `quote` only and write nothing. The holds are placed when the shopper submits:

| Checkout | Hook | Priority / args | Order state at that point | Abort |
|---|---|---|---|---|
| Classic | `woocommerce_checkout_create_order` | **20**, `( WC_Order $order, array $data )` | **unsaved**: ID 0, **empty order key**. Line items and coupon lines (with our meta) are already attached | `throw new Exception( $msg )`. `WC_Checkout::create_order()` returns a `WP_Error`, nothing is saved, and `process_checkout()` turns it into an error notice |
| Blocks / Store API | `woocommerce_store_api_checkout_order_processed` | 10, `( WC_Order $order )` | saved, key set, **status already `pending`** (Store API calls `update_status( 'pending' )` immediately before the action, in 11.0 and 11.2), before payment | `throw new RouteException( 'pixalink_loyalty_hold_failed', $msg, 409 )`. Set the status back to `checkout-draft` on the same object first (see pitfall 5) |

- **Order key.** In classic, set it ourselves with `if ( '' === $order->get_order_key() ) $order->set_order_key( wc_generate_order_key() );`.
  Both data stores (CPT and HPOS `create()`) keep a key that is already set, so the key we PUT to is the key the order is
  saved with.
- **After a successful hold**, store the response on the order: `_pixalink_redemptions`, plus `_pixalink_redemption_state`
  set to `held` or `released`. Settle on `order.paid` (W2 events). Release on cancel or through the sweeper. The server TTL
  is the backstop.

### 4. Holds use a declarative PUT

`PUT orders/{order_key}/redemptions` sends `{ customer_id, currency, redemptions[] }`. The body is built from the order's
Pixalink coupon lines (`HoldCoordinator::redemptions()` in the spike):
`{ kind, coupon_code, discount, discount_tax, points | voucher_id }`. An empty list means "release all".

The PUT is idempotent by construction. A retry after a refused hold, with fewer points, simply replaces the set. The spike's
body passes the fake server's contract check, which answers 422 on any schema violation.

### 5. One server-side mutation path: Store API `cart/extensions`

- **Server.** `woocommerce_store_api_register_update_callback( [ 'namespace' => 'pixalink-loyalty', 'callback' => [ CartActions, 'handle' ] ] )`
  takes `{ action: 'set_points' | 'apply_voucher' | 'remove_voucher', … }`.
- **Blocks.** `extensionCartUpdate( { namespace: 'pixalink-loyalty', data } )`.
- **Classic.** `fetch( '/wp-json/wc/store/v1/cart/extensions', { method: 'POST', credentials: 'same-origin', headers: { Nonce }, body: JSON.stringify( { namespace, data } ) } )`
  with `Nonce = wp_create_nonce( 'wc_store_api' )` printed on the page, then `jQuery( document.body ).trigger( 'update_checkout' )`.
  Read the refreshed `Nonce` response header after every call. No `X-WP-Nonce` is needed (see the note under pitfall 1).
- **Blocks sends these through `POST /wc/store/v1/batch` (207).** The server path is identical: the batch route dispatches
  each entry to `cart/extensions`.
- **Applying coupons.** `CartActions` applies through `Automattic\WooCommerce\StoreApi\Utilities\CartController::apply_coupon()`.
  It runs the same validation as `POST cart/apply-coupon` and throws a `RouteException` that carries our validity message.
  Unlike `WC_Cart::apply_coupon()`, it adds no `wc_add_notice()` (which would leak into the next classic page view) and has no
  `wc_coupons_enabled()` gate, so loyalty still works on stores that turned merchant coupons off.
- **Save early.** End `CartActions::handle()` with `WC()->session->save_data()`. It narrows WooCommerce's own
  applied-coupons race but cannot close it (pitfall 1). Our state is safe only because it lives outside the blob.
- **Points.** Clamp to `min( available, max_for_cart )`, round down to `step` and enforce `min`. Once `pxl-points` is applied,
  changing the amount only rewrites our state. The Store API recalculates after the callback, and the coupon picks up the
  new amount.
- **Store data.** `ExtendSchema::register_endpoint_data` (`woocommerce_store_api_register_endpoint_data`, `CartSchema::IDENTIFIER`,
  namespace `pixalink-loyalty`) exposes `linked`, `points{balance,max,step,applied,value_per_point}`, `points_label` and
  `vouchers[{code,title,applied}]` to the Blocks UI and the coupons filter.

### 6. Admin recalculation is pinned to the line snapshot

Filter `woocommerce_order_recalculate_coupons_coupon_object` (10, 4 args: `$coupon, $code, $item, $order`). When the line
has `_pixalink_coupon`, return a new virtual `WC_Coupon` built from the snapshot. `woocommerce_order_before_calculate_totals`
is the wrong hook, because `calculate_totals()` never consults coupon data.

### 7. Validity veto

`woocommerce_coupon_is_valid` (10, 3 args) **throws** `Exception( $message )`. `WC_Discounts::is_coupon_valid()` catches it
and keeps our message; returning `false` gives the generic "Coupon is not valid.". The veto skips order contexts
(`$discounts->get_object() instanceof WC_Order`) and requests without a session.

The veto refuses a coupon when:

- the shopper is unverified,
- the kill switch is off,
- the voucher is not in the quote,
- `points` is 0,
- the quote says `eligible: false`,
- or a SKU-scoped voucher resolves to no local product (pitfall 3).

Also filter `woocommerce_coupon_error` (10, 3 args) for `WC_Coupon::E_WC_COUPON_INVALID_REMOVED` (101). Without it, the
classic re-check replaces our reason with a generic notice that names the internal code (pitfall 4).

## Hook map for W3

| Hook | Type | Priority / args | Class in W3 | Purpose |
|---|---|---|---|---|
| `woocommerce_get_shop_coupon_data` | filter | 10, 3 | `Redemption\VirtualCoupons` | props for `pxl-points` / `pxl-*`; `false` without a session |
| `woocommerce_coupon_is_valid` | filter | 10, 3 | `Redemption\VirtualCoupons` | veto by **throwing** |
| `woocommerce_coupon_error` | filter | 10, 3 | `Redemption\VirtualCoupons` | replace code 101 notice for our codes |
| `woocommerce_checkout_create_order_coupon_item` | action | 10, 4 | `Redemption\VirtualCoupons` | stamp `_pixalink_*` meta + snapshot (fires for classic **and** Store API draft orders) |
| `woocommerce_order_recalculate_coupons_coupon_object` | filter | 10, 4 | `Redemption\VirtualCoupons` | rebuild from snapshot |
| `woocommerce_store_api_checkout_update_order_meta` | action | 10, 1 | `Redemption\VirtualCoupons` | POST /checkout: rebuild the draft's coupon lines when one of ours is present (pitfall 14) |
| `woocommerce_cart_totals_coupon_label` | filter | 10, 2 | `Checkout\Classic\ClassicPanel` | classic label "Points (1,000 pts)" / "Voucher: …" |
| `woocommerce_removed_coupon` | action | 10, 1 | `Redemption\RedemptionState` | chip/row removed, so reset points or drop the voucher |
| `woocommerce_cart_emptied` | action | 10 | `Redemption\RedemptionState` | after payment, drop the selection (keep verification) |
| `woocommerce_checkout_create_order` | action | **20**, 2 | `Redemption\HoldCoordinator` | classic hold; set the order key; throw to abort |
| `woocommerce_store_api_checkout_order_processed` | action | 10, 1 | `Redemption\HoldCoordinator` | Blocks hold; reset to draft and throw `RouteException` 409 |
| `woocommerce_blocks_loaded` | action | — | `Checkout\Blocks\StoreApiExtension` | register the update callback + endpoint data. **Check `did_action()` first** (pitfall 7) |
| `woocommerce_blocks_cart_block_registration`, `woocommerce_blocks_checkout_block_registration` | action | 10, 1 | `Checkout\Blocks\BlocksIntegration` | `IntegrationRegistry::register( new BlocksIntegration )` |
| `woocommerce_checkout_before_order_review` | action | 10 | `Checkout\Classic\ClassicPanel` | classic panel outside the AJAX fragments (`woocommerce_review_order_before_payment` is skipped on AJAX refreshes) |

Store API option, not needed by the spike: `woocommerce_checkout_validate_order_before_payment` (WC 9.9+, Blocks only) adds
`WP_Error`s that come back as a 400 before `woocommerce_store_api_checkout_order_processed`. It suits pure validation. The
hold stays on `…order_processed` because that is the last point before payment.

## Blocks client API used, with experimental status

| API | Status | Used for |
|---|---|---|
| `ExperimentalDiscountsMeta` (slot; `wc.blocksCheckout`) | **Experimental**. Exported unchanged in 11.0.1 → 11.2.1. Browser-proven in the Checkout block's order-summary discount area; the Cart block's bundle mounts the same slot (`cart-frontend.js`), but that was not browser-tested. Slot fill props are `{ cart, extensions, context }` | panel (points slider + vouchers) |
| `registerPlugin( name, { render, scope: 'woocommerce-checkout' } )` (`wp.plugins`) | stable | mounts the fill |
| `extensionCartUpdate( { namespace, data } )` | stable | `set_points`, `apply_voucher`, `remove_voucher` |
| `registerCheckoutFilters( ns, { coupons } )` | stable (the `__experimental…` alias is deprecated) | relabel `pxl-points` to `points_label`. **The order-summary discount block calls this filter without `extensions`** (11.0.1 and 11.2.1: `applyCheckoutFilter({ arg, filterName: 'coupons', defaultValue })`), so read the label from `select( 'wc/store/cart' ).getCartData().extensions`. The chip renders as "Coupon: <label>" |
| `select( 'wc/store/cart' ).getCartData().extensions` (`wp.data`) | stable store | panel data without relying on fill props |
| `registerCheckoutBlock` | stable | fallback inner block (below) |
| `IntegrationInterface` / `IntegrationRegistry` | stable PHP | script registration (`get_script_handles`) |

`BlocksIntegration` declares `wc-blocks-checkout`, `wp-plugins`, `wp-element` and `wp-data` explicitly, because the
spike bundle reads `window.wc.blocksCheckout` and `window.wp.*` and dependency extraction can't see those. W3 may import from
`@woocommerce/blocks-checkout` instead. That needs the package in `package.json`, or ESLint `import/no-extraneous-dependencies`
fails.

## Evidence (answers to the spike questions)

Integration tests run inside wp-env with the real WooCommerce, the real Store API (`rest_get_server()->dispatch()`, `Nonce`
header) and the fake Pixalink server. That server is the in-process `pre_http_request` mu-plugin; it signature-checks requests
and answers 422 on any contract violation. Every Spike test passed in all 8 cells: 78 tests / 672 assertions per cell,
existing suite included. Browser tests are `tests/e2e/specs/spike-checkout.spec.ts`, run in CI job `spike-e2e` with
screenshots kept as artifacts.

**Q1: do virtual coupons apply in classic cart/checkout and Blocks cart/checkout, with tax and coupon_lines meta? Yes.**

- **Matrix.** `VirtualCouponsTest::test_classic_cart_and_checkout_totals` and `::test_store_api_cart_and_checkout_totals`
  run 12 cases each: tax off / exclusive 10% / inclusive 10%, crossed with `pxl-points` (1,000 pts = 10.00 fixed_cart),
  percent 10, fixed_product 5 on SKU `PXL-A`, and "free item" (percent 100 + `product_ids` [B] + `limit_usage_to_x_items 1`).
  Cart is 1×A 100 + 2×B 50.
- **What they check.** Exact cart total (classic `WC_Cart`; Store API `totals.total_price`), order total, the coupon line's
  `discount + discount_tax`, `discount_tax > 0` when taxed, and `_pixalink_kind` / `_pixalink_points` / `_pixalink_voucher_id`
  plus the snapshot (free item keeps `product_ids` [B] and limit 1).
- **Expected totals:**
  - tax off: 190 / 180 / 195 / 150
  - exclusive: 209 / 198 / 214.50 / 165
  - inclusive: 210 / 198 / 215 / 165
- **Browser.** A $100 cart with "Welcome 10 off" + 1,000 pts gives $80.00 in both the Checkout block and the classic review
  table. The order is placed and the PUT recorded with 2 redemptions.
- **Also covered:** `test_points_coupon_is_capped_at_the_discountable_subtotal` (pitfall 8).

**Q2: can `woocommerce_coupon_is_valid` veto with our message in both checkouts? Yes, by throwing.**

- `test_unverified_shopper_is_vetoed_with_our_message_in_the_classic_cart`: `wc_add_notice` gets exactly our text.
- `test_unverified_shopper_is_vetoed_with_our_message_in_the_store_api`: 400 `woocommerce_rest_cart_coupon_error` with our
  text.
- `test_coupon_invalidated_after_application_is_removed_with_our_message_in_both_checkouts`:
  - Blocks: POST `/checkout` → 409 containing our text, refused **before** a draft order exists.
  - Classic: `check_cart_coupons()` → "Your Pixalink points discount was removed. Pixalink points and vouchers are
    temporarily unavailable.", with no `pxl-points` in the notice.
- `test_sku_scoped_voucher_with_unknown_skus_is_refused_instead_of_becoming_cart_wide`.

**Q3: does one server callback serve Blocks `extensionCartUpdate` and the classic fetch? Yes.**

- `CartActionsTest::test_one_update_callback_is_registered_for_the_namespace_even_when_blocks_loaded_first`:
  `ExtendSchema::get_update_callback( 'pixalink-loyalty' ) === [ CartActions, 'handle' ]`.
- `test_blocks_extension_cart_update_applies_points_and_returns_the_recalculated_cart` checks the response cart
  (`total_price` 19000, coupon `pxl-points` 1000, `extensions…points_label` "Points (1,000 pts)").
- `test_classic_fetch_then_update_checkout_sees_the_same_cart_through_the_same_callback`: three actions through one hook;
  classic labels "Points (1,000 pts)" / "Voucher: Welcome 10 off"; `create_order` carries both lines.
- Also: `test_missing_store_api_nonce_is_rejected` (401 `woocommerce_rest_missing_nonce`),
  `test_spending_requires_a_verified_shopper` (403), `test_points_are_clamped_to_step_and_cap_and_zero_removes_the_coupon`,
  `test_removing_the_points_chip_resets_the_state`, `test_a_refused_voucher_is_not_left_selected` and
  `test_points_apply_when_merchant_coupons_are_disabled`.
- **Browser.** Blocks requests go through `/wc/store/v1/batch` → `cart/extensions`. Classic sends a direct
  `fetch( cart/extensions )` + `update_checkout`. Both hit the same callback (trace lines `action set_points`).

**Q4: commit at submit. Yes in both checkouts, with one Blocks caveat (pending status, pitfall 5).**

- `HoldCoordinatorTest::test_hooks_and_priorities` (20 / 10).
- `test_classic_hold_runs_on_an_unsaved_order_whose_key_we_set_and_the_order_is_saved_with_it`: at priority 19 the order has
  ID 0 and key `''`. The saved order keeps the key we set, and the PUT path is `orders/<that key>/redemptions`.
- `test_classic_hold_failure_aborts_without_saving_an_order`: `WP_Error` with our text, no order saved,
  `woocommerce_checkout_order_exception` fired, PUT got 409.
- `test_classic_process_checkout_shows_the_refusal_as_a_notice` runs the full classic handler: error notice, no order.
- `test_blocks_hold_uses_the_saved_draft_order_key`: key present, PUT accepted, then BACS → `on-hold`.
- `test_blocks_hold_failure_returns_409_and_keeps_a_reusable_draft_order`: 409 `pixalink_loyalty_hold_failed` with our text,
  draft kept as `checkout-draft`; after lowering the points the retry succeeds on the **same** order ID with the refreshed
  line (1,000 pts / 10.00).
- `test_without_restoring_the_draft_a_failed_hold_leaves_an_orphaned_pending_order` proves pitfall 5.
- `test_a_reused_draft_keeps_a_stale_points_line_without_the_refresh` proves pitfall 14.
- `test_put_body_is_built_from_the_coupon_lines`.
- **Browser, both checkouts.** Refused hold (1,500 pts > fake balance 1,250) shows our message ("Please fix the following
  error before continuing / You no longer have enough Pixalink points…" in Blocks, the checkout error notice in classic).
  The shopper stays on checkout. Success places the order.

**Q5: does admin "Recalculate" re-resolve coupon data? No.** `OrderRecalculationTest` uses an order with exclusive tax,
"free B" voucher and 1,000 points, total 154.00.

- `test_recalculate_button_keeps_totals_and_never_reresolves_coupons` runs `TaxesController::calc_line_taxes()`, the button's
  handler. `woocommerce_order_before_calculate_totals` fires, the coupon-data filter is never asked, and total and tax are
  unchanged.
- `test_recalculate_coupons_without_the_snapshot_filter_corrupts_scoped_vouchers`: `coupon_info` = `[0, code, 'percent', 100]`
  and the total collapses to 0.
- `test_recalculate_coupons_with_the_snapshot_filter_keeps_totals` keeps the total at 154.00.
- `test_admin_apply_coupon_on_the_order_only_adds_the_new_discount` gives 154.00 − 5.50.
- So `woocommerce_order_before_calculate_totals` is not the hook. `woocommerce_order_recalculate_coupons_coupon_object` is.

**Q6: does a minimal Blocks slot fill mount, and does the label filter work? Yes, with one quirk.**

- **Browser (WC 11.0.1 and 11.2.1).** The `ExperimentalDiscountsMeta` fill renders in the Checkout block's order summary
  under the coupon form (points slider, number input, voucher buttons). Linking fills it from `extensions`. "Apply points"
  shows the chip **"Points (1,000 pts)"**, and no chip reads `pxl-points`.
- **The quirk.** The first browser run showed the raw `pxl-points` chip, because the discount block passes no `extensions`
  to the `coupons` filter. Reading `wc/store/cart` fixed it.
- **PHP side.** `BlocksIntegrationTest::test_integration_registers_its_script_in_cart_and_checkout_blocks` (both registries)
  and `::test_cart_response_carries_the_extension_data`.
- **JS size.** `build/spike-blocks.js` is 1.9 KB and `build/spike-classic.js` 0.9 KB.

**Q7: which versions, and which APIs are `Experimental*`?** Versions are in the header.
`BlocksIntegrationTest::test_installed_woocommerce_exports_the_client_api_we_use` asserts the export map of the installed
WooCommerce on every cell: `ExperimentalDiscountsMeta`, `ExperimentalOrderMeta`, `extensionCartUpdate`,
`registerCheckoutFilters` and `registerCheckoutBlock`. The browser records `Object.keys( wc.blocksCheckout )` as a test
annotation. The only experimental API in the design is the `ExperimentalDiscountsMeta` slot; everything else is stable.

**Test harness notes for P11:**

- `@wordpress/e2e-test-utils-playwright` defaults `WP_BASE_URL` to `:8889` (the wp-env *tests* site) for REST discovery.
  Set `WP_BASE_URL=http://localhost:8888`.
- In-process Store API tests need a fresh `rest_get_server()` per request, because the Checkout route keeps `$this->order`.
- Normalise response data with `json_decode( wp_json_encode() )`, because schemas return nested `stdClass`.

## Pitfalls found

1. **The WC session is a last-writer-wins blob, and Action Scheduler can overwrite it.** In the first browser run, the Blocks
   `spike_link` request saved `verified = true` (trace: `db_verified=true` at its shutdown). The next request, 700 ms later
   and strictly sequential, still loaded the unverified state. The cause is in WooCommerce's bundled Action Scheduler:
   `ActionScheduler_QueueRunner::maybe_dispatch_async_request` runs at `shutdown:10`, **before**
   `WC_Session_Handler::save_data()` at `shutdown:20`. It fires a loopback `admin-ajax` request with `'cookies' => $_COOKIE`,
   so the loopback runs as the same shopper on the same WC session. It can load the pre-save blob and write it back.
   This only happens while actions are due, which is why only the first attempt after setup failed.

   The trace shows it directly (run 40113470629, job 120929840302). After `spike_link` shutdown: `db_verified=true`. Then
   the `ajax_action=as_async_request_queue_runner user=1 customer=1` request finishes with `mem_verified=no` and
   `db_verified=n/a`, erasing the state. The next `set_points` sees `mem_verified=no`.

   An early `save_data()` in the callback did **not** help. The loopback had loaded the blob before the link even happened,
   because it was dispatched by an earlier request's shutdown and was busy running actions.

   The fix is to keep the state **outside the WC session blob**. In run 40113959448 the same loopback runs between
   `spike_link` and `set_points` (`db_verified=true` at its shutdown) and the state survives. All five browser tests now pass
   on the first attempt on both WC versions (no retries).

   Also:
   - For logged-in shoppers, keep the verified link in user meta.
   - Keep the server hold authoritative. A lost selection just shows up as "no points applied". The worst case is a refused
     hold, never an over-spend.
   - WooCommerce's own `applied_coupons` share the blob and can still be rolled back this way. That is a WooCommerce-wide
     property, not ours. `StoreApiExtension` reports `applied` from the cart, not from our state, so the UI stays truthful.

   **Not a pitfall (checked):** Store API calls from the classic page need only the Store API `Nonce` header. Store API
   `Authentication::check_authentication()` returns `true` on `rest_authentication_errors` at priority 10, so WordPress's
   cookie check (priority 100), which would demote a request without `X-WP-Nonce` to user 0, never runs. Proven by the e2e
   test `the Store API Nonce header alone keeps the logged-in shopper session (no X-WP-Nonce)`.
2. **`woocommerce_coupon_is_valid` returning `false` loses the message.** Throw instead (decision 7).
3. **An empty `product_ids` list means "every product".** A SKU-scoped voucher whose SKUs match nothing locally would
   silently discount the whole cart. Veto it with "This voucher does not apply to any product sold in this store." Variation
   SKUs work only if they exist locally (the open item about variant SKUs still applies server-side).
4. **The classic re-check swaps our reason for a generic notice.** `WC_Cart::check_cart_coupons()` replaces our reason with
   'Sorry, it seems the coupon "pxl-points" is invalid - it has now been removed from your order.', which exposes the
   internal code. Fix it with `woocommerce_coupon_error`. The Store API equivalent is 'The "pxl-points" coupon has been
   removed from your cart: <our reason>'. That string is built inline (not filterable), so the code still shows in Blocks.
   This is cosmetic. W3 can relabel it client-side, or accept it.
5. **A Blocks hold failure leaves a `pending` order, not a draft.** The Store API retries a pending order only while the cart
   hash is unchanged, and the shopper is about to change it (fewer points), so the retry creates a **second order** and
   orphans the first. Before throwing, call `$order->set_status( 'checkout-draft' )` on the same object; the Store API's
   catch block saves it. The retry then reuses that draft. Both behaviours are proven (see Q4).
6. **`recalculate_coupons()` turns scoped vouchers into whole-cart discounts.** Admin "Apply coupon" / "Remove", REST v4
   order updates and order-pay validation call `WC_Abstract_Order::recalculate_coupons()`, which rebuilds virtual coupons
   from `coupon_info` (`[id, code, type, amount]` only). A "free item" voucher (percent 100 + `product_ids` +
   `limit_usage_to_x_items 1`) becomes 100% off every line, and the order total collapses to 0. Fixed by decision 6. The
   **"Recalculate" button itself never re-resolves coupons**: it only runs `calculate_taxes()` and
   `calculate_totals( false )` on already-discounted line totals.
7. **`woocommerce_blocks_loaded` may already have fired.** It fires inside `plugins_loaded:10` (`Automattic\WooCommerce\Packages::on_init`),
   the same priority as our boot. Whether it ran first depends on plugin load order, which is alphabetical on a real site and
   puts WooCommerce first in the test bootstrap. Use `did_action( 'woocommerce_blocks_loaded' ) ? register() : add_action( … )`.
8. **`fixed_cart` is capped silently.** When the requested points value exceeds the discountable subtotal, the coupon line
   carries the capped amount. Never derive points from the coupon amount. The PUT sends the selected `points` and the
   actual `discount`, the server must accept `discount ≤ points × value_per_point`, and W3 should clamp `max_for_cart` to
   the remaining subtotal after vouchers.
9. **The PUT body can't say whether a store is tax-inclusive** (contract gap). `discount` + `discount_tax` is the shopper's
   saving in an inclusive store, but in an exclusive store the points value equals `discount` alone. Add
   `prices_include_tax` (bool) to `redemptions-put.request.json`, or a per-entry `value` field, before X14 freezes the
   schema. Otherwise the server can't validate `discount_mismatch` correctly.
10. **WooCommerce lower-cases coupon codes** (`woocommerce_coupon_code` → `wc_strtolower`). Pixalink issues
    `PXL-WELCOME-88`; the order line says `pxl-welcome-88`. Compare with `wc_is_same_coupon()`. The server must match codes
    case-insensitively, or rely on `voucher_id` (the PUT sends both).
11. **`woocommerce_review_order_before_payment` is not rendered on AJAX refreshes** (`payment.php` checks
    `! wp_doing_ajax()`). Put the classic panel on `woocommerce_checkout_before_order_review`. The coupon rows in the
    refreshed review table carry the label.
12. **WC 11.2 rejects a POST `/checkout` whose total grew** since the client saw it
    (`expected_total` → 409 `woocommerce_rest_checkout_total_mismatch`). This is a free safety net if a coupon disappears
    between preview and submit. It is absent in 11.0.1, so don't rely on it.
13. **WC 10.8+ creates the Blocks draft order lazily, at POST** (`woocommerce_store_api_checkout_order_created`). No order
    exists during the cart/checkout preview. That matches "preview writes nothing".
14. **A reused Blocks draft keeps a stale points line.** `OrderController::update_line_items_from_cart()` rebuilds coupon
    lines only when `_coupons_hash` changes, and that hash is `md5` of the applied coupon **codes**. Changing the points
    amount keeps the code `pxl-points`, so on the retry after a refused hold the draft still says 1,500 points / 15.00. Line
    items and totals are already updated, so only the coupon line and our meta are wrong, and the PUT re-sends the stale
    hold. Rebuild the coupon lines at `woocommerce_store_api_checkout_update_order_meta`
    (`remove_order_items( 'coupon' )` + `WC()->checkout()->create_order_coupon_lines()`). This was found by the spike's
    first CI run and is proven both ways (see Q4).
15. **Voucher chips show the raw code in Blocks** (`pxl-welcome-88`; see the screenshot artifact). The spike relabels only
    `pxl-points`. W3 should map every `pxl-*` code to its quote title in the same `coupons` filter. Classic already uses
    "Voucher: <title>" through `woocommerce_cart_totals_coupon_label`.
16. **Throwaway note: e2e reset.** The spike's `?pixalink_spike_reset=1` (on `wp_loaded:5`) exists only so the specs start
    with an empty cart. Drop it in W3.

## Fallbacks if an experimental API breaks

1. **Inner block.** Register a `pixalink-loyalty/checkout-panel` block with `registerCheckoutBlock( { metadata: { name, parent: [ 'woocommerce/checkout-order-summary-block' ] }, component } )`
   and lock it into the order summary. It uses the same `extensionCartUpdate` calls, so no server change is needed.
   `registerCheckoutBlock` is exported in both tested versions, but the spike did not build this fallback.
2. **The typed code still resolves.** Vouchers typed into the native coupon field (`POST cart/apply-coupon` /
   `WC_Cart::apply_coupon`) resolve through the same filter, with the same veto and messages (proven by the Q1 Store API
   matrix, which applies vouchers by typed code). If the fill disappears, vouchers keep working, but points need the panel.
3. **Server kill switch.** `features.points_redemption` / `features.vouchers` from the heartbeat. With the switch off, the
   filter returns a placeholder and the veto throws "temporarily unavailable", so applied coupons are removed at the next
   cart check with that message (proven in `test_coupon_invalidated_after_application_is_removed_with_our_message_in_both_checkouts`).
4. **Stores with merchant coupons disabled.** Redemption still works through `CartActions`. Only the typed-code fallback is
   gated (proven in `test_points_apply_when_merchant_coupons_are_disabled`). Health panel: warn but don't block.

## Consequences

- W3 ports the spike's shape into `Redemption\{RedemptionState,CouponMapper,VirtualCoupons,CartActions,HoldCoordinator}` and
  `Checkout\Blocks\{BlocksIntegration,StoreApiExtension}` / `Checkout\Classic\ClassicPanel`, keeping the hook map above.
  `QuoteService` replaces `SpikeQuote`, and the OTP link replaces `spike_link`.
- Contract change before X14: `prices_include_tax` on `PUT redemptions` (pitfall 9). Document the reserved `PXL-` code prefix
  and case-insensitive matching.
- Storage: the per-session state moves out of the WC session blob (transient or small table keyed by session customer id),
  and the verified link of logged-in shoppers lives in user meta (pitfall 1).
- Not covered by the spike (W3 / W2 scope):
  - settlement and release events, the hold TTL sweeper and partial-refund restore
  - multi-currency stores and page caching of the classic nonce for guests
  - guest → login session migration (the state key changes with the session customer id)
  - the Cart block and the Mini-Cart block in a browser. The Cart block mounts the same slot. The Mini-Cart registration
    hook exists: `woocommerce_blocks_mini-cart_block_registration`.
  - the inner-block fallback
- Spike-only scaffolding not to port: `SpikeQuote`, `spike_link`, `?pixalink_spike_reset`, `SpikeDebug` (WP_DEBUG tracing)
  and the `spike-e2e` CI job.

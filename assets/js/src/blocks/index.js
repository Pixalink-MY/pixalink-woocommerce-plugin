/**
 * Cart & Checkout blocks: the loyalty panel and the coupon chip labels.
 *
 * - The panel fills `ExperimentalDiscountsMeta` (under the coupon form of the order summary), the only experimental
 *   API ADR 0001 allows. It reads `extensions['pixalink-loyalty']` from the `wc/store/cart` store and changes the
 *   cart only through `extensionCartUpdate()` -> POST /wc/store/v1/cart/extensions (the callback the classic
 *   checkout uses too).
 * - The `coupons` checkout filter relabels our chips ("Points (1,000 pts)", "Voucher: <title>") from the same
 *   store: the order-summary discount block calls the filter without `extensions` (WC 11.0-11.2).
 * - When the server marks the quote stale (no quote yet, expired, another cart) the panel asks for a new one,
 *   debounced.
 *
 * BlocksIntegration declares the wc-blocks-checkout, wc-settings, wp-plugins, wp-element and wp-data handles this
 * bundle reads from the window globals.
 */
import { DiscountsPanel } from './panel';

const blocksCheckout = window.wc?.blocksCheckout;
const settings =
	window.wc?.wcSettings?.getSetting?.( 'pixalink-loyalty_data', {} ) || {};
const NAMESPACE = settings.namespace || 'pixalink-loyalty';

/**
 * Our panel data on the cart, from the store.
 *
 * @return {Object|undefined} Panel data.
 */
const storeData = () =>
	window.wp.data.select( 'wc/store/cart' ).getCartData()?.extensions?.[
		NAMESPACE
	];

if ( blocksCheckout && window.wp?.plugins && settings.enabled ) {
	const {
		ExperimentalDiscountsMeta,
		extensionCartUpdate,
		registerCheckoutFilters,
	} = blocksCheckout;

	registerCheckoutFilters( NAMESPACE, {
		coupons: ( coupons, extensions ) => {
			const labels =
				( extensions?.[ NAMESPACE ] ?? storeData() )?.labels || {};
			return coupons.map( ( coupon ) =>
				labels[ coupon.code ]
					? { ...coupon, label: labels[ coupon.code ] }
					: coupon
			);
		},
	} );

	// One stable function, so the panel's refresh effect is not re-armed on every render.
	const update = ( data ) =>
		extensionCartUpdate( { namespace: NAMESPACE, data } );
	const strings = settings.strings || {};

	window.wp.plugins.registerPlugin( 'pixalink-loyalty', {
		render: () => (
			<ExperimentalDiscountsMeta>
				<DiscountsPanel
					namespace={ NAMESPACE }
					strings={ strings }
					update={ update }
				/>
			</ExperimentalDiscountsMeta>
		),
		scope: 'woocommerce-checkout',
	} );
}

/**
 * SPIKE (P02, throwaway): Blocks UI for the virtual-coupon experiment.
 *
 * - ExperimentalDiscountsMeta fill: renders below the coupon form in the Cart and Checkout blocks.
 * - extensionCartUpdate() -> POST /wc/store/v1/cart/extensions -> CartActions::handle() (same callback as classic).
 * - registerCheckoutFilters( coupons ): relabels the pxl-points chip to "Points (1,000 pts)".
 *
 * Reads the WooCommerce / WordPress globals so the bundle stays tiny; BlocksIntegration declares the handles.
 */
const { registerPlugin } = window.wp.plugins;
const { useState } = window.wp.element;
const { useSelect } = window.wp.data;
const {
	ExperimentalDiscountsMeta,
	extensionCartUpdate,
	registerCheckoutFilters,
} = window.wc.blocksCheckout;

const NAMESPACE = 'pixalink-loyalty';

// The order-summary discount block calls applyCheckoutFilter( { filterName: 'coupons' } ) without `extensions`
// (WC 11.0-11.2), so the documented second argument is empty there: read the cart store instead.
const extensionData = ( extensions ) =>
	extensions?.[ NAMESPACE ] ??
	window.wp.data.select( 'wc/store/cart' ).getCartData()?.extensions?.[
		NAMESPACE
	];

registerCheckoutFilters( NAMESPACE, {
	coupons: ( coupons, extensions ) => {
		const data = extensionData( extensions );
		return coupons.map( ( coupon ) =>
			data && coupon.code === data.points_code
				? { ...coupon, label: data.points_label }
				: coupon
		);
	},
} );

const Panel = () => {
	const data = useSelect(
		( select ) =>
			select( 'wc/store/cart' ).getCartData()?.extensions?.[ NAMESPACE ],
		[]
	);
	const [ points, setPoints ] = useState( null );
	const [ error, setError ] = useState( '' );

	if ( ! data ) {
		return null;
	}

	const run = ( payload ) =>
		extensionCartUpdate( { namespace: NAMESPACE, data: payload } )
			.then( () => setError( '' ) )
			.catch( ( response ) => setError( response?.message ?? 'Error' ) );

	if ( ! data.linked ) {
		return (
			<div
				className="wc-block-components-totals-wrapper"
				data-testid="pixalink-loyalty-spike"
			>
				<button
					type="button"
					onClick={ () => run( { action: 'spike_link' } ) }
				>
					Link Pixalink (spike)
				</button>
			</div>
		);
	}

	const value = points ?? data.points.applied;

	return (
		<div
			className="wc-block-components-totals-wrapper"
			data-testid="pixalink-loyalty-spike"
		>
			<p>
				Balance: { data.points.balance.toLocaleString( 'en-US' ) } pts
			</p>
			<input
				aria-label="Points slider"
				type="range"
				min={ 0 }
				max={ data.points.max }
				step={ data.points.step }
				value={ value }
				onChange={ ( event ) =>
					setPoints( Number( event.target.value ) )
				}
			/>
			<label htmlFor="pixalink-spike-points">Points to redeem</label>
			<input
				id="pixalink-spike-points"
				type="number"
				min={ 0 }
				max={ data.points.max }
				step={ data.points.step }
				value={ value }
				onChange={ ( event ) =>
					setPoints( Number( event.target.value ) )
				}
			/>
			<button
				type="button"
				onClick={ () => run( { action: 'set_points', points: value } ) }
			>
				Apply points
			</button>
			{ data.vouchers.map( ( voucher ) => (
				<button
					key={ voucher.code }
					type="button"
					onClick={ () =>
						run( {
							action: voucher.applied
								? 'remove_voucher'
								: 'apply_voucher',
							code: voucher.code,
						} )
					}
				>
					{ voucher.applied
						? `Remove ${ voucher.title }`
						: voucher.title }
				</button>
			) ) }
			{ error && <p role="alert">{ error }</p> }
		</div>
	);
};

registerPlugin( 'pixalink-loyalty-spike', {
	render: () => (
		<ExperimentalDiscountsMeta>
			<Panel />
		</ExperimentalDiscountsMeta>
	),
	scope: 'woocommerce-checkout',
} );

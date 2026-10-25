/**
 * The Blocks loyalty panel: balances, voucher cards, the points slider and the credits field.
 */
import {
	formatCents,
	formatNumber,
	pointsValueCents,
	fill,
} from '../shared/format';

const { useCallback, useEffect, useRef, useState } = window.wp.element;
const { useSelect } = window.wp.data;

/**
 * Debounce before asking for a fresh quote (cart edits come in bursts).
 */
const REFRESH_DELAY_MS = 400;

/**
 * A price in the cart's currency from hundredths.
 *
 * @param {number} cents  Hundredths.
 * @param {Object} totals Store API cart totals (currency_* fields).
 * @return {string} Formatted price.
 */
const money = ( cents, totals = {} ) =>
	`${ totals.currency_prefix ?? '' }${ formatCents( cents, {
		decimals: Math.min( 2, totals.currency_minor_unit ?? 2 ),
		decimalSeparator: totals.currency_decimal_separator ?? '.',
		thousandSeparator: totals.currency_thousand_separator ?? ',',
	} ) }${ totals.currency_suffix ?? '' }`;

/**
 * One voucher card.
 *
 * @param {Object}                          props
 * @param {Object}                          props.voucher Voucher card data.
 * @param {Object}                          props.strings Strings.
 * @param {boolean}                         props.busy    Whether a request is running.
 * @param {(data: Object) => Promise<void>} props.run     Run a cart action.
 * @return {Element} Card.
 */
const VoucherCard = ( { voucher, strings, busy, run } ) => {
	const classes = [ 'pixalink-loyalty-voucher' ];
	if ( voucher.applied ) {
		classes.push( 'is-applied' );
	}
	if ( ! voucher.usable ) {
		classes.push( 'is-unavailable' );
	}

	return (
		<li className={ classes.join( ' ' ) }>
			{ voucher.image_url && (
				<img
					className="pixalink-loyalty-voucher__image"
					src={ voucher.image_url }
					alt=""
					width="48"
					height="48"
					loading="lazy"
				/>
			) }
			<span className="pixalink-loyalty-voucher__body">
				<strong className="pixalink-loyalty-voucher__title">
					{ voucher.title }
				</strong>
				{ voucher.description && (
					<span className="pixalink-loyalty-voucher__description">
						{ voucher.description }
					</span>
				) }
				{ voucher.expires_label && (
					<span className="pixalink-loyalty-voucher__expiry">
						{ voucher.expires_label }
					</span>
				) }
				{ ! voucher.usable && voucher.reason && (
					<span className="pixalink-loyalty-voucher__reason">
						{ voucher.reason }
					</span>
				) }
			</span>
			{ ( voucher.applied || voucher.usable ) && (
				<button
					type="button"
					className="wc-block-components-button wp-element-button pixalink-loyalty-button"
					disabled={ busy }
					aria-label={ fill(
						voucher.applied
							? strings.voucher_remove_a
							: strings.voucher_apply_a,
						voucher.title
					) }
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
						? strings.voucher_remove
						: strings.voucher_apply }
				</button>
			) }
		</li>
	);
};

/**
 * Points slider and field.
 *
 * @param {Object}                          props
 * @param {Object}                          props.points  Points data.
 * @param {Object}                          props.totals  Cart totals (currency).
 * @param {Object}                          props.strings Strings.
 * @param {boolean}                         props.busy    Whether a request is running.
 * @param {(data: Object) => Promise<void>} props.run     Run a cart action.
 * @return {Element} Points block.
 */
const PointsField = ( { points, totals, strings, busy, run } ) => {
	const initial = points.applied > 0 ? points.applied : points.max;
	const [ value, setValue ] = useState( initial );

	// A new server value (applied, or a new cap) resets the field.
	useEffect( () => setValue( initial ), [ initial ] );

	if ( ! points.usable ) {
		return (
			<div className="pixalink-loyalty-panel__section pixalink-loyalty-points">
				<h4>{ strings.points_heading }</h4>
				<p className="pixalink-loyalty-panel__note">
					{ fill(
						strings.points_unusable,
						formatNumber( points.min )
					) }
				</p>
			</div>
		);
	}

	const change = ( event ) =>
		setValue(
			Math.min(
				points.max,
				Math.max( 0, Number( event.target.value ) || 0 )
			)
		);

	return (
		<div className="pixalink-loyalty-panel__section pixalink-loyalty-points">
			<h4>{ strings.points_heading }</h4>
			<label
				className="pixalink-loyalty-points__label"
				htmlFor="pixalink-loyalty-points-input"
			>
				{ strings.points_label }
			</label>
			<div className="pixalink-loyalty-points__controls">
				<input
					type="range"
					className="pixalink-loyalty-points__slider"
					aria-label={ strings.points_label }
					min={ 0 }
					max={ points.max }
					step={ points.step }
					value={ value }
					onChange={ change }
				/>
				<input
					type="number"
					id="pixalink-loyalty-points-input"
					className="pixalink-loyalty-points__input"
					min={ 0 }
					max={ points.max }
					step={ points.step }
					value={ value }
					inputMode="numeric"
					onChange={ change }
				/>
			</div>
			<p className="pixalink-loyalty-points__preview" aria-live="polite">
				{ fill(
					strings.points_preview,
					formatNumber( value ),
					money(
						pointsValueCents( value, points.value_per_point ),
						totals
					)
				) }
			</p>
			<button
				type="button"
				className="wc-block-components-button wp-element-button pixalink-loyalty-button"
				disabled={ busy }
				onClick={ () => run( { action: 'set_points', points: value } ) }
			>
				{ strings.points_apply }
			</button>
			{ points.applied > 0 && (
				<button
					type="button"
					className="wc-block-components-button wp-element-button pixalink-loyalty-button pixalink-loyalty-button--secondary"
					disabled={ busy }
					onClick={ () => run( { action: 'set_points', points: 0 } ) }
				>
					{ strings.points_remove }
				</button>
			) }
		</div>
	);
};

/**
 * Credits field (credits are shown as "10.00 credits", never as money).
 *
 * @param {Object}                          props
 * @param {Object}                          props.credits Credits data.
 * @param {Object}                          props.strings Strings.
 * @param {boolean}                         props.busy    Whether a request is running.
 * @param {(data: Object) => Promise<void>} props.run     Run a cart action.
 * @return {Element|null} Credits block.
 */
const CreditsField = ( { credits, strings, busy, run } ) => {
	const initial = formatCents(
		credits.applied_cents > 0 ? credits.applied_cents : credits.max_cents,
		{ thousandSeparator: '' }
	);
	const [ value, setValue ] = useState( initial );

	useEffect( () => setValue( initial ), [ initial ] );

	if ( ! credits.usable ) {
		return null;
	}

	const cents = Math.round(
		( parseFloat( String( value ).replace( ',', '.' ) ) || 0 ) * 100
	);

	return (
		<div className="pixalink-loyalty-panel__section pixalink-loyalty-credits">
			<h4>{ strings.credits_heading }</h4>
			<label
				className="pixalink-loyalty-credits__label"
				htmlFor="pixalink-loyalty-credits-input"
			>
				{ strings.credits_label }
			</label>
			<input
				type="number"
				id="pixalink-loyalty-credits-input"
				className="pixalink-loyalty-credits__input"
				min={ 0 }
				max={ credits.max_cents / 100 }
				step="0.01"
				value={ value }
				inputMode="decimal"
				onChange={ ( event ) => setValue( event.target.value ) }
			/>
			<p className="pixalink-loyalty-panel__note">
				{ fill(
					strings.credits_max,
					formatCents( credits.max_cents )
				) }
			</p>
			<button
				type="button"
				className="wc-block-components-button wp-element-button pixalink-loyalty-button"
				disabled={ busy }
				onClick={ () =>
					run( { action: 'set_credits', amount_cents: cents } )
				}
			>
				{ strings.credits_apply }
			</button>
			{ credits.applied_cents > 0 && (
				<button
					type="button"
					className="wc-block-components-button wp-element-button pixalink-loyalty-button pixalink-loyalty-button--secondary"
					disabled={ busy }
					onClick={ () =>
						run( { action: 'set_credits', amount_cents: 0 } )
					}
				>
					{ strings.credits_remove }
				</button>
			) }
		</div>
	);
};

/**
 * The panel.
 *
 * @param {Object}                            props
 * @param {string}                            props.namespace Store API namespace.
 * @param {Object}                            props.strings   Strings.
 * @param {boolean}                           props.branded   Whether the member portal's colours are used.
 * @param {(data: Object) => Promise<Object>} props.update    `extensionCartUpdate` for our namespace.
 * @return {Element|null} Panel.
 */
export const DiscountsPanel = ( { namespace, strings, branded, update } ) => {
	const { data, totals } = useSelect(
		( select ) => {
			const cart = select( 'wc/store/cart' ).getCartData();
			return {
				data: cart?.extensions?.[ namespace ],
				totals: cart?.totals,
			};
		},
		[ namespace ]
	);
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const refreshing = useRef( false );

	const run = useCallback(
		( payload ) => {
			setBusy( true );
			setError( '' );
			return Promise.resolve( update( payload ) )
				.catch( ( response ) =>
					setError( response?.message || strings.error )
				)
				.finally( () => setBusy( false ) );
		},
		[ update, strings.error ]
	);

	const stale = Boolean( data?.verified && data?.quote?.stale );
	useEffect( () => {
		if ( ! stale || refreshing.current ) {
			return undefined;
		}
		const timer = window.setTimeout( () => {
			refreshing.current = true;
			Promise.resolve( update( { action: 'refresh_quote' } ) )
				.catch( () => {} )
				.finally( () => {
					refreshing.current = false;
				} );
		}, REFRESH_DELAY_MS );

		return () => window.clearTimeout( timer );
	}, [ stale, update ] );

	if ( ! data || ! data.show ) {
		return null;
	}

	const body = () => {
		if ( ! data.verified ) {
			return (
				<p className="pixalink-loyalty-panel__prompt">
					{ data.verify_prompt }{ ' ' }
					{ data.verify_url && (
						<a href={ data.verify_url }>{ strings.verify_link }</a>
					) }
				</p>
			);
		}

		if ( ! data.quote?.available ) {
			return (
				<p className="pixalink-loyalty-panel__status" role="status">
					{ data.quote?.error
						? strings.quote_error
						: strings.loading }
				</p>
			);
		}

		const balances = [
			data.points?.balance_label,
			data.credits?.balance_label,
		].filter( Boolean );
		const nothing =
			! data.points &&
			! data.credits?.usable &&
			0 === data.vouchers.length;

		return (
			<>
				{ balances.length > 0 && (
					<p className="pixalink-loyalty-panel__balance">
						{ fill(
							strings.balance,
							balances.join( strings.separator )
						) }
					</p>
				) }
				{ data.vouchers.length > 0 && (
					<div className="pixalink-loyalty-panel__section pixalink-loyalty-vouchers">
						<h4>{ strings.vouchers_heading }</h4>
						<ul className="pixalink-loyalty-vouchers__list">
							{ data.vouchers.map( ( voucher ) => (
								<VoucherCard
									key={ voucher.code }
									voucher={ voucher }
									strings={ strings }
									busy={ busy }
									run={ run }
								/>
							) ) }
						</ul>
					</div>
				) }
				{ data.points && (
					<PointsField
						points={ data.points }
						totals={ totals }
						strings={ strings }
						busy={ busy }
						run={ run }
					/>
				) }
				{ data.credits && (
					<CreditsField
						credits={ data.credits }
						strings={ strings }
						busy={ busy }
						run={ run }
					/>
				) }
				{ nothing && (
					<p className="pixalink-loyalty-panel__note">
						{ strings.nothing }
					</p>
				) }
				{ data.earn_preview > 0 && (
					<p className="pixalink-loyalty-panel__earn">
						{ fill(
							strings.earn_preview,
							formatNumber( data.earn_preview )
						) }
					</p>
				) }
			</>
		);
	};

	return (
		<div
			className={ `wc-block-components-totals-wrapper pixalink-loyalty-panel${
				branded ? ' is-branded' : ''
			}${ busy ? ' is-busy' : '' }` }
			data-testid="pixalink-loyalty-panel"
		>
			<h3 className="pixalink-loyalty-panel__title">{ strings.title }</h3>
			{ body() }
			{ error && (
				<p className="pixalink-loyalty-panel__error" role="alert">
					{ error }
				</p>
			) }
		</div>
	);
};

<?php
/**
 * Per-shopper redemption state, outside the WooCommerce session blob.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Redemption;

defined( 'ABSPATH' ) || exit;

/**
 * The last quote and what the shopper chose (points, credits), keyed by the WC session's customer id.
 *
 * Stored in its own transient `pixalink_loyalty_redemption_<md5(session customer id)>`, never with
 * `WC()->session->set()`: the session is one last-writer-wins blob, and same-session requests that loaded it
 * earlier (Action Scheduler's loopback forwards the shopper's cookies) write their stale copy back over our keys
 * (ADR 0001, pitfall 1). Logged-in shoppers are mirrored to user meta, so the state survives an evicted transient
 * and follows the account.
 *
 * Two lifetimes:
 * - the **quote** is fresh for its `ttl_seconds`; after that it is re-fetched at the next refresh point (the
 *   panel's refresh, any spend action, a refused hold) before it is relied on again;
 * - the **record** lives as long as the WC session (48 h, or longer when the quote's TTL is), so a slow checkout
 *   keeps its discounts: the coupons keep resolving from the last quote without HTTP, and the hold at submit is
 *   authoritative anyway.
 *
 * Vouchers are not part of the selection: which vouchers are applied is the cart's applied coupons.
 */
final class RedemptionState {

	public const KEY_PREFIX = 'pixalink_loyalty_redemption_';
	public const USER_META  = 'pixalink_loyalty_redemption';

	/**
	 * Record lifetime in seconds: the WooCommerce session's 48 hours.
	 */
	public const SELECTION_TTL = 172800;

	/**
	 * Session customer id source (null without a session).
	 *
	 * @var \Closure():(string|null)
	 */
	private \Closure $session_customer_id;

	/**
	 * Current user id source.
	 *
	 * @var \Closure():int
	 */
	private \Closure $user_id;

	/**
	 * Unix time source.
	 *
	 * @var \Closure():int
	 */
	private \Closure $now;

	/**
	 * Constructor.
	 *
	 * @param \Closure      $session_customer_id `():?string` WC session customer id.
	 * @param \Closure      $user_id             `():int` current user id.
	 * @param \Closure|null $now                 `():int` unix time (tests); defaults to time().
	 */
	public function __construct( \Closure $session_customer_id, \Closure $user_id, ?\Closure $now = null ) {
		$this->session_customer_id = $session_customer_id;
		$this->user_id             = $user_id;
		$this->now                 = $now ?? static fn (): int => time();
	}

	/**
	 * The production state: WooCommerce's session and the current user.
	 *
	 * @return RedemptionState
	 */
	public static function for_wc_session(): RedemptionState {
		return new self(
			static function (): ?string {
				if ( ! function_exists( 'WC' ) || ! WC()->session instanceof \WC_Session ) {
					return null;
				}
				$customer = (string) WC()->session->get_customer_id();

				return '' === $customer ? null : $customer;
			},
			static fn (): int => (int) get_current_user_id()
		);
	}

	/**
	 * Keep the selection in step with the cart (ADR 0001 hook map).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_removed_coupon', array( $this, 'on_removed_coupon' ), 10, 1 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'clear_selection' ), 10, 0 );
	}

	/**
	 * `woocommerce_removed_coupon` action: the shopper (or WooCommerce) took the points or credits chip off.
	 *
	 * @param mixed $code Removed coupon code.
	 * @return void
	 */
	public function on_removed_coupon( $code ): void {
		$kind = is_string( $code ) ? CouponMapper::kind_of( $code ) : null;
		if ( CouponMapper::KIND_POINTS === $kind && $this->points() > 0 ) {
			$this->update( array( 'points' => 0 ) );
		} elseif ( CouponMapper::KIND_CREDIT === $kind && $this->credits_cents() > 0 ) {
			$this->update( array( 'credits_cents' => 0 ) );
		}
	}

	/**
	 * Whether a shopper session exists in this request (front end and Store API; not admin, cron or IPN).
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return null !== $this->key();
	}

	/**
	 * The normalised record.
	 *
	 * @return array{customer_id:string,quote:array<string,mixed>,quote_hash:string,quoted_at:int,expires_at:int,quote_error:string,points:int,credits_cents:int}
	 */
	public function get(): array {
		$key = $this->key();
		if ( null === $key ) {
			return self::defaults();
		}

		// get_transient() is cached in-request by WordPress; no second cache here (it would outlive a reset elsewhere).
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) ) {
			$stored = $this->read_mirror();
			if ( null !== $stored ) {
				set_transient( $key, $stored, $this->lifetime( $stored ) );
			}
		}

		return self::normalise( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Merge changes and persist (no-op without a session).
	 *
	 * @param array<string,mixed> $changes Keys of the record to replace.
	 * @return void
	 */
	public function update( array $changes ): void {
		$key = $this->key();
		if ( null === $key ) {
			return;
		}

		$record = self::normalise( array_merge( $this->get(), $changes ) );
		set_transient( $key, $record, $this->lifetime( $record ) );

		$user_id = ( $this->user_id )();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::USER_META, $record + array( 'stored_until' => ( $this->now )() + $this->lifetime( $record ) ) );
		}
	}

	/**
	 * Forget everything (transient and mirror).
	 *
	 * @return void
	 */
	public function reset(): void {
		$key = $this->key();
		if ( null === $key ) {
			return;
		}

		delete_transient( $key );

		$user_id = ( $this->user_id )();
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::USER_META );
		}
	}

	/**
	 * Drop the selection (points, credits) and keep the quote.
	 *
	 * @return void
	 */
	public function clear_selection(): void {
		if ( 0 === $this->points() && 0 === $this->credits_cents() ) {
			return;
		}

		$this->update(
			array(
				'points'        => 0,
				'credits_cents' => 0,
			)
		);
	}

	/**
	 * Store a quote for a customer and the cart it priced.
	 *
	 * @param string              $customer_id Pixalink customer id.
	 * @param array<string,mixed> $quote       Quote response.
	 * @param string              $cart_hash   Signature of the quoted cart ({@see CartPayload::signature()}).
	 * @return void
	 */
	public function store_quote( string $customer_id, array $quote, string $cart_hash ): void {
		$now = ( $this->now )();
		$ttl = isset( $quote['ttl_seconds'] ) && is_int( $quote['ttl_seconds'] ) ? max( 0, $quote['ttl_seconds'] ) : 0;

		$this->update(
			array(
				'customer_id' => $customer_id,
				'quote'       => $quote,
				'quote_hash'  => $cart_hash,
				'quoted_at'   => $now,
				'expires_at'  => $now + $ttl,
				'quote_error' => '',
			)
		);
	}

	/**
	 * Remember why the last quote attempt failed (machine code; '' clears it).
	 *
	 * @param string $code Error code.
	 * @return void
	 */
	public function record_quote_error( string $code ): void {
		$this->update( array( 'quote_error' => $code ) );
	}

	/**
	 * Why the last quote attempt failed ('' when it did not).
	 *
	 * @return string
	 */
	public function quote_error(): string {
		return $this->get()['quote_error'];
	}

	/**
	 * When the stored quote was fetched (unix time, 0 when never).
	 *
	 * @return int
	 */
	public function quoted_at(): int {
		return $this->get()['quoted_at'];
	}

	/**
	 * Whether any quote is stored.
	 *
	 * @return bool
	 */
	public function has_quote(): bool {
		return array() !== $this->get()['quote'];
	}

	/**
	 * Whether the stored quote is within its ttl_seconds.
	 *
	 * @return bool
	 */
	public function is_quote_fresh(): bool {
		return $this->has_quote() && ( $this->now )() < $this->get()['expires_at'];
	}

	/**
	 * The stored quote (empty when none).
	 *
	 * @return array<string,mixed>
	 */
	public function quote(): array {
		return $this->get()['quote'];
	}

	/**
	 * Signature of the cart the quote priced.
	 *
	 * @return string
	 */
	public function quote_hash(): string {
		return $this->get()['quote_hash'];
	}

	/**
	 * Pixalink customer the quote is for.
	 *
	 * @return string
	 */
	public function customer_id(): string {
		return $this->get()['customer_id'];
	}

	/**
	 * Selected points.
	 *
	 * @return int
	 */
	public function points(): int {
		return $this->get()['points'];
	}

	/**
	 * Selected credits in cents.
	 *
	 * @return int
	 */
	public function credits_cents(): int {
		return $this->get()['credits_cents'];
	}

	/**
	 * Points offer of the quote (null when the quote has none: switched off or unverified).
	 *
	 * @return array{balance:int,available:int,value_per_point:string,min:int,max_for_cart:int,step:int}|null
	 */
	public function points_config(): ?array {
		$points = $this->quote()['points'] ?? null;
		if ( ! is_array( $points ) ) {
			return null;
		}

		return array(
			'balance'         => self::int( $points['balance'] ?? 0 ),
			'available'       => self::int( $points['available'] ?? 0 ),
			'value_per_point' => isset( $points['value_per_point'] ) && is_string( $points['value_per_point'] ) ? $points['value_per_point'] : '0',
			'min'             => self::int( $points['min'] ?? 0 ),
			'max_for_cart'    => self::int( $points['max_for_cart'] ?? 0 ),
			'step'            => max( 1, self::int( $points['step'] ?? 1 ) ),
		);
	}

	/**
	 * Credits offer of the quote (null when the quote has none).
	 *
	 * @return array{balance_cents:int,max_for_cart_cents:int}|null
	 */
	public function credits_config(): ?array {
		$credits = $this->quote()['credits'] ?? null;
		if ( ! is_array( $credits ) ) {
			return null;
		}

		return array(
			'balance_cents'      => self::int( $credits['balance_cents'] ?? 0 ),
			'max_for_cart_cents' => self::int( $credits['max_for_cart_cents'] ?? 0 ),
		);
	}

	/**
	 * Points the quote estimates this cart earns.
	 *
	 * @return int|null
	 */
	public function earn_preview_points(): ?int {
		$preview = $this->quote()['earn_preview'] ?? null;

		return is_array( $preview ) && isset( $preview['points'] ) && is_int( $preview['points'] ) ? $preview['points'] : null;
	}

	/**
	 * Quote vouchers, each with its WooCommerce `coupon_code`.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function vouchers(): array {
		$vouchers = $this->quote()['vouchers'] ?? array();
		if ( ! is_array( $vouchers ) ) {
			return array();
		}

		$list = array();
		foreach ( $vouchers as $voucher ) {
			if ( is_array( $voucher ) && isset( $voucher['id'] ) && is_scalar( $voucher['id'] ) ) {
				$list[] = array( 'coupon_code' => CouponMapper::voucher_code( $voucher ) ) + $voucher;
			}
		}

		return $list;
	}

	/**
	 * The quote voucher for a coupon code, or for the Pixalink voucher code itself (any case).
	 *
	 * @param string $code Coupon code or Pixalink voucher code.
	 * @return array<string,mixed>|null
	 */
	public function voucher( string $code ): ?array {
		$normalised = CouponMapper::normalise( $code );
		if ( '' === $normalised ) {
			return null;
		}

		foreach ( $this->vouchers() as $voucher ) {
			$own = isset( $voucher['code'] ) && is_scalar( $voucher['code'] ) ? CouponMapper::normalise( (string) $voucher['code'] ) : '';
			if ( $voucher['coupon_code'] === $normalised || $own === $normalised ) {
				return $voucher;
			}
		}

		return null;
	}

	/**
	 * Storage key for this session.
	 *
	 * @return string|null
	 */
	private function key(): ?string {
		$customer = ( $this->session_customer_id )();

		return null === $customer || '' === $customer ? null : self::KEY_PREFIX . md5( $customer );
	}

	/**
	 * The logged-in shopper's mirror, unless it expired.
	 *
	 * @return array<string,mixed>|null
	 */
	private function read_mirror(): ?array {
		$user_id = ( $this->user_id )();
		if ( $user_id < 1 ) {
			return null;
		}

		$mirror = get_user_meta( $user_id, self::USER_META, true );
		if ( ! is_array( $mirror ) || self::int( $mirror['stored_until'] ?? 0 ) <= ( $this->now )() ) {
			return null;
		}

		unset( $mirror['stored_until'] );

		return $mirror;
	}

	/**
	 * Storage lifetime of a record: the session's, or the quote's TTL when longer.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return int
	 */
	private function lifetime( array $record ): int {
		$ttl = isset( $record['quote']['ttl_seconds'] ) && is_int( $record['quote']['ttl_seconds'] ) ? $record['quote']['ttl_seconds'] : 0;

		return max( self::SELECTION_TTL, $ttl );
	}

	/**
	 * Empty record.
	 *
	 * @return array{customer_id:string,quote:array<string,mixed>,quote_hash:string,quoted_at:int,expires_at:int,quote_error:string,points:int,credits_cents:int}
	 */
	private static function defaults(): array {
		return array(
			'customer_id'   => '',
			'quote'         => array(),
			'quote_hash'    => '',
			'quoted_at'     => 0,
			'expires_at'    => 0,
			'quote_error'   => '',
			'points'        => 0,
			'credits_cents' => 0,
		);
	}

	/**
	 * Coerce a stored record into its shape.
	 *
	 * @param array<mixed> $stored Stored record.
	 * @return array{customer_id:string,quote:array<string,mixed>,quote_hash:string,quoted_at:int,expires_at:int,quote_error:string,points:int,credits_cents:int}
	 */
	private static function normalise( array $stored ): array {
		$quote = isset( $stored['quote'] ) && is_array( $stored['quote'] ) ? $stored['quote'] : array();
		$keyed = array();
		foreach ( $quote as $name => $value ) {
			$keyed[ (string) $name ] = $value;
		}

		return array(
			'customer_id'   => isset( $stored['customer_id'] ) && is_string( $stored['customer_id'] ) ? $stored['customer_id'] : '',
			'quote'         => $keyed,
			'quote_hash'    => isset( $stored['quote_hash'] ) && is_string( $stored['quote_hash'] ) ? $stored['quote_hash'] : '',
			'quoted_at'     => self::int( $stored['quoted_at'] ?? 0 ),
			'expires_at'    => self::int( $stored['expires_at'] ?? 0 ),
			'quote_error'   => isset( $stored['quote_error'] ) && is_string( $stored['quote_error'] ) ? $stored['quote_error'] : '',
			'points'        => self::int( $stored['points'] ?? 0 ),
			'credits_cents' => self::int( $stored['credits_cents'] ?? 0 ),
		);
	}

	/**
	 * A non-negative integer from a stored scalar.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function int( $value ): int {
		return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ? max( 0, (int) $value ) : 0;
	}
}

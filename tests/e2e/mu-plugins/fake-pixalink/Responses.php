<?php
/**
 * Fake Pixalink server: canned responses.
 *
 * @package Pixalink\Loyalty\Tests
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Tests\FakeServer;

/**
 * Default responses for every /api/commerce/v1 endpoint, shaped exactly like tests/contract/schemas.
 *
 * Pure PHP (no WordPress calls) so the contract suite can validate every canned body. Fixture data:
 * one member (+60123456789 / member@example.com, WordPress id 17, Pixalink id 5001) with 1250 points and two vouchers.
 *
 * Identity scenarios:
 * - Portal sign-in tickets: {@see Responses::SSO_TICKET_MEMBER}* resolves the member, {@see Responses::SSO_TICKET_NEW}*
 *   a new customer, {@see Responses::SSO_TICKET_NO_EMAIL}* a customer without e-mail; anything else (or a ticket the
 *   server saw before, context `ticket_used`) is 410 ticket_invalid.
 * - One-time codes: {@see Responses::OTP_CODE} verifies; {@see Responses::OTP_CODE_EXPIRED} and
 *   {@see Responses::OTP_CODE_EXHAUSTED} produce otp_expired / otp_attempts_exceeded; any other code otp_invalid.
 */
final class Responses {

	public const OUTLET_ID        = '101';
	public const OUTLET_UUID      = '9a3c1f0e-6b1d-4c39-9f7e-0c2b5d6a7e81';
	public const MEMBER_ID        = '5001';
	public const MEMBER_PHONE     = '+60123456789';
	public const MEMBER_EMAIL     = 'member@example.com';
	public const VOUCHER_ID       = '88';
	public const VOUCHER_CODE     = 'PXL-WELCOME-88';
	public const MEMBER_WP_ID     = '17';
	public const NEW_CUSTOMER_ID  = '6001';
	public const OTP_CODE         = '123456';
	public const POINTS_AVAILABLE = 1250;

	/**
	 * Host of the fake member portal (heartbeat branding, summary and portal-session links all point here).
	 */
	public const PORTAL_HOST = 'fake-pixalink.test';

	/**
	 * Signed-out home of the fake member portal.
	 */
	public const PORTAL_URL = 'https://fake-pixalink.test/l/fake-cafe';

	/**
	 * Fake portal colours (lower-case #rrggbb, like production).
	 */
	public const BRAND_PRIMARY = '#0f5c56';
	public const BRAND_ON      = '#ffffff';
	public const BRAND_INK     = '#0f5c56';
	public const BRAND_ACCENT  = '#f2b84b';

	public const OTP_CODE_EXPIRED   = '111111';
	public const OTP_CODE_EXHAUSTED = '999999';

	public const SSO_TICKET_MEMBER   = 'fake-sso-member-';
	public const SSO_TICKET_NEW      = 'fake-sso-new-';
	public const SSO_TICKET_NO_EMAIL = 'fake-sso-noemail-';

	/**
	 * Credits balance of the member, in cents (25.00 credits).
	 */
	public const CREDITS_BALANCE_CENTS = 2500;

	/**
	 * Vouchers the quote offers: id => [code, title, discount_type, amount, product_skus, limit_usage_to_x_items, minimum_amount].
	 * 93 is listed but only redeemable in store.
	 */
	public const QUOTE_VOUCHERS = array(
		'88' => array( 'PXL-WELCOME-88', 'Welcome 10 off', 'fixed_cart', '10.00', array(), null, '50.00' ),
		'89' => array( 'PXL-TENPCT-89', '10% off', 'percent', '10', array(), null, null ),
		'90' => array( 'PXL-SKU5-90', '5 off product A', 'fixed_product', '5.00', array( 'PXL-A' ), null, null ),
		'91' => array( 'PXL-FREEB-91', 'Free product B', 'percent', '100', array( 'PXL-B' ), 1, null ),
		'92' => array( 'PXL-GHOST-92', 'Mystery gift', 'fixed_product', '5.00', array( 'NO-SUCH-SKU' ), null, null ),
	);

	/**
	 * In-store-only voucher id listed in every quote.
	 */
	public const INSTORE_VOUCHER_ID = '93';

	/**
	 * Codes starting with this prefix make connect/exchange fail with invalid_grant.
	 */
	public const INVALID_CODE_PREFIX = 'fake-code-invalid';

	/**
	 * Route templates served by the fake server.
	 */
	public const ROUTES = array(
		'connect/exchange',
		'connection/heartbeat',
		'connection/disconnect',
		'outlet',
		'customers',
		'customers/{id}',
		'customers/{id}/otp',
		'customers/{id}/otp/verify',
		'customers/{id}/portal-session',
		'quote',
		'orders/{order_ref}/redemptions',
		'events',
	);

	/**
	 * Build the default response.
	 *
	 * @param string              $method  HTTP method.
	 * @param string              $route   Route template from {@see Responses::ROUTES}.
	 * @param array<string,mixed> $params  Path parameters (id, order_ref).
	 * @param array<string,mixed> $query   Query parameters.
	 * @param array<mixed>        $body    Decoded JSON body.
	 * @param array<string,mixed> $context {now:int, api_key:string, event_seen:bool, connected_site:string, ticket_used:bool}.
	 * @return array{status:int,body:mixed}
	 */
	public static function respond( string $method, string $route, array $params, array $query, array $body, array $context ): array {
		$now     = isset( $context['now'] ) && is_int( $context['now'] ) ? $context['now'] : time();
		$api_key = isset( $context['api_key'] ) && is_string( $context['api_key'] ) ? $context['api_key'] : Signature::FAKE_API_KEY;
		$key     = strtoupper( $method ) . ' ' . $route;

		switch ( $key ) {
			case 'POST connect/exchange':
				$code = isset( $body['code'] ) && is_string( $body['code'] ) ? $body['code'] : '';
				if ( '' === $code || 0 === strpos( $code, self::INVALID_CODE_PREFIX ) ) {
					return self::error( 400, 'invalid_grant', 'The authorisation code is invalid or expired.' );
				}
				return self::ok( self::exchange( $api_key ) );

			case 'POST connection/heartbeat':
				$connected_site = isset( $context['connected_site'] ) && is_string( $context['connected_site'] ) ? $context['connected_site'] : '';
				$site           = isset( $body['site'] ) && is_string( $body['site'] ) ? rtrim( $body['site'], '/' ) : '';
				if ( '' !== $connected_site && $connected_site !== $site ) {
					return self::error( 409, 'site_already_connected', 'This outlet is already connected to another WooCommerce store.' );
				}
				return self::ok( self::heartbeat( $now ) );

			case 'POST connection/disconnect':
				return array(
					'status' => 204,
					'body'   => null,
				);

			case 'GET outlet':
				return self::ok(
					array(
						'outlet'       => self::outlet(),
						'organisation' => self::organisation(),
					)
				);

			case 'GET customers':
				$matches = ( ( $query['phone'] ?? null ) === self::MEMBER_PHONE || ( $query['email'] ?? null ) === self::MEMBER_EMAIL || ( $query['external_id'] ?? null ) === self::MEMBER_WP_ID );
				return $matches ? self::ok( array( 'customer' => self::member( false ) ) ) : self::error( 404, 'not_found', 'No customer matches.' );

			case 'POST customers':
				if ( 'sso_ticket' === ( $body['verified_via'] ?? null ) ) {
					return self::redeem_ticket( isset( $body['sso_ticket'] ) && is_string( $body['sso_ticket'] ) ? $body['sso_ticket'] : '', ! empty( $context['ticket_used'] ) );
				}
				$existing = ( $body['phone'] ?? null ) === self::MEMBER_PHONE;
				return array(
					'status' => $existing ? 200 : 201,
					'body'   => array(
						'customer' => $existing ? self::member( false ) : self::new_customer( $body, false ),
						'created'  => ! $existing,
					),
				);

			case 'GET customers/{id}':
				if ( ! in_array( $params['id'] ?? null, array( self::MEMBER_ID, self::NEW_CUSTOMER_ID ), true ) ) {
					return self::error( 404, 'not_found', 'Customer not found.' );
				}
				return self::ok( self::MEMBER_ID === $params['id'] ? self::summary( $now ) : self::empty_summary() );

			case 'POST customers/{id}/otp':
				return self::ok(
					array(
						'sent_via'            => 'sms' === ( $body['channel'] ?? null ) ? 'sms' : 'whatsapp',
						'retry_after_seconds' => 60,
					)
				);

			case 'POST customers/{id}/otp/verify':
				$code = $body['code'] ?? null;
				if ( self::OTP_CODE_EXPIRED === $code ) {
					return self::error( 422, 'otp_expired', 'The code has expired.' );
				}
				if ( self::OTP_CODE_EXHAUSTED === $code ) {
					return self::error( 422, 'otp_attempts_exceeded', 'Too many wrong codes.' );
				}
				if ( self::OTP_CODE !== $code ) {
					return self::error( 422, 'otp_invalid', 'The code is incorrect.' );
				}
				return self::ok( array( 'verified' => true ) );

			case 'POST customers/{id}/portal-session':
				if ( ! in_array( $params['id'] ?? null, array( self::MEMBER_ID, self::NEW_CUSTOMER_ID ), true ) ) {
					return self::error( 404, 'not_found', 'Customer not found.' );
				}
				return array(
					'status' => 201,
					'body'   => self::portal_session( $now ),
				);

			case 'POST quote':
				return self::ok( self::quote( $body, self::points_balance( $context ) ) );

			case 'PUT orders/{order_ref}/redemptions':
				return self::hold( (string) ( $params['order_ref'] ?? '' ), $body, $now, self::points_balance( $context ) );

			case 'DELETE orders/{order_ref}/redemptions':
				return self::ok(
					array(
						'order_ref'   => (string) ( $params['order_ref'] ?? '' ),
						'redemptions' => array(),
						'released'    => 0,
					)
				);

			case 'POST events':
				return self::event( $body, ! empty( $context['event_seen'] ) );
		}

		return self::error( 404, 'not_found', 'No such fake endpoint.' );
	}

	/**
	 * Error envelope.
	 *
	 * @param int                 $status  HTTP status.
	 * @param string              $code    Machine code.
	 * @param string              $message Message.
	 * @param array<string,mixed> $extra   Extra top-level fields (server_time, available, errors).
	 * @return array{status:int,body:array<string,mixed>}
	 */
	public static function error( int $status, string $code, string $message, array $extra = array() ): array {
		return array(
			'status' => $status,
			'body'   => array(
				'message' => $message,
				'code'    => $code,
			) + $extra,
		);
	}

	/**
	 * Every canned response with the schema it must satisfy (used by the contract suite).
	 *
	 * @param int $now Unix time.
	 * @return array<string,array{response:array{status:int,body:mixed},schema:string}>
	 */
	public static function samples( int $now ): array {
		$context = array(
			'now'     => $now,
			'api_key' => Signature::FAKE_API_KEY,
		);
		$cart    = self::sample_cart( 6000 );
		$hold    = array(
			'customer_id' => self::MEMBER_ID,
			'cart'        => $cart,
			'redemptions' => array(
				array(
					'kind'               => 'voucher',
					'voucher_id'         => self::VOUCHER_ID,
					'coupon_code'        => strtolower( self::VOUCHER_CODE ),
					'discount_cents'     => 1000,
					'discount_tax_cents' => 0,
				),
				array(
					'kind'               => 'points',
					'points'             => 500,
					'coupon_code'        => 'pxl-points',
					'discount_cents'     => 500,
					'discount_tax_cents' => 0,
				),
				array(
					'kind'               => 'credit',
					'amount_cents'       => 250,
					'coupon_code'        => 'pxl-credits',
					'discount_cents'     => 250,
					'discount_tax_cents' => 0,
				),
			),
		);
		$entry   = static fn ( array $redemption ): array => array(
			'customer_id' => self::MEMBER_ID,
			'cart'        => $cart,
			'redemptions' => array( $redemption ),
		);
		$ping    = array(
			'id'   => 'a6417789-0a05-5068-bab0-90d991946d97',
			'type' => 'ping',
		);

		$samples = array(
			'exchange'               => array( self::respond( 'POST', 'connect/exchange', array(), array(), array( 'code' => 'fake-code-0123456789abcdef' ), $context ), 'connect-exchange.response.json' ),
			'exchange invalid'       => array( self::respond( 'POST', 'connect/exchange', array(), array(), array( 'code' => self::INVALID_CODE_PREFIX . '-1' ), $context ), 'error.response.json' ),
			'heartbeat'              => array( self::respond( 'POST', 'connection/heartbeat', array(), array(), array(), $context ), 'connection-heartbeat.response.json' ),
			'heartbeat other site'   => array(
				self::respond(
					'POST',
					'connection/heartbeat',
					array(),
					array(),
					array( 'site' => 'https://shop.example.test' ),
					array( 'connected_site' => 'https://other-shop.example.test' ) + $context
				),
				'error.response.json',
			),
			'outlet'                 => array( self::respond( 'GET', 'outlet', array(), array(), array(), $context ), 'outlet.response.json' ),
			'lookup hit'             => array( self::respond( 'GET', 'customers', array(), array( 'phone' => self::MEMBER_PHONE ), array(), $context ), 'customers-lookup.response.json' ),
			'lookup miss'            => array( self::respond( 'GET', 'customers', array(), array( 'phone' => '+60199999999' ), array(), $context ), 'error.response.json' ),
			'create existing'        => array( self::respond( 'POST', 'customers', array(), array(), array( 'phone' => self::MEMBER_PHONE ), $context ), 'customers-create.response.json' ),
			'create new'             => array(
				self::respond(
					'POST',
					'customers',
					array(),
					array(),
					array(
						'phone'         => '+60199999999',
						'phone_country' => 'MY',
						'email'         => 'new@example.com',
						'first_name'    => 'New',
					),
					$context
				),
				'customers-create.response.json',
			),
			'sso member'             => array( self::respond( 'POST', 'customers', array(), array(), self::ticket_body( self::SSO_TICKET_MEMBER . 'abc123' ), $context ), 'customers-create.response.json' ),
			'sso new'                => array( self::respond( 'POST', 'customers', array(), array(), self::ticket_body( self::SSO_TICKET_NEW . 'abc123' ), $context ), 'customers-create.response.json' ),
			'sso no email'           => array( self::respond( 'POST', 'customers', array(), array(), self::ticket_body( self::SSO_TICKET_NO_EMAIL . 'abc1' ), $context ), 'customers-create.response.json' ),
			'sso unknown'            => array( self::respond( 'POST', 'customers', array(), array(), self::ticket_body( 'someone-elses-ticket-0001' ), $context ), 'error.response.json' ),
			'sso replayed'           => array( self::respond( 'POST', 'customers', array(), array(), self::ticket_body( self::SSO_TICKET_MEMBER . 'abc123' ), array( 'ticket_used' => true ) + $context ), 'error.response.json' ),
			'summary'                => array( self::respond( 'GET', 'customers/{id}', array( 'id' => self::MEMBER_ID ), array(), array(), $context ), 'customer-summary.response.json' ),
			'summary new'            => array( self::respond( 'GET', 'customers/{id}', array( 'id' => self::NEW_CUSTOMER_ID ), array(), array(), $context ), 'customer-summary.response.json' ),
			'summary missing'        => array( self::respond( 'GET', 'customers/{id}', array( 'id' => '1' ), array(), array(), $context ), 'error.response.json' ),
			'otp'                    => array( self::respond( 'POST', 'customers/{id}/otp', array( 'id' => self::MEMBER_ID ), array(), array( 'channel' => null ), $context ), 'customer-otp.response.json' ),
			'otp sms'                => array( self::respond( 'POST', 'customers/{id}/otp', array( 'id' => self::MEMBER_ID ), array(), array( 'channel' => 'sms' ), $context ), 'customer-otp.response.json' ),
			'otp verify'             => array( self::respond( 'POST', 'customers/{id}/otp/verify', array( 'id' => self::MEMBER_ID ), array(), array( 'code' => self::OTP_CODE ), $context ), 'customer-otp-verify.response.json' ),
			'otp verify wrong'       => array( self::respond( 'POST', 'customers/{id}/otp/verify', array( 'id' => self::MEMBER_ID ), array(), array( 'code' => '000000' ), $context ), 'error.response.json' ),
			'otp verify expired'     => array( self::respond( 'POST', 'customers/{id}/otp/verify', array( 'id' => self::MEMBER_ID ), array(), array( 'code' => self::OTP_CODE_EXPIRED ), $context ), 'error.response.json' ),
			'otp verify exhausted'   => array( self::respond( 'POST', 'customers/{id}/otp/verify', array( 'id' => self::MEMBER_ID ), array(), array( 'code' => self::OTP_CODE_EXHAUSTED ), $context ), 'error.response.json' ),
			'otp throttled'          => array( self::error( 429, 'otp_throttled', 'Too many codes requested.' ), 'error.response.json' ),
			'portal session'         => array( self::respond( 'POST', 'customers/{id}/portal-session', array( 'id' => self::MEMBER_ID ), array(), array( 'wp_user_id' => 17 ), $context ), 'customer-portal-session.response.json' ),
			'portal session missing' => array( self::respond( 'POST', 'customers/{id}/portal-session', array( 'id' => '1' ), array(), array( 'wp_user_id' => 17 ), $context ), 'error.response.json' ),
			'portal rate limited'    => array( self::error( 429, 'rate_limited', 'Too many sign-in links requested.' ), 'error.response.json' ),
			'verification required'  => array( self::error( 403, 'verification_required', 'Verify the phone number first.' ), 'error.response.json' ),
			'quote'                  => array(
				self::respond(
					'POST',
					'quote',
					array(),
					array(),
					array(
						'customer_id' => self::MEMBER_ID,
						'cart'        => $cart,
					),
					$context
				),
				'quote.response.json',
			),
			'quote empty cart'       => array( self::respond( 'POST', 'quote', array(), array(), array(), $context ), 'quote.response.json' ),
			'hold'                   => array( self::respond( 'PUT', 'orders/{order_ref}/redemptions', array( 'order_ref' => 'wc_order_AbC123xyz' ), array(), $hold, $context ), 'redemptions.response.json' ),
			'hold unknown voucher'   => array(
				self::respond(
					'PUT',
					'orders/{order_ref}/redemptions',
					array( 'order_ref' => 'wc_order_AbC123xyz' ),
					array(),
					$entry(
						array(
							'kind'               => 'voucher',
							'voucher_id'         => self::INSTORE_VOUCHER_ID,
							'coupon_code'        => 'pxl-instore-93',
							'discount_cents'     => 0,
							'discount_tax_cents' => 0,
						)
					),
					$context
				),
				'error.response.json',
			),
			'hold too few credits'   => array(
				self::respond(
					'PUT',
					'orders/{order_ref}/redemptions',
					array( 'order_ref' => 'wc_order_AbC123xyz' ),
					array(),
					$entry(
						array(
							'kind'               => 'credit',
							'amount_cents'       => self::CREDITS_BALANCE_CENTS + 1,
							'coupon_code'        => 'pxl-credits',
							'discount_cents'     => self::CREDITS_BALANCE_CENTS + 1,
							'discount_tax_cents' => 0,
						)
					),
					$context
				),
				'error.response.json',
			),
			'hold mismatch'          => array(
				self::respond(
					'PUT',
					'orders/{order_ref}/redemptions',
					array( 'order_ref' => 'wc_order_AbC123xyz' ),
					array(),
					$entry(
						array(
							'kind'               => 'points',
							'points'             => 500,
							'coupon_code'        => 'pxl-points',
							'discount_cents'     => 900,
							'discount_tax_cents' => 0,
						)
					),
					$context
				),
				'error.response.json',
			),
			'hold too many points'   => array(
				self::respond(
					'PUT',
					'orders/{order_ref}/redemptions',
					array( 'order_ref' => 'wc_order_AbC123xyz' ),
					array(),
					$entry(
						array(
							'kind'               => 'points',
							'points'             => self::POINTS_AVAILABLE + 100,
							'coupon_code'        => 'pxl-points',
							'discount_cents'     => self::POINTS_AVAILABLE + 100,
							'discount_tax_cents' => 0,
						)
					),
					$context
				),
				'error.response.json',
			),
			'release'                => array( self::respond( 'DELETE', 'orders/{order_ref}/redemptions', array( 'order_ref' => 'wc_order_AbC123xyz' ), array(), array(), $context ), 'redemptions.response.json' ),
			'event ping'             => array( self::respond( 'POST', 'events', array(), array(), $ping, $context ), 'event.response.json' ),
			'event duplicate'        => array( self::respond( 'POST', 'events', array(), array(), $ping, array( 'event_seen' => true ) + $context ), 'event.response.json' ),
			'event order.paid'       => array( self::respond( 'POST', 'events', array(), array(), array( 'type' => 'order.paid' ), $context ), 'event.response.json' ),
			'event refund_deleted'   => array( self::respond( 'POST', 'events', array(), array(), array( 'type' => 'order.refund_deleted' ), $context ), 'event.response.json' ),
			'timestamp skew'         => array( self::error( 401, 'timestamp_skew', 'The request timestamp is outside the allowed window.', array( 'server_time' => $now ) ), 'error.response.json' ),
		);

		$named = array();
		foreach ( $samples as $name => $sample ) {
			$named[ $name ] = array(
				'response' => $sample[0],
				'schema'   => $sample[1],
			);
		}

		return $named;
	}

	/**
	 * 200 wrapper.
	 *
	 * @param mixed $body Body.
	 * @return array{status:int,body:mixed}
	 */
	private static function ok( $body ): array {
		return array(
			'status' => 200,
			'body'   => $body,
		);
	}

	/**
	 * Outlet fixture.
	 *
	 * @return array<string,string>
	 */
	private static function outlet(): array {
		return array(
			'id'       => self::OUTLET_ID,
			'uuid'     => self::OUTLET_UUID,
			'name'     => 'Fake Online Store',
			'currency' => 'MYR',
			'timezone' => 'Asia/Kuala_Lumpur',
		);
	}

	/**
	 * Organisation fixture.
	 *
	 * @return array<string,string>
	 */
	private static function organisation(): array {
		return array(
			'id'   => '229',
			'name' => 'Fake Cafe',
		);
	}

	/**
	 * Exchange body.
	 *
	 * @param string $api_key Key handed out.
	 * @return array<string,mixed>
	 */
	private static function exchange( string $api_key ): array {
		return array(
			'api_key'       => $api_key,
			'outlet'        => self::outlet(),
			'organisation'  => self::organisation(),
			'api_base_url'  => 'https://fake-pixalink.test/api/commerce/v1',
			'dashboard_url' => 'https://fake-pixalink.test/admin',
		);
	}

	/**
	 * Heartbeat body.
	 *
	 * @param int $now Unix time.
	 * @return array<string,mixed>
	 */
	private static function heartbeat( int $now ): array {
		return array(
			'status'             => 'active',
			'outlet'             => self::outlet(),
			'features'           => array(
				'earn'              => true,
				'vouchers'          => true,
				'points_redemption' => true,
				'credits'           => true,
				'otp_channels'      => array( 'whatsapp', 'sms' ),
			),
			'redemption_rules'   => array(
				'points'   => array(
					'value_per_point'         => '0.01',
					'min'                     => 100,
					'step'                    => 100,
					'max_percent_of_subtotal' => 50,
				),
				'vouchers' => array( 'max_per_order' => 1 ),
			),
			'earn_on'            => 'paid',
			'min_plugin_version' => '0.1.0',
			'server_time'        => $now,
			'branding'           => array(
				'name'              => 'Fake Cafe',
				'logo_url'          => null,
				'primary_color'     => self::BRAND_PRIMARY,
				'primary_color_on'  => self::BRAND_ON,
				'primary_color_ink' => self::BRAND_INK,
				'secondary_color'   => self::BRAND_ACCENT,
				'portal_url'        => self::PORTAL_URL,
			),
		);
	}

	/**
	 * A single-use portal sign-in link (a new ticket every time, valid for 60 seconds).
	 *
	 * @param int $now Unix time.
	 * @return array{url:string,expires_at:string}
	 */
	private static function portal_session( int $now ): array {
		return array(
			'url'        => self::PORTAL_URL . '/handoff/' . bin2hex( random_bytes( 20 ) ),
			'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now + 60 ),
		);
	}

	/**
	 * Member fixture.
	 *
	 * @param bool $verified Whether the request proved the phone.
	 * @return array<string,mixed>
	 */
	private static function member( bool $verified ): array {
		return array(
			'id'            => self::MEMBER_ID,
			'phone'         => self::MEMBER_PHONE,
			'verified'      => $verified,
			'phone_country' => 'MY',
			'email'         => self::MEMBER_EMAIL,
			'first_name'    => 'Aisyah',
			'last_name'     => 'Rahman',
		);
	}

	/**
	 * Newly created customer.
	 *
	 * @param array<mixed> $body     Request body (phone, names, e-mail).
	 * @param bool         $verified Whether the request proved the phone.
	 * @return array<string,mixed>
	 */
	private static function new_customer( array $body, bool $verified ): array {
		$string = static fn ( string $field ): ?string => isset( $body[ $field ] ) && is_string( $body[ $field ] ) ? $body[ $field ] : null;

		return array(
			'id'            => self::NEW_CUSTOMER_ID,
			'phone'         => $string( 'phone' ) ?? '+60199999999',
			'verified'      => $verified,
			'phone_country' => $string( 'phone_country' ),
			'email'         => $string( 'email' ),
			'first_name'    => $string( 'first_name' ),
			'last_name'     => $string( 'last_name' ),
		);
	}

	/**
	 * POST customers body for a portal sign-in ticket.
	 *
	 * @param string $ticket Ticket.
	 * @return array<string,mixed>
	 */
	public static function ticket_body( string $ticket ): array {
		return array(
			'external_id'  => null,
			'verified_via' => 'sso_ticket',
			'sso_ticket'   => $ticket,
		);
	}

	/**
	 * Resolve a portal sign-in ticket.
	 *
	 * @param string $ticket Ticket.
	 * @param bool   $used   Whether the server saw this ticket before (tickets are single use).
	 * @return array{status:int,body:mixed}
	 */
	private static function redeem_ticket( string $ticket, bool $used ): array {
		if ( ! $used && 0 === strpos( $ticket, self::SSO_TICKET_MEMBER ) ) {
			return self::ok(
				array(
					'customer' => self::member( true ),
					'created'  => false,
				)
			);
		}

		if ( ! $used && ( 0 === strpos( $ticket, self::SSO_TICKET_NEW ) || 0 === strpos( $ticket, self::SSO_TICKET_NO_EMAIL ) ) ) {
			$has_email = 0 === strpos( $ticket, self::SSO_TICKET_NEW );

			return self::ok(
				array(
					'customer' => self::new_customer(
						array(
							'phone'         => '+60199999999',
							'phone_country' => 'MY',
							'email'         => $has_email ? 'portal.member@example.com' : null,
							'first_name'    => 'Portal',
							'last_name'     => 'Member',
						),
						true
					),
					'created'  => false,
				)
			);
		}

		return self::error( 410, 'ticket_invalid', 'This sign-in link has expired or was already used.' );
	}

	/**
	 * My Account summary of the member.
	 *
	 * @param int $now Unix time.
	 * @return array<string,mixed>
	 */
	private static function summary( int $now ): array {
		return array(
			'id'              => self::MEMBER_ID,
			'points'          => array(
				'balance'       => self::POINTS_AVAILABLE,
				'expiring_soon' => array(
					array(
						'points'     => 200,
						'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now + 20 * 86400 ),
					),
				),
			),
			'credits'         => array( 'balance_cents' => 1500 ),
			'tier'            => array(
				'name'     => 'Silver',
				'color'    => '#c0c0c0',
				'progress' => array(
					'current'        => 700,
					'next'           => 1000,
					'remaining'      => 300,
					'next_tier_name' => 'Gold',
				),
			),
			'vouchers'        => array(
				array(
					'id'              => self::VOUCHER_ID,
					'code'            => self::VOUCHER_CODE,
					'title'           => 'RM10 off',
					'description'     => 'Welcome voucher',
					'image_url'       => null,
					'expires_at'      => gmdate( 'Y-m-d\TH:i:s\Z', $now + 30 * 86400 ),
					'redeemable_here' => true,
				),
				array(
					'id'              => '90',
					'code'            => 'PXL-OUTLET-90',
					'title'           => 'Free pastry (in store)',
					'description'     => null,
					'image_url'       => null,
					'expires_at'      => null,
					'redeemable_here' => false,
				),
			),
			'memberships'     => array(
				array(
					'id'         => '7',
					'name'       => 'Coffee Club',
					'status'     => 'active',
					'expires_at' => null,
				),
			),
			'recent_activity' => array(
				array(
					'type'        => 'points_earned',
					'description' => 'Order #1001',
					'points'      => 120,
					'occurred_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now - 86400 ),
				),
			),
			'qr'              => array(
				'type'  => 'phone',
				'value' => self::MEMBER_PHONE,
			),
			'portal_url'      => 'https://fake-pixalink.test/loyalty',
		);
	}

	/**
	 * Summary of a brand-new customer (nothing earned yet).
	 *
	 * @return array<string,mixed>
	 */
	private static function empty_summary(): array {
		return array(
			'id'              => self::NEW_CUSTOMER_ID,
			'points'          => array(
				'balance'       => 0,
				'expiring_soon' => array(),
			),
			'credits'         => null,
			'tier'            => null,
			'vouchers'        => array(),
			'memberships'     => array(),
			'recent_activity' => array(),
			'qr'              => array(
				'type'  => 'phone',
				'value' => '+60199999999',
			),
			'portal_url'      => 'https://fake-pixalink.test/loyalty',
		);
	}

	/**
	 * Points the member holds right now (the fake balance control route can lower it between quote and hold).
	 *
	 * @param array<string,mixed> $context Context.
	 * @return int
	 */
	private static function points_balance( array $context ): int {
		return isset( $context['points_balance'] ) && is_int( $context['points_balance'] ) ? max( 0, $context['points_balance'] ) : self::POINTS_AVAILABLE;
	}

	/**
	 * A one-line `cart` object for the samples.
	 *
	 * @param int $cents Line total.
	 * @return array<string,mixed>
	 */
	private static function sample_cart( int $cents ): array {
		return array(
			'lines'              => array(
				array(
					'line_id'             => 'c4ca4238a0b923820dcc509a6f75849b',
					'sku'                 => 'LATTE-12',
					'external_product_id' => '21',
					'name'                => 'Latte 12oz',
					'quantity'            => 5,
					'unit_price_cents'    => intdiv( $cents, 5 ),
					'line_total_cents'    => $cents,
				),
			),
			'subtotal_cents'     => $cents,
			'currency'           => 'MYR',
			'shipping_cents'     => 0,
			'discount_cents'     => 0,
			'tax_cents'          => 0,
			'prices_include_tax' => false,
			'held_refs'          => array(),
		);
	}

	/**
	 * Quote body: points at 1 cent each (min 100, step 100), 25.00 credits and the {@see Responses::QUOTE_VOUCHERS},
	 * capped at what the cart can absorb (subtotal less the store's own discounts).
	 *
	 * @param array<mixed> $body    Request body.
	 * @param int          $balance Points balance.
	 * @return array<string,mixed>
	 */
	private static function quote( array $body, int $balance ): array {
		$cart         = isset( $body['cart'] ) && is_array( $body['cart'] ) ? $body['cart'] : array();
		$subtotal     = isset( $cart['subtotal_cents'] ) && is_int( $cart['subtotal_cents'] ) ? $cart['subtotal_cents'] : 0;
		$discount     = isset( $cart['discount_cents'] ) && is_int( $cart['discount_cents'] ) ? $cart['discount_cents'] : 0;
		$discountable = max( 0, $subtotal - $discount );
		$currency     = isset( $cart['currency'] ) && is_string( $cart['currency'] ) ? $cart['currency'] : 'MYR';

		$vouchers = array();
		foreach ( self::QUOTE_VOUCHERS as $id => $definition ) {
			$vouchers[] = self::quote_voucher( (string) $id, $definition, $discountable );
		}
		$vouchers[] = array(
			'id'                => self::INSTORE_VOUCHER_ID,
			'code'              => 'PXL-INSTORE-93',
			'title'             => 'Birthday cake',
			'description'       => 'Collect at the counter',
			'image_url'         => null,
			'expires_at'        => null,
			'redeemable_online' => false,
			'discount_cents'    => 0,
			'eligible'          => false,
			'ineligible_reason' => 'Only redeemable in store.',
			'woo_coupon'        => null,
		);

		$lines = array();
		foreach ( isset( $cart['lines'] ) && is_array( $cart['lines'] ) ? $cart['lines'] : array() as $line ) {
			if ( is_array( $line ) && isset( $line['line_id'] ) && is_string( $line['line_id'] ) ) {
				$lines[] = array(
					'line_id' => $line['line_id'],
					'class'   => 'regular',
				);
			}
		}

		return array(
			'currency'     => $currency,
			'vouchers'     => $vouchers,
			'points'       => array(
				'balance'         => $balance,
				'available'       => $balance,
				'value_per_point' => '0.01',
				'min'             => 100,
				'max_for_cart'    => intdiv( min( $balance, $discountable ), 100 ) * 100,
				'step'            => 100,
			),
			'credits'      => array(
				'balance_cents'      => self::CREDITS_BALANCE_CENTS,
				'max_for_cart_cents' => min( self::CREDITS_BALANCE_CENTS, $discountable ),
				'applies_as'         => 'discount',
			),
			'lines'        => $lines,
			'earn_preview' => array( 'points' => intdiv( $discountable, 100 ) ),
			'ttl_seconds'  => 300,
		);
	}

	/**
	 * One quote voucher.
	 *
	 * @param string            $id           Voucher id.
	 * @param array<int,mixed>  $definition   {@see Responses::QUOTE_VOUCHERS} entry.
	 * @param int               $discountable Cents the cart can absorb.
	 * @return array<string,mixed>
	 */
	private static function quote_voucher( string $id, array $definition, int $discountable ): array {
		list( $code, $title, $type, $amount, $skus, $limit, $minimum ) = $definition;

		$discount = 'fixed_cart' === $type ? min( $discountable, (int) round( (float) $amount * 100 ) ) : 0;

		return array(
			'id'                => $id,
			'code'              => $code,
			'title'             => $title,
			'description'       => null,
			'image_url'         => null,
			'expires_at'        => '2027-12-31T15:59:59Z',
			'redeemable_online' => true,
			'discount_cents'    => $discount,
			'eligible'          => true,
			'ineligible_reason' => null,
			'woo_coupon'        => array(
				'discount_type'          => $type,
				'amount'                 => $amount,
				'product_skus'           => $skus,
				'excluded_product_skus'  => array(),
				'category_slugs'         => array(),
				'minimum_amount'         => $minimum,
				'maximum_amount'         => null,
				'limit_usage_to_x_items' => $limit,
				'exclude_sale_items'     => false,
				'individual_use'         => false,
			),
		);
	}

	/**
	 * Declarative hold: points at most the balance (1 point = 1 cent), credits at most 25.00, only the quote's
	 * online vouchers; the value taken off (discount, plus its tax share in a tax-inclusive cart) may not exceed what
	 * the redemption is worth.
	 *
	 * @param string       $order_ref Order key.
	 * @param array<mixed> $body      Request body.
	 * @param int          $now       Unix time.
	 * @param int          $balance   Points balance.
	 * @return array{status:int,body:mixed}
	 */
	private static function hold( string $order_ref, array $body, int $now, int $balance ): array {
		$redemptions = isset( $body['redemptions'] ) && is_array( $body['redemptions'] ) ? $body['redemptions'] : array();
		$inclusive   = isset( $body['cart']['prices_include_tax'] ) && true === $body['cart']['prices_include_tax'];
		$held        = array();
		$expires_at  = gmdate( 'Y-m-d\TH:i:s\Z', $now + 168 * 3600 );

		foreach ( array_values( $redemptions ) as $index => $redemption ) {
			if ( ! is_array( $redemption ) ) {
				continue;
			}

			$kind  = isset( $redemption['kind'] ) && is_string( $redemption['kind'] ) ? $redemption['kind'] : 'voucher';
			$value = (int) ( $redemption['discount_cents'] ?? 0 ) + ( $inclusive ? (int) ( $redemption['discount_tax_cents'] ?? 0 ) : 0 );

			if ( 'points' === $kind ) {
				$points = (int) ( $redemption['points'] ?? 0 );
				if ( $points > $balance ) {
					return self::error( 409, 'points_insufficient', 'Not enough points.', array( 'available' => $balance ) );
				}
				if ( $value > $points ) {
					return self::error( 422, 'discount_mismatch', 'The discount is worth more than the points held for it.' );
				}
			}

			if ( 'credit' === $kind ) {
				$amount = (int) ( $redemption['amount_cents'] ?? 0 );
				if ( $amount > self::CREDITS_BALANCE_CENTS ) {
					return self::error( 409, 'credits_insufficient', 'Not enough credits.', array( 'available_cents' => self::CREDITS_BALANCE_CENTS ) );
				}
				if ( $value > $amount ) {
					return self::error( 422, 'discount_mismatch', 'The discount is worth more than the credits held for it.' );
				}
			}

			if ( 'voucher' === $kind && ! array_key_exists( (string) ( $redemption['voucher_id'] ?? '' ), self::QUOTE_VOUCHERS ) ) {
				return self::error( 409, 'voucher_unavailable', 'This voucher cannot be used.' );
			}

			$entry = array(
				'redemption_id'  => 'CH-FAKE' . strtoupper( substr( md5( $order_ref . '|' . $index . '|' . $kind ), 0, 10 ) ),
				'kind'           => $kind,
				'status'         => 'held',
				'expires_at'     => $expires_at,
				'coupon_code'    => (string) ( $redemption['coupon_code'] ?? $kind ),
				'discount_cents' => max( 0, $value ),
			);
			if ( isset( $redemption['voucher_id'] ) ) {
				$entry['voucher_id'] = (string) $redemption['voucher_id'];
			}
			if ( isset( $redemption['points'] ) ) {
				$entry['points'] = (int) $redemption['points'];
			}
			if ( isset( $redemption['amount_cents'] ) ) {
				$entry['amount_cents'] = (int) $redemption['amount_cents'];
			}
			$held[] = $entry;
		}

		return self::ok(
			array(
				'order_ref'   => $order_ref,
				'redemptions' => $held,
				'released'    => 0,
			)
		);
	}

	/**
	 * Event processing outcome.
	 *
	 * @param array<mixed> $body Envelope.
	 * @param bool         $seen Whether this event id was already processed.
	 * @return array{status:int,body:mixed}
	 */
	private static function event( array $body, bool $seen ): array {
		if ( $seen ) {
			return self::ok(
				array(
					'status' => 'duplicate',
					'result' => null,
				)
			);
		}

		$type = isset( $body['type'] ) && is_string( $body['type'] ) ? $body['type'] : '';
		switch ( $type ) {
			case 'ping':
				$result = array( 'pong' => true );
				break;
			case 'order.paid':
				$result = array(
					'pos_transaction_id' => '90001',
					'points_earned'      => 145,
					'earn_state'         => 'earned',
				);
				break;
			case 'order.refunded':
				$result = array(
					'restored' => array(
						'points'        => 0,
						'credits_cents' => 0,
						'vouchers'      => array(),
					),
				);
				break;
			case 'order.refund_deleted':
				return self::ok(
					array(
						'status' => 'ignored',
						'result' => array( 'reason' => 'refund_deleted_not_supported' ),
					)
				);
			default:
				$result = null;
		}

		return self::ok(
			array(
				'status' => 'processed',
				'result' => $result,
			)
		);
	}
}

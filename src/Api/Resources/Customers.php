<?php
/**
 * Customer endpoints of the Pixalink Commerce API.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Api\Resources;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Fields\ConsentRecord;
use Pixalink\Loyalty\Fields\PhoneNumber;
use Pixalink\Loyalty\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * `GET customers` (lookup), `POST customers` (resolve or create, or redeem a portal sign-in ticket),
 * `POST customers/{id}/otp`, `POST customers/{id}/otp/verify`, `GET customers/{id}` (My Account summary) and
 * `POST customers/{id}/portal-session` (a single-use link that opens the member portal signed in).
 *
 * The static builders are the single source of the request bodies and are validated against
 * `tests/contract/schemas/*.json` by the contract suite.
 */
final class Customers {

	public const PATH = 'customers';

	public const VERIFIED_VIA_NONE       = 'none';
	public const VERIFIED_VIA_OTP        = 'otp';
	public const VERIFIED_VIA_SSO_TICKET = 'sso_ticket';

	/**
	 * Portal pages a portal session can open (`home` when the page is unknown).
	 */
	public const PORTAL_DESTINATIONS = array( 'home', 'rewards', 'points', 'credits' );

	public const PORTAL_HOME = 'home';

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private Client $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client API client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Query for GET customers by phone.
	 *
	 * @param string $phone_e164 E.164 number.
	 * @return array{phone:string}
	 */
	public static function lookup_query( string $phone_e164 ): array {
		return array( 'phone' => $phone_e164 );
	}

	/**
	 * Body for POST customers (resolve or create; the phone is not proven yet).
	 *
	 * @param int                $wp_user_id WordPress user id (0 for guests).
	 * @param PhoneNumber        $phone      Phone number.
	 * @param string|null        $email      E-mail address.
	 * @param string|null        $first_name First name.
	 * @param string|null        $last_name  Last name.
	 * @param ConsentRecord|null $consent    Recorded consent (sent only when reportable).
	 * @return array<string,mixed>
	 */
	public static function create_payload( int $wp_user_id, PhoneNumber $phone, ?string $email, ?string $first_name, ?string $last_name, ?ConsentRecord $consent ): array {
		return array(
			'external_id'       => self::external_id( $wp_user_id ),
			'phone'             => $phone->e164,
			'phone_country'     => $phone->country,
			'email'             => self::email( $email ),
			'first_name'        => self::name( $first_name ),
			'last_name'         => self::name( $last_name ),
			'marketing_consent' => $consent?->to_reportable_array(),
			'verified_via'      => self::VERIFIED_VIA_NONE,
		);
	}

	/**
	 * Body for POST customers that redeems a Pixalink portal sign-in ticket.
	 *
	 * @param string $ticket     The `pixalink_sso` query parameter.
	 * @param int    $wp_user_id Logged-in WordPress user id (0 when nobody is logged in).
	 * @return array{external_id:string|null,verified_via:string,sso_ticket:string}
	 */
	public static function ticket_payload( string $ticket, int $wp_user_id ): array {
		return array(
			'external_id'  => self::external_id( $wp_user_id ),
			'verified_via' => self::VERIFIED_VIA_SSO_TICKET,
			'sso_ticket'   => $ticket,
		);
	}

	/**
	 * Body for POST customers/{id}/otp (the server picks WhatsApp or SMS).
	 *
	 * @return array{channel:null}
	 */
	public static function otp_payload(): array {
		return array( 'channel' => null );
	}

	/**
	 * Body for POST customers/{id}/otp/verify.
	 *
	 * @param string $code Code the shopper typed.
	 * @return array{code:string}
	 */
	public static function verify_payload( string $code ): array {
		return array( 'code' => trim( $code ) );
	}

	/**
	 * Path of GET customers/{id}.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @return string
	 */
	public static function summary_path( string $customer_id ): string {
		return self::PATH . '/' . rawurlencode( $customer_id );
	}

	/**
	 * Path of POST customers/{id}/otp.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @return string
	 */
	public static function otp_path( string $customer_id ): string {
		return self::summary_path( $customer_id ) . '/otp';
	}

	/**
	 * Path of POST customers/{id}/otp/verify.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @return string
	 */
	public static function verify_path( string $customer_id ): string {
		return self::otp_path( $customer_id ) . '/verify';
	}

	/**
	 * Path of POST customers/{id}/portal-session.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @return string
	 */
	public static function portal_session_path( string $customer_id ): string {
		return self::summary_path( $customer_id ) . '/portal-session';
	}

	/**
	 * Body for POST customers/{id}/portal-session.
	 *
	 * @param int    $wp_user_id  The signed-in WordPress user (the store account Pixalink holds for the member).
	 * @param string $destination One of {@see Customers::PORTAL_DESTINATIONS}; anything else opens the portal home.
	 * @return array{wp_user_id:int,destination:string}
	 * @throws \InvalidArgumentException When there is no user.
	 */
	public static function portal_session_payload( int $wp_user_id, string $destination ): array {
		if ( $wp_user_id < 1 ) {
			throw new \InvalidArgumentException( 'A portal session needs the signed-in WordPress user.' );
		}

		return array(
			'wp_user_id'  => $wp_user_id,
			'destination' => in_array( $destination, self::PORTAL_DESTINATIONS, true ) ? $destination : self::PORTAL_HOME,
		);
	}

	/**
	 * The `{url, expires_at}` of a 201 portal-session answer (the caller checks the URL's scheme and host).
	 *
	 * @param array<mixed> $body Decoded body.
	 * @return array{url:string,expires_at:string}
	 * @throws ApiException When the answer has no usable link.
	 */
	public static function portal_session_response( array $body ): array {
		$url        = isset( $body['url'] ) && is_string( $body['url'] ) ? trim( $body['url'] ) : '';
		$expires_at = isset( $body['expires_at'] ) && is_string( $body['expires_at'] ) ? trim( $body['expires_at'] ) : '';
		if ( '' === $url || '' === $expires_at ) {
			$exception = ApiException::invalid_response( 201 );
			throw $exception;
		}

		return array(
			'url'        => $url,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Mint a single-use link that opens the member portal signed in (works once, for 60 seconds).
	 *
	 * Each call carries a fresh Idempotency-Key: a second click is a new ticket, never a replay of the first.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @param int    $wp_user_id  Signed-in WordPress user.
	 * @param string $destination Portal page.
	 * @return array{url:string,expires_at:string}
	 * @throws ApiException On failure (403 verification_required, 404 not_found, 429 rate_limited).
	 */
	public function portal_session( string $customer_id, int $wp_user_id, string $destination ): array {
		$response = $this->client->post(
			self::portal_session_path( $customer_id ),
			self::portal_session_payload( $wp_user_id, $destination ),
			array( 'idempotency_key' => Uuid::v4() )
		);

		return self::portal_session_response( $response->data );
	}

	/**
	 * Look a customer up by phone number.
	 *
	 * @param string $phone_e164 E.164 number.
	 * @return array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null}|null Null when there is no such member (404).
	 * @throws ApiException On other failures.
	 */
	public function find_by_phone( string $phone_e164 ): ?array {
		try {
			$data = $this->client->get( self::PATH, self::lookup_query( $phone_e164 ) )->data;
		} catch ( ApiException $exception ) {
			if ( 404 === $exception->http_status() ) {
				return null;
			}
			throw $exception;
		}

		return self::customer( $data );
	}

	/**
	 * Resolve or create a customer, or redeem a ticket.
	 *
	 * @param array<string,mixed> $payload Body from {@see Customers::create_payload()} or {@see Customers::ticket_payload()}.
	 * @return array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null}
	 * @throws ApiException On failure (410 ticket_invalid for a spent or foreign ticket).
	 */
	public function create( array $payload ): array {
		return self::customer( $this->client->post( self::PATH, $payload )->data );
	}

	/**
	 * Send a one-time code to the customer's phone.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @return array{sent_via:string,retry_after_seconds:int}
	 * @throws ApiException On failure (429 otp_throttled with Retry-After).
	 */
	public function send_otp( string $customer_id ): array {
		$data = $this->client->post( self::otp_path( $customer_id ), self::otp_payload() )->data;

		$sent_via = isset( $data['sent_via'] ) && is_string( $data['sent_via'] ) ? $data['sent_via'] : '';
		if ( ! in_array( $sent_via, array( 'whatsapp', 'sms' ), true ) ) {
			$exception = ApiException::invalid_response( 200 );
			throw $exception;
		}

		return array(
			'sent_via'            => $sent_via,
			'retry_after_seconds' => isset( $data['retry_after_seconds'] ) && is_int( $data['retry_after_seconds'] ) ? max( 0, $data['retry_after_seconds'] ) : 60,
		);
	}

	/**
	 * Check a one-time code.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @param string $code        Code.
	 * @return void
	 * @throws ApiException On failure (422 otp_invalid, otp_expired or otp_attempts_exceeded).
	 */
	public function verify_otp( string $customer_id, string $code ): void {
		$data = $this->client->post( self::verify_path( $customer_id ), self::verify_payload( $code ) )->data;

		if ( true !== ( $data['verified'] ?? null ) ) {
			$exception = ApiException::invalid_response( 200 );
			throw $exception;
		}
	}

	/**
	 * My Account summary of a verified customer.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @return array<mixed>
	 * @throws ApiException On failure (403 verification_required, 404).
	 */
	public function summary( string $customer_id ): array {
		return $this->client->get( self::summary_path( $customer_id ) )->data;
	}

	/**
	 * The `customer` object of a response.
	 *
	 * @param array<mixed> $body Decoded body.
	 * @return array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null}
	 * @throws ApiException When the body has no usable customer.
	 */
	private static function customer( array $body ): array {
		$customer = isset( $body['customer'] ) && is_array( $body['customer'] ) ? $body['customer'] : array();
		$id       = isset( $customer['id'] ) && is_scalar( $customer['id'] ) ? (string) $customer['id'] : '';
		$phone    = isset( $customer['phone'] ) && is_string( $customer['phone'] ) ? $customer['phone'] : '';

		if ( '' === $id || 1 !== preg_match( '/^\+[1-9][0-9]{6,14}$/', $phone ) ) {
			$exception = ApiException::invalid_response( 200 );
			throw $exception;
		}

		$string = static fn ( string $key ): ?string => isset( $customer[ $key ] ) && is_string( $customer[ $key ] ) && '' !== trim( $customer[ $key ] ) ? trim( $customer[ $key ] ) : null;

		return array(
			'id'            => $id,
			'phone'         => $phone,
			'verified'      => true === ( $customer['verified'] ?? null ),
			'phone_country' => $string( 'phone_country' ),
			'email'         => self::email( $string( 'email' ) ),
			'first_name'    => $string( 'first_name' ),
			'last_name'     => $string( 'last_name' ),
		);
	}

	/**
	 * WordPress user id as the contract's external_id.
	 *
	 * @param int $wp_user_id User id.
	 * @return string|null
	 */
	private static function external_id( int $wp_user_id ): ?string {
		return $wp_user_id > 0 ? (string) $wp_user_id : null;
	}

	/**
	 * A valid e-mail address or null.
	 *
	 * @param string|null $email Address.
	 * @return string|null
	 */
	private static function email( ?string $email ): ?string {
		$email = trim( (string) $email );

		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : null;
	}

	/**
	 * A trimmed name of at most 191 characters, or null.
	 *
	 * @param string|null $name Name.
	 * @return string|null
	 */
	private static function name( ?string $name ): ?string {
		$name = trim( (string) $name );

		return '' === $name ? null : mb_substr( $name, 0, 191 );
	}
}

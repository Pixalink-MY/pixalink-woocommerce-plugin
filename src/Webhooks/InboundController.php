<?php
/**
 * Signed notifications from Pixalink.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Webhooks;

use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Signer;
use Pixalink\Loyalty\Fields\ConsentRecord;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /wp-json/pixalink-loyalty/v1/webhook` (webhook.request.json).
 *
 * Authenticated by signature only: `X-Pixalink-Signature: v1=hmac_sha256("{timestamp}.{event id}.{raw body}")` with the
 * signing key derived from the outlet API key ({@see Signer}), the timestamp within ±300 s. Event ids processed in the
 * last 7 days are answered `duplicate` without acting again.
 *
 * - `customer.updated`: bust the cached summary of every account linked to the customer; `marketing_status`
 *   `blocked` or `do_not_contact` records a withdrawal (source `pixalink`, never sent back).
 * - `voucher.issued`: bust the cached summary.
 */
final class InboundController {

	public const ROUTE_NAMESPACE = 'pixalink-loyalty/v1';
	public const ROUTE           = '/webhook';

	public const SEEN_PREFIX = 'pixalink_loyalty_webhook_';

	/**
	 * How long processed event ids are remembered (seconds).
	 */
	public const REPLAY_TTL = 7 * 86400;

	public const STATUS_PROCESSED = 'processed';
	public const STATUS_DUPLICATE = 'duplicate';
	public const STATUS_IGNORED   = 'ignored';

	/**
	 * Outlet credentials (signing key).
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Summary cache.
	 *
	 * @var SummaryService
	 */
	private SummaryService $summaries;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Current unix time.
	 *
	 * @var \Closure():int
	 */
	private \Closure $now;

	/**
	 * Constructor.
	 *
	 * @param Credentials    $credentials Outlet credentials.
	 * @param SummaryService $summaries   Summary cache.
	 * @param Logger         $logger      Logger.
	 * @param \Closure|null  $now         Unix time source (tests); defaults to time().
	 */
	public function __construct( Credentials $credentials, SummaryService $summaries, Logger $logger, ?\Closure $now = null ) {
		$this->credentials = $credentials;
		$this->summaries   = $summaries;
		$this->logger      = $logger;
		$this->now         = $now ?? static fn (): int => time();
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * `rest_api_init` callback.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Public by design: the HMAC signature is the credential and is checked before anything is read.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Route callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$signing_key = $this->credentials->signing_key();
		if ( null === $signing_key ) {
			return self::error( 'not_connected', 'This store is not connected to Pixalink.', 503 );
		}

		$raw_body = $request->get_body();
		$headers  = array(
			'timestamp' => (string) $request->get_header( 'x-pixalink-timestamp' ),
			'event_id'  => (string) $request->get_header( 'x-pixalink-event-id' ),
			'signature' => (string) $request->get_header( 'x-pixalink-signature' ),
		);

		$now     = ( $this->now )();
		$failure = self::verify_signature( $headers, $raw_body, $signing_key, $now );
		if ( null !== $failure ) {
			$this->logger->warning( 'Rejected a Pixalink webhook.', array( 'reason' => $failure ) );

			return self::error( $failure, 'The webhook signature could not be verified.', 401, 'timestamp_skew' === $failure ? array( 'server_time' => $now ) : array() );
		}

		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) || ( $payload['id'] ?? null ) !== $headers['event_id'] || ! isset( $payload['type'] ) || ! is_string( $payload['type'] ) ) {
			return self::error( 'invalid_payload', 'The webhook body is not a valid notification.', 400 );
		}

		$seen_key = self::SEEN_PREFIX . md5( $headers['event_id'] );
		if ( false !== get_transient( $seen_key ) ) {
			return self::response( self::STATUS_DUPLICATE );
		}

		$data   = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();
		$status = $this->dispatch( $payload['type'], $data );

		set_transient( $seen_key, 1, self::REPLAY_TTL );

		return self::response( $status );
	}

	/**
	 * Check the signature headers of a webhook.
	 *
	 * @param array{timestamp:string,event_id:string,signature:string} $headers     Header values.
	 * @param string                                                   $raw_body    Raw body exactly as received.
	 * @param string                                                   $signing_key Derived signing key.
	 * @param int                                                      $now         Current unix time.
	 * @return string|null Null when valid; otherwise `signature_invalid` or `timestamp_skew`.
	 */
	public static function verify_signature( array $headers, string $raw_body, string $signing_key, int $now ): ?string {
		$timestamp = trim( $headers['timestamp'] );
		$event_id  = trim( $headers['event_id'] );
		$signature = trim( $headers['signature'] );

		if ( '' === $timestamp || ! ctype_digit( $timestamp ) || ! Signer::is_valid_nonce( $event_id ) || 0 !== strpos( $signature, Signer::SCHEME . '=' ) ) {
			return 'signature_invalid';
		}

		if ( abs( $now - (int) $timestamp ) > Signer::WINDOW_SECONDS ) {
			return 'timestamp_skew';
		}

		return Signer::verify( $signing_key, $signature, (int) $timestamp, $event_id, $raw_body, $now ) ? null : 'signature_invalid';
	}

	/**
	 * Act on one notification.
	 *
	 * @param string       $type Type.
	 * @param array<mixed> $data Data.
	 * @return string One of the STATUS_* constants.
	 */
	private function dispatch( string $type, array $data ): string {
		if ( ! in_array( $type, array( 'customer.updated', 'voucher.issued' ), true ) ) {
			return self::STATUS_IGNORED;
		}

		$customer_id = isset( $data['customer_id'] ) && is_scalar( $data['customer_id'] ) ? (string) $data['customer_id'] : '';
		$user_ids    = '' === $customer_id ? array() : self::linked_users( $customer_id );
		if ( array() === $user_ids ) {
			return self::STATUS_IGNORED;
		}

		$withdraw = 'customer.updated' === $type && in_array( $data['marketing_status'] ?? null, array( 'blocked', 'do_not_contact' ), true );
		foreach ( $user_ids as $user_id ) {
			if ( $withdraw ) {
				UserMeta::set_consent( $user_id, ConsentRecord::given( false, ConsentRecord::SOURCE_PIXALINK, '', ( $this->now )() ) );
			}
			$this->summaries->bust( $user_id );
		}

		/**
		 * Fires after a verified Pixalink webhook was applied.
		 *
		 * @since 0.1.0
		 *
		 * @param string       $type     Webhook type.
		 * @param array<mixed> $data     Webhook data.
		 * @param list<int>    $user_ids Accounts linked to the customer.
		 */
		do_action( 'pixalink_loyalty_webhook_processed', $type, $data, $user_ids );

		return self::STATUS_PROCESSED;
	}

	/**
	 * Accounts with a verified link to a customer.
	 *
	 * @param string $customer_id Pixalink customer id.
	 * @return list<int>
	 */
	private static function linked_users( string $customer_id ): array {
		$ids = get_users(
			array(
				'meta_key'   => UserMeta::CUSTOMER_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta_key lookup, run only for verified webhooks.
				'meta_value' => $customer_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'fields'     => 'ID',
				'number'     => 20,
			)
		);

		$linked = array();
		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) && UserMeta::is_verified( (int) $id ) ) {
				$linked[] = (int) $id;
			}
		}

		return $linked;
	}

	/**
	 * 200 answer.
	 *
	 * @param string $status Status.
	 * @return \WP_REST_Response
	 */
	private static function response( string $status ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'status' => $status ), 200 );
	}

	/**
	 * Error answer in the Pixalink envelope.
	 *
	 * @param string              $code    Machine code.
	 * @param string              $message Message.
	 * @param int                 $status  HTTP status.
	 * @param array<string,mixed> $extra   Extra fields.
	 * @return \WP_REST_Response
	 */
	private static function error( string $code, string $message, int $status, array $extra = array() ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'message' => $message,
				'code'    => $code,
			) + $extra,
			$status
		);
	}
}

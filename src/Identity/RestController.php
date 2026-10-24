<?php
/**
 * REST routes of the phone verification dialog.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Fields\InvalidPhoneNumber;
use Pixalink\Loyalty\Fields\PhoneNormalizer;
use Pixalink\Loyalty\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * `pixalink-loyalty/v1/link`, `otp/send`, `otp/verify` and `summary` for logged-in shoppers.
 *
 * Every route requires a logged-in user and the `wp_rest` nonce (`X-WP-Nonce`), and is rate limited per user:
 * link 5 / 15 min, otp/send 5 / 15 min, otp/verify 10 / 15 min, summary 30 / min. Failures answer
 * `{code, message, data: {status, retry_after?}}` with a `Retry-After` header on 429.
 */
final class RestController {

	public const ROUTE_NAMESPACE = 'pixalink-loyalty/v1';

	/**
	 * Limits per route: [hits, window seconds].
	 */
	private const LIMITS = array(
		'link'       => array( 5, 900 ),
		'otp_send'   => array( 5, 900 ),
		'otp_verify' => array( 10, 900 ),
		'summary'    => array( 30, 60 ),
	);

	/**
	 * Linker.
	 *
	 * @var CustomerLinker
	 */
	private CustomerLinker $linker;

	/**
	 * Summaries.
	 *
	 * @var SummaryService
	 */
	private SummaryService $summaries;

	/**
	 * Phone normaliser.
	 *
	 * @var PhoneNormalizer
	 */
	private PhoneNormalizer $normalizer;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Guest links.
	 *
	 * @var GuestLinkStore
	 */
	private GuestLinkStore $guests;

	/**
	 * Constructor.
	 *
	 * @param CustomerLinker  $linker     Linker.
	 * @param SummaryService  $summaries  Summaries.
	 * @param PhoneNormalizer $normalizer Phone normaliser.
	 * @param RateLimiter     $limiter    Rate limiter.
	 * @param GuestLinkStore  $guests     Guest links.
	 */
	public function __construct( CustomerLinker $linker, SummaryService $summaries, PhoneNormalizer $normalizer, RateLimiter $limiter, GuestLinkStore $guests ) {
		$this->linker     = $linker;
		$this->summaries  = $summaries;
		$this->normalizer = $normalizer;
		$this->limiter    = $limiter;
		$this->guests     = $guests;
	}

	/**
	 * Register hooks.
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
		$permission = array( $this, 'permission' );

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/link',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'link' ),
				'permission_callback' => $permission,
				'args'                => array(
					'phone_country' => array(
						'type'     => 'string',
						'required' => true,
					),
					'phone'         => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/otp/send',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/otp/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => $permission,
				'args'                => array(
					'code' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/summary',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Permission callback: a logged-in user with a valid `wp_rest` nonce.
	 *
	 * Cookie authentication already demotes a request without a valid nonce to user 0; the explicit check keeps the
	 * routes closed even where another plugin authenticates REST requests differently.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}
		if ( ! is_user_logged_in() || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'pixalink_loyalty_forbidden', __( 'Please log in again.', 'pixalink-loyalty-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * POST link `{phone_country, phone}`: find or create the customer and send the first code.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function link( \WP_REST_Request $request ) {
		$limited = $this->limit( 'link' );
		if ( null !== $limited ) {
			return $limited;
		}

		try {
			$phone = $this->normalizer->parse( (string) $request->get_param( 'phone' ), (string) $request->get_param( 'phone_country' ) );
		} catch ( InvalidPhoneNumber $exception ) {
			return new \WP_Error( 'pixalink_loyalty_invalid_phone', $exception->user_message(), array( 'status' => 400 ) );
		}

		$user = wp_get_current_user();
		try {
			$sent = $this->linker->start(
				$this->shopper(),
				$phone,
				array(
					'email'      => $user->user_email,
					'first_name' => (string) get_user_meta( $user->ID, 'first_name', true ),
					'last_name'  => (string) get_user_meta( $user->ID, 'last_name', true ),
					'consent'    => UserMeta::consent( $user->ID ),
				)
			);
		} catch ( LinkException $exception ) {
			return self::failure( $exception );
		}

		return new \WP_REST_Response( array( 'status' => 'code_sent' ) + $sent, 200 );
	}

	/**
	 * POST otp/send: send another code.
	 *
	 * @return \WP_REST_Response
	 */
	public function send(): \WP_REST_Response {
		$limited = $this->limit( 'otp_send' );
		if ( null !== $limited ) {
			return $limited;
		}

		try {
			$sent = $this->linker->resend( $this->shopper() );
		} catch ( LinkException $exception ) {
			return self::failure( $exception );
		}

		return new \WP_REST_Response( array( 'status' => 'code_sent' ) + $sent, 200 );
	}

	/**
	 * POST otp/verify `{code}`.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function verify( \WP_REST_Request $request ): \WP_REST_Response {
		$limited = $this->limit( 'otp_verify' );
		if ( null !== $limited ) {
			return $limited;
		}

		try {
			$this->linker->verify( $this->shopper(), (string) $request->get_param( 'code' ) );
		} catch ( LinkException $exception ) {
			return self::failure( $exception );
		}

		return new \WP_REST_Response( array( 'status' => 'verified' ), 200 );
	}

	/**
	 * GET summary: the member's normalised summary (403 until verified).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function summary() {
		$limited = $this->limit( 'summary' );
		if ( null !== $limited ) {
			return $limited;
		}

		$result = $this->summaries->for_user( get_current_user_id() );
		if ( SummaryService::UNVERIFIED === $result['state'] ) {
			return new \WP_Error( 'pixalink_loyalty_verification_required', __( 'Verify your phone number to see your points.', 'pixalink-loyalty-for-woocommerce' ), array( 'status' => 403 ) );
		}
		if ( SummaryService::UNAVAILABLE === $result['state'] ) {
			return new \WP_Error( 'pixalink_loyalty_unavailable', __( 'Loyalty is temporarily unavailable. Please try again in a few minutes.', 'pixalink-loyalty-for-woocommerce' ), array( 'status' => 503 ) );
		}

		return new \WP_REST_Response( array( 'summary' => $result['summary'] ), 200 );
	}

	/**
	 * Count a hit; a 429 error when over the limit.
	 *
	 * @param string $route Route key in LIMITS.
	 * @return \WP_REST_Response|null
	 */
	private function limit( string $route ): ?\WP_REST_Response {
		[ $hits, $window ] = self::LIMITS[ $route ];

		$retry_after = $this->limiter->hit( $route, 'user:' . get_current_user_id(), $hits, $window );
		if ( null === $retry_after ) {
			return null;
		}

		return self::failure( new LinkException( LinkException::THROTTLED, $retry_after ) );
	}

	/**
	 * The current shopper.
	 *
	 * @return ShopperContext
	 */
	private function shopper(): ShopperContext {
		return ShopperContext::current( $this->guests );
	}

	/**
	 * A failure as a REST error body `{code, message, data: {status, retry_after?}}`; 429s carry Retry-After.
	 *
	 * @param LinkException $exception Failure.
	 * @return \WP_REST_Response
	 */
	private static function failure( LinkException $exception ): \WP_REST_Response {
		$data = array( 'status' => $exception->http_status() );
		if ( null !== $exception->retry_after() ) {
			$data['retry_after'] = $exception->retry_after();
		}

		$response = new \WP_REST_Response(
			array(
				'code'    => 'pixalink_loyalty_' . $exception->reason(),
				'message' => $exception->user_message(),
				'data'    => $data,
			),
			$exception->http_status()
		);
		if ( 429 === $exception->http_status() && null !== $exception->retry_after() ) {
			$response->header( 'Retry-After', (string) $exception->retry_after() );
		}

		return $response;
	}
}

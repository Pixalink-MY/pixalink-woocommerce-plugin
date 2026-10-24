<?php
/**
 * Links shoppers to Pixalink customers.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Api\ApiException;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Fields\ConsentRecord;
use Pixalink\Loyalty\Fields\PhoneNumber;
use Pixalink\Loyalty\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Phone link with a one-time code, and portal sign-in tickets.
 *
 * 1. {@see CustomerLinker::start()}: `GET customers?phone=` finds the member; when there is none, `POST customers`
 *    creates one (verified_via `none`). `POST customers/{id}/otp` sends a code. The customer id waits in
 *    {@see PendingLinks}.
 * 2. {@see CustomerLinker::verify()}: `POST customers/{id}/otp/verify`; on success the link becomes durable user meta
 *    (logged in) or a guest link for the WooCommerce session, and `pixalink_loyalty_shopper_verified` fires.
 *
 * Linking always needs a proof: an OTP here, or a ticket the Pixalink portal minted ({@see CustomerLinker::redeem_ticket()}).
 */
final class CustomerLinker {

	/**
	 * Format of `pixalink_sso` tickets (as the contract's common.json `sso_ticket`).
	 */
	public const TICKET_PATTERN = '/^[A-Za-z0-9_-]{16,255}$/';

	/**
	 * Customer endpoints.
	 *
	 * @var Customers
	 */
	private Customers $customers;

	/**
	 * Pending links.
	 *
	 * @var PendingLinks
	 */
	private PendingLinks $pending;

	/**
	 * Guest links.
	 *
	 * @var GuestLinkStore
	 */
	private GuestLinkStore $guests;

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
	 * @param Customers      $customers Customer endpoints.
	 * @param PendingLinks   $pending   Pending links.
	 * @param GuestLinkStore $guests    Guest links.
	 * @param Logger         $logger    Logger.
	 * @param \Closure|null  $now       Unix time source (tests); defaults to time().
	 */
	public function __construct( Customers $customers, PendingLinks $pending, GuestLinkStore $guests, Logger $logger, ?\Closure $now = null ) {
		$this->customers = $customers;
		$this->pending   = $pending;
		$this->guests    = $guests;
		$this->logger    = $logger;
		$this->now       = $now ?? static fn (): int => time();
	}

	/**
	 * Find or create the customer for a phone number and send the first code.
	 *
	 * @param ShopperContext      $shopper Shopper.
	 * @param PhoneNumber         $phone   Phone number to verify.
	 * @param array<string,mixed> $profile `{email?, first_name?, last_name?, consent?: ConsentRecord}` sent when the customer is created.
	 * @return array{sent_via:string,retry_after_seconds:int,phone_masked:string}
	 * @throws LinkException On failure.
	 */
	public function start( ShopperContext $shopper, PhoneNumber $phone, array $profile ): array {
		try {
			$customer = $this->customers->find_by_phone( $phone->e164 );
			if ( null === $customer ) {
				$string   = static fn ( string $key ): ?string => isset( $profile[ $key ] ) && is_string( $profile[ $key ] ) ? $profile[ $key ] : null;
				$consent  = isset( $profile['consent'] ) && $profile['consent'] instanceof ConsentRecord ? $profile['consent'] : null;
				$customer = $this->customers->create( Customers::create_payload( $shopper->user_id(), $phone, $string( 'email' ), $string( 'first_name' ), $string( 'last_name' ), $consent ) );
			}

			$sent = $this->customers->send_otp( $customer['id'] );
		} catch ( ApiException $exception ) {
			$failure = $this->failure( $exception, 'start' );
			throw $failure;
		}

		$this->pending->put(
			$shopper->subject(),
			array(
				'customer_id'   => $customer['id'],
				'phone_e164'    => $phone->e164,
				'phone_country' => $phone->country,
				'sent_via'      => $sent['sent_via'],
				'resend_at'     => ( $this->now )() + $sent['retry_after_seconds'],
			)
		);

		return $sent + array( 'phone_masked' => Logger::mask_phone( $phone->e164 ) );
	}

	/**
	 * Send another code for the pending link.
	 *
	 * @param ShopperContext $shopper Shopper.
	 * @return array{sent_via:string,retry_after_seconds:int,phone_masked:string}
	 * @throws LinkException On failure, or when it is too soon to ask again.
	 */
	public function resend( ShopperContext $shopper ): array {
		$pending = $this->pending_or_fail( $shopper );
		$wait    = $pending['resend_at'] - ( $this->now )();
		if ( $wait > 0 ) {
			$failure = new LinkException( LinkException::RESEND_TOO_SOON, $wait );
			throw $failure;
		}

		try {
			$sent = $this->customers->send_otp( $pending['customer_id'] );
		} catch ( ApiException $exception ) {
			$failure = $this->failure( $exception, 'resend' );
			throw $failure;
		}

		$pending['sent_via']  = $sent['sent_via'];
		$pending['resend_at'] = ( $this->now )() + $sent['retry_after_seconds'];
		$this->pending->put( $shopper->subject(), $pending );

		return $sent + array( 'phone_masked' => Logger::mask_phone( $pending['phone_e164'] ) );
	}

	/**
	 * Check the code and store the verified link.
	 *
	 * @param ShopperContext $shopper Shopper.
	 * @param string         $code    Code the shopper typed.
	 * @return Link
	 * @throws LinkException On failure.
	 */
	public function verify( ShopperContext $shopper, string $code ): Link {
		$pending = $this->pending_or_fail( $shopper );
		$code    = trim( $code );
		if ( 1 !== preg_match( '/^[0-9]{4,8}$/', $code ) ) {
			$failure = new LinkException( LinkException::OTP_INVALID );
			throw $failure;
		}

		try {
			$this->customers->verify_otp( $pending['customer_id'], $code );
		} catch ( ApiException $exception ) {
			$failure = $this->failure( $exception, 'verify' );
			throw $failure;
		}

		$link = new Link( $pending['customer_id'], $pending['phone_e164'], $pending['phone_country'], gmdate( 'Y-m-d\TH:i:s\Z', ( $this->now )() ), Link::VIA_OTP );
		$this->store( $shopper, $link );
		$this->pending->forget( $shopper->subject() );

		return $link;
	}

	/**
	 * Store a verified link for the shopper and announce it.
	 *
	 * @param ShopperContext $shopper Shopper.
	 * @param Link           $link    Verified link.
	 * @return void
	 */
	public function store( ShopperContext $shopper, Link $link ): void {
		if ( $shopper->is_logged_in() ) {
			UserMeta::store_link( $shopper->user_id(), $link );
		} else {
			$this->guests->put( $shopper->session_id(), $link );
		}

		/**
		 * Fires when a shopper proved their phone number (OTP or portal sign-in).
		 *
		 * @since 0.1.0
		 *
		 * @param Link   $link       The verified link.
		 * @param int    $user_id    WordPress user id (0 for a guest).
		 * @param string $session_id WooCommerce session customer id ('' when none).
		 */
		do_action( 'pixalink_loyalty_shopper_verified', $link, $shopper->user_id(), $shopper->session_id() );
	}

	/**
	 * Redeem a Pixalink portal sign-in ticket (single use: any ticket presented is used up).
	 *
	 * @param string $ticket     The `pixalink_sso` value.
	 * @param int    $wp_user_id Logged-in user id (0 when nobody is logged in).
	 * @return array{id:string,phone:string,verified:bool,phone_country:string|null,email:string|null,first_name:string|null,last_name:string|null}
	 * @throws LinkException When the ticket is malformed, spent, foreign or did not prove the phone.
	 */
	public function redeem_ticket( string $ticket, int $wp_user_id ): array {
		if ( 1 !== preg_match( self::TICKET_PATTERN, $ticket ) ) {
			$failure = new LinkException( LinkException::TICKET_INVALID );
			throw $failure;
		}

		try {
			$customer = $this->customers->create( Customers::ticket_payload( $ticket, $wp_user_id ) );
		} catch ( ApiException $exception ) {
			$failure = $this->failure( $exception, 'ticket' );
			throw $failure;
		}

		if ( ! $customer['verified'] ) {
			$failure = new LinkException( LinkException::TICKET_INVALID );
			throw $failure;
		}

		return $customer;
	}

	/**
	 * The pending link of a shopper.
	 *
	 * @param ShopperContext $shopper Shopper.
	 * @return array{customer_id:string,phone_e164:string,phone_country:string,sent_via:string,resend_at:int}|null
	 */
	public function pending( ShopperContext $shopper ): ?array {
		return $this->pending->get( $shopper->subject() );
	}

	/**
	 * The pending link, or a failure.
	 *
	 * @param ShopperContext $shopper Shopper.
	 * @return array{customer_id:string,phone_e164:string,phone_country:string,sent_via:string,resend_at:int}
	 * @throws LinkException When nothing is pending.
	 */
	private function pending_or_fail( ShopperContext $shopper ): array {
		$pending = $this->pending->get( $shopper->subject() );
		if ( null === $pending ) {
			$failure = new LinkException( LinkException::NO_PENDING_LINK );
			throw $failure;
		}

		return $pending;
	}

	/**
	 * Log an API failure and translate it.
	 *
	 * @param ApiException $exception API failure.
	 * @param string       $step      Step name for the log.
	 * @return LinkException
	 */
	private function failure( ApiException $exception, string $step ): LinkException {
		$link_exception = LinkException::from_api( $exception );
		$level          = LinkException::UNAVAILABLE === $link_exception->reason() ? 'warning' : 'info';
		$this->logger->log(
			$level,
			'Pixalink link step failed.',
			array(
				'step'        => $step,
				'error_code'  => $exception->error_code(),
				'http_status' => $exception->http_status(),
			)
		);

		return $link_exception;
	}
}

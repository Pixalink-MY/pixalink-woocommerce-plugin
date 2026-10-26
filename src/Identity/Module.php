<?php
/**
 * Composition root of shopper identity: fields, linking, My Account, webhooks and privacy.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Identity;

use Pixalink\Loyalty\Account\AccountView;
use Pixalink\Loyalty\Account\DashboardCard;
use Pixalink\Loyalty\Account\LoyaltyEndpoint;
use Pixalink\Loyalty\Account\PortalHandoff;
use Pixalink\Loyalty\Account\QrService;
use Pixalink\Loyalty\Account\SummaryService;
use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Customers;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Events\EventQueue;
use Pixalink\Loyalty\Events\Event;
use Pixalink\Loyalty\Fields\AccountFields;
use Pixalink\Loyalty\Fields\BillingPhoneSync;
use Pixalink\Loyalty\Fields\CheckoutFields;
use Pixalink\Loyalty\Fields\ConsentText;
use Pixalink\Loyalty\Fields\OrderIdentity;
use Pixalink\Loyalty\Fields\PhoneCountries;
use Pixalink\Loyalty\Fields\PhoneNormalizer;
use Pixalink\Loyalty\Fields\PhoneNumber;
use Pixalink\Loyalty\Orders\OrderPayloadBuilder;
use Pixalink\Loyalty\Privacy\Eraser;
use Pixalink\Loyalty\Privacy\Exporter;
use Pixalink\Loyalty\Privacy\PolicyText;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;
use Pixalink\Loyalty\Support\RateLimiter;
use Pixalink\Loyalty\Webhooks\InboundController;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and registers the W2 services and exposes the shopper context other units (W3 redemption) read.
 */
final class Module {

	/**
	 * Outlet credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Guest links.
	 *
	 * @var GuestLinkStore
	 */
	private GuestLinkStore $guests;

	/**
	 * Phone countries.
	 *
	 * @var PhoneCountries
	 */
	private PhoneCountries $countries;

	/**
	 * Phone normaliser.
	 *
	 * @var PhoneNormalizer
	 */
	private PhoneNormalizer $normalizer;

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
	 * Records customer.upserted events.
	 *
	 * @var CustomerEvents
	 */
	private CustomerEvents $events;

	/**
	 * Billing phone sync.
	 *
	 * @var BillingPhoneSync
	 */
	private BillingPhoneSync $sync;

	/**
	 * Consent wording.
	 *
	 * @var ConsentText
	 */
	private ConsentText $consent_text;

	/**
	 * Signed-in portal links.
	 *
	 * @var PortalHandoff
	 */
	private PortalHandoff $handoff;

	/**
	 * Connection state (store paused by Pixalink).
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Whether register() already ran.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param Client      $client      API client.
	 * @param Credentials $credentials Outlet credentials.
	 * @param Options     $options     Option storage.
	 * @param Logger      $logger      Logger.
	 * @param EventQueue  $queue       Outbox writer.
	 */
	public function __construct( Client $client, Credentials $credentials, Options $options, Logger $logger, EventQueue $queue ) {
		$this->credentials  = $credentials;
		$this->options      = $options;
		$this->logger       = $logger;
		$this->guests       = new GuestLinkStore();
		$this->countries    = new PhoneCountries( PhoneCountries::data() );
		$this->normalizer   = new PhoneNormalizer( PhoneCountries::data() );
		$this->consent_text = new ConsentText( $options );
		$this->sync         = new BillingPhoneSync();

		$customers        = new Customers( $client );
		$this->linker     = new CustomerLinker( $customers, new PendingLinks(), $this->guests, $logger );
		$this->summaries  = new SummaryService( $customers, $logger );
		$this->events     = new CustomerEvents( $queue, $credentials, new CustomerPayloadBuilder( new OrderPayloadBuilder() ) );
		$this->connection = new Connection( $options );
		$this->handoff    = new PortalHandoff( $customers, $this->summaries, $options, $this->connection, $this->guests, $logger );
	}

	/**
	 * Register every hook of the unit (idempotent).
	 *
	 * @return void
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		( new CheckoutFields( $this->options, $this->countries, $this->normalizer, $this->consent_text, new OrderIdentity( $this->events, $this->sync ), $this->guests ) )->register();
		( new AccountFields( $this->options, $this->countries, $this->normalizer, $this->consent_text, $this->sync, $this->events ) )->register();

		$view     = new AccountView( $this->summaries, new QrService(), $this->options, $this->countries, $this->normalizer, $this->linker, $this->guests, $this->handoff, $this->connection );
		$endpoint = new LoyaltyEndpoint( $this->options, $view );
		$endpoint->register();
		( new DashboardCard( $endpoint, $this->summaries, $this->options, $this->credentials, $this->guests, $this->logger, $this->handoff, $this->connection ) )->register();
		$this->handoff->register();

		( new RestController( $this->linker, $this->summaries, $this->normalizer, new RateLimiter(), $this->guests ) )->register();
		$sign_in_state = new SignInState();
		( new SsoLanding( $this->linker, $this->credentials, $this->guests, $this->normalizer, $this->logger, $this->options, $sign_in_state ) )->register();
		( new PortalSignIn( $this->options, $this->credentials, $sign_in_state ) )->register();
		( new InboundController( $this->credentials, $this->summaries, $this->logger ) )->register();

		( new Exporter() )->register();
		( new Eraser() )->register();
		( new PolicyText() )->register();

		add_action( 'pixalink_loyalty_shopper_verified', array( $this, 'on_shopper_verified' ), 10, 3 );
		add_action( 'pixalink_loyalty_event_delivered', array( $this, 'on_event_delivered' ), 20, 1 );
		add_action( 'wp_login', array( $this, 'on_login' ), 20, 2 );
	}

	/**
	 * The shopper on the current request (W3 reads this through {@see ShopperContextInterface}).
	 *
	 * @return ShopperContext
	 */
	public function shopper(): ShopperContext {
		return ShopperContext::current( $this->guests );
	}

	/**
	 * The shopper context long-lived services hold: resolves {@see Module::shopper()} again on every question.
	 *
	 * @return CurrentShopper
	 */
	public function current_shopper(): CurrentShopper {
		return new CurrentShopper( $this->guests );
	}

	/**
	 * Linker (OTP and portal tickets).
	 *
	 * @return CustomerLinker
	 */
	public function linker(): CustomerLinker {
		return $this->linker;
	}

	/**
	 * Signed-in portal links.
	 *
	 * @return PortalHandoff
	 */
	public function handoff(): PortalHandoff {
		return $this->handoff;
	}

	/**
	 * Summaries.
	 *
	 * @return SummaryService
	 */
	public function summaries(): SummaryService {
		return $this->summaries;
	}

	/**
	 * Phone normaliser.
	 *
	 * @return PhoneNormalizer
	 */
	public function normalizer(): PhoneNormalizer {
		return $this->normalizer;
	}

	/**
	 * Guest links.
	 *
	 * @return GuestLinkStore
	 */
	public function guests(): GuestLinkStore {
		return $this->guests;
	}

	/**
	 * `pixalink_loyalty_shopper_verified` callback: fresh summary, billing phone, customer.upserted.
	 *
	 * @param mixed $link       Verified link.
	 * @param mixed $user_id    User id (0 for guests).
	 * @param mixed $session_id Session id.
	 * @return void
	 */
	public function on_shopper_verified( $link, $user_id = 0, $session_id = '' ): void {
		unset( $session_id );
		$user_id = is_numeric( $user_id ) ? (int) $user_id : 0;
		if ( ! $link instanceof Link || $user_id < 1 ) {
			return;
		}

		$this->summaries->bust( $user_id );
		if ( '' !== $link->phone_country ) {
			$this->sync->user( $user_id, new PhoneNumber( $link->phone_e164, $link->phone_country, '' ), null );
		}
		$this->events->user_changed( $user_id );
	}

	/**
	 * `pixalink_loyalty_event_delivered` callback: an order event changed the balance, so drop the cached summary.
	 *
	 * @param mixed $event Delivered event.
	 * @return void
	 */
	public function on_event_delivered( $event ): void {
		if ( ! $event instanceof Event || ! $event->is_for_order() ) {
			return;
		}

		$order = wc_get_order( $event->object_id );
		if ( $order instanceof \WC_Order && $order->get_customer_id() > 0 ) {
			$this->summaries->bust( (int) $order->get_customer_id() );
		}
	}

	/**
	 * `wp_login` callback: a link the shopper verified as a guest in this session moves to their account (never to a
	 * staff account, which the linker refuses).
	 *
	 * @param mixed $user_login Login name.
	 * @param mixed $user       User.
	 * @return void
	 */
	public function on_login( $user_login, $user = null ): void {
		unset( $user_login );
		if ( ! $user instanceof \WP_User || UserMeta::is_verified( (int) $user->ID ) ) {
			return;
		}

		$session_id = ShopperContext::current_session_id();
		$link       = $this->guests->get( $session_id );
		if ( null === $link ) {
			return;
		}

		$this->guests->forget( $session_id );
		try {
			$this->linker->store( ShopperContext::resolve( (int) $user->ID, $session_id, $this->guests ), $link );
		} catch ( LinkException $exception ) {
			unset( $exception ); // A staff account: the linker logged the refusal.
		}
	}
}

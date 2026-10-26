<?php
/**
 * Custom WooCommerce settings field: the Pixalink connection card.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Admin;

use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Connect\ConnectController;
use Pixalink\Loyalty\Connect\Connection;
use Pixalink\Loyalty\Connect\ConnectService;
use Pixalink\Loyalty\Connect\Heartbeat;
use Pixalink\Loyalty\Connect\LeadPayload;
use Pixalink\Loyalty\Connect\LegacyWebhookDetector;
use Pixalink\Loyalty\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Renders field type `pixalink_loyalty_connection` (WooCommerce calls `woocommerce_admin_field_{type}`).
 *
 * The card sits inside WooCommerce's settings `<form>`, so its buttons submit to `admin-post.php` through
 * `formaction` (nested forms are invalid HTML); each action carries its own nonce field.
 */
final class ConnectionField {

	public const TYPE = 'pixalink_loyalty_connection';

	/**
	 * Connection state.
	 *
	 * @var Connection
	 */
	private Connection $connection;

	/**
	 * Credentials.
	 *
	 * @var Credentials
	 */
	private Credentials $credentials;

	/**
	 * Connect service (URLs, consent text).
	 *
	 * @var ConnectService
	 */
	private ConnectService $service;

	/**
	 * Legacy webhook detector.
	 *
	 * @var LegacyWebhookDetector
	 */
	private LegacyWebhookDetector $legacy_webhooks;

	/**
	 * Heartbeat.
	 *
	 * @var Heartbeat
	 */
	private Heartbeat $heartbeat;

	/**
	 * Server-aligned clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Nonce fields already printed during the current render.
	 *
	 * @var array<string,bool>
	 */
	private array $rendered_nonces = array();

	/**
	 * Constructor.
	 *
	 * @param Connection            $connection      Connection state.
	 * @param Credentials           $credentials     Credentials.
	 * @param ConnectService        $service         Connect service.
	 * @param LegacyWebhookDetector $legacy_webhooks Legacy webhook detector.
	 * @param Heartbeat             $heartbeat       Heartbeat.
	 * @param Clock                 $clock           Server-aligned clock.
	 */
	public function __construct( Connection $connection, Credentials $credentials, ConnectService $service, LegacyWebhookDetector $legacy_webhooks, Heartbeat $heartbeat, Clock $clock ) {
		$this->connection      = $connection;
		$this->credentials     = $credentials;
		$this->service         = $service;
		$this->legacy_webhooks = $legacy_webhooks;
		$this->heartbeat       = $heartbeat;
		$this->clock           = $clock;
	}

	/**
	 * Register the field renderer and the admin assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::TYPE, array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load the card's stylesheet and the confirm/copy script on the plugin's settings tab only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix || ConnectController::SETTINGS_TAB !== $tab ) {
			return;
		}

		wp_enqueue_style( 'pixalink-loyalty-admin', PIXALINK_LOYALTY_URL . 'assets/css/admin.css', array(), PIXALINK_LOYALTY_VERSION );

		$asset_file = PIXALINK_LOYALTY_DIR . 'build/admin.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = self::read_asset_file( $asset_file );
		wp_enqueue_script(
			'pixalink-loyalty-admin',
			PIXALINK_LOYALTY_URL . 'build/admin.js',
			isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
			isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : PIXALINK_LOYALTY_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * The `*.asset.php` manifest written by `wp-scripts build` (dependencies + version).
	 *
	 * @param string $path Absolute path.
	 * @return array<mixed>
	 */
	private static function read_asset_file( string $path ): array {
		$asset = require $path;

		return is_array( $asset ) ? $asset : array();
	}

	/**
	 * Field renderer.
	 *
	 * @param mixed $field WooCommerce field definition.
	 * @return void
	 */
	public function render( $field ): void {
		unset( $field );
		$this->rendered_nonces = array();

		echo '<tr class="pixalink-loyalty-connection-row"><td colspan="2" class="pixalink-loyalty-connection-cell">';
		echo '<div class="pixalink-loyalty-card" data-status="' . esc_attr( $this->connection->status() ) . '">';

		switch ( $this->connection->status() ) {
			case Connection::STATUS_CONNECTED:
				$this->render_connected();
				break;
			case Connection::STATUS_AUTH_FAILED:
				$this->render_auth_failed();
				break;
			default:
				$this->render_not_connected();
		}

		echo '</div></td></tr>';
	}

	/**
	 * Card for a store that is not connected.
	 *
	 * @return void
	 */
	private function render_not_connected(): void {
		echo '<h3>' . esc_html__( 'Connect your store to Pixalink', 'pixalink-loyalty-for-woocommerce' ) . '</h3>';
		echo '<p>' . esc_html__( 'Once connected, shoppers earn points on every paid order and verified members can use their vouchers and points at checkout.', 'pixalink-loyalty-for-woocommerce' ) . '</p>';

		$this->render_history();
		$this->render_connect_paths();
		$this->render_request_access();
	}

	/**
	 * Card when Pixalink rejects the stored key.
	 *
	 * @return void
	 */
	private function render_auth_failed(): void {
		$data = $this->connection->data();

		echo '<h3>' . esc_html__( 'Reconnect to Pixalink', 'pixalink-loyalty-for-woocommerce' ) . '</h3>';
		echo '<div class="notice notice-error inline"><p>';
		printf(
			/* translators: %s: outlet name. */
			esc_html__( 'Pixalink no longer accepts this store\'s key for %s. Orders are kept and sent once you reconnect. The key may have been rotated or the connection removed in Pixalink.', 'pixalink-loyalty-for-woocommerce' ),
			'<strong>' . esc_html( self::name_of( $data['outlet'] ) ) . '</strong>'
		);
		echo '</p></div>';

		$this->render_connect_paths();

		echo '<p class="pixalink-loyalty-actions">';
		$this->render_button( ConnectController::ACTION_TEST, __( 'Test connection', 'pixalink-loyalty-for-woocommerce' ), 'button' );
		$this->render_disconnect_button();
		echo '</p>';
	}

	/**
	 * Card for a connected store.
	 *
	 * @return void
	 */
	private function render_connected(): void {
		$data   = $this->connection->data();
		$remote = $this->connection->remote_status();

		echo '<h3><span class="pixalink-loyalty-badge pixalink-loyalty-badge--ok">' . esc_html__( 'Connected', 'pixalink-loyalty-for-woocommerce' ) . '</span> ';
		echo esc_html( self::name_of( $data['organisation'] ) ) . '</h3>';

		$rows = array(
			__( 'Organisation', 'pixalink-loyalty-for-woocommerce' )   => esc_html( self::name_of( $data['organisation'] ) ),
			__( 'Outlet', 'pixalink-loyalty-for-woocommerce' )         => esc_html( self::name_of( $data['outlet'] ) ),
			__( 'Connected', 'pixalink-loyalty-for-woocommerce' )      => esc_html( $this->connected_summary( $data ) ),
			__( 'Last heartbeat', 'pixalink-loyalty-for-woocommerce' ) => $this->heartbeat_summary( $remote ),
			__( 'Pixalink status', 'pixalink-loyalty-for-woocommerce' ) => esc_html( self::remote_status_label( $remote['status'] ?? null ) ),
			__( 'Features', 'pixalink-loyalty-for-woocommerce' )       => $this->features_summary(),
			__( 'Points are earned', 'pixalink-loyalty-for-woocommerce' ) => esc_html( 'completed' === ( $remote['earn_on'] ?? null ) ? __( 'when the order is completed', 'pixalink-loyalty-for-woocommerce' ) : __( 'when the order is paid', 'pixalink-loyalty-for-woocommerce' ) ),
			__( 'Clock offset', 'pixalink-loyalty-for-woocommerce' )   => esc_html(
				sprintf(
					/* translators: %d: seconds between this server's clock and Pixalink's. */
					_n( '%d second', '%d seconds', abs( $this->clock->offset() ), 'pixalink-loyalty-for-woocommerce' ),
					$this->clock->offset()
				)
			),
			__( 'Checkout', 'pixalink-loyalty-for-woocommerce' )       => esc_html( $this->store_summary() ),
			__( 'API key', 'pixalink-loyalty-for-woocommerce' )        => esc_html(
				$this->credentials->is_overridden()
					? __( 'Set in wp-config.php', 'pixalink-loyalty-for-woocommerce' )
					: __( 'Stored encrypted on this site', 'pixalink-loyalty-for-woocommerce' )
			),
		);

		echo '<table class="widefat striped pixalink-loyalty-facts"><tbody>';
		foreach ( $rows as $label => $html ) {
			// $html is escaped above; it may contain markup.
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $html ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$this->render_legacy_warning( true );

		echo '<p class="pixalink-loyalty-actions">';
		$this->render_button( ConnectController::ACTION_TEST, __( 'Test connection', 'pixalink-loyalty-for-woocommerce' ), 'button button-primary' );
		$this->render_disconnect_button();
		if ( null !== $data['dashboard_url'] ) {
			echo ' <a class="button button-link" href="' . esc_url( $data['dashboard_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open Pixalink', 'pixalink-loyalty-for-woocommerce' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * Path B (hosted) and Path A (key) controls plus the disclosure.
	 *
	 * @return void
	 */
	private function render_connect_paths(): void {
		$this->render_legacy_warning( false );

		if ( $this->credentials->is_overridden() ) {
			echo '<div class="pixalink-loyalty-path pixalink-loyalty-path--primary">';
			echo '<p>' . esc_html__( 'An outlet API key is set in wp-config.php (PIXALINK_LOYALTY_API_KEY). Verify it to finish connecting.', 'pixalink-loyalty-for-woocommerce' ) . '</p>';
			$this->render_button( ConnectController::ACTION_KEY, __( 'Verify wp-config key', 'pixalink-loyalty-for-woocommerce' ), 'button button-primary' );
			echo '</div>';
			$this->render_disclosure();
			return;
		}

		echo '<div class="pixalink-loyalty-path pixalink-loyalty-path--primary">';
		$this->render_button(
			ConnectController::ACTION_START,
			__( 'Connect existing Pixalink account', 'pixalink-loyalty-for-woocommerce' ),
			'button button-primary button-hero',
			array(
				'name'  => ConnectController::FIELD_INTENT,
				'value' => Connect::INTENT_CONNECT,
			)
		);
		$host = wp_parse_url( $this->service->pixalink_base_url(), PHP_URL_HOST );
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: Pixalink host name, e.g. explore.pixalink.io. */
				__( 'You sign in on %s and choose the outlet that represents this online store. Your Pixalink password is never entered on this site.', 'pixalink-loyalty-for-woocommerce' ),
				is_string( $host ) ? $host : 'pixalink.io'
			)
		) . '</p>';
		echo '</div>';

		echo '<details class="pixalink-loyalty-path"><summary>' . esc_html__( 'I have an outlet API key', 'pixalink-loyalty-for-woocommerce' ) . '</summary>';
		echo '<p><label for="pixalink-loyalty-api-key">' . esc_html__( 'Outlet API key', 'pixalink-loyalty-for-woocommerce' ) . '</label><br />';
		echo '<input type="password" id="pixalink-loyalty-api-key" class="regular-text code" name="' . esc_attr( ConnectController::FIELD_API_KEY ) . '" autocomplete="off" spellcheck="false" data-pixalink-submit="' . esc_attr( ConnectController::ACTION_KEY ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'Create the key in Pixalink under Outlets › your online-store outlet › WooCommerce › Generate key for manual setup.', 'pixalink-loyalty-for-woocommerce' ) . ' ';
		echo esc_html__( 'This site\'s address:', 'pixalink-loyalty-for-woocommerce' ) . ' <code>' . esc_html( ConnectService::site_url() ) . '</code> ';
		echo '<button type="button" class="button-link" data-pixalink-copy="' . esc_attr( ConnectService::site_url() ) . '">' . esc_html__( 'Copy', 'pixalink-loyalty-for-woocommerce' ) . '</button></p>';
		echo '<p>';
		$this->render_button( ConnectController::ACTION_KEY, __( 'Connect with key', 'pixalink-loyalty-for-woocommerce' ), 'button' );
		echo '</p></details>';

		$this->render_disclosure();
	}

	/**
	 * What connecting sends, and the separate (unticked) marketing consent box.
	 *
	 * @return void
	 */
	private function render_disclosure(): void {
		echo '<p class="pixalink-loyalty-disclosure description">';
		echo esc_html__( 'Connecting sends this site\'s address and the plugin, WordPress and WooCommerce versions to Pixalink. Once connected, paid, cancelled and refunded orders (totals, items, and the shopper\'s name, e-mail, phone and marketing choice) are sent to run your loyalty programme.', 'pixalink-loyalty-for-woocommerce' ) . ' ';
		echo '<a href="https://pixalink.io/privacy-policy" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy policy', 'pixalink-loyalty-for-woocommerce' ) . '</a>';
		echo '</p>';

		echo '<input type="hidden" name="' . esc_attr( ConnectController::FIELD_CONSENT_SHOWN ) . '" value="1" />';
		echo '<p class="pixalink-loyalty-consent"><label><input type="checkbox" name="' . esc_attr( ConnectController::FIELD_MARKETING_CONSENT ) . '" value="1" /> ';
		echo esc_html( ConnectService::marketing_consent_text() ) . ' ' . esc_html__( '(optional)', 'pixalink-loyalty-for-woocommerce' ) . '</label></p>';
	}

	/**
	 * "Not on Pixalink yet? Request access" (same hosted URL; guests see the request-access form).
	 *
	 * @return void
	 */
	private function render_request_access(): void {
		if ( $this->credentials->is_overridden() ) {
			return;
		}

		echo '<p class="pixalink-loyalty-request-access">' . esc_html__( 'Not on Pixalink yet?', 'pixalink-loyalty-for-woocommerce' ) . ' ';
		$this->render_button(
			ConnectController::ACTION_START,
			__( 'Request access', 'pixalink-loyalty-for-woocommerce' ),
			'button-link',
			array(
				'name'  => ConnectController::FIELD_INTENT,
				'value' => Connect::INTENT_REQUEST_ACCESS,
			)
		);
		echo '<br /><span class="description">' . esc_html__( 'Requesting access shares your store name, this site\'s address, your name and e-mail, and your store\'s country, currency and checkout type with Pixalink\'s team so they can contact you.', 'pixalink-loyalty-for-woocommerce' ) . '</span></p>';
	}

	/**
	 * Earlier connection, kept after a disconnect.
	 *
	 * @return void
	 */
	private function render_history(): void {
		$data = $this->connection->data();
		if ( null === $data['disconnected_at'] || null === $data['outlet'] ) {
			return;
		}

		echo '<p class="pixalink-loyalty-history description">';
		printf(
			/* translators: 1: outlet name, 2: organisation name, 3: date. */
			esc_html__( 'Previously connected to %1$s (%2$s); disconnected on %3$s.', 'pixalink-loyalty-for-woocommerce' ),
			esc_html( self::name_of( $data['outlet'] ) ),
			esc_html( self::name_of( $data['organisation'] ) ),
			esc_html( self::format_time( $data['disconnected_at'] ) )
		);
		echo '</p>';
	}

	/**
	 * Legacy Pixalink webhooks still active on this store.
	 *
	 * @param bool $connected Whether the store is already connected (button instead of checkbox).
	 * @return void
	 */
	private function render_legacy_warning( bool $connected ): void {
		$webhooks = $this->legacy_webhooks->find_active();
		if ( array() === $webhooks ) {
			return;
		}

		echo '<div class="notice notice-warning inline pixalink-loyalty-legacy"><p>';
		echo esc_html__( 'This store still sends orders to Pixalink through the old WooCommerce webhooks below. Pause them so orders are not counted twice.', 'pixalink-loyalty-for-woocommerce' );
		echo '</p><ul>';
		foreach ( $webhooks as $webhook ) {
			echo '<li><code>' . esc_html( (string) $webhook->get_name() ) . '</code> → ' . esc_html( (string) $webhook->get_delivery_url() ) . '</li>';
		}
		echo '</ul><p>';

		if ( $connected ) {
			$this->render_button( ConnectController::ACTION_PAUSE_LEGACY, __( 'Pause legacy Pixalink webhooks', 'pixalink-loyalty-for-woocommerce' ), 'button' );
		} else {
			echo '<label><input type="checkbox" name="' . esc_attr( ConnectController::FIELD_PAUSE_LEGACY ) . '" value="1" checked="checked" /> ';
			echo esc_html__( 'Pause legacy Pixalink webhooks when connected (recommended)', 'pixalink-loyalty-for-woocommerce' ) . '</label>';
		}
		echo '</p></div>';
	}

	/**
	 * Disconnect button with its nonce and confirmation.
	 *
	 * @return void
	 */
	private function render_disconnect_button(): void {
		$this->render_button(
			ConnectController::ACTION_DISCONNECT,
			__( 'Disconnect', 'pixalink-loyalty-for-woocommerce' ),
			'button button-link-delete',
			array(
				'data-pixalink-confirm' => __( 'Disconnect this store from Pixalink? Shoppers stop earning points until you connect again. Order history is kept.', 'pixalink-loyalty-for-woocommerce' ),
			)
		);
	}

	/**
	 * A submit button that posts WooCommerce's settings form to admin-post.php?action={action}.
	 *
	 * @param string               $action     admin-post action.
	 * @param string               $label      Button label.
	 * @param string               $css_class  CSS classes.
	 * @param array<string,string> $attributes Extra attributes (name/value, data-*).
	 * @return void
	 */
	private function render_button( string $action, string $label, string $css_class, array $attributes = array() ): void {
		$this->render_nonce( $action );

		$attributes = array_merge(
			array(
				'type'                 => 'submit',
				'class'                => $css_class,
				'formaction'           => admin_url( 'admin-post.php?action=' . $action ),
				'formmethod'           => 'post',
				'formnovalidate'       => 'formnovalidate',
				'data-pixalink-action' => $action,
			),
			$attributes
		);

		echo '<button';
		foreach ( $attributes as $name => $value ) {
			echo ' ' . esc_attr( $name ) . '="' . ( 'formaction' === $name ? esc_url( $value ) : esc_attr( $value ) ) . '"';
		}
		echo '>' . esc_html( $label ) . '</button> ';
	}

	/**
	 * Nonce field for an action (printed once per render).
	 *
	 * @param string $action Action.
	 * @return void
	 */
	private function render_nonce( string $action ): void {
		if ( isset( $this->rendered_nonces[ $action ] ) ) {
			return;
		}
		$this->rendered_nonces[ $action ] = true;

		wp_nonce_field( $action, ConnectController::NONCE_FIELDS[ $action ], false );
	}

	/**
	 * "On {date} by {user} (method)".
	 *
	 * @param array{connected_at:int|null,connected_by:int|null,method:string|null} $data Connection data.
	 * @return string
	 */
	private function connected_summary( array $data ): string {
		$when = null === $data['connected_at'] ? '—' : self::format_time( $data['connected_at'] );
		$user = null === $data['connected_by'] ? false : get_userdata( $data['connected_by'] );
		$by   = false === $user ? __( 'unknown user', 'pixalink-loyalty-for-woocommerce' ) : (string) $user->display_name;
		$how  = Connection::METHOD_API_KEY === $data['method'] ? __( 'outlet API key', 'pixalink-loyalty-for-woocommerce' ) : __( 'Pixalink sign-in', 'pixalink-loyalty-for-woocommerce' );

		/* translators: 1: date and time, 2: user display name, 3: connection method. */
		return sprintf( __( '%1$s by %2$s (%3$s)', 'pixalink-loyalty-for-woocommerce' ), $when, $by, $how );
	}

	/**
	 * Last heartbeat, schedule and last error (escaped HTML).
	 *
	 * @param array<mixed> $remote Remote status.
	 * @return string
	 */
	private function heartbeat_summary( array $remote ): string {
		$received = $this->connection->last_heartbeat_at();
		$html     = null === $received
			? esc_html__( 'Not yet', 'pixalink-loyalty-for-woocommerce' )
			/* translators: %s: human-readable time difference, e.g. "5 mins". */
			: esc_html( sprintf( __( '%s ago', 'pixalink-loyalty-for-woocommerce' ), human_time_diff( $received, time() ) ) );

		if ( ! $this->heartbeat->is_scheduled() ) {
			$html .= ' · ' . esc_html__( 'not scheduled', 'pixalink-loyalty-for-woocommerce' );
		}

		$error = $remote['last_error'] ?? null;
		if ( is_array( $error ) && isset( $error['code'] ) && is_string( $error['code'] ) ) {
			/* translators: %s: machine error code. */
			$html .= '<br /><span class="pixalink-loyalty-error">' . esc_html( sprintf( __( 'Last attempt failed: %s', 'pixalink-loyalty-for-woocommerce' ), $error['code'] ) ) . '</span>';
		}

		return $html;
	}

	/**
	 * Remote kill switches (escaped HTML).
	 *
	 * @return string
	 */
	private function features_summary(): string {
		$labels = array(
			'earn'              => __( 'Earn points', 'pixalink-loyalty-for-woocommerce' ),
			'vouchers'          => __( 'Vouchers', 'pixalink-loyalty-for-woocommerce' ),
			'points_redemption' => __( 'Points at checkout', 'pixalink-loyalty-for-woocommerce' ),
			'credits'           => __( 'Credits', 'pixalink-loyalty-for-woocommerce' ),
		);

		if ( null === $this->connection->last_heartbeat_at() ) {
			return esc_html__( 'Known after the first heartbeat', 'pixalink-loyalty-for-woocommerce' );
		}

		$parts = array();
		foreach ( $labels as $feature => $label ) {
			$on      = $this->connection->feature( $feature );
			$parts[] = '<span class="pixalink-loyalty-feature pixalink-loyalty-feature--' . ( $on ? 'on' : 'off' ) . '">' . esc_html( $label ) . ': ' . esc_html( $on ? __( 'on', 'pixalink-loyalty-for-woocommerce' ) : __( 'off', 'pixalink-loyalty-for-woocommerce' ) ) . '</span>';
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Checkout type and HPOS state.
	 *
	 * @return string
	 */
	private function store_summary(): string {
		$types = array(
			LeadPayload::CHECKOUT_BLOCKS  => __( 'Checkout block', 'pixalink-loyalty-for-woocommerce' ),
			LeadPayload::CHECKOUT_CLASSIC => __( 'Classic checkout', 'pixalink-loyalty-for-woocommerce' ),
			LeadPayload::CHECKOUT_NONE    => __( 'No checkout page set', 'pixalink-loyalty-for-woocommerce' ),
		);

		return $types[ LeadPayload::checkout_type() ] . ' · ' . ( LeadPayload::hpos_enabled()
			? __( 'High-Performance Order Storage', 'pixalink-loyalty-for-woocommerce' )
			: __( 'Posts order storage', 'pixalink-loyalty-for-woocommerce' ) );
	}

	/**
	 * Label for the heartbeat `status`.
	 *
	 * @param mixed $status Status.
	 * @return string
	 */
	private static function remote_status_label( $status ): string {
		switch ( $status ) {
			case 'active':
				return __( 'Active', 'pixalink-loyalty-for-woocommerce' );
			case 'subscription_inactive':
				return __( 'Subscription inactive: loyalty is paused', 'pixalink-loyalty-for-woocommerce' );
			case 'commerce_not_enabled':
				return __( 'Online store integration is not enabled for this organisation', 'pixalink-loyalty-for-woocommerce' );
			case 'commerce_paused':
				return __( 'Paused by Pixalink: points and vouchers are off until Pixalink turns this store back on', 'pixalink-loyalty-for-woocommerce' );
			default:
				return __( 'Unknown', 'pixalink-loyalty-for-woocommerce' );
		}
	}

	/**
	 * Display name of an outlet/organisation record.
	 *
	 * @param array<string,mixed>|null $entity Record.
	 * @return string
	 */
	private static function name_of( ?array $entity ): string {
		if ( null === $entity ) {
			return '—';
		}

		return isset( $entity['name'] ) && is_string( $entity['name'] ) && '' !== $entity['name'] ? $entity['name'] : (string) ( $entity['id'] ?? '—' );
	}

	/**
	 * Site-formatted date and time.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function format_time( int $timestamp ): string {
		$formatted = wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $timestamp );

		return false === $formatted ? gmdate( 'Y-m-d H:i', $timestamp ) : $formatted;
	}
}

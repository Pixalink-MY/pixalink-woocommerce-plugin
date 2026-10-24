<?php
/**
 * Plugin composition root.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty;

use Pixalink\Loyalty\Api\Client;
use Pixalink\Loyalty\Api\Credentials;
use Pixalink\Loyalty\Api\Resources\Connect;
use Pixalink\Loyalty\Install\Installer;
use Pixalink\Loyalty\Support\Clock;
use Pixalink\Loyalty\Support\Crypto;
use Pixalink\Loyalty\Support\Logger;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin on `plugins_loaded` and lazily builds shared services.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Lazily built services keyed by class name.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * The shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * `plugins_loaded` callback: check requirements, upgrade schema, register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		$plugin = self::instance();
		if ( $plugin->booted ) {
			return;
		}
		$plugin->booted = true;

		$failures = Requirements::failures();
		if ( array() !== $failures ) {
			Requirements::register_notice( $failures );
			return;
		}

		Installer::maybe_upgrade();

		if ( 'selfhosted' === PIXALINK_LOYALTY_DISTRIBUTION && class_exists( Updates\UpdateChecker::class ) ) {
			Updates\UpdateChecker::register( PIXALINK_LOYALTY_FILE );
		}

		$plugin->events()->register();

		/**
		 * Fires once the plugin has booted and its requirements are met.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin Plugin instance (service accessors).
		 */
		do_action( 'pixalink_loyalty_booted', $plugin );
	}

	/**
	 * Whether boot() completed with all requirements met.
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted && Requirements::are_met();
	}

	/**
	 * Option storage.
	 *
	 * @return Options
	 */
	public function options(): Options {
		return $this->service( Options::class, static fn (): Options => new Options() );
	}

	/**
	 * Secret encryption.
	 *
	 * @return Crypto
	 */
	public function crypto(): Crypto {
		return $this->service( Crypto::class, static fn (): Crypto => new Crypto() );
	}

	/**
	 * Logger.
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		return $this->service( Logger::class, static fn (): Logger => new Logger() );
	}

	/**
	 * Server-aligned clock.
	 *
	 * @return Clock
	 */
	public function clock(): Clock {
		return $this->service( Clock::class, fn (): Clock => new Clock( $this->options() ) );
	}

	/**
	 * Outlet credentials.
	 *
	 * @return Credentials
	 */
	public function credentials(): Credentials {
		return $this->service( Credentials::class, fn (): Credentials => new Credentials( $this->crypto(), $this->options() ) );
	}

	/**
	 * API client.
	 *
	 * @return Client
	 */
	public function client(): Client {
		return $this->service(
			Client::class,
			fn (): Client => new Client( $this->credentials(), $this->clock(), $this->logger(), $this->options(), PIXALINK_LOYALTY_VERSION )
		);
	}

	/**
	 * Connect API resource.
	 *
	 * @return Connect
	 */
	public function connect(): Connect {
		return $this->service( Connect::class, fn (): Connect => new Connect( $this->client() ) );
	}

	/**
	 * Event outbox, order events and their admin screens.
	 *
	 * @return Events\Module
	 */
	public function events(): Events\Module {
		return $this->service(
			Events\Module::class,
			fn (): Events\Module => new Events\Module( $this->client(), $this->credentials(), $this->options(), $this->logger() )
		);
	}

	/**
	 * Memoised service lookup.
	 *
	 * @template T of object
	 * @param class-string<T> $id      Service class.
	 * @param \Closure():T    $factory Factory.
	 * @return T
	 */
	private function service( string $id, \Closure $factory ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}

		/**
		 * Service of the requested class.
		 *
		 * @var T $service
		 */
		$service = $this->services[ $id ];

		return $service;
	}
}

<?php
/**
 * Phone number and marketing consent on the account forms.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

use Pixalink\Loyalty\Identity\CustomerEvents;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * My Account registration and "Account details" forms.
 *
 * - Registration: optional phone, and the consent box (unticked; ticking records `registration`).
 * - Account details: the phone (read-only once verified with Pixalink: changing it means verifying the new number on
 *   the Loyalty tab) and the consent box showing the saved answer, so withdrawing is as easy as giving (`my_account`).
 *
 * WooCommerce's form handler checks the form nonces before these hooks run.
 */
final class AccountFields {

	public const COUNTRY = 'pixalink_loyalty_phone_country';
	public const PHONE   = 'pixalink_loyalty_phone';
	public const CONSENT = 'pixalink_loyalty_marketing_consent';

	/**
	 * Option storage.
	 *
	 * @var Options
	 */
	private Options $options;

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
	 * Consent wording.
	 *
	 * @var ConsentText
	 */
	private ConsentText $consent_text;

	/**
	 * Billing phone sync.
	 *
	 * @var BillingPhoneSync
	 */
	private BillingPhoneSync $sync;

	/**
	 * Records customer.upserted events.
	 *
	 * @var CustomerEvents
	 */
	private CustomerEvents $events;

	/**
	 * Whether the current request submitted the My Account registration form (not a checkout sign-up).
	 *
	 * @var bool
	 */
	private bool $registering = false;

	/**
	 * Constructor.
	 *
	 * @param Options          $options      Option storage.
	 * @param PhoneCountries   $countries    Phone countries.
	 * @param PhoneNormalizer  $normalizer   Phone normaliser.
	 * @param ConsentText      $consent_text Consent wording.
	 * @param BillingPhoneSync $sync         Billing phone sync.
	 * @param CustomerEvents   $events       customer.upserted events.
	 */
	public function __construct( Options $options, PhoneCountries $countries, PhoneNormalizer $normalizer, ConsentText $consent_text, BillingPhoneSync $sync, CustomerEvents $events ) {
		$this->options      = $options;
		$this->countries    = $countries;
		$this->normalizer   = $normalizer;
		$this->consent_text = $consent_text;
		$this->sync         = $sync;
		$this->events       = $events;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_register_form', array( $this, 'render_registration' ) );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate_registration' ), 10, 1 );
		add_action( 'woocommerce_created_customer', array( $this, 'save_registration' ), 10, 1 );

		add_action( 'woocommerce_edit_account_form', array( $this, 'render_edit_account' ) );
		add_action( 'woocommerce_save_account_details_errors', array( $this, 'validate_edit_account' ), 10, 2 );
		add_action( 'woocommerce_save_account_details', array( $this, 'save_edit_account' ), 10, 1 );
	}

	/**
	 * `woocommerce_register_form` callback.
	 *
	 * @return void
	 */
	public function render_registration(): void {
		$this->render_phone( self::posted( self::COUNTRY ) ?? $this->countries->default_country(), self::posted( self::PHONE ) ?? '', false );

		if ( $this->options->get_bool( Options::CHECKOUT_CONSENT_ENABLED ) ) {
			$this->render_consent( ConsentRecord::SOURCE_REGISTRATION, false );
		}
	}

	/**
	 * `woocommerce_process_registration_errors` filter (My Account registration form only).
	 *
	 * @param mixed $errors WP_Error.
	 * @return mixed
	 */
	public function validate_registration( $errors ) {
		$this->registering = true;

		if ( $errors instanceof \WP_Error ) {
			$message = $this->phone_error( self::posted( self::PHONE ) ?? '', self::posted( self::COUNTRY ) ?? '' );
			if ( null !== $message ) {
				$errors->add( 'pixalink_loyalty_phone', $message );
			}
		}

		return $errors;
	}

	/**
	 * `woocommerce_created_customer` callback.
	 *
	 * @param mixed $customer_id New user id.
	 * @return void
	 */
	public function save_registration( $customer_id ): void {
		if ( ! $this->registering || ! is_numeric( $customer_id ) ) {
			return;
		}

		$user_id = (int) $customer_id;
		$changed = $this->save_phone( $user_id );
		if ( $this->options->get_bool( Options::CHECKOUT_CONSENT_ENABLED ) && '1' === self::posted( self::CONSENT ) ) {
			$changed = UserMeta::set_consent( $user_id, ConsentRecord::given( true, ConsentRecord::SOURCE_REGISTRATION, $this->consent_text->text( ConsentRecord::SOURCE_REGISTRATION ), time() ) ) || $changed;
		}

		if ( $changed ) {
			$this->events->user_changed( $user_id );
		}
	}

	/**
	 * `woocommerce_edit_account_form` callback.
	 *
	 * @return void
	 */
	public function render_edit_account(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}

		$saved    = UserMeta::phone( $user_id );
		$country  = self::posted( self::COUNTRY ) ?? ( null !== $saved ? $saved[1] : $this->countries->default_country() );
		$national = self::posted( self::PHONE ) ?? ( null !== $saved ? (string) $this->normalizer->normalize( $saved[0], $saved[1] )?->national : '' );

		echo '<fieldset class="pixalink-loyalty-account-fields"><legend>' . esc_html__( 'Loyalty', 'pixalink-loyalty-for-woocommerce' ) . '</legend>';
		$this->render_phone( $country, $national, UserMeta::is_verified( $user_id ) );

		$consent = UserMeta::consent( $user_id );
		if ( $this->options->get_bool( Options::CHECKOUT_CONSENT_ENABLED ) || ( null !== $consent && $consent->value ) ) {
			$this->render_consent( ConsentRecord::SOURCE_MY_ACCOUNT, null !== $consent && $consent->value );
		}
		echo '</fieldset>';
	}

	/**
	 * `woocommerce_save_account_details_errors` callback.
	 *
	 * @param mixed $errors WP_Error.
	 * @param mixed $user   User being saved.
	 * @return void
	 */
	public function validate_edit_account( $errors, $user = null ): void {
		$user_id = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : get_current_user_id();
		if ( ! $errors instanceof \WP_Error || UserMeta::is_verified( $user_id ) ) {
			return;
		}

		$message = $this->phone_error( self::posted( self::PHONE ) ?? '', self::posted( self::COUNTRY ) ?? '' );
		if ( null !== $message ) {
			$errors->add( 'pixalink_loyalty_phone', $message );
		}
	}

	/**
	 * `woocommerce_save_account_details` callback.
	 *
	 * @param mixed $user_id User id.
	 * @return void
	 */
	public function save_edit_account( $user_id ): void {
		if ( ! is_numeric( $user_id ) || (int) $user_id < 1 ) {
			return;
		}

		$user_id = (int) $user_id;
		$changed = $this->save_phone( $user_id );

		// Only an explicit change is recorded: ticking a box that showed "no", or unticking one that showed "yes".
		$current = UserMeta::consent( $user_id );
		$shown   = $this->options->get_bool( Options::CHECKOUT_CONSENT_ENABLED ) || ( null !== $current && $current->value );
		if ( $shown && null !== self::posted( 'pixalink_loyalty_consent_shown' ) ) {
			$ticked  = '1' === self::posted( self::CONSENT );
			$was_yes = null !== $current && $current->value;
			if ( $ticked !== $was_yes ) {
				$record  = ConsentRecord::given( $ticked, ConsentRecord::SOURCE_MY_ACCOUNT, $this->consent_text->text( ConsentRecord::SOURCE_MY_ACCOUNT ), time() );
				$changed = UserMeta::set_consent( $user_id, $record ) || $changed;
			}
		}

		if ( $changed ) {
			$this->events->user_changed( $user_id );
		}
	}

	/**
	 * Store the posted phone as the user's unverified number (never over a verified one).
	 *
	 * @param int $user_id User id.
	 * @return bool Whether it changed.
	 */
	private function save_phone( int $user_id ): bool {
		$raw = self::posted( self::PHONE ) ?? '';
		if ( '' === trim( $raw ) || UserMeta::is_verified( $user_id ) ) {
			return false;
		}

		$phone = $this->normalizer->normalize( $raw, self::posted( self::COUNTRY ) ?? '' );
		if ( null === $phone ) {
			return false;
		}

		$previous = UserMeta::phone( $user_id );
		if ( ! UserMeta::set_phone( $user_id, $phone ) ) {
			return false;
		}

		$this->sync->user( $user_id, $phone, null !== $previous ? $previous[0] : null );

		return true;
	}

	/**
	 * Render the phone country and number.
	 *
	 * @param string $country  Selected country.
	 * @param string $national Number in national form.
	 * @param bool   $verified Whether the number is verified (read-only).
	 * @return void
	 */
	private function render_phone( string $country, string $national, bool $verified ): void {
		$custom = $verified ? array(
			'readonly' => 'readonly',
			'disabled' => 'disabled',
		) : array();

		woocommerce_form_field(
			self::COUNTRY,
			array(
				'type'              => 'select',
				'label'             => __( 'Phone country', 'pixalink-loyalty-for-woocommerce' ),
				'options'           => $this->countries->options(),
				'class'             => array( 'form-row-first' ),
				'custom_attributes' => $verified ? array( 'disabled' => 'disabled' ) : array(),
			),
			$country
		);

		woocommerce_form_field(
			self::PHONE,
			array(
				'type'              => 'tel',
				'label'             => __( 'Mobile number for loyalty points', 'pixalink-loyalty-for-woocommerce' ),
				'class'             => array( 'form-row-last' ),
				'autocomplete'      => 'tel-national',
				'custom_attributes' => $custom,
				'description'       => $verified ? __( 'Verified with Pixalink. To use a different number, verify it on the Loyalty tab.', 'pixalink-loyalty-for-woocommerce' ) : '',
			),
			$national
		);
	}

	/**
	 * Render the consent box.
	 *
	 * @param string $context Consent source shown (registration or my_account).
	 * @param bool   $checked Whether to show the saved "yes".
	 * @return void
	 */
	private function render_consent( string $context, bool $checked ): void {
		echo '<input type="hidden" name="pixalink_loyalty_consent_shown" value="1" />';
		woocommerce_form_field(
			self::CONSENT,
			array(
				'type'  => 'checkbox',
				'label' => esc_html( $this->consent_text->text( $context ) ),
				'class' => array( 'form-row-wide' ),
			),
			$checked ? '1' : '0'
		);
	}

	/**
	 * Why a typed number is not acceptable (null when fine or empty).
	 *
	 * @param string $phone   Typed number.
	 * @param string $country Country.
	 * @return string|null
	 */
	private function phone_error( string $phone, string $country ): ?string {
		if ( '' === trim( $phone ) ) {
			return null;
		}

		try {
			$this->normalizer->parse( $phone, $country );
		} catch ( InvalidPhoneNumber $exception ) {
			return $exception->user_message();
		}

		return null;
	}

	/**
	 * A posted form value (the form nonce was verified by WooCommerce's form handler).
	 *
	 * @param string $key Field name.
	 * @return string|null
	 */
	private static function posted( string $key ): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Form_Handler verified the registration/account nonce before these hooks.
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}
}

<?php
/**
 * Phone number and marketing consent at checkout.
 *
 * @package Pixalink\Loyalty
 */

declare( strict_types=1 );

namespace Pixalink\Loyalty\Fields;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields as BlocksCheckoutFields;
use Automattic\WooCommerce\Blocks\Package as BlocksPackage;
use Pixalink\Loyalty\Identity\GuestLinkStore;
use Pixalink\Loyalty\Identity\ShopperContext;
use Pixalink\Loyalty\Identity\UserMeta;
use Pixalink\Loyalty\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a phone country, a phone number and a separate, unticked marketing-consent box to both checkouts.
 *
 * - Checkout block: Additional Checkout Fields API, location `contact` (`pixalink-loyalty/phone-country`,
 *   `pixalink-loyalty/phone`, `pixalink-loyalty/marketing-consent`); cross-field validation on
 *   `woocommerce_blocks_validate_location_contact_fields`; saved at `woocommerce_store_api_checkout_update_order_from_request`.
 *   WooCommerce copies contact fields into the customer's profile, so the consent answer is removed from it after each
 *   order: the box is never shown ticked at the next checkout.
 * - Classic checkout: `woocommerce_checkout_fields` (billing section), validated at
 *   `woocommerce_after_checkout_validation`, saved at `woocommerce_checkout_create_order` (priority 10, before W3's
 *   hold at 20).
 *
 * The account update and `customer.upserted` follow once the order exists ({@see OrderIdentity::after_order()}).
 */
final class CheckoutFields {

	public const BLOCKS_COUNTRY = 'pixalink-loyalty/phone-country';
	public const BLOCKS_PHONE   = 'pixalink-loyalty/phone';
	public const BLOCKS_CONSENT = 'pixalink-loyalty/marketing-consent';

	public const CLASSIC_COUNTRY = 'pixalink_loyalty_phone_country';
	public const CLASSIC_PHONE   = 'pixalink_loyalty_phone';
	public const CLASSIC_CONSENT = 'pixalink_loyalty_marketing_consent';

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
	 * Order identity writer.
	 *
	 * @var OrderIdentity
	 */
	private OrderIdentity $identity;

	/**
	 * Guest links (to stamp a verified guest's customer id on the order).
	 *
	 * @var GuestLinkStore
	 */
	private GuestLinkStore $guests;

	/**
	 * Constructor.
	 *
	 * @param Options         $options      Option storage.
	 * @param PhoneCountries  $countries    Phone countries.
	 * @param PhoneNormalizer $normalizer   Phone normaliser.
	 * @param ConsentText     $consent_text Consent wording.
	 * @param OrderIdentity   $identity     Order identity writer.
	 * @param GuestLinkStore  $guests       Guest links.
	 */
	public function __construct( Options $options, PhoneCountries $countries, PhoneNormalizer $normalizer, ConsentText $consent_text, OrderIdentity $identity, GuestLinkStore $guests ) {
		$this->options      = $options;
		$this->countries    = $countries;
		$this->normalizer   = $normalizer;
		$this->consent_text = $consent_text;
		$this->identity     = $identity;
		$this->guests       = $guests;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Blocks.
		add_action( 'woocommerce_init', array( $this, 'register_blocks_fields' ) );
		add_filter( 'woocommerce_get_default_value_for_' . self::BLOCKS_COUNTRY, array( $this, 'default_blocks_country' ), 10, 3 );
		add_filter( 'woocommerce_get_default_value_for_' . self::BLOCKS_PHONE, array( $this, 'default_blocks_phone' ), 10, 3 );
		add_action( 'woocommerce_blocks_validate_location_contact_fields', array( $this, 'validate_blocks' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_blocks' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'after_blocks_order' ), 5, 1 );

		// Classic.
		add_filter( 'woocommerce_checkout_fields', array( $this, 'classic_fields' ), 20, 1 );
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'classic_value' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'after_classic_order' ), 10, 3 );
	}

	/**
	 * Whether the phone number is required.
	 *
	 * @return bool
	 */
	public function phone_required(): bool {
		return 'required' === $this->options->get_string( Options::PHONE_FIELD );
	}

	/**
	 * Whether the consent box is shown at checkout.
	 *
	 * @return bool
	 */
	public function consent_enabled(): bool {
		return $this->options->get_bool( Options::CHECKOUT_CONSENT_ENABLED );
	}

	/**
	 * `woocommerce_init` callback: register the Checkout block fields.
	 *
	 * @return void
	 */
	public function register_blocks_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$options = array();
		foreach ( $this->countries->options() as $iso => $label ) {
			$options[] = array(
				'value' => $iso,
				'label' => $label,
			);
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => self::BLOCKS_COUNTRY,
				'label'                      => __( 'Phone country', 'pixalink-loyalty-for-woocommerce' ),
				'optionalLabel'              => __( 'Phone country', 'pixalink-loyalty-for-woocommerce' ),
				'location'                   => 'contact',
				'type'                       => 'select',
				'options'                    => $options,
				'required'                   => false,
				'show_in_order_confirmation' => false,
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'                         => self::BLOCKS_PHONE,
				'label'                      => __( 'Mobile number for loyalty points', 'pixalink-loyalty-for-woocommerce' ),
				'optionalLabel'              => __( 'Mobile number for loyalty points (optional)', 'pixalink-loyalty-for-woocommerce' ),
				'location'                   => 'contact',
				'type'                       => 'text',
				'required'                   => $this->phone_required(),
				'attributes'                 => array(
					'autocomplete' => 'tel-national',
					'maxLength'    => 25,
				),
				'show_in_order_confirmation' => true,
			)
		);

		if ( $this->consent_enabled() ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::BLOCKS_CONSENT,
					'label'                      => $this->consent_text->text( ConsentRecord::SOURCE_CHECKOUT ),
					'location'                   => 'contact',
					'type'                       => 'checkbox',
					'required'                   => false,
					'show_in_order_confirmation' => false,
				)
			);
		}
	}

	/**
	 * Default phone country of the Checkout block: the shopper's saved one, else the store's country.
	 *
	 * @param mixed  $value     Default (null).
	 * @param string $group     Group.
	 * @param mixed  $wc_object Customer or order.
	 * @return mixed
	 */
	public function default_blocks_country( $value, $group = '', $wc_object = null ) {
		unset( $group );
		if ( null !== $value ) {
			return $value;
		}

		$user_id = $wc_object instanceof \WC_Customer ? (int) $wc_object->get_id() : 0;
		$saved   = UserMeta::phone( $user_id );

		return null !== $saved && $this->countries->is_supported( $saved[1] ) ? $saved[1] : $this->countries->default_country();
	}

	/**
	 * Default phone number of the Checkout block: the account's Pixalink phone in national form.
	 *
	 * @param mixed  $value     Default (null).
	 * @param string $group     Group.
	 * @param mixed  $wc_object Customer or order.
	 * @return mixed
	 */
	public function default_blocks_phone( $value, $group = '', $wc_object = null ) {
		unset( $group );
		if ( null !== $value || ! $wc_object instanceof \WC_Customer ) {
			return $value;
		}

		return $this->saved_national( (int) $wc_object->get_id() ) ?? $value;
	}

	/**
	 * `woocommerce_blocks_validate_location_contact_fields` callback.
	 *
	 * @param mixed $errors WP_Error to add to.
	 * @param mixed $fields Field values keyed by id.
	 * @param mixed $group  Group.
	 * @return void
	 */
	public function validate_blocks( $errors, $fields, $group = '' ): void {
		unset( $group );
		if ( ! $errors instanceof \WP_Error || ! is_array( $fields ) ) {
			return;
		}

		$message = $this->phone_error( self::string( $fields[ self::BLOCKS_PHONE ] ?? '' ), self::string( $fields[ self::BLOCKS_COUNTRY ] ?? '' ) );
		if ( null !== $message ) {
			$errors->add( 'pixalink_loyalty_invalid_phone', $message );
		}
	}

	/**
	 * `woocommerce_store_api_checkout_update_order_from_request` callback.
	 *
	 * @param mixed $order Order.
	 * @return void
	 */
	public function save_blocks( $order ): void {
		$service = self::blocks_service();
		if ( ! $order instanceof \WC_Order || null === $service ) {
			return;
		}

		$phone   = $this->normalizer->normalize( self::string( $service->get_field_from_object( self::BLOCKS_PHONE, $order, 'other' ) ), self::string( $service->get_field_from_object( self::BLOCKS_COUNTRY, $order, 'other' ) ) );
		$ticked  = $this->consent_enabled() && true === $service->get_field_from_object( self::BLOCKS_CONSENT, $order, 'other' );
		$consent = $ticked ? ConsentRecord::given( true, ConsentRecord::SOURCE_CHECKOUT, $this->consent_text->text( ConsentRecord::SOURCE_CHECKOUT ), time() ) : null;

		$this->identity->record( $order, $phone, $consent, ShopperContext::current( $this->guests ) );
	}

	/**
	 * `woocommerce_store_api_checkout_order_processed` callback.
	 *
	 * @param mixed $order Order.
	 * @return void
	 */
	public function after_blocks_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$this->identity->after_order( $order );
		self::forget_blocks_consent( $order );
	}

	/**
	 * `woocommerce_checkout_fields` callback: add the fields to the billing section, after the billing phone.
	 *
	 * @param mixed $fields Checkout fields.
	 * @return mixed
	 */
	public function classic_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return $fields;
		}

		$fields['billing'] = isset( $fields['billing'] ) && is_array( $fields['billing'] ) ? $fields['billing'] : array();

		$fields['billing'][ self::CLASSIC_COUNTRY ] = array(
			'type'     => 'select',
			'label'    => __( 'Phone country', 'pixalink-loyalty-for-woocommerce' ),
			'options'  => $this->countries->options(),
			'required' => $this->phone_required(),
			'class'    => array( 'form-row-first', 'pixalink-loyalty-phone-country' ),
			'priority' => 101,
		);

		$fields['billing'][ self::CLASSIC_PHONE ] = array(
			'type'         => 'tel',
			'label'        => __( 'Mobile number for loyalty points', 'pixalink-loyalty-for-woocommerce' ),
			'required'     => $this->phone_required(),
			'class'        => array( 'form-row-last', 'pixalink-loyalty-phone' ),
			'autocomplete' => 'tel-national',
			'priority'     => 102,
		);

		if ( $this->consent_enabled() ) {
			$fields['billing'][ self::CLASSIC_CONSENT ] = array(
				'type'     => 'checkbox',
				'label'    => esc_html( $this->consent_text->text( ConsentRecord::SOURCE_CHECKOUT ) ),
				'required' => false,
				'class'    => array( 'form-row-wide', 'pixalink-loyalty-consent' ),
				'priority' => 103,
			);
		}

		return $fields;
	}

	/**
	 * `woocommerce_checkout_get_value` callback: prefill phone fields, never the consent box.
	 *
	 * @param mixed $value Value (null when not handled).
	 * @param mixed $input Field key.
	 * @return mixed
	 */
	public function classic_value( $value, $input ) {
		if ( null !== $value ) {
			return $value;
		}

		$user_id = get_current_user_id();

		switch ( $input ) {
			case self::CLASSIC_COUNTRY:
				$saved = UserMeta::phone( $user_id );
				return null !== $saved && $this->countries->is_supported( $saved[1] ) ? $saved[1] : $this->countries->default_country();
			case self::CLASSIC_PHONE:
				return $this->saved_national( $user_id ) ?? '';
			case self::CLASSIC_CONSENT:
				return '';
			default:
				return $value;
		}
	}

	/**
	 * `woocommerce_after_checkout_validation` callback.
	 *
	 * @param mixed $data   Posted data.
	 * @param mixed $errors WP_Error.
	 * @return void
	 */
	public function validate_classic( $data, $errors ): void {
		if ( ! is_array( $data ) || ! $errors instanceof \WP_Error ) {
			return;
		}

		$message = $this->phone_error( self::string( $data[ self::CLASSIC_PHONE ] ?? '' ), self::string( $data[ self::CLASSIC_COUNTRY ] ?? '' ) );
		if ( null !== $message ) {
			$errors->add( 'pixalink_loyalty_phone_validation', $message, array( 'id' => self::CLASSIC_PHONE ) );
		}
	}

	/**
	 * `woocommerce_checkout_create_order` callback (order not saved yet).
	 *
	 * @param mixed $order Order.
	 * @param mixed $data  Posted data.
	 * @return void
	 */
	public function save_classic( $order, $data ): void {
		if ( ! $order instanceof \WC_Order || ! is_array( $data ) ) {
			return;
		}

		$phone   = $this->normalizer->normalize( self::string( $data[ self::CLASSIC_PHONE ] ?? '' ), self::string( $data[ self::CLASSIC_COUNTRY ] ?? '' ) );
		$ticked  = $this->consent_enabled() && ! empty( $data[ self::CLASSIC_CONSENT ] );
		$consent = $ticked ? ConsentRecord::given( true, ConsentRecord::SOURCE_CHECKOUT, $this->consent_text->text( ConsentRecord::SOURCE_CHECKOUT ), time() ) : null;

		$this->identity->record( $order, $phone, $consent, ShopperContext::current( $this->guests ) );
	}

	/**
	 * `woocommerce_checkout_order_processed` callback.
	 *
	 * @param mixed $order_id    Order id.
	 * @param mixed $posted_data Posted data.
	 * @param mixed $order       Order.
	 * @return void
	 */
	public function after_classic_order( $order_id, $posted_data = array(), $order = null ): void {
		unset( $posted_data );
		$order = $order instanceof \WC_Order ? $order : wc_get_order( is_numeric( $order_id ) ? (int) $order_id : 0 );
		if ( $order instanceof \WC_Order ) {
			$this->identity->after_order( $order );
		}
	}

	/**
	 * Why a typed phone number is not acceptable, or null when it is (or when it was left empty).
	 *
	 * @param string $phone   Typed number.
	 * @param string $country Chosen country.
	 * @return string|null
	 */
	public function phone_error( string $phone, string $country ): ?string {
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
	 * The account's Pixalink phone in national form, when it has one.
	 *
	 * @param int $user_id User id.
	 * @return string|null
	 */
	private function saved_national( int $user_id ): ?string {
		$saved = UserMeta::phone( $user_id );
		if ( null === $saved ) {
			return null;
		}

		return $this->normalizer->normalize( $saved[0], $saved[1] )?->national;
	}

	/**
	 * Remove the consent answer WooCommerce copied into the shopper's profile and session customer.
	 *
	 * @param \WC_Order $order Order.
	 * @return void
	 */
	private static function forget_blocks_consent( \WC_Order $order ): void {
		$key = BlocksCheckoutFields::OTHER_FIELDS_PREFIX . self::BLOCKS_CONSENT;

		// The session customer (what the next checkout reads) and, for accounts, the stored profile (user meta).
		$customers = array();
		if ( function_exists( 'WC' ) && WC()->customer instanceof \WC_Customer ) {
			$customers[] = WC()->customer;
		}
		if ( $order->get_customer_id() > 0 ) {
			$customers[] = new \WC_Customer( (int) $order->get_customer_id() );
		}

		foreach ( $customers as $customer ) {
			if ( $customer->meta_exists( $key ) ) {
				$customer->delete_meta_data( $key );
				$customer->save();
			}
		}
	}

	/**
	 * WooCommerce's additional checkout fields service.
	 *
	 * @return BlocksCheckoutFields|null
	 */
	private static function blocks_service(): ?BlocksCheckoutFields {
		if ( ! class_exists( BlocksPackage::class ) || ! class_exists( BlocksCheckoutFields::class ) ) {
			return null;
		}

		$service = BlocksPackage::container()->get( BlocksCheckoutFields::class );

		return $service instanceof BlocksCheckoutFields ? $service : null;
	}

	/**
	 * A scalar as a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}

<?php
/**
 * My Account › Loyalty before the phone number is verified.
 *
 * Override by copying to yourtheme/pixalink-loyalty/myaccount/link-account.php. Keep the `data-pixalink-*` attributes:
 * build/account.js drives the one-time code dialog through them.
 *
 * @package Pixalink\Loyalty
 * @version 0.3.0
 *
 * @var array<string,string> $countries  Phone country options (ISO => label).
 * @var string               $country    Selected country.
 * @var string               $national   Saved number in national form ('' when none).
 * @var bool                 $pending    Whether a code was sent in the last 15 minutes.
 * @var bool                 $branded    Whether the member portal's colours are used.
 * @var string|null          $portal_url The member portal, signed out (null when unknown).
 */

defined( 'ABSPATH' ) || exit;

$pixalink_loyalty_button = 'button pixalink-loyalty-button ' . ( function_exists( 'wc_wp_theme_get_element_class_name' ) ? (string) wc_wp_theme_get_element_class_name( 'button' ) : '' );
?>
<div class="pixalink-loyalty-link<?php echo ! empty( $branded ) ? ' is-branded' : ''; ?>" data-pixalink-link>
	<div class="pixalink-loyalty-link__panel">
		<h3 class="pixalink-loyalty-link__title"><?php esc_html_e( 'Verify your phone to see your points', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
		<p class="pixalink-loyalty-link__intro"><?php esc_html_e( 'Enter the mobile number you use for our loyalty programme. We will send you a one-time code to confirm it is yours.', 'pixalink-loyalty-for-woocommerce' ); ?></p>

		<form class="pixalink-loyalty-link__form" data-pixalink-link-form novalidate>
			<p class="form-row pixalink-loyalty-link__field pixalink-loyalty-link__field--country">
				<label for="pixalink-loyalty-link-country"><?php esc_html_e( 'Phone country', 'pixalink-loyalty-for-woocommerce' ); ?></label>
				<select id="pixalink-loyalty-link-country" name="phone_country" autocomplete="tel-country-code">
					<?php foreach ( $countries as $pixalink_loyalty_iso => $pixalink_loyalty_label ) : ?>
						<option value="<?php echo esc_attr( $pixalink_loyalty_iso ); ?>" <?php selected( $country, $pixalink_loyalty_iso ); ?>><?php echo esc_html( $pixalink_loyalty_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-row pixalink-loyalty-link__field pixalink-loyalty-link__field--phone">
				<label for="pixalink-loyalty-link-phone"><?php esc_html_e( 'Mobile number', 'pixalink-loyalty-for-woocommerce' ); ?></label>
				<input type="tel" class="input-text" id="pixalink-loyalty-link-phone" name="phone" value="<?php echo esc_attr( $national ); ?>" autocomplete="tel-national" inputmode="tel" required />
			</p>
			<p class="pixalink-loyalty-link__submit">
				<button type="submit" class="<?php echo esc_attr( trim( $pixalink_loyalty_button ) ); ?>"><?php esc_html_e( 'Send code', 'pixalink-loyalty-for-woocommerce' ); ?></button>
			</p>
			<p class="pixalink-loyalty-link__message" data-pixalink-message role="status" aria-live="polite"></p>
		</form>
	</div>

	<?php if ( ! empty( $portal_url ) ) : ?>
		<p class="pixalink-loyalty-link__portal">
			<?php esc_html_e( 'You can also sign in to our member portal with this number.', 'pixalink-loyalty-for-woocommerce' ); ?>
			<a href="<?php echo esc_url( (string) $portal_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open member portal', 'pixalink-loyalty-for-woocommerce' ); ?></a>
		</p>
	<?php endif; ?>

	<dialog class="pixalink-loyalty-link__dialog" data-pixalink-otp-dialog<?php echo $pending ? ' data-pixalink-pending' : ''; ?> aria-labelledby="pixalink-loyalty-otp-title">
		<form method="dialog" data-pixalink-otp-form novalidate>
			<h3 class="pixalink-loyalty-link__title" id="pixalink-loyalty-otp-title"><?php esc_html_e( 'Enter your code', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
			<p class="pixalink-loyalty-link__intro" data-pixalink-otp-sent></p>
			<p class="form-row pixalink-loyalty-link__field">
				<label for="pixalink-loyalty-otp-code"><?php esc_html_e( 'One-time code', 'pixalink-loyalty-for-woocommerce' ); ?></label>
				<input type="text" class="input-text pixalink-loyalty-link__code" id="pixalink-loyalty-otp-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,8}" maxlength="8" required />
			</p>
			<p class="pixalink-loyalty-link__message" data-pixalink-otp-message role="alert" aria-live="assertive"></p>
			<p class="pixalink-loyalty-link__dialog-actions">
				<button type="submit" class="<?php echo esc_attr( trim( $pixalink_loyalty_button ) ); ?>" value="verify"><?php esc_html_e( 'Verify', 'pixalink-loyalty-for-woocommerce' ); ?></button>
				<button type="button" class="pixalink-loyalty-link__text-button" data-pixalink-otp-resend disabled><?php esc_html_e( 'Send a new code', 'pixalink-loyalty-for-woocommerce' ); ?></button>
				<button type="button" class="pixalink-loyalty-link__text-button" data-pixalink-otp-close><?php esc_html_e( 'Cancel', 'pixalink-loyalty-for-woocommerce' ); ?></button>
			</p>
		</form>
	</dialog>

	<noscript><p class="woocommerce-info"><?php esc_html_e( 'Turn on JavaScript in your browser to verify your phone number.', 'pixalink-loyalty-for-woocommerce' ); ?></p></noscript>
</div>

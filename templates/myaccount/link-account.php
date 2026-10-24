<?php
/**
 * My Account › Loyalty before the phone number is verified.
 *
 * Override by copying to yourtheme/pixalink-loyalty/myaccount/link-account.php. Keep the `data-pixalink-*` attributes:
 * build/account.js drives the one-time code dialog through them.
 *
 * @package Pixalink\Loyalty
 * @version 0.1.0
 *
 * @var array<string,string> $countries Phone country options (ISO => label).
 * @var string               $country   Selected country.
 * @var string               $national  Saved number in national form ('' when none).
 * @var bool                 $pending   Whether a code was sent in the last 15 minutes.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="pixalink-loyalty-link" data-pixalink-link>
	<h3><?php esc_html_e( 'Verify your phone to see your points', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Enter the mobile number you use for our loyalty programme. We will send you a one-time code to confirm it is yours.', 'pixalink-loyalty-for-woocommerce' ); ?></p>

	<form class="pixalink-loyalty-link__form" data-pixalink-link-form novalidate>
		<p class="form-row form-row-first">
			<label for="pixalink-loyalty-link-country"><?php esc_html_e( 'Phone country', 'pixalink-loyalty-for-woocommerce' ); ?></label>
			<select id="pixalink-loyalty-link-country" name="phone_country" autocomplete="tel-country-code">
				<?php foreach ( $countries as $pixalink_loyalty_iso => $pixalink_loyalty_label ) : ?>
					<option value="<?php echo esc_attr( $pixalink_loyalty_iso ); ?>" <?php selected( $country, $pixalink_loyalty_iso ); ?>><?php echo esc_html( $pixalink_loyalty_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="form-row form-row-last">
			<label for="pixalink-loyalty-link-phone"><?php esc_html_e( 'Mobile number', 'pixalink-loyalty-for-woocommerce' ); ?></label>
			<input type="tel" id="pixalink-loyalty-link-phone" name="phone" value="<?php echo esc_attr( $national ); ?>" autocomplete="tel-national" inputmode="tel" required />
		</p>
		<p class="form-row form-row-wide">
			<button type="submit" class="button"><?php esc_html_e( 'Send code', 'pixalink-loyalty-for-woocommerce' ); ?></button>
		</p>
		<p class="pixalink-loyalty-link__message" data-pixalink-message role="status" aria-live="polite"></p>
	</form>

	<dialog class="pixalink-loyalty-link__dialog" data-pixalink-otp-dialog<?php echo $pending ? ' data-pixalink-pending' : ''; ?> aria-labelledby="pixalink-loyalty-otp-title">
		<form method="dialog" data-pixalink-otp-form novalidate>
			<h3 id="pixalink-loyalty-otp-title"><?php esc_html_e( 'Enter your code', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
			<p data-pixalink-otp-sent></p>
			<p class="form-row form-row-wide">
				<label for="pixalink-loyalty-otp-code"><?php esc_html_e( 'One-time code', 'pixalink-loyalty-for-woocommerce' ); ?></label>
				<input type="text" id="pixalink-loyalty-otp-code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{4,8}" maxlength="8" required />
			</p>
			<p class="pixalink-loyalty-link__message" data-pixalink-otp-message role="alert" aria-live="assertive"></p>
			<p class="form-row form-row-wide">
				<button type="submit" class="button" value="verify"><?php esc_html_e( 'Verify', 'pixalink-loyalty-for-woocommerce' ); ?></button>
				<button type="button" class="button button-link" data-pixalink-otp-resend disabled><?php esc_html_e( 'Send a new code', 'pixalink-loyalty-for-woocommerce' ); ?></button>
				<button type="button" class="button button-link" data-pixalink-otp-close><?php esc_html_e( 'Cancel', 'pixalink-loyalty-for-woocommerce' ); ?></button>
			</p>
		</form>
	</dialog>

	<noscript><p class="woocommerce-info"><?php esc_html_e( 'Turn on JavaScript in your browser to verify your phone number.', 'pixalink-loyalty-for-woocommerce' ); ?></p></noscript>
</div>

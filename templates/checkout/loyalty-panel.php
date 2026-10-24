<?php
/**
 * Classic checkout loyalty panel.
 *
 * Override by copying this file to yourtheme/pixalink-loyalty/checkout/loyalty-panel.php. Keep the
 * `data-pixalink-*` attributes: assets/js/src/classic-checkout reads them.
 *
 * @package Pixalink\Loyalty
 * @version 0.2.0
 *
 * @var array<string,mixed>  $data    Panel data ({@see \Pixalink\Loyalty\Checkout\PanelData::build()}).
 * @var array<string,string> $strings Translated strings ({@see \Pixalink\Loyalty\Checkout\Strings::all()}).
 */

defined( 'ABSPATH' ) || exit;

$pixalink_loyalty_points   = is_array( $data['points'] ?? null ) ? $data['points'] : null;
$pixalink_loyalty_credits  = is_array( $data['credits'] ?? null ) ? $data['credits'] : null;
$pixalink_loyalty_vouchers = is_array( $data['vouchers'] ?? null ) ? $data['vouchers'] : array();
$pixalink_loyalty_stale    = ! empty( $data['quote']['stale'] );
$pixalink_loyalty_balances = array();
if ( null !== $pixalink_loyalty_points ) {
	$pixalink_loyalty_balances[] = (string) $pixalink_loyalty_points['balance_label'];
}
if ( null !== $pixalink_loyalty_credits ) {
	$pixalink_loyalty_balances[] = (string) $pixalink_loyalty_credits['balance_label'];
}
?>
<div class="pixalink-loyalty-panel" data-pixalink-panel data-verified="<?php echo esc_attr( ! empty( $data['verified'] ) ? '1' : '0' ); ?>" data-stale="<?php echo esc_attr( $pixalink_loyalty_stale ? '1' : '0' ); ?>" data-testid="pixalink-loyalty-panel">
	<h3 class="pixalink-loyalty-panel__title"><?php echo esc_html( $strings['title'] ); ?></h3>

	<?php if ( empty( $data['verified'] ) ) : ?>
		<p class="pixalink-loyalty-panel__prompt">
			<?php echo esc_html( (string) $data['verify_prompt'] ); ?>
			<?php if ( '' !== (string) $data['verify_url'] ) : ?>
				<a href="<?php echo esc_url( (string) $data['verify_url'] ); ?>"><?php echo esc_html( $strings['verify_link'] ); ?></a>
			<?php endif; ?>
		</p>
	<?php elseif ( empty( $data['quote']['available'] ) ) : ?>
		<p class="pixalink-loyalty-panel__status" role="status">
			<?php echo esc_html( '' !== (string) $data['quote']['error'] ? $strings['quote_error'] : $strings['loading'] ); ?>
		</p>
	<?php else : ?>
		<?php if ( array() !== $pixalink_loyalty_balances ) : ?>
			<p class="pixalink-loyalty-panel__balance">
				<?php echo esc_html( sprintf( $strings['balance'], implode( $strings['separator'], $pixalink_loyalty_balances ) ) ); ?>
			</p>
		<?php endif; ?>

		<?php if ( array() !== $pixalink_loyalty_vouchers ) : ?>
			<div class="pixalink-loyalty-panel__section pixalink-loyalty-vouchers">
				<h4><?php echo esc_html( $strings['vouchers_heading'] ); ?></h4>
				<ul class="pixalink-loyalty-vouchers__list">
					<?php foreach ( $pixalink_loyalty_vouchers as $pixalink_loyalty_voucher ) : ?>
						<li class="pixalink-loyalty-voucher<?php echo $pixalink_loyalty_voucher['applied'] ? ' is-applied' : ''; ?><?php echo $pixalink_loyalty_voucher['usable'] ? '' : ' is-unavailable'; ?>" data-code="<?php echo esc_attr( (string) $pixalink_loyalty_voucher['code'] ); ?>">
							<?php if ( '' !== (string) $pixalink_loyalty_voucher['image_url'] ) : ?>
								<img class="pixalink-loyalty-voucher__image" src="<?php echo esc_url( (string) $pixalink_loyalty_voucher['image_url'] ); ?>" alt="" loading="lazy" width="48" height="48" />
							<?php endif; ?>
							<span class="pixalink-loyalty-voucher__body">
								<strong class="pixalink-loyalty-voucher__title"><?php echo esc_html( (string) $pixalink_loyalty_voucher['title'] ); ?></strong>
								<?php if ( '' !== (string) $pixalink_loyalty_voucher['description'] ) : ?>
									<span class="pixalink-loyalty-voucher__description"><?php echo esc_html( (string) $pixalink_loyalty_voucher['description'] ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== (string) $pixalink_loyalty_voucher['expires_label'] ) : ?>
									<span class="pixalink-loyalty-voucher__expiry"><?php echo esc_html( (string) $pixalink_loyalty_voucher['expires_label'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! $pixalink_loyalty_voucher['usable'] && '' !== (string) $pixalink_loyalty_voucher['reason'] ) : ?>
									<span class="pixalink-loyalty-voucher__reason"><?php echo esc_html( (string) $pixalink_loyalty_voucher['reason'] ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( $pixalink_loyalty_voucher['applied'] ) : ?>
								<button type="button" class="button pixalink-loyalty-button" data-pixalink-action="remove_voucher" data-code="<?php echo esc_attr( (string) $pixalink_loyalty_voucher['code'] ); ?>" aria-label="<?php echo esc_attr( sprintf( $strings['voucher_remove_a'], (string) $pixalink_loyalty_voucher['title'] ) ); ?>"><?php echo esc_html( $strings['voucher_remove'] ); ?></button>
							<?php elseif ( $pixalink_loyalty_voucher['usable'] ) : ?>
								<button type="button" class="button pixalink-loyalty-button" data-pixalink-action="apply_voucher" data-code="<?php echo esc_attr( (string) $pixalink_loyalty_voucher['code'] ); ?>" aria-label="<?php echo esc_attr( sprintf( $strings['voucher_apply_a'], (string) $pixalink_loyalty_voucher['title'] ) ); ?>"><?php echo esc_html( $strings['voucher_apply'] ); ?></button>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( null !== $pixalink_loyalty_points ) : ?>
			<div class="pixalink-loyalty-panel__section pixalink-loyalty-points" data-pixalink-points data-value-per-point="<?php echo esc_attr( (string) $pixalink_loyalty_points['value_per_point'] ); ?>">
				<h4><?php echo esc_html( $strings['points_heading'] ); ?></h4>
				<?php if ( $pixalink_loyalty_points['usable'] ) : ?>
					<?php $pixalink_loyalty_value = $pixalink_loyalty_points['applied'] > 0 ? (int) $pixalink_loyalty_points['applied'] : (int) $pixalink_loyalty_points['max']; ?>
					<label class="pixalink-loyalty-points__label" for="pixalink-loyalty-points-input"><?php echo esc_html( $strings['points_label'] ); ?></label>
					<div class="pixalink-loyalty-points__controls">
						<input type="range" class="pixalink-loyalty-points__slider" data-pixalink-points-slider min="0" max="<?php echo esc_attr( (string) $pixalink_loyalty_points['max'] ); ?>" step="<?php echo esc_attr( (string) $pixalink_loyalty_points['step'] ); ?>" value="<?php echo esc_attr( (string) $pixalink_loyalty_value ); ?>" aria-label="<?php echo esc_attr( $strings['points_label'] ); ?>" />
						<input type="number" id="pixalink-loyalty-points-input" class="input-text pixalink-loyalty-points__input" data-pixalink-points-input min="0" max="<?php echo esc_attr( (string) $pixalink_loyalty_points['max'] ); ?>" step="<?php echo esc_attr( (string) $pixalink_loyalty_points['step'] ); ?>" value="<?php echo esc_attr( (string) $pixalink_loyalty_value ); ?>" inputmode="numeric" />
					</div>
					<p class="pixalink-loyalty-points__preview" data-pixalink-points-preview aria-live="polite"></p>
					<button type="button" class="button pixalink-loyalty-button" data-pixalink-action="set_points"><?php echo esc_html( $strings['points_apply'] ); ?></button>
					<?php if ( $pixalink_loyalty_points['applied'] > 0 ) : ?>
						<button type="button" class="button pixalink-loyalty-button pixalink-loyalty-button--secondary" data-pixalink-action="remove_points"><?php echo esc_html( $strings['points_remove'] ); ?></button>
					<?php endif; ?>
				<?php else : ?>
					<p class="pixalink-loyalty-panel__note"><?php echo esc_html( sprintf( $strings['points_unusable'], number_format_i18n( (int) $pixalink_loyalty_points['min'] ) ) ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( null !== $pixalink_loyalty_credits && $pixalink_loyalty_credits['usable'] ) : ?>
			<div class="pixalink-loyalty-panel__section pixalink-loyalty-credits" data-pixalink-credits>
				<h4><?php echo esc_html( $strings['credits_heading'] ); ?></h4>
				<label class="pixalink-loyalty-credits__label" for="pixalink-loyalty-credits-input"><?php echo esc_html( $strings['credits_label'] ); ?></label>
				<input type="number" id="pixalink-loyalty-credits-input" class="input-text pixalink-loyalty-credits__input" data-pixalink-credits-input min="0" max="<?php echo esc_attr( wc_format_decimal( (int) $pixalink_loyalty_credits['max_cents'] / 100, 2 ) ); ?>" step="0.01" value="<?php echo esc_attr( wc_format_decimal( (int) ( $pixalink_loyalty_credits['applied_cents'] > 0 ? $pixalink_loyalty_credits['applied_cents'] : $pixalink_loyalty_credits['max_cents'] ) / 100, 2 ) ); ?>" inputmode="decimal" />
				<p class="pixalink-loyalty-panel__note"><?php echo esc_html( sprintf( $strings['credits_max'], number_format_i18n( (int) $pixalink_loyalty_credits['max_cents'] / 100, 2 ) ) ); ?></p>
				<button type="button" class="button pixalink-loyalty-button" data-pixalink-action="set_credits"><?php echo esc_html( $strings['credits_apply'] ); ?></button>
				<?php if ( $pixalink_loyalty_credits['applied_cents'] > 0 ) : ?>
					<button type="button" class="button pixalink-loyalty-button pixalink-loyalty-button--secondary" data-pixalink-action="remove_credits"><?php echo esc_html( $strings['credits_remove'] ); ?></button>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( null === $pixalink_loyalty_points && ( null === $pixalink_loyalty_credits || ! $pixalink_loyalty_credits['usable'] ) && array() === $pixalink_loyalty_vouchers ) : ?>
			<p class="pixalink-loyalty-panel__note"><?php echo esc_html( $strings['nothing'] ); ?></p>
		<?php endif; ?>

		<?php if ( is_int( $data['earn_preview'] ?? null ) && $data['earn_preview'] > 0 ) : ?>
			<p class="pixalink-loyalty-panel__earn"><?php echo esc_html( sprintf( $strings['earn_preview'], number_format_i18n( (int) $data['earn_preview'] ) ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<p class="pixalink-loyalty-panel__error" data-pixalink-error role="alert"></p>
</div>

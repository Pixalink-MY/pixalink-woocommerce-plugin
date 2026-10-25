<?php
/**
 * My Account › Dashboard: loyalty summary card.
 *
 * Override by copying to yourtheme/pixalink-loyalty/myaccount/dashboard-card.php.
 *
 * `$card` is null for a logged-in shopper who has not verified their phone yet: they get a prompt that opens the
 * Loyalty tab, where the one-time code flow lives (their portal link, if any, opens the portal signed out). Credits are
 * not shown here. A verified member's portal link points at the store's handler, which mints a single-use sign-in link
 * only when they click.
 *
 * @package Pixalink\Loyalty
 * @version 0.3.0
 *
 * @var array{points:int,expiring:array{points:int,expires_at:string}|null,tier:array{name:string,color:string|null,remaining:int|null,next_tier_name:string|null,percent:int|null}|null,vouchers_ready:int}|null $card
 * @var string      $title       Title of the Loyalty tab.
 * @var string      $loyalty_url URL of the Loyalty tab.
 * @var bool        $branded     Whether the member portal's colours are used.
 * @var string|null $logo_url    Member portal logo (null: show the store name).
 * @var string      $store_name  Store or portal name.
 * @var string|null $portal_url  "Open member portal" link (signed in for verified members, signed out otherwise).
 * @var string      $tier_style  Inline style carrying the tier colour ('' when none).
 */

defined( 'ABSPATH' ) || exit;

$pixalink_loyalty_button = function_exists( 'wc_wp_theme_get_element_class_name' ) ? (string) wc_wp_theme_get_element_class_name( 'button' ) : '';
?>
<section class="pixalink-loyalty-dashboard-card<?php echo ! empty( $branded ) ? ' is-branded' : ''; ?>" aria-labelledby="pixalink-loyalty-dashboard-card-title">
	<h2 id="pixalink-loyalty-dashboard-card-title" class="pixalink-loyalty-dashboard-card__title"><?php echo esc_html( $title ); ?></h2>

	<?php if ( null === $card ) : ?>
		<div class="pixalink-loyalty-dashboard-card__invite">
			<p class="pixalink-loyalty-dashboard-card__prompt"><?php esc_html_e( 'Link your Pixalink membership to see your points and vouchers.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
			<p class="pixalink-loyalty-dashboard-card__action">
				<a class="<?php echo esc_attr( trim( 'button pixalink-loyalty-button ' . $pixalink_loyalty_button ) ); ?>" href="<?php echo esc_url( $loyalty_url ); ?>"><?php esc_html_e( 'Link your membership', 'pixalink-loyalty-for-woocommerce' ); ?></a>
				<?php if ( ! empty( $portal_url ) ) : ?>
					<a class="pixalink-loyalty-dashboard-card__portal" href="<?php echo esc_url( (string) $portal_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open member portal', 'pixalink-loyalty-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php else : ?>
		<div class="pixalink-loyalty-card pixalink-loyalty-card--compact">
			<div class="pixalink-loyalty-card__top">
				<?php if ( ! empty( $logo_url ) ) : ?>
					<span class="pixalink-loyalty-card__logo"><img src="<?php echo esc_url( (string) $logo_url ); ?>" alt="<?php echo esc_attr( (string) $store_name ); ?>" height="24" loading="lazy" /></span>
				<?php else : ?>
					<span class="pixalink-loyalty-card__store"><?php echo esc_html( (string) $store_name ); ?></span>
				<?php endif; ?>
				<?php if ( null !== $card['tier'] ) : ?>
					<span class="pixalink-loyalty-tier"<?php echo '' !== (string) ( $tier_style ?? '' ) ? ' style="' . esc_attr( (string) $tier_style ) . '"' : ''; ?>>
						<span class="pixalink-loyalty-tier__dot" aria-hidden="true"></span>
						<?php echo esc_html( $card['tier']['name'] ); ?>
					</span>
				<?php endif; ?>
			</div>

			<p class="pixalink-loyalty-card__balance pixalink-loyalty-dashboard-card__points">
				<strong class="pixalink-loyalty-card__points pixalink-loyalty-dashboard-card__points-value"><?php echo esc_html( number_format_i18n( $card['points'] ) ); ?></strong>
				<span class="pixalink-loyalty-card__unit"><?php echo esc_html( _n( 'point', 'points', $card['points'], 'pixalink-loyalty-for-woocommerce' ) ); ?></span>
			</p>

			<?php if ( null !== $card['expiring'] || $card['vouchers_ready'] > 0 ) : ?>
				<div class="pixalink-loyalty-card__details">
					<?php if ( null !== $card['expiring'] ) : ?>
						<?php $pixalink_loyalty_expires_at = strtotime( $card['expiring']['expires_at'] ); ?>
						<p class="pixalink-loyalty-card__expiring pixalink-loyalty-dashboard-card__expiring">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of points, 2: expiry date. */
									_n( '%1$s point expires on %2$s.', '%1$s points expire on %2$s.', $card['expiring']['points'], 'pixalink-loyalty-for-woocommerce' ),
									number_format_i18n( $card['expiring']['points'] ),
									false === $pixalink_loyalty_expires_at ? '' : date_i18n( (string) get_option( 'date_format' ), $pixalink_loyalty_expires_at )
								)
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( $card['vouchers_ready'] > 0 ) : ?>
						<p class="pixalink-loyalty-card__vouchers pixalink-loyalty-dashboard-card__vouchers">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: number of vouchers. */
									_n( '%s voucher ready', '%s vouchers ready', $card['vouchers_ready'], 'pixalink-loyalty-for-woocommerce' ),
									number_format_i18n( $card['vouchers_ready'] )
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( null !== $card['tier'] && ( null !== $card['tier']['percent'] || null !== $card['tier']['next_tier_name'] ) ) : ?>
				<div class="pixalink-loyalty-card__progress">
					<?php if ( null !== $card['tier']['percent'] ) : ?>
						<div class="pixalink-loyalty-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $card['tier']['percent'] ); ?>" aria-label="<?php echo esc_attr( null !== $card['tier']['next_tier_name'] ? sprintf( /* translators: %s: next tier name. */ __( 'Progress to %s', 'pixalink-loyalty-for-woocommerce' ), $card['tier']['next_tier_name'] ) : __( 'Tier progress', 'pixalink-loyalty-for-woocommerce' ) ); ?>">
							<span class="pixalink-loyalty-progress__bar" style="inline-size: <?php echo esc_attr( (string) $card['tier']['percent'] ); ?>%"></span>
						</div>
					<?php endif; ?>
					<?php if ( null !== $card['tier']['remaining'] && null !== $card['tier']['next_tier_name'] ) : ?>
						<p class="pixalink-loyalty-progress__label pixalink-loyalty-dashboard-card__next-tier">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: amount left, 2: next tier name. */
									__( '%1$s more to reach %2$s.', 'pixalink-loyalty-for-woocommerce' ),
									number_format_i18n( $card['tier']['remaining'] ),
									$card['tier']['next_tier_name']
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<p class="pixalink-loyalty-card__actions pixalink-loyalty-dashboard-card__action">
				<a class="pixalink-loyalty-card__button" href="<?php echo esc_url( $loyalty_url ); ?>"><?php esc_html_e( 'View points and vouchers', 'pixalink-loyalty-for-woocommerce' ); ?></a>
				<?php if ( ! empty( $portal_url ) ) : ?>
					<a class="pixalink-loyalty-card__button pixalink-loyalty-card__button--quiet" href="<?php echo esc_url( (string) $portal_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open member portal', 'pixalink-loyalty-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>
</section>

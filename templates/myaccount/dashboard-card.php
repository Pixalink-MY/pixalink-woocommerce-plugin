<?php
/**
 * My Account › Dashboard: loyalty summary card.
 *
 * Override by copying to yourtheme/pixalink-loyalty/myaccount/dashboard-card.php.
 *
 * `$card` is null for a logged-in shopper who has not verified their phone yet: they get a prompt that opens the
 * Loyalty tab, where the one-time code flow lives. Credits are not shown here.
 *
 * @package Pixalink\Loyalty
 * @version 0.1.0
 *
 * @var array{points:int,expiring:array{points:int,expires_at:string}|null,tier:array{name:string,remaining:int|null,next_tier_name:string|null,percent:int|null}|null,vouchers_ready:int}|null $card
 * @var string $title       Title of the Loyalty tab.
 * @var string $loyalty_url URL of the Loyalty tab.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="pixalink-loyalty-dashboard-card" aria-labelledby="pixalink-loyalty-dashboard-card-title">
	<h2 id="pixalink-loyalty-dashboard-card-title" class="pixalink-loyalty-dashboard-card__title"><?php echo esc_html( $title ); ?></h2>

	<?php if ( null === $card ) : ?>
		<p class="pixalink-loyalty-dashboard-card__prompt"><?php esc_html_e( 'Link your Pixalink membership to see your points and vouchers.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
		<p class="pixalink-loyalty-dashboard-card__action">
			<a class="button" href="<?php echo esc_url( $loyalty_url ); ?>"><?php esc_html_e( 'Link your membership', 'pixalink-loyalty-for-woocommerce' ); ?></a>
		</p>
	<?php else : ?>
		<p class="pixalink-loyalty-dashboard-card__points">
			<strong class="pixalink-loyalty-dashboard-card__points-value"><?php echo esc_html( number_format_i18n( $card['points'] ) ); ?></strong>
			<?php echo esc_html( _n( 'point', 'points', $card['points'], 'pixalink-loyalty-for-woocommerce' ) ); ?>
		</p>

		<?php if ( null !== $card['expiring'] ) : ?>
			<?php $pixalink_loyalty_expires_at = strtotime( $card['expiring']['expires_at'] ); ?>
			<p class="pixalink-loyalty-dashboard-card__expiring">
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

		<?php if ( null !== $card['tier'] ) : ?>
			<p class="pixalink-loyalty-dashboard-card__tier">
				<strong><?php echo esc_html( $card['tier']['name'] ); ?></strong>
				<?php if ( null !== $card['tier']['remaining'] && null !== $card['tier']['next_tier_name'] ) : ?>
					<span class="pixalink-loyalty-dashboard-card__next-tier">
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
					</span>
				<?php endif; ?>
			</p>
			<?php if ( null !== $card['tier']['percent'] ) : ?>
				<progress class="pixalink-loyalty-dashboard-card__progress" max="100" value="<?php echo esc_attr( (string) $card['tier']['percent'] ); ?>"><?php echo esc_html( $card['tier']['percent'] . '%' ); ?></progress>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $card['vouchers_ready'] > 0 ) : ?>
			<p class="pixalink-loyalty-dashboard-card__vouchers">
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

		<p class="pixalink-loyalty-dashboard-card__action">
			<a class="button" href="<?php echo esc_url( $loyalty_url ); ?>"><?php esc_html_e( 'View points and vouchers', 'pixalink-loyalty-for-woocommerce' ); ?></a>
		</p>
	<?php endif; ?>
</section>

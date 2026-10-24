<?php
/**
 * My Account › Loyalty for a verified member.
 *
 * Override by copying to yourtheme/pixalink-loyalty/myaccount/loyalty.php.
 *
 * Credits are a closed-loop balance: they are shown as credits, never as money.
 *
 * @package Pixalink\Loyalty
 * @version 0.1.0
 *
 * @var array{points_balance:int,points_expiring:list<array{points:int,expires_at:string}>,credits_cents:int|null,tier:array{name:string,current:int|null,next:int|null,remaining:int|null,next_tier_name:string|null,percent:int|null}|null,vouchers:list<array{id:string,code:string,title:string,description:string|null,image_url:string|null,expires_at:string|null,redeemable_here:bool}>,memberships:list<array{id:string,name:string,status:string|null,expires_at:string|null}>,activity:list<array{type:string,description:string,points:int|null,occurred_at:string}>,qr:string|null,portal_url:string|null}|null $summary
 * @var bool   $unavailable  Whether Pixalink could not be reached.
 * @var string $qr_markup    Member QR markup ('' when hidden).
 * @var string $checkout_url Checkout URL.
 */

defined( 'ABSPATH' ) || exit;

$pixalink_loyalty_date = static function ( ?string $iso ): string {
	$timestamp = null === $iso ? false : strtotime( $iso );

	return false === $timestamp ? '' : date_i18n( (string) get_option( 'date_format' ), $timestamp );
};
?>
<div class="pixalink-loyalty-account">
	<?php if ( $unavailable || null === $summary ) : ?>
		<p class="pixalink-loyalty-account__notice woocommerce-info"><?php esc_html_e( 'Your points are temporarily unavailable. Please check again in a few minutes.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
	<?php else : ?>
		<section class="pixalink-loyalty-account__balances">
			<p class="pixalink-loyalty-account__points">
				<strong class="pixalink-loyalty-account__points-value"><?php echo esc_html( number_format_i18n( $summary['points_balance'] ) ); ?></strong>
				<?php echo esc_html( _n( 'point', 'points', $summary['points_balance'], 'pixalink-loyalty-for-woocommerce' ) ); ?>
			</p>

			<?php foreach ( $summary['points_expiring'] as $pixalink_loyalty_expiring ) : ?>
				<p class="pixalink-loyalty-account__expiring">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of points, 2: expiry date. */
							_n( '%1$s point expires on %2$s.', '%1$s points expire on %2$s.', $pixalink_loyalty_expiring['points'], 'pixalink-loyalty-for-woocommerce' ),
							number_format_i18n( $pixalink_loyalty_expiring['points'] ),
							$pixalink_loyalty_date( $pixalink_loyalty_expiring['expires_at'] )
						)
					);
					?>
				</p>
			<?php endforeach; ?>

			<?php if ( null !== $summary['credits_cents'] ) : ?>
				<p class="pixalink-loyalty-account__credits">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: credit balance such as 15.00. */
							__( 'Credits: %s', 'pixalink-loyalty-for-woocommerce' ),
							number_format_i18n( $summary['credits_cents'] / 100, 2 )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</section>

		<?php if ( null !== $summary['tier'] ) : ?>
			<section class="pixalink-loyalty-account__tier">
				<h3><?php echo esc_html( $summary['tier']['name'] ); ?></h3>
				<?php if ( null !== $summary['tier']['percent'] ) : ?>
					<progress class="pixalink-loyalty-account__progress" max="100" value="<?php echo esc_attr( (string) $summary['tier']['percent'] ); ?>"><?php echo esc_html( $summary['tier']['percent'] . '%' ); ?></progress>
				<?php endif; ?>
				<?php if ( null !== $summary['tier']['remaining'] && null !== $summary['tier']['next_tier_name'] ) : ?>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: amount left, 2: next tier name. */
								__( '%1$s more to reach %2$s.', 'pixalink-loyalty-for-woocommerce' ),
								number_format_i18n( $summary['tier']['remaining'] ),
								$summary['tier']['next_tier_name']
							)
						);
						?>
					</p>
				<?php endif; ?>
			</section>
		<?php endif; ?>

		<?php if ( '' !== $qr_markup ) : ?>
			<section class="pixalink-loyalty-account__qr">
				<h3><?php esc_html_e( 'Your member code', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
				<p><?php esc_html_e( 'Show this code at our outlets to earn and use your points.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
				<?php echo $qr_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by QrService::markup(). ?>
			</section>
		<?php endif; ?>

		<section class="pixalink-loyalty-account__vouchers">
			<h3><?php esc_html_e( 'Your vouchers', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
			<?php if ( array() === $summary['vouchers'] ) : ?>
				<p><?php esc_html_e( 'You have no vouchers right now.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
			<?php else : ?>
				<ul class="pixalink-loyalty-vouchers">
					<?php foreach ( $summary['vouchers'] as $pixalink_loyalty_voucher ) : ?>
						<li class="pixalink-loyalty-voucher <?php echo esc_attr( $pixalink_loyalty_voucher['redeemable_here'] ? 'is-redeemable' : 'is-not-redeemable' ); ?>">
							<?php if ( null !== $pixalink_loyalty_voucher['image_url'] ) : ?>
								<img class="pixalink-loyalty-voucher__image" src="<?php echo esc_url( $pixalink_loyalty_voucher['image_url'] ); ?>" alt="" loading="lazy" width="64" height="64" />
							<?php endif; ?>
							<strong class="pixalink-loyalty-voucher__title"><?php echo esc_html( $pixalink_loyalty_voucher['title'] ); ?></strong>
							<?php if ( null !== $pixalink_loyalty_voucher['description'] ) : ?>
								<span class="pixalink-loyalty-voucher__description"><?php echo esc_html( $pixalink_loyalty_voucher['description'] ); ?></span>
							<?php endif; ?>
							<span class="pixalink-loyalty-voucher__expiry">
								<?php
								echo esc_html(
									null === $pixalink_loyalty_voucher['expires_at']
										? __( 'No expiry date', 'pixalink-loyalty-for-woocommerce' )
										/* translators: %s: expiry date. */
										: sprintf( __( 'Valid until %s', 'pixalink-loyalty-for-woocommerce' ), $pixalink_loyalty_date( $pixalink_loyalty_voucher['expires_at'] ) )
								);
								?>
							</span>
							<span class="pixalink-loyalty-voucher__hint">
								<?php if ( $pixalink_loyalty_voucher['redeemable_here'] ) : ?>
									<?php
									echo wp_kses(
										sprintf(
											/* translators: %s: checkout URL. */
											__( 'Use it at <a href="%s">checkout</a>.', 'pixalink-loyalty-for-woocommerce' ),
											esc_url( $checkout_url )
										),
										array( 'a' => array( 'href' => array() ) )
									);
									?>
								<?php else : ?>
									<?php esc_html_e( 'Not available in this online store.', 'pixalink-loyalty-for-woocommerce' ); ?>
								<?php endif; ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<?php if ( array() !== $summary['memberships'] ) : ?>
			<section class="pixalink-loyalty-account__memberships">
				<h3><?php esc_html_e( 'Memberships', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
				<ul>
					<?php foreach ( $summary['memberships'] as $pixalink_loyalty_membership ) : ?>
						<li>
							<strong><?php echo esc_html( $pixalink_loyalty_membership['name'] ); ?></strong>
							<?php
							if ( null !== $pixalink_loyalty_membership['expires_at'] ) {
								/* translators: %s: expiry date. */
								echo ' ' . esc_html( sprintf( __( 'until %s', 'pixalink-loyalty-for-woocommerce' ), $pixalink_loyalty_date( $pixalink_loyalty_membership['expires_at'] ) ) );
							}
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<?php if ( array() !== $summary['activity'] ) : ?>
			<section class="pixalink-loyalty-account__activity">
				<h3><?php esc_html_e( 'Recent activity', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
				<table class="shop_table">
					<tbody>
						<?php foreach ( $summary['activity'] as $pixalink_loyalty_row ) : ?>
							<tr>
								<td><?php echo esc_html( $pixalink_loyalty_date( $pixalink_loyalty_row['occurred_at'] ) ); ?></td>
								<td><?php echo esc_html( $pixalink_loyalty_row['description'] ); ?></td>
								<td><?php echo null === $pixalink_loyalty_row['points'] ? '' : esc_html( ( $pixalink_loyalty_row['points'] > 0 ? '+' : '' ) . number_format_i18n( $pixalink_loyalty_row['points'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>

		<?php if ( null !== $summary['portal_url'] ) : ?>
			<p class="pixalink-loyalty-account__portal">
				<a class="button" href="<?php echo esc_url( $summary['portal_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open your member page', 'pixalink-loyalty-for-woocommerce' ); ?></a>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>

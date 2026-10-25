<?php
/**
 * My Account › Loyalty for a verified member.
 *
 * Override by copying to yourtheme/pixalink-loyalty/myaccount/loyalty.php.
 *
 * Credits are a closed-loop balance: they are shown as credits, never as money. Portal links point at the store's own
 * handler, which asks Pixalink for a single-use sign-in link only when the member clicks; never print a sign-in link
 * into this page.
 *
 * Colours come from CSS custom properties on `.pixalink-loyalty-account` (`--pxl-primary`, `--pxl-on-primary`,
 * `--pxl-ink`, `--pxl-accent`, `--pxl-tier`); `is-branded` is present while the member portal's colours are used.
 *
 * @package Pixalink\Loyalty
 * @version 0.3.0
 *
 * @var array{points_balance:int,points_expiring:list<array{points:int,expires_at:string}>,credits_cents:int|null,tier:array{name:string,color:string|null,current:int|null,next:int|null,remaining:int|null,next_tier_name:string|null,percent:int|null}|null,vouchers:list<array{id:string,code:string,title:string,description:string|null,image_url:string|null,expires_at:string|null,redeemable_here:bool}>,memberships:list<array{id:string,name:string,status:string|null,expires_at:string|null}>,activity:list<array{type:string,description:string,points:int|null,occurred_at:string}>,qr:string|null,portal_url:string|null}|null $summary
 * @var bool                 $unavailable  Whether Pixalink could not be reached.
 * @var string               $qr_markup    Member QR markup ('' when hidden).
 * @var string               $checkout_url Checkout URL.
 * @var bool                 $branded      Whether the member portal's colours are used.
 * @var string|null          $logo_url     Member portal logo (null: show the store name).
 * @var string               $store_name   Store or portal name.
 * @var string               $member_name  The member's name ('' when unknown).
 * @var array<string,string> $portal_links Portal page => link that opens it signed in (rewards, points, credits).
 * @var string               $tier_style   Inline style carrying the tier colour ('' when none).
 * @var array{points:int,expires_at:string}|null $expiring The soonest points to expire.
 */

defined( 'ABSPATH' ) || exit;

$pixalink_loyalty_date = static function ( ?string $iso ): string {
	$timestamp = null === $iso ? false : strtotime( $iso );

	return false === $timestamp ? '' : date_i18n( (string) get_option( 'date_format' ), $timestamp );
};

$pixalink_loyalty_button = function_exists( 'wc_wp_theme_get_element_class_name' ) ? (string) wc_wp_theme_get_element_class_name( 'button' ) : '';
$pixalink_loyalty_links  = isset( $portal_links ) && is_array( $portal_links ) ? $portal_links : array();
?>
<div class="pixalink-loyalty-account<?php echo ! empty( $branded ) ? ' is-branded' : ''; ?>">
	<?php if ( $unavailable || null === $summary ) : ?>
		<p class="pixalink-loyalty-account__notice woocommerce-info"><?php esc_html_e( 'Your points are temporarily unavailable. Please check again in a few minutes.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
	<?php else : ?>
		<?php
		$pixalink_loyalty_tier     = $summary['tier'];
		$pixalink_loyalty_expiring = isset( $expiring ) && is_array( $expiring ) ? $expiring : ( $summary['points_expiring'][0] ?? null );
		?>
		<section class="pixalink-loyalty-card" aria-label="<?php esc_attr_e( 'Your membership', 'pixalink-loyalty-for-woocommerce' ); ?>">
			<div class="pixalink-loyalty-card__top">
				<?php if ( ! empty( $logo_url ) ) : ?>
					<span class="pixalink-loyalty-card__logo"><img src="<?php echo esc_url( (string) $logo_url ); ?>" alt="<?php echo esc_attr( (string) $store_name ); ?>" height="28" loading="lazy" /></span>
				<?php else : ?>
					<span class="pixalink-loyalty-card__store"><?php echo esc_html( (string) $store_name ); ?></span>
				<?php endif; ?>

				<?php if ( null !== $pixalink_loyalty_tier ) : ?>
					<span class="pixalink-loyalty-tier"<?php echo '' !== (string) ( $tier_style ?? '' ) ? ' style="' . esc_attr( (string) $tier_style ) . '"' : ''; ?>>
						<span class="pixalink-loyalty-tier__dot" aria-hidden="true"></span>
						<?php echo esc_html( $pixalink_loyalty_tier['name'] ); ?>
					</span>
				<?php endif; ?>
			</div>

			<p class="pixalink-loyalty-card__balance">
				<strong class="pixalink-loyalty-card__points"><?php echo esc_html( number_format_i18n( $summary['points_balance'] ) ); ?></strong>
				<span class="pixalink-loyalty-card__unit"><?php echo esc_html( _n( 'point', 'points', $summary['points_balance'], 'pixalink-loyalty-for-woocommerce' ) ); ?></span>
			</p>

			<?php if ( null !== $summary['credits_cents'] || null !== $pixalink_loyalty_expiring ) : ?>
				<div class="pixalink-loyalty-card__details">
					<?php if ( null !== $summary['credits_cents'] ) : ?>
						<p class="pixalink-loyalty-card__credits">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: credit balance such as 15.00 (credits are not money). */
									__( '%s credits', 'pixalink-loyalty-for-woocommerce' ),
									number_format_i18n( $summary['credits_cents'] / 100, 2 )
								)
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( null !== $pixalink_loyalty_expiring ) : ?>
						<p class="pixalink-loyalty-card__expiring">
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
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( null !== $pixalink_loyalty_tier && ( null !== $pixalink_loyalty_tier['percent'] || null !== $pixalink_loyalty_tier['next_tier_name'] ) ) : ?>
				<div class="pixalink-loyalty-card__progress">
					<?php if ( null !== $pixalink_loyalty_tier['percent'] ) : ?>
						<div class="pixalink-loyalty-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $pixalink_loyalty_tier['percent'] ); ?>" aria-label="<?php echo esc_attr( null !== $pixalink_loyalty_tier['next_tier_name'] ? sprintf( /* translators: %s: next tier name. */ __( 'Progress to %s', 'pixalink-loyalty-for-woocommerce' ), $pixalink_loyalty_tier['next_tier_name'] ) : __( 'Tier progress', 'pixalink-loyalty-for-woocommerce' ) ); ?>">
							<span class="pixalink-loyalty-progress__bar" style="inline-size: <?php echo esc_attr( (string) $pixalink_loyalty_tier['percent'] ); ?>%"></span>
						</div>
					<?php endif; ?>
					<?php if ( null !== $pixalink_loyalty_tier['remaining'] && null !== $pixalink_loyalty_tier['next_tier_name'] ) : ?>
						<p class="pixalink-loyalty-progress__label">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: amount left, 2: next tier name. */
									__( '%1$s more to reach %2$s.', 'pixalink-loyalty-for-woocommerce' ),
									number_format_i18n( $pixalink_loyalty_tier['remaining'] ),
									$pixalink_loyalty_tier['next_tier_name']
								)
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( '' !== (string) $member_name || '' !== $qr_markup ) : ?>
				<div class="pixalink-loyalty-card__footer">
					<span class="pixalink-loyalty-card__member"><?php echo esc_html( (string) $member_name ); ?></span>
					<?php if ( '' !== $qr_markup ) : ?>
						<button type="button" class="pixalink-loyalty-card__toggle" data-pixalink-code-toggle aria-expanded="false" aria-controls="pixalink-loyalty-member-code" data-label-show="<?php esc_attr_e( 'Show member code', 'pixalink-loyalty-for-woocommerce' ); ?>" data-label-hide="<?php esc_attr_e( 'Hide member code', 'pixalink-loyalty-for-woocommerce' ); ?>" hidden><?php esc_html_e( 'Show member code', 'pixalink-loyalty-for-woocommerce' ); ?></button>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $qr_markup ) : ?>
				<div class="pixalink-loyalty-card__code" id="pixalink-loyalty-member-code" data-pixalink-code>
					<?php echo $qr_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built and escaped by QrService::markup(). ?>
					<p class="pixalink-loyalty-card__code-hint"><?php esc_html_e( 'Show this code at our outlets to earn and use your points.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
				</div>
			<?php endif; ?>
		</section>

		<section class="pixalink-loyalty-section" aria-labelledby="pixalink-loyalty-vouchers-title">
			<div class="pixalink-loyalty-section__head">
				<h3 class="pixalink-loyalty-section__title" id="pixalink-loyalty-vouchers-title"><?php esc_html_e( 'Your vouchers', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
			</div>
			<?php if ( array() === $summary['vouchers'] ) : ?>
				<div class="pixalink-loyalty-empty">
					<p class="pixalink-loyalty-empty__title"><?php esc_html_e( 'No vouchers yet', 'pixalink-loyalty-for-woocommerce' ); ?></p>
					<p class="pixalink-loyalty-empty__text"><?php esc_html_e( 'Vouchers you earn or claim with your points show up here, ready to use.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="pixalink-loyalty-account__vouchers">
					<?php foreach ( $summary['vouchers'] as $pixalink_loyalty_voucher ) : ?>
						<li class="pixalink-loyalty-account__voucher <?php echo esc_attr( $pixalink_loyalty_voucher['redeemable_here'] ? 'is-redeemable' : 'is-not-redeemable' ); ?>">
							<div class="pixalink-loyalty-account__voucher-media">
								<?php if ( null !== $pixalink_loyalty_voucher['image_url'] ) : ?>
									<img src="<?php echo esc_url( $pixalink_loyalty_voucher['image_url'] ); ?>" alt="" loading="lazy" width="320" height="140" />
								<?php else : ?>
									<svg class="pixalink-loyalty-account__voucher-glyph" viewBox="0 0 48 32" width="48" height="32" aria-hidden="true" focusable="false"><path d="M4 4h40a2 2 0 0 1 2 2v6a4 4 0 0 0 0 8v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6a4 4 0 0 0 0-8V6a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M31 6v20" stroke="currentColor" stroke-width="2" stroke-dasharray="2 3"/></svg>
								<?php endif; ?>
							</div>
							<div class="pixalink-loyalty-account__voucher-body">
								<strong class="pixalink-loyalty-account__voucher-title"><?php echo esc_html( $pixalink_loyalty_voucher['title'] ); ?></strong>
								<?php if ( null !== $pixalink_loyalty_voucher['description'] ) : ?>
									<span class="pixalink-loyalty-account__voucher-description"><?php echo esc_html( $pixalink_loyalty_voucher['description'] ); ?></span>
								<?php endif; ?>
								<span class="pixalink-loyalty-account__voucher-meta">
									<span class="pixalink-loyalty-account__voucher-expiry">
										<?php
										echo esc_html(
											null === $pixalink_loyalty_voucher['expires_at']
												? __( 'No expiry date', 'pixalink-loyalty-for-woocommerce' )
												/* translators: %s: expiry date. */
												: sprintf( __( 'Valid until %s', 'pixalink-loyalty-for-woocommerce' ), $pixalink_loyalty_date( $pixalink_loyalty_voucher['expires_at'] ) )
										);
										?>
									</span>
									<?php if ( $pixalink_loyalty_voucher['redeemable_here'] ) : ?>
										<a class="pixalink-loyalty-chip pixalink-loyalty-chip--online" href="<?php echo esc_url( $checkout_url ); ?>"><?php esc_html_e( 'Use at checkout', 'pixalink-loyalty-for-woocommerce' ); ?></a>
									<?php else : ?>
										<span class="pixalink-loyalty-chip pixalink-loyalty-chip--store"><?php esc_html_e( 'In store only', 'pixalink-loyalty-for-woocommerce' ); ?></span>
									<?php endif; ?>
								</span>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<?php if ( array() !== $summary['memberships'] ) : ?>
			<section class="pixalink-loyalty-section" aria-labelledby="pixalink-loyalty-memberships-title">
				<div class="pixalink-loyalty-section__head">
					<h3 class="pixalink-loyalty-section__title" id="pixalink-loyalty-memberships-title"><?php esc_html_e( 'Memberships', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
				</div>
				<ul class="pixalink-loyalty-list">
					<?php foreach ( $summary['memberships'] as $pixalink_loyalty_membership ) : ?>
						<li class="pixalink-loyalty-list__row">
							<span class="pixalink-loyalty-list__main">
								<strong class="pixalink-loyalty-list__title"><?php echo esc_html( $pixalink_loyalty_membership['name'] ); ?></strong>
								<span class="pixalink-loyalty-list__meta">
									<?php
									echo esc_html(
										null === $pixalink_loyalty_membership['expires_at']
											? __( 'No end date', 'pixalink-loyalty-for-woocommerce' )
											/* translators: %s: expiry date. */
											: sprintf( __( 'Until %s', 'pixalink-loyalty-for-woocommerce' ), $pixalink_loyalty_date( $pixalink_loyalty_membership['expires_at'] ) )
									);
									?>
								</span>
							</span>
							<?php
							$pixalink_loyalty_statuses = array(
								'active'    => __( 'Active', 'pixalink-loyalty-for-woocommerce' ),
								'expired'   => __( 'Expired', 'pixalink-loyalty-for-woocommerce' ),
								'cancelled' => __( 'Cancelled', 'pixalink-loyalty-for-woocommerce' ),
							);
							?>
							<?php if ( isset( $pixalink_loyalty_statuses[ (string) $pixalink_loyalty_membership['status'] ] ) ) : ?>
								<span class="pixalink-loyalty-status is-<?php echo esc_attr( (string) $pixalink_loyalty_membership['status'] ); ?>"><?php echo esc_html( $pixalink_loyalty_statuses[ (string) $pixalink_loyalty_membership['status'] ] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>

		<section class="pixalink-loyalty-section" aria-labelledby="pixalink-loyalty-activity-title">
			<div class="pixalink-loyalty-section__head">
				<h3 class="pixalink-loyalty-section__title" id="pixalink-loyalty-activity-title"><?php esc_html_e( 'Recent activity', 'pixalink-loyalty-for-woocommerce' ); ?></h3>
				<?php if ( isset( $pixalink_loyalty_links['points'] ) ) : ?>
					<a class="pixalink-loyalty-section__link" href="<?php echo esc_url( $pixalink_loyalty_links['points'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Full points history', 'pixalink-loyalty-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( array() === $summary['activity'] ) : ?>
				<div class="pixalink-loyalty-empty">
					<p class="pixalink-loyalty-empty__title"><?php esc_html_e( 'No activity yet', 'pixalink-loyalty-for-woocommerce' ); ?></p>
					<p class="pixalink-loyalty-empty__text"><?php esc_html_e( 'Points you earn on orders and spend on rewards appear here.', 'pixalink-loyalty-for-woocommerce' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="pixalink-loyalty-list">
					<?php foreach ( $summary['activity'] as $pixalink_loyalty_row ) : ?>
						<li class="pixalink-loyalty-list__row">
							<span class="pixalink-loyalty-list__main">
								<span class="pixalink-loyalty-list__title"><?php echo esc_html( $pixalink_loyalty_row['description'] ); ?></span>
								<time class="pixalink-loyalty-list__meta" datetime="<?php echo esc_attr( $pixalink_loyalty_row['occurred_at'] ); ?>"><?php echo esc_html( $pixalink_loyalty_date( $pixalink_loyalty_row['occurred_at'] ) ); ?></time>
							</span>
							<?php if ( null !== $pixalink_loyalty_row['points'] && 0 !== $pixalink_loyalty_row['points'] ) : ?>
								<span class="pixalink-loyalty-list__points <?php echo esc_attr( $pixalink_loyalty_row['points'] > 0 ? 'is-gain' : 'is-spend' ); ?>">
									<?php echo esc_html( ( $pixalink_loyalty_row['points'] > 0 ? '+' : "\u{2212}" ) . number_format_i18n( abs( $pixalink_loyalty_row['points'] ) ) ); ?>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>

		<?php if ( isset( $pixalink_loyalty_links['rewards'] ) || isset( $pixalink_loyalty_links['credits'] ) ) : ?>
			<p class="pixalink-loyalty-actions">
				<?php if ( isset( $pixalink_loyalty_links['rewards'] ) ) : ?>
					<a class="<?php echo esc_attr( trim( 'button pixalink-loyalty-button ' . $pixalink_loyalty_button ) ); ?>" href="<?php echo esc_url( $pixalink_loyalty_links['rewards'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View all rewards', 'pixalink-loyalty-for-woocommerce' ); ?></a>
				<?php endif; ?>
				<?php if ( isset( $pixalink_loyalty_links['credits'] ) ) : ?>
					<a class="pixalink-loyalty-actions__link" href="<?php echo esc_url( $pixalink_loyalty_links['credits'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Credits', 'pixalink-loyalty-for-woocommerce' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>

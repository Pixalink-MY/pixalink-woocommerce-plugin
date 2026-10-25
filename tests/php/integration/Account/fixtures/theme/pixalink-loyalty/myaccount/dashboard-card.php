<?php
/**
 * Stand-in for a theme's pixalink-loyalty/myaccount/dashboard-card.php.
 *
 * @package Pixalink\Loyalty\Tests
 *
 * @var array{points:int}|null $card
 */

defined( 'ABSPATH' ) || exit;

echo '<p>Theme dashboard card: ' . esc_html( null === $card ? 'unlinked' : $card['points'] . ' points' ) . '</p>';

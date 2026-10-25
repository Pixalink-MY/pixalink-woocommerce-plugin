<?php
/**
 * PHPStan bootstrap: constants defined at runtime by WordPress or the main plugin file.
 *
 * @package Pixalink\Loyalty
 */

define( 'PIXALINK_LOYALTY_VERSION', '0.0.0' );
define( 'PIXALINK_LOYALTY_FILE', __FILE__ );
define( 'PIXALINK_LOYALTY_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'PIXALINK_LOYALTY_URL', 'https://example.test/wp-content/plugins/pixalink-loyalty-for-woocommerce/' );
define( 'PIXALINK_LOYALTY_DISTRIBUTION', 'dev' );

// Defined by wp_cookie_constants() (COOKIE_DOMAIN comes from phpstan-wordpress).
define( 'COOKIEPATH', '/' );

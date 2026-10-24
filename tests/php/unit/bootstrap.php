<?php
/**
 * Unit and contract test bootstrap: Composer autoload and the constants WordPress would define.
 *
 * @package Pixalink\Loyalty\Tests
 */

require_once dirname( __DIR__, 3 ) . '/vendor/autoload.php';

// Source files guard direct access with ABSPATH.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/pixalink-loyalty-unit/' );
}

if ( ! defined( 'PIXALINK_LOYALTY_VERSION' ) ) {
	define( 'PIXALINK_LOYALTY_VERSION', '0.0.0-test' );
	define( 'PIXALINK_LOYALTY_FILE', dirname( __DIR__, 3 ) . '/pixalink-loyalty-for-woocommerce.php' );
	define( 'PIXALINK_LOYALTY_DIR', dirname( __DIR__, 3 ) . '/' );
	define( 'PIXALINK_LOYALTY_URL', 'https://shop.example.test/wp-content/plugins/pixalink-loyalty-for-woocommerce/' );
	define( 'PIXALINK_LOYALTY_DISTRIBUTION', 'dev' );
}

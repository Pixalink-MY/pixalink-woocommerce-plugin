<?php
/**
 * Plugin Name: Fake Pixalink Commerce API (tests only)
 * Description: Serves /api/commerce/v1 from WP REST routes and answers the plugin's calls to https://fake-pixalink.test in-process. Never install on a production site.
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * @package Pixalink\Loyalty\Tests
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/fake-pixalink/Signature.php';
require_once __DIR__ . '/fake-pixalink/ContractValidator.php';
require_once __DIR__ . '/fake-pixalink/Responses.php';
require_once __DIR__ . '/fake-pixalink/Server.php';

if ( \Pixalink\Loyalty\Tests\FakeServer\Server::is_enabled() ) {
	\Pixalink\Loyalty\Tests\FakeServer\Server::boot();
}

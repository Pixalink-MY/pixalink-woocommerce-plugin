<?php
/**
 * Plugin Name: Pixalink Loyalty e2e helpers (tests only)
 * Description: REST helpers the Playwright specs use to start every test from a clean shopper. Never install on a production site.
 * Version:     0.1.0
 * License:     GPL-2.0-or-later
 *
 * @package Pixalink\Loyalty\Tests
 */

defined( 'ABSPATH' ) || exit;

if ( \Pixalink\Loyalty\Tests\FakeServer\Server::is_enabled() ) {
	add_action(
		'rest_api_init',
		static function (): void {
			register_rest_route(
				'pixalink-e2e/v1',
				'/shopper',
				array(
					'methods'             => 'DELETE',
					'permission_callback' => static fn (): bool => current_user_can( 'manage_options' ),
					'callback'            => static function (): \WP_REST_Response {
						$user_id = get_current_user_id();

						// Forget the stored quote and selection of the logged-in shopper (WC session customer id = user id).
						delete_transient( \Pixalink\Loyalty\Redemption\RedemptionState::KEY_PREFIX . md5( (string) $user_id ) );
						delete_user_meta( $user_id, \Pixalink\Loyalty\Redemption\RedemptionState::USER_META );

						return new \WP_REST_Response( array( 'reset' => $user_id ) );
					},
				)
			);
		}
	);
}

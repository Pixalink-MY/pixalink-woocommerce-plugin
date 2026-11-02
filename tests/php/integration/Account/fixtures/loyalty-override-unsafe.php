<?php
/**
 * Stand-in for a theme override that prints markup it should not.
 *
 * @package Pixalink\Loyalty\Tests
 */

defined( 'ABSPATH' ) || exit;

echo '<p>Theme loyalty template</p><script>window.pixalinkInjected = 1;</script><a href="javascript:alert(1)" onclick="alert(2)">Rewards</a>';

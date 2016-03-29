<?php

class WpcomLegacyHashRedirectsTest extends WP_UnitTestCase {

	/**
	 * Makes sure the foundational stuff is sorted so tests work
	 */
	function setup() {

		// We need to trick the plugin into thinking it's run by WP-CLI
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		// We need to trick the plugin into thinking we're in admin
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

	}

	/**
	 * Make sure redirects with hashes are added, and redirect
	 *
	 * The plugin should strip the hash and only store the URL path
	 */
	function test_hash_redirect() {

		// Set our from/to URLs
		$from = '/hash-redirect#with-hash';
		$to = 'http://example.com';

		// Test insert
		$redirect = WPCOM_Legacy_Redirector::insert_legacy_redirect( $from, $to );

		$this->assertTrue( $redirect );

		// Test redirect
		$redirect = WPCOM_Legacy_Redirector::get_redirect_uri( $from );

		$this->assertEquals( $redirect, $to );

	}


}

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
	 * Make sure redirects with hashes are added
	 *
	 * The plugin should strip the hash and only store the URL path
	 */
	function test_insert_hash_redirect() {

		// Set our from/to URLs
		$from = '/hash-redirect#with-hash';
		$to = 'http://example.com';

		$redirect = WPCOM_Legacy_Redirector::insert_legacy_redirect( $from, $to );

		$this->assertTrue( $redirect );

	}

	/**
	 * Make sure redirects are stored
	 *
	 * The plugin should strip any hashes before checking for the redirect using
	 * only the path from the input URL
	 */
	function test_get_hash_redirect() {

		$from = '/hash-redirect#with-hash';
		$to = 'http://example.com';

		$redirect = WPCOM_Legacy_Redirector::get_redirect_uri( $from );

		$this->assertEquals( $redirect, $to );

	}


}


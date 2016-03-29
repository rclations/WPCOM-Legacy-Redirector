<?php

class WpcomLegacyQueryStringRedirectsTest extends WP_UnitTestCase {

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
	 * Make sure redirects are added
	 *
	 * The plugin should strip the query parameters and only store the URL path
	 */
	function test_insert_query_string_redirect() {

		// Set our from/to URLs
		$from = '/a-redirect?with=query-string';
		$to = 'http://example.com';

		$redirect = WPCOM_Legacy_Redirector::insert_legacy_redirect( $from, $to );

		$this->assertTrue( $redirect );

	}

	/**
	 * Make sure redirects are stored
	 *
	 * The plugin should strip any query params before checking for the redirect using
	 * only the path from the input URL
	 */
	function test_get_query_string_redirect() {

		$from = '/a-redirect?with=query-string';
		$to = 'http://example.com';

		$redirect = WPCOM_Legacy_Redirector::get_redirect_uri( $from );

		$this->assertEquals( $redirect, $to );

	}


}


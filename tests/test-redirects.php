<?php

class WpcomLegacyRedirectsTest extends WP_UnitTestCase {

	/**
	 * Makes sure the foundational stuff is sorted so tests work
	 */
	function setUp() {

		// We need to trick the plugin into thinking it's run by WP-CLI
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		// We need to trick the plugin into thinking we're in admin
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

	}

	public function get_redirect_data() {
		return array(
			'redirect_simple' => array(
				'/simple-redirect',
				'http://example.com'
			),

			'redirect_with_querystring' => array(
				'/a-redirect?with=query-string',
				'http://example.com'
			),

			'redirect_with_hashes' => array(
				// The plugin should strip the hash and only store the URL path.
				'/hash-redirect#with-hash',
				'http://example.com'
			),

			'redirect_unicode_in_path' => array(
				// https://www.w3.org/International/articles/idn-and-iri/
				'/JP納豆',
				'http://example.com',
			),
		);
	}

	/**
	 * @dataProvider get_redirect_data
	 */
	function test_redirect( $from, $to ) {
		// Test insert
		$redirect = WPCOM_Legacy_Redirector::insert_legacy_redirect( $from, $to );

		$this->assertTrue( $redirect );

		// Test redirect
		$redirect = WPCOM_Legacy_Redirector::get_redirect_uri( $from );

		$this->assertEquals( $redirect, $to );
	}
}

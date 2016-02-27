<?php

class WpcomLegacyRedirectsTest extends WP_UnitTestCase {

	protected $redirects;

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

		// Let's set a bunch of redirects to loop over
		self::set_redirects( [
			'/simple-redirect' => 'http://example.com',
		] );

	}

	/**
	 * @param array $redirects
	 * @return WpcomLegacyRedirectsTest
	 */
	public function set_redirects($redirects ) {
		$this->redirects = $redirects;
		return $this;
	}

	/**
	 * Make sure redirects are added
	 */
	function test_insert_redirects() {

		self::setup();

		foreach ( $this->redirects as $from => $to ) {

			$redirect = WPCOM_Legacy_Redirector::insert_legacy_redirect( $from, $to );

			$this->assertTrue( $redirect );

		}

	}

	/**
	 * Make sure redirects are stored
	 */
	function test_get_redirects() {

		self::setup();

		foreach ( $this->redirects as $from => $to ) {

			$redirect = WPCOM_Legacy_Redirector::get_redirect_uri( $from );

			$this->assertEquals( $redirect, $to );

		}

	}

}


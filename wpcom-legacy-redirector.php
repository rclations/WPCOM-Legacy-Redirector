<?php
/**
 * Plugin Name: WPCOM Legacy Redirector
 * Plugin URI: https://vip.wordpress.com/plugins/wpcom-legacy-redirector/
 * Description: Simple plugin for handling legacy redirects in a scalable manner.
 * Version: 1.2.0
 * Requires PHP: 5.6
 * Author: Automattic / WordPress.com VIP
 * Author URI: https://vip.wordpress.com
 *
 * This is a no-frills plugin (no UI, for example). Data entry needs to be bulk-loaded via the wp-cli commands provided or custom scripts.
 *
 * Redirects are stored as a custom post type and use the following fields:
 *
 * - post_name for the md5 hash of the "from" path or URL.
 *  - we use this column, since it's indexed and queries are super fast.
 *  - we also use an md5 just to simplify the storage.
 * - post_title to store the non-md5 version of the "from" path.
 * - one of either:
 *  - post_parent if we're redirect to a post; or
 *  - post_excerpt if we're redirecting to an alternate URL.
 *
 * Please contact us before using this plugin.
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require( __DIR__ . '/includes/wp-cli.php' );
}

class WPCOM_Legacy_Redirector {
	const POST_TYPE = 'vip-legacy-redirect';
	const CACHE_GROUP = 'vip-legacy-redirect-2';

	static function start() {
		add_action( 'init', array( __CLASS__, 'init' ) );
		add_filter( 'template_redirect', array( __CLASS__, 'maybe_do_redirect' ), 0 ); // hook in early, before the canonical redirect
	}

	static function init() {
		register_post_type( self::POST_TYPE, array(
			'public' => false,
		) );
	}

	static function maybe_do_redirect() {
		// Avoid the overhead of running this on every single pageload.
		// We move the overhead to the 404 page but the trade-off for site performance is worth it.
		if ( ! is_404() ) {
			return;
		}

		$url = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

		if ( ! empty( $_SERVER['QUERY_STRING'] ) )
			$url .= '?' . $_SERVER['QUERY_STRING'];

		$request_path = apply_filters( 'wpcom_legacy_redirector_request_path', $url );

		if ( $request_path ) {
			$redirect_uri = self::get_redirect_uri( $request_path );
			if ( $redirect_uri ) {
				header( 'X-legacy-redirect: HIT' );
				$redirect_status = apply_filters( 'wpcom_legacy_redirector_redirect_status', 301, $url );
				wp_safe_redirect( $redirect_uri, $redirect_status );
				exit;
			}
		}
	}

	/**
	 *
	 * @param string $from_url URL or path that should be redirected; should have leading slash if path.
	 * @param int|string $redirect_to The post ID or URL to redirect to.
	 * @return bool|WP_Error Error if invalid redirect URL specified or if the URI already has a rule; false if not is_admin, true otherwise.
	 */
	static function insert_legacy_redirect( $from_url, $redirect_to ) {

		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! is_admin() && ! apply_filters( 'wpcom_legacy_redirector_allow_insert', false ) ) {
			// never run on the front end
			return false;
		}

		$from_url = self::normalise_url( $from_url );
		if ( is_wp_error( $from_url ) ) {
			return $from_url;
		}

		$from_url_hash = self::get_url_hash( $from_url );

		if ( false !== self::get_redirect_uri( $from_url ) ) {
			return new WP_Error( 'duplicate-redirect-uri', 'A redirect for this URI already exists' );
		}

		$args = array(
			'post_name' => $from_url_hash,
			'post_title' => $from_url,
			'post_type' => self::POST_TYPE,
		);

		if ( is_numeric( $redirect_to ) ) {
			$args['post_parent'] = $redirect_to;
		} elseif ( false !== parse_url( $redirect_to ) ) {
			$args['post_excerpt'] = esc_url_raw( $redirect_to );
		} else {
			return new WP_Error( 'invalid-redirect-url', 'Invalid redirect_to param; should be a post_id or a URL' );
		}

		wp_insert_post( $args );

		wp_cache_delete( $from_url_hash, self::CACHE_GROUP );

		return true;
	}

	static function get_redirect_uri( $url ) {

		$url = self::normalise_url( $url );
		if ( is_wp_error( $url ) ) {
			return false;
		}

		// White list of Params that should be pass through as is.
		$protected_params = apply_filters( 'wpcom_legacy_redirector_preserve_query_params', array(), $url );
		$protected_param_values = array();
		$param_values = array();

		// Parse URL to get Query Params.
		$query_params = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! empty( $query_params ) ) { // Verify Query Params exist.

			// Parse Query String to Associated Array.
			parse_str( $query_params, $param_values );
			// For every white listed param save value and strip from url
			foreach ( $protected_params as $protected_param ) {
				if ( ! empty( $param_values[ $protected_param ] ) ) {
					$protected_param_values[ $protected_param ] = $param_values[ $protected_param ];
					$url = remove_query_arg( $protected_param, $url );
				}
			}
		}

		$url_hash = self::get_url_hash( $url );

		$redirect_post_id = wp_cache_get( $url_hash, self::CACHE_GROUP );

		if ( false === $redirect_post_id ) {
			$redirect_post_id = self::get_redirect_post_id( $url );
			wp_cache_add( $url_hash, $redirect_post_id, self::CACHE_GROUP );
		}

		if ( $redirect_post_id ) {
			$redirect_post = get_post( $redirect_post_id );
			if ( ! $redirect_post instanceof WP_Post ) {
				// If redirect post object doesn't exist, reset cache
				wp_cache_set( $url_hash, 0, self::CACHE_GROUP );

				return false;
			} elseif ( 0 !== $redirect_post->post_parent ) {
				return add_query_arg( $protected_param_values, get_permalink( $redirect_post->post_parent ) ); // Add Whitelisted Params to the Redirect URL.
			} elseif ( ! empty( $redirect_post->post_excerpt ) ) {
				return add_query_arg( $protected_param_values, esc_url_raw( $redirect_post->post_excerpt ) ); // Add Whitelisted Params to the Redirect URL
			}
		}

		return false;
	}

	static function get_redirect_post_id( $url ) {
		global $wpdb;

		$url_hash = self::get_url_hash( $url );

		$redirect_post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type = %s AND post_name = %s LIMIT 1", self::POST_TYPE, $url_hash ) );

		if ( ! $redirect_post_id ) {
			$redirect_post_id = 0;
		}

		return $redirect_post_id;
	}

	/**
	 * Update the query count, and sleep as needed.
	 *
	 * @param int $query_count Current tally of db queries run.
	 * @return int|bool Updated query count, or false if a non-integer is passed.
	 */
	static function update_query_count( $query_count ) {
		if ( ! is_int( $query_count ) ) {
			return false;
		}

		$query_count++;
		if ( 0 == $query_count % 100 ) {
			sleep( 1 );
		}

		return $query_count;
	}

	/**
	 * Validate and format redirects for verification.
	 *
	 * @param array $redirect Array of redirect objects to process.
	 * @param obj $progress WP-CLI progress bar.
	 * @param bool $force_ssl Whether to format URLs using SSL.
	 * @return array|WP_Error Updated redirects array, or WP_Error on failure.
	 */
	static function validate_redirects( $redirects, $progress, $force_ssl = false ) {

		$post_types = get_post_types( array( 'public' => true ) );

		if ( ! is_array( $redirects['redirects'] ) ) {
			return new WP_Error( 'no-redirects', 'No redirects to validate.' );
		}

		$validated_redirects = array();

		foreach ( $redirects['redirects'] as $redirect ) {

			$formatted_redirect = array(
				'id'            => $redirect->ID,
				'from'          => array(
					'raw'           => $redirect->post_title,
					'formatted'     => $redirect->post_title,
				),
				'to'            => array(
					'raw'           => '',
					'formatted'     => '',
				),
				'post_status'   => $redirect->post_status,
				'parent'        => array(
					'id'            => $redirect->parent_id,
					'status'        => $redirect->parent_status,
					'post_type'     => $redirect->parent_post_type,
				),
			);

			// Format relative from urls
			if ( '/' == substr( $formatted_redirect['from']['raw'], 0, 1 ) ) {
				if ( $force_ssl ) {
					$formatted_redirect['from']['formatted'] = home_url( $formatted_redirect['from']['raw'], 'https' );
				} else {
					$formatted_redirect['from']['formatted'] = home_url( $formatted_redirect['from']['raw'] );
				}
			}

			// Format and validate, based on redirect destination type.
			if ( ! empty( $redirect->post_excerpt ) ) {
				$formatted_redirect['destination_type'] = 'url';
				$formatted_redirect['to']['raw'] = $redirect->post_excerpt;

				// Format relative to URLs
				if ( '/' == substr( $formatted_redirect['to']['raw'], 0, 1 ) ) {
					if ( $force_ssl ) {
						$formatted_redirect['to']['formatted'] = home_url( $formatted_redirect['to']['raw'], 'https' );
					} else {
						$formatted_redirect['to']['formatted'] = home_url( $formatted_redirect['to']['raw'] );
					}
				}

				$validation = self::validate_url_redirect( $formatted_redirect, $post_types );

			} else {
				$formatted_redirect['destination_type'] = 'post';
				$formatted_redirect['to']['raw'] = $redirect->post_parent;

				// Set here for error handling. Update value post-validation, since it requires an expensive get_permalink() call.
				$formatted_redirect['to']['formatted'] = $formatted_redirect['to']['raw'];

				$validation = self::validate_post_redirect( $formatted_redirect, $post_types );
			}

			if ( is_wp_error( $validation ) ) {
				$redirects['notices'][] = array(
					'id'        => $formatted_redirect['id'],
					'from_url'  => $formatted_redirect['from']['raw'],
					'to_url'    => $formatted_redirect['to']['formatted'],
					'message'   => $validation->get_error_message(),
				);
				$redirects['update_status'] = self::maybe_update_redirect_status( $redirects['update_status'], $formatted_redirect, 'draft' );
				$progress->tick();
				continue;
			}

			// Update $formatted_redirect['to']['formatted'] for redirects to post ids.
			if ( 'post' === $formatted_redirect['destination_type'] ) {

				// Reuse parent post object in case of multiple redirects to same post ID.
				if ( ! isset( $parent->ID ) || intval( $formatted_redirect['parent']['id'] ) !== $parent->ID ) {
					$parent = get_post( $formatted_redirect['parent']['id'] );
				}

				$formatted_redirect['to']['formatted'] = get_permalink( $parent );
				$query_count = self::update_query_count( $query_count );
			}

			$validated_redirects[ $formatted_redirect['id'] ] = array(
				'id'            => $formatted_redirect['id'],
				'from'          => $formatted_redirect['from'],
				'to'            => $formatted_redirect['to'],
				'poststatus'    => $formatted_redirect['post_status'],
			);
		}

		$redirects['redirects'] = $validated_redirects;

		return $redirects;
	}

	/**
	 * Validate a redirect to an internal or external URL.
	 *
	 * @param array $redirect Redirect array.
	 * @param array $post_types Array of publicly accessible post types.
	 * @return bool|WP_Error True on success, false or WP_Error on failure.
	 */
	static function validate_url_redirect( $redirect, $post_types ) {

		// Validate non-relative URLs.
		if (
			'/' !== substr( $redirect['to']['raw'], 0, 1 )
			&& ! wp_validate_redirect( $redirect['to']['formatted'], false )
		) {
			return new WP_Error( 'failed_url_validation', 'failed wp_validate_redirect()' );
		}

		return true;
	}

	/**
	 * Validate a redirect to a post id.
	 *
	 * @param array $redirect Redirect array.
	 * @param array $post_types Array of publicly accessible post types.
	 *
	 * @return bool|WP_Error True on success, false or WP_Error on failure.
	 */
	static function validate_post_redirect( $redirect, $post_types ) {

		if ( $redirect['parent']['id'] <= 0 ) {
			return new WP_Error( 'no-parent-post', 'Attempting to redirect to a nonexistent post id.' );

		} elseif ( 'publish' !== $redirect['parent']['status'] && 'attachment' !== $redirect['parent']['post_type'] ) {
			return new WP_Error( 'unpublished-post', 'Attempting to redirect to an unpublished post.' );

		} elseif ( ! in_array( $redirect['parent']['post_type'], $post_types ) ) {
			return new WP_Error( 'private-post-type', 'Attempting to redirect to a private post type: ' . $redirect['parent']['post_type'] );
		}

		return true;
	}

	/**
	 * Verify a batch of redirects.
	 *
	 * @param array $redirect Array of redirect objects to process.
	 * @param obj $progress WP-CLI progress bar.
	 * @param bool $verbose Whether to print success messages.
	 * @return array|WP_Error Updated redirects array, or WP_Error on failure.
	 */
	static function verify_redirects( $redirects, $progress, $verbose = false ) {

		if ( empty( $redirects['redirects'] ) ) {
			return $redirects;
		}

		if ( version_compare( PHP_VERSION, '7.0.7' ) >= 0 ) {
			$redirects['redirects'] = self::try_redirects( $redirects['redirects'], $progress );

		} else {
			// If PHP < 7.0.7, we need to manually limit the # of connections.
			$processed_redirects = array();
			$redirects_array = array_chunk( $redirects['redirects'], 5 );
			foreach ( $redirects_array as $redirects_chunk ) {
				$processed_redirects[] = self::try_redirects( $redirects_chunk, $progress );
				sleep( 1 );
			}
			$redirects['redirects'] = $processed_redirects;
		}

		foreach ( $redirects['redirects'] as $key => $redirect ) {

			$verify = self::verify_redirect_status( $redirect );

			if ( is_wp_error( $verify ) ) {
				$redirects['notices'][] = array(
					'id'        => $redirect['id'],
					'from_url'  => $redirect['from']['raw'],
					'to_url'    => $redirect['to']['formatted'],
					'message'   => $verify->get_error_message(),
				);
				$redirects['update_status'] = self::maybe_update_redirect_status( $redirects['update_status'], $redirect, 'draft' );
				continue;
			} else {
				if ( $verbose ) {
					$redirects['notices'][] = array(
						'id'        => $redirect['id'],
						'from_url'  => $redirect['from']['raw'],
						'to_url'    => $redirect['to']['raw'],
						'message'   => 'Verified',
					);
				}
				$redirects['update_status'] = self::maybe_update_redirect_status( $redirects['update_status'], $redirect, 'publish' );
			}
		}

		return $redirects;
	}

	/**
	 * Try executing a batch of redirects using parallel processing.
	 *
	 * @param array $redirect Array of redirects to process.
	 * @param obj $progress WP-CLI progress bar.
	 * @return array|WP_Error Array of redirects with updated redirect information, or WP_Error on failure.
	 */
	private static function try_redirects( $redirects, $progress ) {

		if ( ! is_array( $redirects ) || empty( $redirects ) ) {
			return false;
		}

		$mh = curl_multi_init();

		// CURLMOPT_MAX_TOTAL_CONNECTIONS was added in PHP 7.0.7, which is needed to limit concurrent connections.
		if ( version_compare( PHP_VERSION, '7.0.7' ) >= 0 ) {
			curl_multi_setopt( $mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 100 );                      // max simultaneous open connections - see https://curl.haxx.se/libcurl/c/CURLMOPT_MAX_TOTAL_CONNECTIONS.html
			curl_multi_setopt( $mh, CURLMOPT_PIPELINING, CURLPIPE_HTTP1 | CURLPIPE_MULTIPLEX ); // try HTTP/1 pipelining and HTTP/2 multiplexing - see https://curl.haxx.se/libcurl/c/CURLMOPT_PIPELINING.html
		} else {
			curl_multi_setopt( $mh, CURLMOPT_PIPELINING, CURLPIPE_HTTP1 );
		}

		foreach ( $redirects as $key => $redirect ) {
			$ch[ $key ] = curl_init();

			curl_setopt_array(
				$ch[ $key ],
				array(
					CURLOPT_URL             => $redirect['from']['formatted'],
					CURLOPT_HEADER          => true,    // Include the header in the body output.
					CURLOPT_NOBODY          => true,    // Do not get the body contents.
					CURLOPT_FOLLOWLOCATION  => true,    // follow HTTP 3xx redirects
					CURLOPT_RETURNTRANSFER  => true,
				)
			);

			curl_multi_add_handle( $mh, $ch[ $key ] );
		}

		$active = null;
		do {
			$mrc = curl_multi_exec( $mh, $active );
		} while ( CURLM_CALL_MULTI_PERFORM == $mrc );

		$thread_count = 0;

		while ( $active && CURLM_OK == $mrc ) {
			// Wait for activity on any curl-connection
			if ( curl_multi_select( $mh ) == -1 ) {
				usleep( 1 );
			}

			// Continue to exec until curl is ready to give us more data
			do {
				$mrc = curl_multi_exec( $mh, $active );

				// Monitor progress and report back to WP_CLI progressbar
				if ( ( count( $ch ) - $active ) != $thread_count ) {
					$thread_count++;
					$progress->tick();
				}
			} while ( CURLM_CALL_MULTI_PERFORM == $mrc );
		}

		foreach ( array_keys( $ch ) as $key ) {
			$redirects[ $key ]['redirect']['status']           = curl_getinfo( $ch[ $key ], CURLINFO_HTTP_CODE );
			$redirects[ $key ]['redirect']['count']            = curl_getinfo( $ch[ $key ], CURLINFO_REDIRECT_COUNT );
			$redirects[ $key ]['redirect']['resulting_url']    = curl_getinfo( $ch[ $key ], CURLINFO_EFFECTIVE_URL );
			curl_multi_remove_handle( $mh, $ch[ $key ] );
		}

		// Close the multi-handle and return our results
		curl_multi_close( $mh );

		foreach ( array_keys( $ch ) as $key ) {
			curl_close( $ch[ $key ] );
			unset( $ch[ $key ] );
		}

		return $redirects;
	}

	/**
	 * Verify a redirect.
	 *
	 * @param array $redirect Array of redirects.
	 * @return bool|WP_Error True if the redirect works as expected, otherwise WP_Error.
	 */
	static function verify_redirect_status( $redirect ) {

		if (
			! isset( $redirect['to']['formatted'] )
			|| ! isset( $redirect['redirect']['resulting_url'] )
			|| ! isset( $redirect['redirect']['status'] )
		) {
			return new WP_Error( 'redirect-missing-information', 'Redirect is missing information and cannot be validated.' );
		}

		if ( $redirect['to']['formatted'] === $redirect['redirect']['resulting_url'] ) {
			return true;

		} elseif ( $redirect['from']['formatted'] === $redirect['redirect']['resulting_url'] ) {
			return new WP_Error( 'did-not-redirect', 'Did not redirect.' );

		} elseif ( $redirect['from']['formatted'] . '/' === $redirect['redirect']['resulting_url'] ) {
			return new WP_Error( 'missing-trailing-slash', 'Warning: Redirect works, but missing trailing slash.' );

		} elseif ( 200 !== $redirect['redirect']['status'] ) {
			return new WP_Error( 'http-error-code', sprintf( 'Returned %s', $redirect['redirect']['status'] ) );

		} elseif ( 1 < $redirect['redirect']['count'] ) {
			return new WP_Error( 'http-error-code', sprintf( 'Mismatch: Redirected %d times, ending at %s', number_format( $redirect['redirect']['count'] ), $redirect['redirect']['resulting_url'] ) );

		} else {
			return new WP_Error( 'redirect-mismatch', sprintf( 'Mismatch: redirected to %s', $redirect['redirect']['resulting_url'] ) );
		}
	}

	/**
	 * Update the status of verified redirects.
	 *
	 * @param array $redirect Array of redirect objects to process.
	 * @return array Array containing updated $query_count and $offset values.
	 */
	static function update_redirects_status( $redirects ) {
		if ( is_array( $redirects['update_status'] ) && count( $redirects['update_status'] ) > 0 ) {
			global $wpdb;

			foreach ( $redirects['update_status'] as $redirect_status => $redirects_to_update ) {
				foreach ( $redirects_to_update as $redirect_to_update ) {
					$updated_rows = $wpdb->update( $wpdb->posts, array( 'post_status' => $redirect_status ), array( 'ID' => $redirect_to_update ) );
					$redirects['query_count'] = self::update_query_count( $redirects['query_count'] );

					if ( $updated_rows ) {
						clean_post_cache( $updated_rows );

						if ( $redirect_status !== $status ) {
							$redirects['offset'] = $redirects['offset'] + $updated_rows;
						}
					}
				}
			}
		}

		return $redirects;
	}

	/**
	 * Add redirect to update_status array, if it needs updating.
	 *
	 * @param array $redirects Array of redirects that need status updates.
	 * @param array $formatted_redirect A single redirect.
	 * @param string $status The status the redirect should (potentially) be upated to.
	 *
	 * @return array $redirects array.
	 */
	private static function maybe_update_redirect_status( $update_status, $formatted_redirect, $status ) {
		if ( $status !== $formatted_redirect['post_status'] ) {
			$update_status[ $status ][] = $formatted_redirect['id'];
		}

		return $update_status;
	}


	private static function get_url_hash( $url ) {
		return md5( $url );
	}

	/**
	 * Takes a request URL and "normalises" it, stripping common elements
	 *
	 * Removes scheme and host from the URL, as redirects should be independent of these.
	 *
	 * @param string $url URL to transform
	 *
	 * @return string $url Transformed URL
	 */
	private static function normalise_url( $url ) {

		// Sanitise the URL first rather than trying to normalise a non-URL
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new WP_Error( 'invalid-redirect-url', 'The URL does not validate' );
		}

		// Break up the URL into it's constituent parts
		$components = wp_parse_url( $url );

		// Avoid playing with unexpected data
		if ( ! is_array( $components ) ) {
			return new WP_Error( 'url-parse-failed', 'The URL could not be parsed' );
		}

		// We should have at least a path or query
		if ( ! isset( $components['path'] ) && ! isset( $components['query'] ) ) {
			return new WP_Error( 'url-parse-failed', 'The URL contains neither a path nor query string' );
		}

		// Make sure $components['query'] is set, to avoid errors
		$components['query'] = ( isset( $components['query'] ) ) ? $components['query'] : '';

		// All we want is path and query strings
		// Note this strips hashes (#) too
		// @todo should we destory the query strings and rebuild with `add_query_arg()`?
		$normalised_url = $components['path'];

		// Only append '?' and the query if there is one
		if ( ! empty( $components['query'] ) ) {
			$normalised_url = $components['path'] . '?' . $components['query'];
		}

		return $normalised_url;

	}
}

WPCOM_Legacy_Redirector::start();

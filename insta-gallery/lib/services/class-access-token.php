<?php

namespace QuadLayers\IGG\Services;

/**
 * Stateless helpers to normalize the access tokens returned by the OAuth
 * and renewal proxies.
 */
class Access_Token {

	/**
	 * Remove the obfuscation added by the proxy.
	 *
	 * @param string $maybe_dirty Access token.
	 * @return string
	 */
	public static function clean( $maybe_dirty ) {
		$maybe_dirty = (string) $maybe_dirty;

		if ( substr_count( $maybe_dirty, '.' ) < 3 ) {
			return str_replace( '634hgdf83hjdj2', '', $maybe_dirty );
		}

		$parts     = explode( '.', trim( $maybe_dirty ) );
		$last_part = $parts[2] . $parts[3];

		return $parts[0] . '.' . base64_decode( $parts[1] ) . '.' . base64_decode( $last_part );
	}

	/**
	 * Instagram Login tokens start with IG, Facebook Login tokens don't.
	 *
	 * @param string $access_token Access token.
	 * @return string PERSONAL or BUSINESS.
	 */
	public static function get_type( $access_token ) {
		if ( substr( (string) $access_token, 0, 2 ) === 'IG' ) {
			return 'PERSONAL';
		}
		return 'BUSINESS';
	}

	/**
	 * Expiration timestamp (UTC) from the token lifetime.
	 *
	 * @param int $expires_in Seconds until the token expires.
	 * @return int
	 */
	public static function calculate_expiration_date( $expires_in ) {
		return time() + intval( $expires_in ) - 1;
	}
}

<?php

namespace QuadLayers\IGG\Services;

/**
 * Signs the account, source and hashtag of a rendered feed so the public
 * frontend endpoints only serve what the site shows (see
 * Frontend::create_shortcode()). Signed with the site salt and without
 * expiration, so feeds keep working on cached pages.
 */
class Feed_Signature {

	/**
	 * Signature of a rendered feed.
	 *
	 * @param string $account_id Account id.
	 * @param string $source     Feed source: username, tag, tagged or stories.
	 * @param string $tag        Hashtag, only signed for the tag source.
	 * @return string Empty for invalid params.
	 */
	public static function sign( $account_id, $source, $tag = '' ) {
		if ( ! self::is_valid( $account_id, $source, $tag ) ) {
			return '';
		}

		$payload = self::payload( $account_id, $source, $tag );

		return false === $payload ? '' : hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	}

	/**
	 * Whether the signature is the one of a feed with these params.
	 *
	 * @param mixed  $signature  Signature of the request.
	 * @param string $account_id Account id.
	 * @param string $source     Feed source.
	 * @param string $tag        Hashtag.
	 * @return bool
	 */
	public static function verify( $signature, $account_id, $source, $tag = '' ) {
		if ( ! is_string( $signature ) || '' === $signature ) {
			return false;
		}

		$expected = self::sign( $account_id, $source, $tag );

		return '' !== $expected && hash_equals( $expected, $signature );
	}

	private static function is_valid( $account_id, $source, $tag ) {
		return is_scalar( $account_id ) && is_scalar( $source ) && ( null === $tag || is_scalar( $tag ) );
	}

	private static function payload( $account_id, $source, $tag ) {
		$source = (string) $source;
		// As WordPress sanitizes the tag of the request, case insensitive
		// like Instagram hashtags (and Hashtag_Tracker).
		$tag = 'tag' === $source ? strtolower( sanitize_text_field( (string) $tag ) ) : '';

		return wp_json_encode( array( 'qligg_feed', trim( (string) $account_id ), $source, $tag ) );
	}
}

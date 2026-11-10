<?php

namespace QuadLayers\IGG\Api\Rest\Endpoints\Frontend;

use QuadLayers\IGG\Api\Rest\Endpoints\Base as Endpoints;
use QuadLayers\IGG\Models\Feeds as Models_Feeds;
use QuadLayers\IGG\Services\Feed_Signature;

/**
 * Base Class for Frontend Endpoints
 *
 * Provides security validation to ensure account_ids are only accessible
 * if they are associated with at least one published feed.
 */
abstract class Base extends Endpoints {

	/**
	 * Validates that an account_id is associated with at least one feed
	 *
	 * @param int $account_id The account ID to validate
	 * @return bool True if account is in a feed, false otherwise
	 */
	protected function validate_account_in_feeds( $account_id ) {
		// Validate account_id is provided and numeric
		if ( empty( $account_id ) || ! is_numeric( $account_id ) ) {
			return false;
		}

		$account_id = intval( $account_id );

		// Get all feeds
		$feeds = Models_Feeds::instance()->get_all();

		// If no feeds exist, deny access
		if ( empty( $feeds ) ) {
			return false;
		}

		// Check if the account_id exists in any feed
		foreach ( $feeds as $feed ) {
			if ( isset( $feed['account_id'] ) && intval( $feed['account_id'] ) === $account_id ) {
				return true; // Account is associated with a published feed
			}
		}

		// Account not found in any feed
		return false;
	}

	/**
	 * Source of the feeds the endpoint serves. Null for the account data
	 * (profile, stories, comments): any feed of the account, named by the
	 * source and tag params of the request.
	 *
	 * @var string|null
	 */
	protected static $feed_source = null;

	/**
	 * Permission callback for REST API endpoints
	 *
	 * Users who manage the feeds preview any feed (feeds tab, block editor).
	 * Otherwise the account has to be used in at least one feed and the
	 * request has to carry the signature of a feed the site rendered with
	 * that account, source and hashtag (see Feed_Signature).
	 *
	 * @return bool True if authorized, false otherwise
	 */
	public function get_rest_permission( \WP_REST_Request $request = null ) {
		// Fall back to 'manage_options' in case 'qligg_manage_feeds' has not been
		// assigned yet (add_capability runs on admin_init which doesn't fire on
		// REST API requests if no admin page has been visited since activation).
		if ( current_user_can( 'qligg_manage_feeds' ) || current_user_can( 'manage_options' ) ) {
			return true;
		}

		if ( ! $request ) {
			return false;
		}

		$account_id = $request->get_param( 'account_id' );

		if ( ! $this->validate_account_in_feeds( $account_id ) ) {
			return false;
		}

		$source = null === static::$feed_source ? $request->get_param( 'source' ) : static::$feed_source;

		return Feed_Signature::verify( $request->get_param( 'signature' ), $account_id, $source, $request->get_param( 'tag' ) );
	}
}

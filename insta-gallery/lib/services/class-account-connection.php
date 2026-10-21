<?php

namespace QuadLayers\IGG\Services;

use QuadLayers\IGG\Models\Accounts as Models_Accounts;

/**
 * Connect, reconnect and disconnect Instagram accounts (OAuth callback and
 * REST endpoints): normalizes the token, persists the account and sets its
 * first token check.
 */
class Account_Connection {

	protected static $instance;

	/**
	 * Create the account or, if it exists, reconnect it with the new token.
	 *
	 * @param array $account_data OAuth payload: id, access_token and, optionally, expires_in.
	 * @return array Account properties.
	 * @throws \Exception When the token can't be exchanged or the account can't be saved.
	 */
	public function connect( array $account_data ) {
		$id = (string) $account_data['id'];

		if ( ! isset( $account_data['expires_in'] ) ) {
			$account_data = $this->exchange_token( $account_data );
		}

		$access_token    = Access_Token::clean( $account_data['access_token'] );
		$expiration_date = Access_Token::calculate_expiration_date( $account_data['expires_in'] );

		$account = array(
			'access_token'                 => $access_token,
			'access_token_type'            => Access_Token::get_type( $access_token ),
			'access_token_expiration_date' => $expiration_date,
			'access_token_expires_in'      => $account_data['expires_in'],
			'access_token_renew_attempts'  => 0,
			'access_token_failures'        => 0,
			'access_token_next_check'      => $this->get_first_check( $access_token, $expiration_date ),
			// Reconnecting solves what the notices were about.
			'notice_stage'                 => '',
			'notice_deadline'              => 0,
			'notice_attempts'              => 0,
		);

		if ( Models_Accounts::instance()->get( $id ) ) {
			// update() returns null when nothing changed (same payload twice).
			$account = Models_Accounts::instance()->update( $id, $account ) ?? Models_Accounts::instance()->get( $id );
		} else {
			$account = Models_Accounts::instance()->create( array_merge( array( 'id' => $id ), $account ) );
		}

		if ( ! $account ) {
			throw new \Exception( esc_html__( 'Error creating account.', 'insta-gallery' ), 400 );
		}

		Token_Renewal::instance()->ensure_scheduled();

		return $account;
	}

	/**
	 * Save the username so the notices can name the account.
	 *
	 * @param string $id Account id.
	 * @param string $username Instagram username from the profile.
	 */
	public function remember_username( $id, $username ) {
		$account = Models_Accounts::instance()->get( (string) $id );

		if ( ! $account || '' === (string) $username || $account['username'] === $username ) {
			return;
		}

		// The Accounts tab fetches every profile at once: reload so a parallel
		// request's write isn't overwritten.
		Models_Accounts::instance()->refresh();
		Models_Accounts::instance()->update( (string) $id, array( 'username' => (string) $username ) );
	}

	/**
	 * A new token can't be renewed yet: check Personal tokens when they can be
	 * refreshed and Business tokens weekly or before the first notice.
	 *
	 * @param string $access_token Access token.
	 * @param int    $expiration_date Expiration timestamp.
	 * @return int
	 */
	protected function get_first_check( $access_token, $expiration_date ) {
		if ( 'PERSONAL' === Access_Token::get_type( $access_token ) ) {
			return $expiration_date - Token_Renewal::PERSONAL_RENEW_WINDOW;
		}
		return min( time() + Token_Renewal::BUSINESS_CHECK_INTERVAL, $expiration_date - 14 * DAY_IN_SECONDS );
	}

	/**
	 * Delete the account and its token check of older versions.
	 *
	 * @param string $id Account id.
	 * @return bool False if the account does not exist.
	 */
	public function disconnect( string $id ) {
		$status = Models_Accounts::instance()->delete( $id );

		if ( $status ) {
			Token_Renewal::instance()->unschedule( $id );
		}

		return (bool) $status;
	}

	/**
	 * Exchange a token without lifetime (Add Basic Account) for a long lived one.
	 *
	 * @param array $account_data Account data with access_token.
	 * @return array Account data with the long lived access_token and expires_in.
	 * @throws \Exception When the proxy doesn't return a token.
	 */
	protected function exchange_token( array $account_data ) {
		$response = Token_Renewal::instance()->request( $account_data['access_token'], 0, (string) $account_data['id'] );

		if ( isset( $response['error'], $response['message'] ) ) {
			throw new \Exception( esc_html( $response['message'] ), intval( $response['code'] ) );
		}

		if ( ! isset( $response['expires_in'], $response['access_token'] ) ) {
			throw new \Exception( esc_html__( 'Unable to create account.', 'insta-gallery' ), 400 );
		}

		$account_data['access_token'] = $response['access_token'];
		$account_data['expires_in']   = $response['expires_in'];

		return $account_data;
	}

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

<?php

namespace QuadLayers\IGG;

use QuadLayers\IGG\Controllers\Backend;
use QuadLayers\IGG\Controllers\Elementor;
use QuadLayers\IGG\Controllers\Frontend;
use QuadLayers\IGG\Controllers\Gutenberg;
use QuadLayers\IGG\Models\Accounts as Models_Accounts;
use QuadLayers\IGG\Services\Token_Renewal;

final class Plugin {

	protected static $instance;

	private function __construct() {
		/**
		 * Load plugin textdomain.
		 */
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		/**
		 * Load api classes.
		 */
		Api\Rest\Routes_Library::instance();
		/**
		 * Load plugin classes.ß
		 */
		Frontend::instance();
		Backend::instance();
		Gutenberg::instance();
		Elementor::instance();

		do_action( 'qligg_init' );

		// Filter to add dynamic interval to cron_schedules.
		add_filter(
			'cron_schedules',
			function ( $schedules ) {
				// Calculate dynamic interval based on existing tokens
				$dynamic_interval_days = $this->calculate_average_token_interval();

				$schedules[ Token_Renewal::CRON_SCHEDULE ] = array(
					'interval' => DAY_IN_SECONDS * $dynamic_interval_days,
					'display'  => sprintf( esc_html__( 'Every %d days (dynamic token check)', 'insta-gallery' ), $dynamic_interval_days ),
				);

				// Keep fifty_days for compatibility
				$schedules['fifty_days'] = array(
					'interval' => DAY_IN_SECONDS * 50,
					'display'  => esc_html__( 'Every fifty days', 'insta-gallery' ),
				);

				return $schedules;
			}
		);

		// Action to auto renew account access_token, if it can be done automatically. Send an email to inform admin about access_token expiration.
		add_action(
			Token_Renewal::CRON_HOOK,
			array( $this, 'send_expiration_mail' ),
			10,
			1
		);
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'insta-gallery', false, dirname( QLIGG_PLUGIN_BASENAME ) . '/languages' );
	}

	public function send_expiration_mail( $id ) {
		Token_Renewal::instance()->run( $id );
	}

	/**
	 * Calculate average token interval based on existing accounts
	 * Returns half of the average days until expiration, with min 25 and max 30 days
	 */
	private function calculate_average_token_interval() {
		$accounts = Models_Accounts::instance()->get_all();

		if ( empty( $accounts ) ) {
			// If no accounts, use default of 25 days (half of 50)
			return 25;
		}

		$current_time   = time();
		$total_days     = 0;
		$valid_accounts = 0;

		foreach ( $accounts as $account ) {
			if ( isset( $account['access_token_expiration_date'] ) && $account['access_token_expiration_date'] > $current_time ) {
				$days_until_expiration = ceil( ( $account['access_token_expiration_date'] - $current_time ) / DAY_IN_SECONDS );
				$total_days           += $days_until_expiration;
				++$valid_accounts;
			}
		}

		if ( $valid_accounts === 0 ) {
			// If no valid accounts, use default of 25 days (half of 50)
			return 25;
		}

		// Calculate average days until expiration and take half
		$average_days  = $total_days / $valid_accounts;
		$interval_days = floor( $average_days / 2 );

		// Apply limits: minimum 10 days, maximum 30 days
		$interval_days = max( 10, min( 30, $interval_days ) );

		return $interval_days;
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

Plugin::instance();

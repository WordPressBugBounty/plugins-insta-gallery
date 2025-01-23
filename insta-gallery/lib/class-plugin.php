<?php

namespace QuadLayers\IGG;

use QuadLayers\IGG\Controllers\Backend;
use QuadLayers\IGG\Controllers\Frontend;
use QuadLayers\IGG\Controllers\Gutenberg;
use QuadLayers\IGG\Models\Accounts as Models_Accounts;
use QuadLayers\IGG\Models\Settings as Models_Settings;

final class Plugin {

	protected static $instance;

	private function __construct() {
		/**
		 * Load plugin textdomain.
		 */
		load_plugin_textdomain( 'insta-gallery', false, QLIGG_PLUGIN_DIR . '/languages/' );
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

		do_action( 'qligg_init' );

		// Filter to add 50 days interval to cron_schedules.
		add_filter(
			'cron_schedules',
			function () {
				$schedules['fifty_days'] = array(
					'interval' => DAY_IN_SECONDS * 50,
					'display'  => esc_html__( 'Every fifty days', 'insta-gallery' ),
				);
				return $schedules;
			}
		);

		// Action to auto renew account access_token, if it can be done automatically. Send an email to inform admin about access_token expiration.
		add_action(
			'qligg_cron_account',
			array( $this, 'send_expiration_mail' ),
			10,
			1
		);
	}

	public function send_expiration_mail( $id ) {
		$account = Models_Accounts::instance()->get( $id );
		$old_expiration_date = $account['access_token_expiration_date'];

		$account_renewed = Models_Accounts::instance()->get( $id );
		$new_expiration  = $account_renewed['access_token_expiration_date'];

		$admin_email = $this->get_admin_email();

		$message = wp_kses_post( "Hi! We would like to inform you that the business account token(Facebook/Meta/Instagram) you are using in Social Feed Gallery is about to expire.\nThe data access expiration period is 90 days and depends on when the user was last active. When this 90-day period expires, the user can still access the application (that is, they are still authenticated), but the application cannot access their data. To regain access, the app must ask the user to reauthorize the app's permissions.", 'insta-gallery' );
		$subject = esc_html__( 'Your business account is about to expire.', 'insta-gallery' );

		if ( $old_expiration_date >= $new_expiration ) {
			wp_mail( $admin_email, $subject, $message );
		}

		if ( ! isset( $account_renewed['access_token'] ) ) {
			$message .= esc_html__( 'Please sign in again to keep the plugin functioning.', 'insta-gallery' );
			wp_mail( $admin_email, $subject, $message );
		}
	}

	protected function get_admin_email() {
		$user_settings = Models_Settings::instance()->get();
		if ( isset( $user_settings['mail_to_alert'] ) ) {
			return $user_settings['mail_to_alert'];
		}
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			return get_site_option( 'admin_email' );
		}
		return get_option( 'admin_email' );
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

Plugin::instance();

<?php

namespace QuadLayers\IGG\Services;

use QuadLayers\IGG\Models\Accounts as Models_Accounts;
use QuadLayers\IGG\Models\Settings as Models_Settings;
use QuadLayers\IGG\Api\Fetch\Business\Access_Token\Refresh as Api_Fetch_Business_Refresh_Access_Token;
use QuadLayers\IGG\Api\Fetch\Personal\Access_Token\Refresh as Api_Fetch_Personal_Refresh_Access_Token;

/**
 * Access token renewal: the renewal itself, the per account token check cron
 * (qligg_cron_account) and the admin notifications about the result.
 */
class Token_Renewal {

	const CRON_HOOK = 'qligg_cron_account';

	const CRON_SCHEDULE = 'qligg_dynamic_token_check';

	/**
	 * Max failed renewals before giving up, to prevent API abuse.
	 */
	const MAX_RENEW_ATTEMPTS = 3;

	protected static $instance;

	private $file_logger;
	private $email_service;

	/**
	 * Dependencies can be injected to test the cron cycle.
	 *
	 * @param File_Logger|null   $file_logger Logger, defaults to File_Logger.
	 * @param Email_Service|null $email_service Mailer, defaults to Email_Service.
	 */
	public function __construct( $file_logger = null, $email_service = null ) {
		$this->file_logger   = $file_logger;
		$this->email_service = $email_service;
	}

	/**
	 * Renew the account access_token.
	 *
	 * @param array|null $account Account to renew, null if it was deleted.
	 * @return bool True only if the token was extended by at least a day.
	 */
	public function renew( $account ) {

		// The account may have been deleted after the cron was scheduled.
		if ( empty( $account['id'] ) || empty( $account['access_token'] ) ) {
			return false;
		}

		$renew_attempts = max( 0, intval( $account['access_token_renew_attempts'] ) );

		if ( $renew_attempts >= self::MAX_RENEW_ATTEMPTS ) {
			return false;
		}

		$response = $this->request( $account['access_token'], $renew_attempts );

		if ( isset( $response['error'] ) || ! isset( $response['expires_in'] ) || ! isset( $response['access_token'] ) ) {
			$account['access_token_renew_attempts'] = $renew_attempts + 1;
			Models_Accounts::instance()->update( $account['id'], $account );
			return false;
		}

		$expiration_date = Access_Token::calculate_expiration_date( $response['expires_in'] );

		// Meta may answer with the same token and the same remaining lifetime:
		// only an extension of at least a day counts as a renewal.
		if ( $expiration_date - intval( $account['access_token_expiration_date'] ) < DAY_IN_SECONDS ) {
			return false;
		}

		$account['access_token_renew_attempts']  = 0;
		$account['access_token']                 = Access_Token::clean( $response['access_token'] );
		$account['access_token_expiration_date'] = $expiration_date;

		return (bool) Models_Accounts::instance()->update( $account['id'], $account );
	}

	/**
	 * Request a renewed access_token to the proxy.
	 *
	 * @param string  $access_token Account access_token.
	 * @param integer $renew_attempts Account renew attempts.
	 * @return array
	 */
	public function request( $access_token, $renew_attempts = 0 ) {
		if ( 'PERSONAL' === Access_Token::get_type( $access_token ) ) {
			return ( new Api_Fetch_Personal_Refresh_Access_Token() )->get_data( $access_token, $renew_attempts );
		}

		return ( new Api_Fetch_Business_Refresh_Access_Token() )->get_data( $access_token, $renew_attempts );
	}

	/**
	 * Reset the renew attempts counter so the cron can renew the token again.
	 *
	 * @param string $id Account id.
	 * @return bool False if the account does not exist.
	 */
	public function reset_renew_attempts( string $id ) {
		$account = Models_Accounts::instance()->get( $id );

		if ( ! $account ) {
			return false;
		}

		if ( 0 === intval( $account['access_token_renew_attempts'] ) ) {
			return true;
		}

		$account['access_token_renew_attempts'] = 0;

		return (bool) Models_Accounts::instance()->update( $id, $account );
	}

	/**
	 * Schedule the token check one cron interval from now. Never run it right
	 * away: a token that was just issued can't be renewed yet and the check
	 * would only send a needless expiration email.
	 *
	 * @param string $id Account id.
	 * @return void
	 */
	public function schedule( $id ) {
		$schedules = wp_get_schedules();
		$interval  = isset( $schedules[ self::CRON_SCHEDULE ]['interval'] ) ? intval( $schedules[ self::CRON_SCHEDULE ]['interval'] ) : DAY_IN_SECONDS * 25;

		$this->unschedule( $id );

		wp_schedule_event( time() + $interval, self::CRON_SCHEDULE, self::CRON_HOOK, array( $id ) );
	}

	/**
	 * Remove the token check of an account.
	 *
	 * @param string $id Account id.
	 * @return void
	 */
	public function unschedule( $id ) {
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $id ) );
	}

	/**
	 * Token check cron: renew the token and notify the admin.
	 *
	 * @param string $id Account id.
	 * @return void
	 */
	public function run( $id ) {
		$file_logger = $this->get_file_logger();

		// Log start of process.
		$file_logger->log( 'INFO', 'send_expiration_mail function called', array( 'account_id' => $id ), 'general' );

		// Get account data and attempt renewal.
		$account_data = $this->prepare_account_data( $id, $file_logger );

		// Account deleted after the cron was scheduled: nothing to renew.
		if ( ! $account_data ) {
			$this->log_completion( $id, $file_logger );
			return;
		}

		// Handle successful token renewal.
		if ( $this->is_token_renewed( $account_data ) ) {
			$this->handle_token_renewed( $account_data, $file_logger );
			$this->log_completion( $id, $file_logger );
			return;
		}

		// Handle missing token case.
		if ( $this->is_token_missing( $account_data['account_renewed'] ) ) {
			$this->handle_token_missing( $account_data, $file_logger );
			$this->log_completion( $id, $file_logger );
			return;
		}

		// Handle token expiration scenarios.
		$this->handle_token_expiration( $account_data, $file_logger );
		$this->log_completion( $id, $file_logger );
	}

	/**
	 * Prepare account data and attempt token renewal
	 */
	private function prepare_account_data( $id, $file_logger ) {
		// Get account data before trying to renew.
		$account = Models_Accounts::instance()->get( $id );

		if ( ! $account ) {
			$file_logger->log( 'WARNING', 'Account not found - token renewal skipped', array( 'account_id' => $id ), 'token_renewal' );
			$this->unschedule( $id );
			return;
		}

		$old_expiration_date = $account['access_token_expiration_date'];

		// Log account data retrieval.
		$file_logger->log(
			'INFO',
			'Token renewal process started',
			array(
				'account_id'         => $id,
				'current_expiration' => $account['access_token_expiration_date'] ?? 'unknown',
				'username'           => $account['username'] ?? 'unknown',
			),
			'token_renewal'
		);

		// Calculate and log days until expiration for debugging.
		$current_time              = time();
		$days_until_expiration     = max( 0, ceil( ( $old_expiration_date - $current_time ) / DAY_IN_SECONDS ) );
		$expiration_date_formatted = wp_date( get_option( 'date_format' ), $old_expiration_date );

		$file_logger->log(
			'INFO',
			'Token expiration analysis',
			array(
				'account_id'                => $id,
				'current_timestamp'         => $current_time,
				'expiration_timestamp'      => $old_expiration_date,
				'days_until_expiration'     => $days_until_expiration,
				'expiration_date_formatted' => $expiration_date_formatted,
			),
			'token_renewal'
		);

		// Attempt to renew the token.
		$is_renewed = $this->renew( $account );

		// Get account data again after renewal attempt.
		$account_renewed = Models_Accounts::instance()->get( $id );
		$new_expiration  = $account_renewed['access_token_expiration_date'];

		return array(
			'id'                        => $id,
			'is_renewed'                => $is_renewed,
			'account_original'          => $account,
			'account_renewed'           => $account_renewed,
			'old_expiration_date'       => $old_expiration_date,
			'new_expiration'            => $new_expiration,
			'days_until_expiration'     => $days_until_expiration,
			'expiration_date_formatted' => $expiration_date_formatted,
			'admin_email'               => $this->get_admin_email(),
		);
	}

	/**
	 * Check if token was successfully renewed
	 */
	private function is_token_renewed( $account_data ) {
		return $account_data['is_renewed'];
	}

	/**
	 * Check if token is missing completely
	 */
	private function is_token_missing( $account_renewed ) {
		return empty( $account_renewed['access_token'] );
	}

	/**
	 * Handle successful token renewal
	 */
	private function handle_token_renewed( $account_data, $file_logger ) {

		$days_extended = round( ( $account_data['new_expiration'] - $account_data['old_expiration_date'] ) / DAY_IN_SECONDS, 2 );

		$file_logger->log(
			'SUCCESS',
			'Token renewed successfully',
			array(
				'account_id'     => $account_data['id'],
				'old_expiration' => gmdate( 'Y-m-d H:i:s', $account_data['old_expiration_date'] ),
				'new_expiration' => gmdate( 'Y-m-d H:i:s', $account_data['new_expiration'] ),
				'days_extended'  => $days_extended,
			),
			'token_renewal'
		);

		$message = sprintf(
			__( "Great news! Your Instagram token has been automatically renewed for Social Feed Gallery.\n\nNew expiration date: %s\n\nNo action needed - your Instagram feed will continue working seamlessly.", 'insta-gallery' ),
			wp_date( get_option( 'date_format' ), $account_data['new_expiration'] )
		) . $this->get_email_footer();

		$subject = esc_html__( '✅ Instagram Token Renewed Successfully', 'insta-gallery' );

		$this->get_email_service()->send_email_with_retry(
			'renewal_success',
			$account_data['admin_email'],
			$subject,
			$message,
			$file_logger
		);
	}

	/**
	 * Handle token expiration scenarios
	 */
	private function handle_token_expiration( $account_data, $file_logger ) {
		// Log token expiration event.
		$file_logger->log(
			'WARNING',
			'Token expiration detected - sending warning email',
			array(
				'account_id'            => $account_data['id'],
				'days_until_expiration' => $account_data['days_until_expiration'],
				'expiration_date'       => $account_data['expiration_date_formatted'],
				'username'              => $account_data['account_renewed']['username'] ?? 'unknown',
			),
			'token_expiration'
		);

		$subject = $this->get_expiration_subject( $account_data );
		$message = $this->get_expiration_message( $account_data ) . $this->get_email_footer();

		$this->get_email_service()->send_email_with_retry(
			'expiration_warning',
			$account_data['admin_email'],
			$subject,
			$message,
			$file_logger
		);
	}

	/**
	 * Handle missing token case
	 */
	private function handle_token_missing( $account_data, $file_logger ) {
		// Log critical missing token event.
		$file_logger->log(
			'ERROR',
			'Critical: Token completely missing - manual reconnection required',
			array(
				'account_id'      => $account_data['id'],
				'expiration_date' => $account_data['expiration_date_formatted'],
				'username'        => $account_data['account_original']['username'] ?? 'unknown',
				'issue'           => 'access_token_completely_missing',
				'action_required' => 'manual_reconnection',
			),
			'token_missing'
		);

		$message = sprintf(
			__( "Your Instagram connection for Social Feed Gallery has been lost and cannot be restored automatically.\n\n⚠️ IMMEDIATE ACTION REQUIRED:\n\n1. Go to your WordPress admin dashboard\n2. Navigate to Social Feed Gallery settings: %s\n3. Reconnect your Instagram account\n\nWithout reconnection, your Instagram feeds will stop displaying new content.", 'insta-gallery' ),
			QLIGG_ACCOUNT_URL
		) . $this->get_email_footer();
		$subject = esc_html__( '🚨 URGENT: Instagram Connection Lost - Action Required', 'insta-gallery' );

		$this->get_email_service()->send_email_with_retry(
			'urgent_missing_token',
			$account_data['admin_email'],
			$subject,
			$message,
			$file_logger
		);
	}

	/**
	 * Get appropriate expiration subject based on days until expiration
	 */
	private function get_expiration_subject( $account_data ) {
		$days = $account_data['days_until_expiration'];

		// Token already expired.
		if ( $days <= 0 ) {
			return esc_html__( '🔴 Instagram Token Expired - Reconnection Needed', 'insta-gallery' );
		}

		// Token expires within 7 days.
		if ( $days <= 7 ) {
			return sprintf(
				esc_html__( '⚠️ URGENT: Instagram Token Expires in %d Day(s)', 'insta-gallery' ),
				$days
			);
		}

		// Token expires in more than 7 days.
		return sprintf(
			esc_html__( '📅 Reminder: Instagram Token Expires in %d Days', 'insta-gallery' ),
			$days
		);
	}

	/**
	 * Get appropriate expiration message based on days until expiration
	 */
	private function get_expiration_message( $account_data ) {
		$days           = $account_data['days_until_expiration'];
		$formatted_date = $account_data['expiration_date_formatted'];

		// Token already expired.
		if ( $days <= 0 ) {
			return sprintf(
				__( "Your Instagram token for Social Feed Gallery expired on %s.\n\n⚠️ Your Instagram feeds may not display new content until you reconnect.\n\nTo restore functionality:\n1. Go to WordPress Admin → Social Feed Gallery\n2. Reconnect your Instagram account\n3. Authorize the connection\n\nThis is a routine Instagram security requirement that happens every 60 days.", 'insta-gallery' ),
				$formatted_date
			);
		}

		// Token expires within 7 days.
		if ( $days <= 7 ) {
			return sprintf(
				__( "⚠️ URGENT: Your Instagram token expires in %1\$d day(s) on %2\$s\n\nYour Social Feed Gallery Instagram feeds will stop working after this date.\n\nTO PREVENT INTERRUPTION:\n1. Go to WordPress Admin → Social Feed Gallery\n2. Reconnect your Instagram account now\n3. Complete the authorization process\n\nDon't wait - reconnect today to ensure uninterrupted service!", 'insta-gallery' ),
				$days,
				$formatted_date
			);
		}

		// Token expires in more than 7 days.
		return sprintf(
			__( "Your Instagram token for Social Feed Gallery expires in %1\$d days on %2\$s\n\nThis is a friendly reminder to help you stay ahead of any service interruptions.\n\nWHAT TO DO:\n1. Mark your calendar for %2\$s\n2. A few days before, go to WordPress Admin → Social Feed Gallery\n3. Reconnect your Instagram account\n\nWe'll send you another reminder closer to the expiration date.\n\nThis is normal Instagram security maintenance that occurs every 60 days.", 'insta-gallery' ),
			$days,
			$formatted_date
		);
	}

	/**
	 * Get email footer with QuadLayers branding
	 */
	private function get_email_footer() {
		return sprintf(
			__( "\n\n---\n\nBest regards,\nQuadLayers Team\n\nVisit us: %s\nSupport: Need help with Social Feed Gallery? Contact our support team.", 'insta-gallery' ),
			'https://quadlayers.com'
		);
	}

	/**
	 * Log completion of the process
	 */
	private function log_completion( $id, $file_logger ) {
		$file_logger->log( 'INFO', 'send_expiration_mail function completed', array( 'account_id' => $id ), 'general' );
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

	private function get_file_logger() {
		if ( ! $this->file_logger ) {
			$this->file_logger = File_Logger::instance();
		}
		return $this->file_logger;
	}

	private function get_email_service() {
		if ( ! $this->email_service ) {
			$this->email_service = Email_Service::instance();
		}
		return $this->email_service;
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

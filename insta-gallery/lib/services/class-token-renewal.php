<?php

namespace QuadLayers\IGG\Services;

use QuadLayers\IGG\Models\Accounts as Models_Accounts;
use QuadLayers\IGG\Api\Fetch\Business\Access_Token\Refresh as Api_Fetch_Business_Refresh_Access_Token;
use QuadLayers\IGG\Api\Fetch\Personal\Access_Token\Refresh as Api_Fetch_Personal_Refresh_Access_Token;

/**
 * Access token renewal: a daily cron event (qligg_token_check) checks every
 * account when it is due (see next_check_for()) and hands it to
 * Token_Notifier, which emails the admin when it has to be reconnected.
 */
class Token_Renewal {

	const CHECK_HOOK = 'qligg_token_check';

	/**
	 * WP-Cron never runs on some sites (loopback blocked, DISABLE_WP_CRON
	 * without a real cron): run the check on an admin visit when it is 3
	 * days late.
	 *
	 * @param int|null $now Current timestamp.
	 */
	public function maybe_run_overdue( $now = null ) {
		$now = null === $now ? time() : (int) $now;

		// wp_doing_cron() needs WordPress 4.8.
		$doing_cron = function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : ( defined( 'DOING_CRON' ) && DOING_CRON );

		// The heartbeat and the reconnect link must not wait for the proxy.
		if ( $doing_cron || wp_doing_ajax() || isset( $_GET['qligg_reconnect'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( $now - (int) get_option( self::LAST_RUN_OPTION, 0 ) < 3 * DAY_IN_SECONDS ) {
			return;
		}

		$this->run_all( $now );
	}

	/**
	 * Per account event of older versions. Kept registered so a downgrade
	 * keeps working: it only makes sure the daily check exists.
	 */
	const CRON_HOOK = 'qligg_cron_account';

	const CRON_SCHEDULE = 'qligg_dynamic_token_check';

	const LAST_RUN_OPTION = 'qligg_token_check_last_run';

	/**
	 * Version of the token check data, see maybe_upgrade().
	 */
	const SCHEMA_OPTION = 'qligg_token_schema';

	const SCHEMA = 2;

	const LOCK = 'qligg_token_check_lock';

	/**
	 * Personal tokens are refreshed when this much is left (they last 60 days
	 * and can't be refreshed in their first 24 hours).
	 */
	const PERSONAL_RENEW_WINDOW = 30 * DAY_IN_SECONDS;

	/**
	 * Business Page tokens don't extend: check them weekly and before each
	 * notice (see next_check_for()).
	 */
	const BUSINESS_CHECK_INTERVAL = 7 * DAY_IN_SECONDS;

	/**
	 * Accounts whose admin was told the feed stopped: quiet checks to notice
	 * a recovery.
	 */
	const STOPPED_CHECK_INTERVAL = 30 * DAY_IN_SECONDS;

	/**
	 * A check due within this margin runs on the current daily tick: the tick
	 * can run a bit earlier than the previous one did.
	 */
	const CHECK_MARGIN = 6 * HOUR_IN_SECONDS;

	protected static $instance;

	private $file_logger;
	private $notifier;

	/**
	 * Dependencies can be injected to test the cron cycle.
	 *
	 * @param File_Logger|null    $file_logger Logger, defaults to File_Logger.
	 * @param Token_Notifier|null $notifier Notices, defaults to Token_Notifier.
	 */
	public function __construct( $file_logger = null, $notifier = null ) {
		$this->file_logger = $file_logger;
		$this->notifier    = $notifier;
	}

	public function register() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedules' ) );
		add_action( self::CHECK_HOOK, array( $this, 'run_all' ), 10, 0 );
		add_action( self::CRON_HOOK, array( $this, 'run_legacy' ), 10, 1 );
		add_action( 'init', array( $this, 'on_init' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_run_overdue' ) );
	}

	public function on_init() {
		$this->maybe_upgrade();
		$this->ensure_scheduled();
	}

	/**
	 * First load after updating from the per account events: unblock the
	 * accounts stuck at the old 3 attempts limit and check them all soon
	 * (User tokens must be swapped for the Page token before they expire).
	 * The events of the old version are kept for a downgrade.
	 *
	 * @param int|null $now Current timestamp.
	 */
	public function maybe_upgrade( $now = null ) {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA ) {
			return;
		}

		// First, so an error below can't make it run on every request.
		update_option( self::SCHEMA_OPTION, self::SCHEMA );

		foreach ( (array) Models_Accounts::instance()->get_all() as $account ) {
			Models_Accounts::instance()->update(
				$account['id'],
				array(
					'access_token_renew_attempts' => 0,
					'access_token_failures'       => 0,
					'access_token_next_check'     => 0,
				)
			);
		}

		update_option( self::LAST_RUN_OPTION, null === $now ? time() : (int) $now, false );

		wp_clear_scheduled_hook( self::CHECK_HOOK );
		$this->ensure_scheduled( MINUTE_IN_SECONDS );
	}

	/**
	 * Schedules of older versions: their events still exist.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function add_schedules( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 25 * DAY_IN_SECONDS,
			'display'  => __( 'Every 25 days (token check)', 'insta-gallery' ),
		);

		$schedules['fifty_days'] = array(
			'interval' => 50 * DAY_IN_SECONDS,
			'display'  => __( 'Every fifty days', 'insta-gallery' ),
		);

		return $schedules;
	}

	/**
	 * @param int $delay Seconds until the first check.
	 */
	public function ensure_scheduled( $delay = HOUR_IN_SECONDS ) {
		if ( ! wp_next_scheduled( self::CHECK_HOOK ) ) {
			wp_schedule_event( time() + $delay, 'daily', self::CHECK_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CHECK_HOOK );
	}

	/**
	 * Daily check: renew the accounts that are due and send their notices.
	 *
	 * @param int|null $now Current timestamp.
	 */
	public function run_all( $now = null ) {
		$now = null === $now ? time() : (int) $now;

		if ( get_transient( self::LOCK ) ) {
			return;
		}

		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );

		foreach ( (array) Models_Accounts::instance()->get_all() as $account ) {
			try {
				$this->get_notifier()->evaluate( $this->check( $account, $now ), $now );
			} catch ( \Throwable $e ) {
				$this->get_file_logger()->log(
					'ERROR',
					'Token check failed',
					array(
						'account_id' => $account['id'] ?? '',
						'error'      => $e->getMessage(),
					),
					'token_renewal'
				);
			}
		}

		update_option( self::LAST_RUN_OPTION, $now, false );
		delete_transient( self::LOCK );
	}

	/**
	 * Per account event of older versions.
	 *
	 * @param string $id Account id.
	 */
	public function run_legacy( $id = null ) {
		$this->ensure_scheduled();
	}

	/**
	 * Ask the proxy for a renewed token and store the answer.
	 *
	 * A valid answer is stored as is, even if its date is earlier: the proxy
	 * knows the real limit (the Page token is bounded by the data access
	 * window). Only the fields that changed are written, the account may be
	 * stale after the HTTP call.
	 *
	 * @param array    $account Account properties.
	 * @param int|null $now Current timestamp.
	 * @return array The account with the changes.
	 */
	public function check( array $account, $now = null ) {
		$now = null === $now ? time() : (int) $now;

		if ( empty( $account['id'] ) || empty( $account['access_token'] ) ) {
			return $account;
		}

		if ( $now + self::CHECK_MARGIN < intval( $account['access_token_next_check'] ?? 0 ) ) {
			return $account;
		}

		$expiration_date = intval( $account['access_token_expiration_date'] );

		if ( 'PERSONAL' === Access_Token::get_type( $account['access_token'] ) && $expiration_date - $now > self::PERSONAL_RENEW_WINDOW ) {
			return $this->save( $account, array( 'access_token_next_check' => $expiration_date - self::PERSONAL_RENEW_WINDOW ) );
		}

		$attempts = max( 0, intval( $account['access_token_renew_attempts'] ?? 0 ) );
		$failures = max( 0, intval( $account['access_token_failures'] ?? 0 ) );

		$response = $this->request( $account['access_token'], $attempts, $account['id'] );
		$is_valid = ! empty( $response['access_token'] ) && isset( $response['expires_in'] ) && is_numeric( $response['expires_in'] );

		if ( $is_valid ) {
			$access_token = Access_Token::clean( $response['access_token'] );
			$changes      = array(
				'access_token'                 => $access_token,
				'access_token_type'            => Access_Token::get_type( $access_token ),
				'access_token_expiration_date' => Access_Token::calculate_expiration_date( $response['expires_in'] ),
				'access_token_expires_in'      => intval( $response['expires_in'] ),
				'access_token_renew_attempts'  => 0,
				'access_token_failures'        => 0,
			);
		} elseif ( 190 === intval( $response['code'] ?? ( $response['error']['code'] ?? 0 ) ) ) {
			// The Personal fetch keeps the error nested (the proxy answers 200).
			$changes = array( 'access_token_failures' => $failures + 1 );
		} else {
			$changes = array( 'access_token_renew_attempts' => $attempts + 1 );
		}

		$changes['access_token_next_check'] = max( $now + HOUR_IN_SECONDS, $this->next_check_for( array_merge( $account, $changes ), $now, $is_valid ) );

		return $this->save( $account, $changes );
	}

	/**
	 * When to check the account again after asking the proxy.
	 *
	 * @param array $account Account with the changes of this check.
	 * @param int   $now Current timestamp.
	 * @param bool  $is_valid Whether the proxy answered with a token.
	 * @return int
	 */
	private function next_check_for( array $account, $now, $is_valid ) {
		$expiration_date = intval( $account['access_token_expiration_date'] );

		// Stopped, or about to be (Token_Notifier sends it after the 2nd 190).
		if ( 'stopped' === ( $account['notice_stage'] ?? '' ) || intval( $account['access_token_failures'] ?? 0 ) >= 2 ) {
			return $now + self::STOPPED_CHECK_INTERVAL;
		}

		if ( $is_valid && 'PERSONAL' === Access_Token::get_type( $account['access_token'] ) ) {
			return $expiration_date - self::PERSONAL_RENEW_WINDOW;
		}

		if ( $is_valid ) {
			$next_check = $now + self::BUSINESS_CHECK_INTERVAL;
			// Check right before each notice and at the deadline itself.
			foreach ( array( 14 * DAY_IN_SECONDS, 3 * DAY_IN_SECONDS, 0 ) as $before ) {
				if ( $expiration_date - $before > $now + HOUR_IN_SECONDS ) {
					return min( $next_check, $expiration_date - $before );
				}
			}
			return $next_check;
		}

		$failures = intval( $account['access_token_failures'] ?? 0 );
		$attempts = intval( $account['access_token_renew_attempts'] ?? 0 );

		// Retry the next day twice, then weekly; daily close to the deadline.
		if ( $failures > 0 || $attempts <= 2 || $expiration_date - $now <= 14 * DAY_IN_SECONDS ) {
			return $now + DAY_IN_SECONDS;
		}

		return $now + 7 * DAY_IN_SECONDS;
	}

	/**
	 * Write only the changed fields: the account may be stale.
	 *
	 * @param array $account Account properties.
	 * @param array $changes Changed fields.
	 * @return array The account with the changes.
	 */
	private function save( array $account, array $changes ) {
		Models_Accounts::instance()->refresh();
		Models_Accounts::instance()->update( $account['id'], $changes );
		return array_merge( $account, $changes );
	}

	/**
	 * Request a renewed access_token to the proxy.
	 *
	 * @param string      $access_token Account access_token.
	 * @param integer     $renew_attempts Account renew attempts.
	 * @param string|null $account_id Instagram account id, used by the Business proxy.
	 * @return array
	 */
	public function request( $access_token, $renew_attempts = 0, $account_id = null ) {
		if ( 'PERSONAL' === Access_Token::get_type( $access_token ) ) {
			return ( new Api_Fetch_Personal_Refresh_Access_Token() )->get_data( $access_token, $renew_attempts );
		}

		return ( new Api_Fetch_Business_Refresh_Access_Token() )->get_data( $access_token, $renew_attempts, $account_id );
	}

	/**
	 * Remove the per account event of older versions.
	 *
	 * @param string $id Account id.
	 * @return void
	 */
	public function unschedule( $id ) {
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $id ) );
	}

	private function get_file_logger() {
		if ( ! $this->file_logger ) {
			$this->file_logger = File_Logger::instance();
		}
		return $this->file_logger;
	}

	private function get_notifier() {
		if ( ! $this->notifier ) {
			$this->notifier = Token_Notifier::instance();
		}
		return $this->notifier;
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

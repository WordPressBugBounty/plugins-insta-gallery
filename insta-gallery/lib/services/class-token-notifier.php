<?php

namespace QuadLayers\IGG\Services;

use QuadLayers\IGG\Models\Accounts as Models_Accounts;
use QuadLayers\IGG\Models\Settings as Models_Settings;

/**
 * Emails the admin about an account that has to be reconnected. Each stage
 * is sent once per deadline (the account expiration date):
 *
 * - expiring: 14 days before the deadline.
 * - final: 3 days before the deadline.
 * - stopped: the token was rejected twice in a row (190), or a Personal
 *   token expired because its renewal failed.
 *
 * A Business Page token past its deadline that still works (Meta doesn't
 * always stop it) gets no email: "stopped" is only sent on a real failure.
 */
class Token_Notifier {

	/**
	 * Failed sends before giving up on a stage, so a mail setup that always
	 * reports failure doesn't turn into a daily email.
	 */
	const MAX_SEND_ATTEMPTS = 3;

	const STAGES = array(
		''         => 0,
		'expiring' => 1,
		'final'    => 2,
		'stopped'  => 3,
	);

	protected static $instance;

	private $email_service;
	private $file_logger;

	/**
	 * @param Email_Service|null $email_service Mailer, defaults to Email_Service.
	 * @param File_Logger|null   $file_logger Logger, defaults to File_Logger.
	 */
	public function __construct( $email_service = null, $file_logger = null ) {
		$this->email_service = $email_service;
		$this->file_logger   = $file_logger;
	}

	/**
	 * Send the notice the account is due, if any.
	 *
	 * @param array    $account Account properties.
	 * @param int|null $now Current timestamp.
	 * @return array The account with the changes.
	 */
	public function evaluate( array $account, $now = null ) {
		$now      = null === $now ? time() : (int) $now;
		$deadline = intval( $account['access_token_expiration_date'] ?? 0 );
		$stage    = (string) ( $account['notice_stage'] ?? '' );

		// The deadline moved later (reconnected or renewed): start over.
		if ( '' !== $stage && $deadline > intval( $account['notice_deadline'] ?? 0 ) + DAY_IN_SECONDS ) {
			$account = $this->save(
				$account,
				array(
					'notice_stage'    => '',
					'notice_deadline' => 0,
					'notice_attempts' => 0,
				)
			);
			$stage   = '';
		}

		$target = $this->get_target_stage( $account, $now );

		if ( self::STAGES[ $target ] <= self::STAGES[ $stage ] ) {
			return $account;
		}

		$recipients = $this->get_recipients();

		if ( ! $recipients ) {
			$this->get_file_logger()->log( 'WARNING', 'No valid recipient for the token notice', array( 'account_id' => $account['id'], 'stage' => $target ), 'email' );
			return $account;
		}

		list( $subject, $message ) = $this->compose( $target, $account, $now );

		$sent     = $this->get_email_service()->send( $target, $recipients, $subject, $message, $this->get_file_logger() );
		$attempts = intval( $account['notice_attempts'] ?? 0 ) + 1;

		if ( ! $sent && $attempts < self::MAX_SEND_ATTEMPTS ) {
			return $this->save( $account, array( 'notice_attempts' => $attempts ) );
		}

		if ( ! $sent ) {
			$this->get_file_logger()->log( 'ERROR', 'Token notice not sent, giving up', array( 'account_id' => $account['id'], 'stage' => $target ), 'email' );
		}

		return $this->save(
			$account,
			array(
				'notice_stage'    => $target,
				'notice_deadline' => $deadline,
				'notice_attempts' => 0,
			)
		);
	}

	/**
	 * Valid addresses of the alert setting (comma separated), else the site
	 * admin email.
	 *
	 * @return string[]
	 */
	public function get_recipients() {
		$settings   = Models_Settings::instance()->get();
		$recipients = array();

		foreach ( explode( ',', (string) ( $settings['mail_to_alert'] ?? '' ) ) as $email ) {
			$email = trim( $email );
			if ( is_email( $email ) ) {
				$recipients[] = $email;
			}
		}

		if ( ! $recipients && is_email( get_option( 'admin_email' ) ) ) {
			$recipients[] = get_option( 'admin_email' );
		}

		return $recipients;
	}

	/**
	 * @param array $account Account properties.
	 * @param int   $now Current timestamp.
	 * @return string Stage key, '' for none.
	 */
	private function get_target_stage( array $account, $now ) {
		$remaining   = intval( $account['access_token_expiration_date'] ?? 0 ) - $now;
		$failures    = intval( $account['access_token_failures'] ?? 0 );
		$attempts    = max( 0, intval( $account['access_token_renew_attempts'] ?? 0 ) );
		$is_personal = 'PERSONAL' === Access_Token::get_type( $account['access_token'] ?? '' );

		if ( $failures >= 2 || ( $is_personal && $remaining < 0 && ( $attempts > 0 || $failures > 0 ) ) ) {
			return 'stopped';
		}

		if ( $remaining > 0 && $remaining <= 3 * DAY_IN_SECONDS ) {
			return 'final';
		}

		if ( $remaining > 3 * DAY_IN_SECONDS && $remaining <= 14 * DAY_IN_SECONDS ) {
			return 'expiring';
		}

		return '';
	}

	/**
	 * Subject and message of a notice.
	 *
	 * @param string $stage Stage key.
	 * @param array  $account Account properties.
	 * @param int    $now Current timestamp.
	 * @return string[] Subject and message.
	 */
	private function compose( $stage, array $account, $now ) {
		$is_personal = 'PERSONAL' === Access_Token::get_type( $account['access_token'] ?? '' );
		$deadline    = intval( $account['access_token_expiration_date'] ?? 0 );
		$days        = max( 1, (int) ceil( ( $deadline - $now ) / DAY_IN_SECONDS ) );

		$site      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		// "Instagram account @name" or "Instagram account ID 123".
		$name      = ! empty( $account['username'] )
			? '@' . $account['username']
			/* translators: %s: Instagram account id */
			: sprintf( __( 'ID %s', 'insta-gallery' ), $account['id'] );
		$date      = wp_date( get_option( 'date_format' ), $deadline );
		$home      = home_url();
		/* translators: %d: number of days */
		$in_days   = sprintf( _n( '%d day from now', '%d days from now', $days, 'insta-gallery' ), $days );
		$reconnect = admin_url( 'admin.php?page=qligg_backend&tab=accounts&qligg_reconnect=' . ( $is_personal ? 'personal' : 'business' ) );

		$hi = __( 'Hi,', 'insta-gallery' );

		switch ( $stage ) {
			case 'expiring':
				if ( $is_personal ) {
					/* translators: 1: site name, 2: Instagram account */
					$subject    = __( '[%1$s] Instagram account %2$s could not be renewed automatically', 'insta-gallery' );
					$paragraphs = array(
						$hi,
						/* translators: 1: site name, 2: Instagram account, 3: date, 4: site URL, 5: days from now */
						__( 'Social Feed Gallery on %1$s (%4$s) renews the connection to the Instagram account %2$s automatically, but the last attempts failed. If this continues, the feed will stop showing new posts on %3$s (%5$s).', 'insta-gallery' ),
						/* translators: 6: reconnect link */
						__( "To fix it, open this link and approve access in the Instagram window:\n%6\$s", 'insta-gallery' ),
						__( 'We will keep trying in the background and remind you once more, 3 days before that date.', 'insta-gallery' ),
					);
					break;
				}
				/* translators: 1: site name, 2: Instagram account, 3: date */
				$subject    = __( '[%1$s] Reconnect Instagram account %2$s before %3$s', 'insta-gallery' );
				$paragraphs = array(
					$hi,
					/* translators: 1: site name, 2: Instagram account, 3: date, 4: site URL, 5: days from now */
					__( 'The Instagram account %2$s used by Social Feed Gallery on %1$s (%4$s) needs to be reconnected by %3$s (%5$s).', 'insta-gallery' ),
					/* translators: 3: date */
					__( "Meta asks accounts connected through Facebook to approve access again from time to time. If you don't, the feed may stop showing new posts after %3\$s.", 'insta-gallery' ),
					/* translators: 6: reconnect link */
					__( "Reconnecting takes about a minute. Open this link and approve access in the Facebook window:\n%6\$s", 'insta-gallery' ),
					__( 'We will remind you once more, 3 days before that date.', 'insta-gallery' ),
				);
				break;

			case 'final':
				/* translators: 1: site name, 2: Instagram account, 3: date */
				$subject    = __( '[%1$s] Last reminder: reconnect Instagram account %2$s by %3$s', 'insta-gallery' );
				$paragraphs = array(
					$hi,
					/* translators: 1: site name, 2: Instagram account, 3: date, 4: site URL, 5: days from now */
					__( 'This is the last reminder. The connection to the Instagram account %2$s on %1$s (%4$s) ends on %3$s (%5$s).', 'insta-gallery' ),
					$is_personal
						? __( 'After that date the feed will stop showing new posts.', 'insta-gallery' )
						: __( 'After that date the feed may stop showing new posts.', 'insta-gallery' ),
					/* translators: 6: reconnect link */
					__( "Open this link and approve access to keep it working:\n%6\$s", 'insta-gallery' ),
					__( 'If you already reconnected the account, you can ignore this email.', 'insta-gallery' ),
				);
				break;

			default:
				/* translators: 1: site name, 2: Instagram account */
				$subject    = __( '[%1$s] Instagram feed for %2$s has stopped updating', 'insta-gallery' );
				$paragraphs = array(
					$hi,
					/* translators: 1: site name, 2: Instagram account, 4: site URL */
					__( "Instagram no longer accepts the connection to the account %2\$s on %1\$s (%4\$s), so the feed can't show new posts.", 'insta-gallery' ),
					/* translators: 6: reconnect link */
					__( "To restore it, open this link and approve access again:\n%6\$s", 'insta-gallery' ),
					__( "This is the only email about this problem. We won't write about this account again until it is reconnected.", 'insta-gallery' ),
				);
		}

		/* translators: 1: site name, 4: site URL, 7: settings link */
		$paragraphs[] = __( "--\nSent by the Social Feed Gallery plugin on %1\$s (%4\$s).\nTo change who receives these emails, go to Social Feed Gallery > Settings: %7\$s", 'insta-gallery' );

		$args = array( $site, $name, $date, $home, $in_days, $reconnect, admin_url( 'admin.php?page=qligg_backend&tab=settings' ) );

		return array(
			$this->fill( $subject, $args ),
			$this->fill( implode( "\n\n", $paragraphs ), $args ),
		);
	}

	/**
	 * Replace the numbered placeholders (%1$s). Unlike vsprintf, a translation
	 * with a literal % or an unknown placeholder doesn't throw.
	 *
	 * @param string   $text Text with placeholders.
	 * @param string[] $args Values, %1$s is the first.
	 * @return string
	 */
	private function fill( $text, array $args ) {
		return preg_replace_callback(
			'/%(\d)\$[sd]/',
			function ( $match ) use ( $args ) {
				return isset( $args[ $match[1] - 1 ] ) ? $args[ $match[1] - 1 ] : $match[0];
			},
			$text
		);
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

	private function get_email_service() {
		if ( ! $this->email_service ) {
			$this->email_service = Email_Service::instance();
		}
		return $this->email_service;
	}

	private function get_file_logger() {
		if ( ! $this->file_logger ) {
			$this->file_logger = File_Logger::instance();
		}
		return $this->file_logger;
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

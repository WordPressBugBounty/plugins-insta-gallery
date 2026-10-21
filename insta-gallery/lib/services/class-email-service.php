<?php

namespace QuadLayers\IGG\Services;

use QuadLayers\IGG\Services\File_Logger;

final class Email_Service {

	protected static $instance;

	/**
	 * Send an email with a single wp_mail call. A failure is retried by the
	 * next daily token check, not here: retrying with sleep() blocked the cron
	 * request.
	 *
	 * @param string          $email_type Type of email being sent.
	 * @param string|string[] $recipient Email recipients.
	 * @param string          $subject Email subject.
	 * @param string          $message Email message.
	 * @param File_Logger     $file_logger Logger instance.
	 * @return bool Whether wp_mail accepted the email.
	 */
	public function send( $email_type, $recipient, $subject, $message, $file_logger ) {
		$email_result = (bool) wp_mail( $recipient, $subject, $message );

		$file_logger->log(
			$email_result ? 'SUCCESS' : 'ERROR',
			$email_result ? 'Email sent successfully' : 'Email sending failed',
			array(
				'type'      => $email_type,
				'recipient' => $recipient,
				'subject'   => $subject,
			),
			'email'
		);

		return $email_result;
	}

	/**
	 * @deprecated 5.0.13 Use send(): it no longer retries.
	 *
	 * @param string      $email_type Type of email being sent.
	 * @param string      $recipient Email recipient.
	 * @param string      $subject Email subject.
	 * @param string      $message Email message.
	 * @param File_Logger $file_logger Logger instance.
	 * @param int         $max_retries Ignored.
	 * @param int         $retry_delay Ignored.
	 * @return bool
	 */
	public function send_email_with_retry( $email_type, $recipient, $subject, $message, $file_logger, $max_retries = 3, $retry_delay = 5 ) {
		return $this->send( $email_type, $recipient, $subject, $message, $file_logger );
	}

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

<?php
/**
 * Alert delivery for Fatal Plugin Auto Deactivator (normal-request side).
 *
 * The shutdown handler sends alerts directly when it can; anything it could not
 * send (transport functions missing during a very early fatal) lands in the
 * fpad_alert_queue option, which this class drains on normal requests.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Notifier
 */
class FPAD_Notifier {

	/**
	 * Hook the queue drain into normal requests, plus the hourly cron backstop
	 * for sites whose traffic never reaches wp-admin.
	 */
	public static function init() {
		// Priority 20 so pluggables (wp_mail overrides) are settled first.
		add_action( 'init', array( __CLASS__, 'drain_queue' ), 20 );
		add_action( 'fpad_notifier_drain', array( __CLASS__, 'drain_queue' ) );
	}

	/**
	 * Deliver queued alerts left behind by the shutdown handler.
	 */
	public static function drain_queue() {
		$queue = get_option( 'fpad_alert_queue', array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		// Failure retries are reserved for the hourly cron backstop: the on-init
		// drain only attempts fresh items, so a permanently failing transport
		// (e.g. a broken SMTP relay with a long timeout) cannot hang every page
		// load. Skip early — before any lock churn — when there is nothing this
		// drain is allowed to attempt.
		$is_backstop = doing_action( 'fpad_notifier_drain' );
		if ( ! $is_backstop ) {
			$has_fresh = false;
			foreach ( $queue as $item ) {
				if ( is_array( $item ) && empty( $item['attempts'] ) ) {
					$has_fresh = true;
					break;
				}
			}
			if ( ! $has_fresh ) {
				return;
			}
		}

		// Best-effort concurrency guard: two simultaneous requests (or init plus
		// the cron backstop) must not double-send the same queued alerts.
		if ( get_transient( 'fpad_notifier_lock' ) ) {
			return;
		}
		set_transient( 'fpad_notifier_lock', 1, 60 );

		try {
			self::process_queue( $queue, $is_backstop );
		} catch ( Throwable $e ) {
			// A failed drain must never break a normal request; retry next run.
		}

		delete_transient( 'fpad_notifier_lock' );
	}

	/**
	 * Send a test notification through one channel, for the Settings tab button.
	 *
	 * @param string $channel 'email' or 'webhook'.
	 * @return array {
	 *     @type bool   $success Whether the send succeeded.
	 *     @type string $detail  HTTP code or error detail, for the admin notice.
	 * }
	 */
	public static function send_test( $channel ) {
		$settings = FPAD_Admin::get_settings();

		if ( 'email' === $channel ) {
			$to = self::recipients( $settings );
			if ( '' === $to ) {
				return array(
					'success' => false,
					'detail'  => __( 'No recipient address is configured.', 'fatal-plugin-auto-deactivator' ),
				);
			}

			$subject = '[' . get_bloginfo( 'name' ) . '] ' . __( 'Test notification from Fatal Plugin Auto Deactivator', 'fatal-plugin-auto-deactivator' );
			$body    = __( 'This is a test notification. Fatal error alerts from this site will look similar to this email.', 'fatal-plugin-auto-deactivator' )
				. "\n\n" . 'Log: ' . admin_url( 'tools.php?page=fpad-log' );

			if ( wp_mail( $to, $subject, $body ) ) {
				return array(
					'success' => true,
					'detail'  => __( 'Email accepted for delivery.', 'fatal-plugin-auto-deactivator' ),
				);
			}

			return array(
				'success' => false,
				'detail'  => __( 'wp_mail() could not send the message. Check your mail configuration.', 'fatal-plugin-auto-deactivator' ),
			);
		}

		if ( 'webhook' === $channel ) {
			$url = $settings['notify_webhook_url'];
			if ( '' === $url ) {
				return array(
					'success' => false,
					'detail'  => __( 'No webhook URL is configured.', 'fatal-plugin-auto-deactivator' ),
				);
			}

			// Payloads are machine-consumed, so they stay untranslated like the
			// real alert payloads.
			if ( 'slack' === $settings['notify_webhook_format'] ) {
				$payload = array( 'text' => '🟢 ' . home_url() . ': test notification from Fatal Plugin Auto Deactivator.' );
			} else {
				$payload = array(
					'event'    => 'fpad.test',
					'site_url' => home_url(),
					'message'  => 'Test notification from Fatal Plugin Auto Deactivator.',
				);
			}

			// Blocking on purpose: the admin asked for a test and wants the result.
			$response = wp_remote_post(
				$url,
				self::webhook_args( $url, wp_json_encode( $payload ), true, 10 )
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'success' => false,
					'detail'  => $response->get_error_message(),
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			return array(
				'success' => $code >= 200 && $code < 300,
				'detail'  => 'HTTP ' . $code,
			);
		}

		return array(
			'success' => false,
			'detail'  => __( 'Unknown notification channel.', 'fatal-plugin-auto-deactivator' ),
		);
	}

	/**
	 * Send a message through every enabled channel — the generic entry point for
	 * other plugin components (e.g. the protection watchdog's
	 * 'fpad.protection_lost' / 'fpad.protection_restored' events).
	 *
	 * Must never throw: callers use it from health-check paths that have to
	 * survive a broken mail or HTTP stack.
	 *
	 * @param string $event   Machine event name, e.g. 'fpad.protection_lost'.
	 * @param string $message Human-readable message.
	 * @param array  $data    Optional extra payload fields; $data['severity'] of
	 *                        'bad' or 'good' selects the Slack emoji, and
	 *                        $data['subject'] overrides the email subject (it is
	 *                        stripped from webhook payloads).
	 */
	public static function dispatch_event( $event, $message, $data = array() ) {
		try {
			$settings = FPAD_Admin::get_settings();

			$email_enabled   = ! empty( $settings['notify_email'] );
			$webhook_enabled = ! empty( $settings['notify_webhook'] ) && '' !== $settings['notify_webhook_url'];

			// Callers pass a translated subject via $data['subject']; the derived
			// event label is only the fallback for callers that do not.
			$subject = ( is_array( $data ) && ! empty( $data['subject'] ) )
				? (string) $data['subject']
				: '[' . get_bloginfo( 'name' ) . '] ' . self::event_label( $event );
			$body    = $message . "\n\n" . __( 'Log:', 'fatal-plugin-auto-deactivator' ) . ' ' . admin_url( 'tools.php?page=fpad-log' );

			if ( $email_enabled ) {
				$to = self::recipients( $settings );
				if ( '' !== $to ) {
					wp_mail( $to, $subject, $body );
				}
			}

			if ( $webhook_enabled ) {
				if ( 'slack' === $settings['notify_webhook_format'] ) {
					$emoji = '';
					if ( isset( $data['severity'] ) && 'bad' === $data['severity'] ) {
						$emoji = '🔴 ';
					} elseif ( isset( $data['severity'] ) && 'good' === $data['severity'] ) {
						$emoji = '🟢 ';
					}
					$payload = array( 'text' => $emoji . $message );
				} else {
					$extra = is_array( $data ) ? $data : array();
					unset( $extra['subject'] ); // Email-only concern; keep payloads machine-clean.
					$payload = array_merge(
						array(
							'event'    => $event,
							'site_url' => home_url(),
							'message'  => $message,
						),
						$extra
					);
				}

				wp_remote_post(
					$settings['notify_webhook_url'],
					self::webhook_args( $settings['notify_webhook_url'], wp_json_encode( $payload ), false, 3 )
				);
			}

			if ( ! $email_enabled && ! $webhook_enabled ) {
				// No channel configured, but the message still matters (protection
				// lost/restored), so fall back to the site admin email.
				wp_mail( get_option( 'admin_email' ), $subject, $body );
			}
		} catch ( Throwable $e ) {
			// Alerting must never take down the caller.
		}
	}

	/**
	 * Deliver each queued item, honoring the alert state, then persist leftovers.
	 *
	 * @param array $queue       Queue entries from the fpad_alert_queue option.
	 * @param bool  $is_backstop Whether this drain is the hourly cron (the only
	 *                           context allowed to retry previously failed items).
	 */
	private static function process_queue( $queue, $is_backstop ) {
		$settings = FPAD_Admin::get_settings();

		$state = get_option( 'fpad_alert_state', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		// Suppression decisions compare against the state as it was BEFORE this
		// drain: comparing against a state mutated in-loop would let the first
		// delivered item suppress its same-fingerprint sibling on the other
		// channel (both queued by the same fatal).
		$initial_state = $state;

		// Identities of the items read at drain start, so the final write can
		// preserve anything a concurrently crashing request appended meanwhile.
		$seen = array();
		foreach ( $queue as $item ) {
			if ( is_array( $item ) ) {
				$seen[ self::queue_item_key( $item ) ] = true;
			}
		}

		$remaining   = array();
		$state_dirty = false;

		foreach ( $queue as $item ) {
			if ( ! is_array( $item ) || empty( $item['channel'] ) ) {
				continue;
			}

			$channel     = (string) $item['channel'];
			$fingerprint = isset( $item['fingerprint'] ) ? (string) $item['fingerprint'] : '';
			$item_time   = isset( $item['time'] ) ? (int) $item['time'] : 0;
			$attempts    = isset( $item['attempts'] ) ? (int) $item['attempts'] : 0;

			// Expire undeliverable items instead of retrying forever: after 24 h
			// or 5 failed attempts the alert is stale anyway (the incident is in
			// the log) and each retry costs a transport timeout.
			if ( $attempts >= 5 || ( $item_time && ( time() - $item_time ) > DAY_IN_SECONDS ) ) {
				continue;
			}

			// Previously failed items wait for the hourly cron backstop.
			if ( $attempts > 0 && ! $is_backstop ) {
				$remaining[] = $item;
				continue;
			}

			// Cooldown re-check, per channel: only a LATER send on this item's own
			// channel makes the queued copy redundant. A sibling channel's direct
			// send (same fatal, different transport availability) never does.
			if ( '' !== $fingerprint
				&& isset( $initial_state[ $fingerprint ] )
				&& is_array( $initial_state[ $fingerprint ] )
				&& isset( $initial_state[ $fingerprint ][ $channel ] )
				&& (int) $initial_state[ $fingerprint ][ $channel ] > $item_time ) {
				continue;
			}

			$sent = false;

			// Per-item guard: a transport that THROWS (an SMTP plugin's hook, an
			// HTTP filter) counts as a failed attempt but must not abort the
			// batch — otherwise items already sent above would never be removed
			// and would duplicate on the next drain.
			try {
				if ( 'email' === $channel && ! empty( $settings['notify_email'] ) ) {
					$to = self::recipients( $settings );
					if ( '' !== $to ) {
						$sent = wp_mail(
							$to,
							isset( $item['subject'] ) ? (string) $item['subject'] : '',
							isset( $item['payload'] ) ? (string) $item['payload'] : ''
						);
					}
				} elseif ( 'webhook' === $channel && ! empty( $settings['notify_webhook'] ) && '' !== $settings['notify_webhook_url'] ) {
					$response = wp_remote_post(
						$settings['notify_webhook_url'],
						self::webhook_args( $settings['notify_webhook_url'], isset( $item['payload'] ) ? (string) $item['payload'] : '', false, 3 )
					);
					$sent = ! is_wp_error( $response );
				} else {
					// The channel was disabled after the item was queued: drop it.
					continue;
				}
			} catch ( Throwable $e ) {
				$sent = false;
			}

			if ( $sent ) {
				if ( '' !== $fingerprint ) {
					if ( ! isset( $state[ $fingerprint ] ) || ! is_array( $state[ $fingerprint ] ) ) {
						$state[ $fingerprint ] = array();
					}
					$state[ $fingerprint ][ $channel ] = time();
					$state_dirty                       = true;
				}
			} else {
				// Failed attempt: count it and leave the retry to the cron backstop.
				$item['attempts'] = $attempts + 1;
				$remaining[]      = $item;
			}
		}

		// Merge-on-write: re-read the queue and keep any entry that was not part
		// of the batch we read at drain start (appended by a crashing request
		// while we were sending). Shrinks the clobber window to milliseconds.
		$latest = get_option( 'fpad_alert_queue', array() );
		if ( is_array( $latest ) ) {
			foreach ( $latest as $item ) {
				if ( is_array( $item ) && empty( $seen[ self::queue_item_key( $item ) ] ) ) {
					$remaining[] = $item;
				}
			}
		}

		update_option( 'fpad_alert_queue', $remaining, false );

		if ( $state_dirty ) {
			$now = time();
			foreach ( $state as $fp => $channels ) {
				$latest_ts = 0;
				foreach ( (array) $channels as $ts ) {
					$latest_ts = max( $latest_ts, (int) $ts );
				}
				if ( ( $now - $latest_ts ) > 604800 ) { // Older than 7 days.
					unset( $state[ $fp ] );
				}
			}
			update_option( 'fpad_alert_state', $state, false );
		}
	}

	/**
	 * Stable identity for a queue entry, used by the merge-on-write in
	 * process_queue().
	 *
	 * @param array $item Queue entry.
	 * @return string
	 */
	private static function queue_item_key( $item ) {
		return md5(
			( isset( $item['channel'] ) ? $item['channel'] : '' ) . '|'
			. ( isset( $item['fingerprint'] ) ? $item['fingerprint'] : '' ) . '|'
			. ( isset( $item['time'] ) ? $item['time'] : '' )
		);
	}

	/**
	 * Arguments for an alert webhook POST — mirrors the hardening in
	 * FPAD_Fatal_Error_Handler::webhook_request_args() (sync pair): no
	 * redirects, request-time URL re-validation except for the documented
	 * loopback exception.
	 *
	 * @param string $url      Webhook URL.
	 * @param string $body     JSON request body.
	 * @param bool   $blocking Whether to wait for the response.
	 * @param int    $timeout  Request timeout in seconds.
	 * @return array
	 */
	private static function webhook_args( $url, $body, $blocking, $timeout ) {
		$host     = wp_parse_url( $url, PHP_URL_HOST );
		$loopback = in_array( $host, array( 'localhost', '127.0.0.1' ), true );

		return array(
			'timeout'            => $timeout,
			'blocking'           => $blocking,
			'redirection'        => 0,
			'reject_unsafe_urls' => ! $loopback,
			'body'               => $body,
			'headers'            => array( 'Content-Type' => 'application/json' ),
		);
	}

	/**
	 * Schedule the queue-drain cron if a notification channel is enabled and it
	 * is not scheduled yet.
	 *
	 * Called from the settings save, from activation, and from the admin_init
	 * drop-in check: the cron is cleared on deactivation, so without the latter
	 * two a deactivate/reactivate cycle would silently strand the hourly
	 * backstop while notifications remain enabled.
	 */
	public static function maybe_schedule_drain() {
		$settings = FPAD_Admin::get_settings();
		$enabled  = ! empty( $settings['notify_email'] )
			|| ( ! empty( $settings['notify_webhook'] ) && '' !== $settings['notify_webhook_url'] );

		if ( $enabled && ! wp_next_scheduled( 'fpad_notifier_drain' ) ) {
			wp_schedule_event( time(), 'hourly', 'fpad_notifier_drain' );
		}
	}

	/**
	 * Resolve the alert email recipients: configured list, or the admin email.
	 *
	 * @param array $settings Normalized settings from FPAD_Admin::get_settings().
	 * @return string Comma-separated recipients, or '' when none can be resolved.
	 */
	private static function recipients( $settings ) {
		if ( '' !== $settings['notify_email_to'] ) {
			return $settings['notify_email_to'];
		}

		return (string) get_option( 'admin_email' );
	}

	/**
	 * Human-readable label derived from a machine event name, for email subjects
	 * ('fpad.protection_lost' becomes 'Protection lost').
	 *
	 * @param string $event Machine event name.
	 * @return string
	 */
	private static function event_label( $event ) {
		$label = trim( str_replace( array( 'fpad.', '_' ), array( '', ' ' ), (string) $event ) );
		if ( '' === $label ) {
			$label = 'notification';
		}

		return ucfirst( $label );
	}
}

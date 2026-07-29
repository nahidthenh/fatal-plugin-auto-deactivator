<?php
/**
 * Plugin lifecycle management for Fatal Plugin Auto Deactivator
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Plugin_Lifecycle
 *
 * Handles plugin activation, deactivation, and uninstall processes
 */
class FPAD_Plugin_Lifecycle {

	/**
	 * Initialize lifecycle hooks
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'check_dropin' ) );
		add_action( 'fpad_watchdog_check', array( __CLASS__, 'watchdog_check' ) );
	}

	/**
	 * Plugin activation hook
	 */
	public static function activate() {
		// Install the drop-in
		$dropin_manager = new FPAD_Dropin_Manager();
		$dropin_manager->install_dropin();

		// Schedule the protection watchdog.
		self::schedule_watchdog();

		// Restore the alert-drain cron if notifications were left enabled across
		// a deactivate/reactivate cycle (settings persist; the cron does not).
		FPAD_Notifier::maybe_schedule_drain();
	}

	/**
	 * Plugin deactivation hook
	 */
	public static function deactivate() {
		// Remove the drop-in
		$dropin_manager = new FPAD_Dropin_Manager();
		$dropin_manager->remove_dropin();

		// The alert-queue drain is scheduled lazily when a notification channel
		// is enabled; always clear it so no orphan cron event survives.
		wp_clear_scheduled_hook( 'fpad_notifier_drain' );

		// Stop the protection watchdog.
		wp_clear_scheduled_hook( 'fpad_watchdog_check' );
	}

	/**
	 * Check if the drop-in is installed and working
	 */
	public static function check_dropin() {
		$dropin_manager = new FPAD_Dropin_Manager();

		// If the drop-in is not installed, try to install it
		if ( ! $dropin_manager->is_dropin_installed() ) {
			$dropin_manager->install_dropin();
		}

		// Upgrade path: sites updated from a pre-watchdog version never
		// re-activate, so make sure the watchdog event exists. Same self-heal for
		// the alert-drain cron while a notification channel is enabled.
		self::schedule_watchdog();
		FPAD_Notifier::maybe_schedule_drain();
	}

	/**
	 * Schedule the watchdog cron event if it is not scheduled yet.
	 */
	protected static function schedule_watchdog() {
		if ( wp_next_scheduled( 'fpad_watchdog_check' ) ) {
			return;
		}

		/**
		 * Filters the recurrence of the protection watchdog cron event.
		 *
		 * Must be the name of a registered cron schedule; unknown names fall
		 * back to 'hourly'. This is the plugin's first public filter.
		 *
		 * @since 1.5.0
		 *
		 * @param string $interval Cron schedule name. Default 'hourly'.
		 */
		$interval = apply_filters( 'fpad_watchdog_interval', 'hourly' );

		$schedules = wp_get_schedules();
		if ( ! is_string( $interval ) || ! isset( $schedules[ $interval ] ) ) {
			$interval = 'hourly';
		}

		wp_schedule_event( time(), $interval, 'fpad_watchdog_check' );
	}

	/**
	 * Watchdog cron callback: verify protection, self-heal, alert.
	 *
	 * Flow per check:
	 * - active            → reset incident state; send a recovery notification
	 *                       only if the loss itself had been alerted.
	 * - missing / foreign → attempt remove + reinstall of our drop-in and
	 *                       re-verify (foreign is reclaimed at most once per
	 *                       24 h so two plugins never fight over the slot).
	 * - still not active  → record the incident and alert, at most once per
	 *                       24 h per distinct status.
	 */
	public static function watchdog_check() {
		$manager      = new FPAD_Dropin_Manager();
		$verification = $manager->verify_protection();
		$status       = $verification['status'];

		$state = self::get_watchdog_state();
		$prev  = $state;
		$now   = time();

		$state['last_check'] = $now;

		// Self-heal: missing and foreign are repairable by reinstalling our
		// copy. A foreign drop-in is reclaimed at most once per 24 h — if it
		// comes back foreign, another plugin wants the slot and reinstalling
		// again would just loop; stop reinstalling and alert instead.
		if ( 'missing' === $status || 'foreign' === $status ) {
			$allow_heal = true;
			if ( 'foreign' === $status ) {
				$allow_heal = ( 0 === $state['reclaim_attempts'] ) || ( ( $now - $state['last_reclaim'] ) >= DAY_IN_SECONDS );
			}

			if ( $allow_heal ) {
				if ( 'foreign' === $status ) {
					$state['reclaim_attempts'] = $state['reclaim_attempts'] + 1;
					$state['last_reclaim']     = $now;
				}

				$manager->remove_dropin();
				$manager->install_dropin();

				$verification = $manager->verify_protection();
				$status       = $verification['status'];
			}
		}

		if ( 'active' === $status ) {
			// Recovery (or a silent self-heal): alert only when the loss itself had
			// been alerted, and at most once per 24 h — so a flapping status (e.g.
			// a competing plugin rewriting the drop-in between checks) cannot
			// produce hourly lost/restored ping-pong.
			if ( '' !== $state['alerted_status'] && ( $now - $state['last_recovery_alert'] ) >= DAY_IN_SECONDS ) {
				self::send_watchdog_alert( 'fpad.protection_restored', $state['alerted_status'], '' );
				$state['last_recovery_alert'] = $now;
			}

			$state['status']         = 'active';
			$state['since']          = 0;
			$state['alerted_status'] = '';
			// last_alert / last_alert_status survive recovery on purpose: they keep
			// the once-per-24h lost-alert throttle armed across a flap.

			// Keep the foreign-reclaim back-off armed until it expires:
			// resetting it the moment a reclaim "succeeds" would let two
			// plugins fight over the drop-in slot in an hourly loop. It
			// decays 24 h after the last reclaim.
			if ( $state['last_reclaim'] > 0 && ( $now - $state['last_reclaim'] ) >= DAY_IN_SECONDS ) {
				$state['reclaim_attempts'] = 0;
				$state['last_reclaim']     = 0;
			}
		} else {
			if ( $status !== $state['status'] ) {
				$state['status'] = $status;
				$state['since']  = $now;
			}

			// Alert at most once per 24 h per distinct status, throttled on memory
			// that survives recoveries (see the active branch above).
			if ( $status !== $state['last_alert_status'] || ( $now - $state['last_alert'] ) >= DAY_IN_SECONDS ) {
				self::send_watchdog_alert( 'fpad.protection_lost', $status, $verification['detail'] );
				$state['last_alert']        = $now;
				$state['last_alert_status'] = $status;
			}
			$state['alerted_status'] = $status;
		}

		// Persist the state. To keep a healthy site from churning wp_options,
		// skip the write when nothing but the last_check heartbeat changed and
		// the previous heartbeat is recent (back-to-back manual runs); on the
		// hourly cadence the heartbeat is always written so the "Protection
		// last verified" line stays truthful. Single update_option, default
		// autoload.
		$prev_rest  = $prev;
		$state_rest = $state;
		unset( $prev_rest['last_check'], $state_rest['last_check'] );

		if ( $state_rest !== $prev_rest || ( $now - $prev['last_check'] ) >= 10 * MINUTE_IN_SECONDS ) {
			update_option( 'fpad_watchdog_state', $state, false );
		}
	}

	/**
	 * Read the watchdog state option, normalized so every field exists.
	 *
	 * @return array {
	 *     @type string $status              Status as of the end of the last check.
	 *     @type int    $since               When the current incident started (0 when healthy).
	 *     @type int    $last_alert          When the last lost-alert was sent (0 when none). Survives recovery.
	 *     @type string $last_alert_status   Status that lost-alert was about. Survives recovery (flap throttle).
	 *     @type int    $last_recovery_alert When the last restored-alert was sent (0 when none).
	 *     @type int    $last_check          When the watchdog last ran (0 when never).
	 *     @type int    $reclaim_attempts    Foreign-drop-in reclaims within the back-off window.
	 *     @type int    $last_reclaim        When the last foreign reclaim happened (0 when none).
	 *     @type string $alerted_status      Status of the outstanding, alerted incident ('' when none).
	 * }
	 */
	protected static function get_watchdog_state() {
		$state = get_option( 'fpad_watchdog_state', array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array(
			'status'              => isset( $state['status'] ) ? (string) $state['status'] : 'active',
			'since'               => isset( $state['since'] ) ? (int) $state['since'] : 0,
			'last_alert'          => isset( $state['last_alert'] ) ? (int) $state['last_alert'] : 0,
			'last_alert_status'   => isset( $state['last_alert_status'] ) ? (string) $state['last_alert_status'] : '',
			'last_recovery_alert' => isset( $state['last_recovery_alert'] ) ? (int) $state['last_recovery_alert'] : 0,
			'last_check'          => isset( $state['last_check'] ) ? (int) $state['last_check'] : 0,
			'reclaim_attempts'    => isset( $state['reclaim_attempts'] ) ? (int) $state['reclaim_attempts'] : 0,
			'last_reclaim'        => isset( $state['last_reclaim'] ) ? (int) $state['last_reclaim'] : 0,
			'alerted_status'      => isset( $state['alerted_status'] ) ? (string) $state['alerted_status'] : '',
		);
	}

	/**
	 * Send a protection-lost or protection-restored notification.
	 *
	 * Uses FPAD_Notifier when the alerts feature is available; otherwise falls
	 * back to a direct email so the watchdog works standalone.
	 *
	 * @param string $event  fpad.protection_lost or fpad.protection_restored.
	 * @param string $status The protection status the alert is about.
	 * @param string $detail Technical detail from verify_protection(), may be ''.
	 */
	protected static function send_watchdog_alert( $event, $status, $detail ) {
		$restored = ( 'fpad.protection_restored' === $event );
		$site     = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

		if ( $restored ) {
			$message = __( 'Fatal error protection has been restored — the fatal error handler drop-in is installed and working again.', 'fatal-plugin-auto-deactivator' );
			/* translators: %s: site name */
			$subject = sprintf( __( '[%s] Fatal error protection restored', 'fatal-plugin-auto-deactivator' ), $site );
		} else {
			$message = self::describe_status( $status );
			/* translators: %s: site name */
			$subject = sprintf( __( '[%s] Fatal error protection is not active', 'fatal-plugin-auto-deactivator' ), $site );
		}

		if ( '' !== $detail ) {
			$message .= "\n\n" . $detail;
		}

		// The notifier appends the log-page link (where the status banner and the
		// one-click reinstall live) to every message, so it is not added here. It
		// also falls back to mailing the site admin when no channel is configured,
		// and carries the translated subject built above.
		FPAD_Notifier::dispatch_event(
			$event,
			$message,
			array(
				'status'   => $status,
				'severity' => $restored ? 'good' : 'bad',
				'subject'  => $subject,
			)
		);
	}

	/**
	 * Human explanation of a non-active protection status, for alert bodies.
	 *
	 * Mirrors the wording of FPAD_Admin::protection_message() (which is
	 * private to the admin class) so alerts and the admin banner tell the
	 * same story. Keep the two in sync.
	 *
	 * @param string $status Protection status.
	 * @return string
	 */
	protected static function describe_status( $status ) {
		switch ( $status ) {
			case 'foreign':
				return __( 'Another plugin currently owns wp-content/fatal-error-handler.php, so Fatal Plugin Auto Deactivator is not protecting your site.', 'fatal-plugin-auto-deactivator' );
			case 'unwritable':
				return __( 'Your wp-content directory is not writable, so the protection file could not be installed. Check file permissions.', 'fatal-plugin-auto-deactivator' );
			case 'no_filesystem':
				return __( 'WordPress could not access the filesystem (credentials may be required), so the protection file could not be installed.', 'fatal-plugin-auto-deactivator' );
			case 'missing':
				return __( 'The protection file is not installed, so your site is not currently protected.', 'fatal-plugin-auto-deactivator' );
			case 'disabled':
				return __( 'The WP_DISABLE_FATAL_ERROR_HANDLER constant is enabled (usually in wp-config.php), so WordPress never runs any fatal error handler and Fatal Plugin Auto Deactivator cannot protect your site. Remove that constant to restore protection.', 'fatal-plugin-auto-deactivator' );
			case 'stranded':
				return __( 'The protection file is installed but points at nothing: the plugin folder appears to have been moved or renamed, so wp-content/plugins/fatal-plugin-auto-deactivator no longer contains the handler it tries to load. Restore the plugin to that folder to fix protection.', 'fatal-plugin-auto-deactivator' );
		}

		/* translators: %s: internal protection status keyword */
		return sprintf( __( 'Fatal error protection is not active (status: %s).', 'fatal-plugin-auto-deactivator' ), $status );
	}

	/**
	 * Clean up plugin data on uninstall
	 */
	public static function uninstall() {
		// Remove the drop-in
		$dropin_manager = new FPAD_Dropin_Manager();
		$dropin_manager->remove_dropin();

		// Stop the protection watchdog.
		wp_clear_scheduled_hook( 'fpad_watchdog_check' );

		// Delete plugin options
		delete_option( 'fpad_deactivated_plugins' );
		delete_option( 'fpad_deactivation_log' );
		delete_option( 'fpad_settings' );
		delete_option( 'fpad_alert_state' );
		delete_option( 'fpad_alert_queue' );
		delete_option( 'fpad_watchdog_state' );

		// Uninstall can run without a prior deactivation on this request; make
		// sure the alert-drain cron is gone either way.
		wp_clear_scheduled_hook( 'fpad_notifier_drain' );
	}
}

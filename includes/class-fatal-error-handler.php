<?php
/**
 * Custom Fatal Error Handler for WordPress
 *
 * This class extends WordPress's built-in fatal error handling to automatically
 * deactivate plugins that cause fatal errors.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Fatal_Error_Handler
 */
class FPAD_Fatal_Error_Handler {

	/**
	 * Handle fatal errors
	 */
	public function handle() {
		// Free the buffer reserved by the drop-in so an out-of-memory fatal leaves
		// the handler ~256 KB of headroom to log and send alerts.
		unset( $GLOBALS['fpad_reserved_memory'] );

		try {
			// During WordPress's plugin/theme editor syntax check (a loopback "scrape"
			// request), step aside so core can finish its own validation. Our exit()
			// would otherwise suppress core's scrape result markers.
			if ( defined( 'WP_SANDBOX_SCRAPING' ) && WP_SANDBOX_SCRAPING ) {
				return;
			}

			// Detect the error
			$error = $this->detect_error();
			if ( ! $error ) {
				return;
			}

			// Decide what to do about the plugin responsible: deactivate it, or just
			// attribute it when log-only mode or the protected-plugins list applies.
			$plugin_result = $this->maybe_deactivate_plugin( $error );

			// Always record the fatal error in our log, regardless of WP_DEBUG and
			// regardless of whether the error could be attributed to a plugin.
			$this->add_to_deactivation_log( $error, $plugin_result );

			// Render and flush the error page BEFORE sending alerts: by the time a
			// mail/HTTP transport runs (either can hang on a broken stack, and
			// wp_mail has no timeout control), the visitor already has the page.
			$page_rendered = $this->display_custom_error_page( $error, $plugin_result );

			// Best-effort instant alerts; internally guarded so an alerting failure
			// cannot skip the exit below.
			$this->maybe_notify( $error, $plugin_result );

			if ( $page_rendered ) {
				exit;
			}
		} catch ( Throwable $e ) {
			// Catch any error or exception thrown by the handler and remain silent
		}
	}

	/**
	 * Detect if a fatal error occurred
	 *
	 * @return array|false Error array or false if no error
	 */
	protected function detect_error() {
		$error = error_get_last();

		if ( ! $error ) {
			return false;
		}

		$fatal_error_types = array(
			E_ERROR,
			E_PARSE,
			E_CORE_ERROR,
			E_COMPILE_ERROR,
			E_USER_ERROR,
			E_RECOVERABLE_ERROR,
		);

		if ( ! in_array( $error['type'], $fatal_error_types, true ) ) {
			return false;
		}

		return $error;
	}

	/**
	 * Find the active plugin whose directory contains the error file.
	 *
	 * Pure matching with no side effects. Returns the plugin basename, or '' when
	 * the fatal cannot be attributed to an active plugin.
	 *
	 * @param array $error Error information.
	 * @return string Plugin basename, or '' when no active plugin matches.
	 */
	protected function match_active_plugin( $error ) {
		// Normalize separators so prefix matching works on Windows too, mirroring
		// detect_error_source(); the raw match here previously failed on Windows
		// paths and on single-file plugins.
		$error_file  = str_replace( '\\', '/', $error['file'] );
		$plugin_root = rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' );

		// PHP reports the resolved (symlink-free) path in error_get_last(), while
		// WP_PLUGIN_DIR is the logical path. Symlinked plugins — common in local
		// dev setups — therefore never matched the logical path alone, so compare
		// both spellings on each side.
		$error_files = self::path_variants( $error_file );

		foreach ( $this->get_active_plugins() as $plugin_base ) {
			$plugin_dir = dirname( $plugin_base );

			if ( '.' === $plugin_dir ) {
				// Single-file plugin (e.g. hello.php): match the file exactly instead
				// of "WP_PLUGIN_DIR/.", which never matches anything.
				$targets = self::path_variants( $plugin_root . '/' . $plugin_base );
				if ( array_intersect( $error_files, $targets ) ) {
					return $plugin_base;
				}
				continue;
			}

			// Directory plugin: prefix match against the folder. The trailing slash
			// stops "akismet" from matching a sibling "akismet-pro" directory.
			foreach ( self::path_variants( $plugin_root . '/' . $plugin_dir ) as $target ) {
				foreach ( $error_files as $candidate ) {
					if ( 0 === strpos( $candidate, $target . '/' ) ) {
						return $plugin_base;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Build the list of spellings a path may appear under.
	 *
	 * Returns the normalized path plus its resolved counterpart when the two
	 * differ (i.e. when a symlink is involved). Uses only core PHP so it stays
	 * safe in the shutdown context.
	 *
	 * @param string $path Path to expand.
	 * @return array Unique normalized paths, without trailing slashes.
	 */
	public static function path_variants( $path ) {
		$path     = rtrim( str_replace( '\\', '/', $path ), '/' );
		$variants = array( $path );

		$real = @realpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir restrictions must not break detection.
		if ( is_string( $real ) && '' !== $real ) {
			$real = rtrim( str_replace( '\\', '/', $real ), '/' );
			if ( $real !== $path ) {
				$variants[] = $real;
			}
		}

		return $variants;
	}

	/**
	 * Check whether a file lives inside a directory, symlinks included.
	 *
	 * @param array  $files Path variants of the file (see path_variants()).
	 * @param string $dir   Directory to test against.
	 * @return bool
	 */
	public static function path_is_inside( $files, $dir ) {
		foreach ( self::path_variants( $dir ) as $target ) {
			foreach ( $files as $file ) {
				if ( 0 === strpos( $file, $target . '/' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check whether a file lives inside a symlinked direct child of a root.
	 *
	 * A plugin or theme symlinked in individually resolves to a path outside
	 * wp-content, so a prefix test against the root alone never matches. Scans
	 * the root's direct children and compares their resolved paths instead.
	 *
	 * @param array  $files Path variants of the file (see path_variants()).
	 * @param string $root  Directory whose children should be resolved.
	 * @return bool
	 */
	public static function matches_symlinked_child( $files, $root ) {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );

		$children = @scandir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_scandir -- shutdown context: WP_Filesystem is unavailable and a warning must not derail detection.
		if ( ! is_array( $children ) ) {
			return false;
		}

		foreach ( $children as $child ) {
			if ( '.' === $child || '..' === $child || ! is_link( $root . '/' . $child ) ) {
				continue;
			}

			if ( self::path_is_inside( $files, $root . '/' . $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decide what to do about the plugin responsible for the fatal error.
	 *
	 * Honors the user's settings: "log only" mode and the protected-plugins
	 * allowlist both attribute the fatal without deactivating anything.
	 *
	 * @param array $error Error information.
	 * @return array|null Outcome array (see build_plugin_result()), or null when no
	 *                    active plugin could be attributed.
	 */
	protected function maybe_deactivate_plugin( $error ) {
		$plugin_base = $this->match_active_plugin( $error );

		if ( '' === $plugin_base ) {
			return null;
		}

		$settings = $this->get_settings();

		// Master switch: detect and log, but never deactivate.
		if ( $settings['log_only'] ) {
			return $this->build_plugin_result( $plugin_base, $error, false, 'log_only' );
		}

		// Allowlist: this plugin is too important to drop on a single fatal.
		if ( in_array( $plugin_base, $settings['protected_plugins'], true ) ) {
			return $this->build_plugin_result( $plugin_base, $error, false, 'protected' );
		}

		$result = $this->deactivate_plugin( $plugin_base, $error );

		// deactivate_plugins() may be unavailable in a very early shutdown; still
		// attribute the fatal so the log and page can report it honestly.
		if ( null === $result ) {
			return $this->build_plugin_result( $plugin_base, $error, false, 'unavailable' );
		}

		return $result;
	}

	/**
	 * Read the plugin settings in a shutdown-safe, guarded way.
	 *
	 * The drop-in only loads this handler class, so settings are read directly here
	 * rather than depending on the admin layer.
	 *
	 * @return array {
	 *     @type bool   $log_only              Detect and log only; never deactivate.
	 *     @type array  $protected_plugins     Plugin basenames that must never be deactivated.
	 *     @type bool   $notify_email          Email alert channel enabled.
	 *     @type string $notify_email_to       Comma-separated recipients; '' means the admin email.
	 *     @type bool   $notify_webhook        Webhook alert channel enabled.
	 *     @type string $notify_webhook_url    Webhook endpoint URL.
	 *     @type string $notify_webhook_format 'json' or 'slack'.
	 *     @type array  $notify_statuses       Outcome statuses that trigger an alert.
	 *     @type int    $notify_cooldown       Per-fingerprint alert cooldown in seconds.
	 * }
	 */
	protected function get_settings() {
		// Mirrored in FPAD_Admin::get_settings() (sync pair): defaults and
		// normalization must stay identical on both sides.
		$notify_statuses_default = array( 'deactivated', 'protected', 'log_only', 'unavailable', 'unattributed' );

		$defaults = array(
			'log_only'              => false,
			'protected_plugins'     => array(),
			'notify_email'          => false,
			'notify_email_to'       => '',
			'notify_webhook'        => false,
			'notify_webhook_url'    => '',
			'notify_webhook_format' => 'json',
			'notify_statuses'       => $notify_statuses_default,
			'notify_cooldown'       => 900,
		);

		if ( ! function_exists( 'get_option' ) ) {
			return $defaults;
		}

		$settings = get_option( 'fpad_settings', array() );
		if ( ! is_array( $settings ) ) {
			return $defaults;
		}

		return array(
			'log_only'              => ! empty( $settings['log_only'] ),
			'protected_plugins'     => ( isset( $settings['protected_plugins'] ) && is_array( $settings['protected_plugins'] ) )
				? $settings['protected_plugins']
				: array(),
			'notify_email'          => ! empty( $settings['notify_email'] ),
			'notify_email_to'       => ( isset( $settings['notify_email_to'] ) && is_string( $settings['notify_email_to'] ) )
				? $settings['notify_email_to']
				: '',
			'notify_webhook'        => ! empty( $settings['notify_webhook'] ),
			'notify_webhook_url'    => ( isset( $settings['notify_webhook_url'] ) && is_string( $settings['notify_webhook_url'] ) )
				? $settings['notify_webhook_url']
				: '',
			'notify_webhook_format' => ( isset( $settings['notify_webhook_format'] ) && in_array( $settings['notify_webhook_format'], array( 'json', 'slack' ), true ) )
				? $settings['notify_webhook_format']
				: 'json',
			// A saved-but-empty array is a deliberate "notify about nothing" choice;
			// only a missing/malformed value falls back to the defaults.
			'notify_statuses'       => ( isset( $settings['notify_statuses'] ) && is_array( $settings['notify_statuses'] ) )
				? array_values( array_intersect( $settings['notify_statuses'], $notify_statuses_default ) )
				: $notify_statuses_default,
			'notify_cooldown'       => ( isset( $settings['notify_cooldown'] ) && is_numeric( $settings['notify_cooldown'] ) )
				? max( 60, min( 86400, (int) $settings['notify_cooldown'] ) )
				: 900,
		);
	}

	/**
	 * Determine where a fatal error originated based on its file path.
	 *
	 * Order matters: mu-plugins and plugins live under wp-content, and drop-ins
	 * sit directly in the wp-content root, so the most specific directories are
	 * checked before the broader ones.
	 *
	 * @param array $error Error information.
	 * @return string One of: plugin, theme, mu-plugin, drop-in, core, unknown.
	 */
	protected function detect_error_source( $error ) {
		$file = isset( $error['file'] ) ? $error['file'] : '';

		if ( '' === $file ) {
			return 'unknown';
		}

		// Normalize directory separators so prefix matching works on Windows too.
		$file = str_replace( '\\', '/', $file );

		$normalize = function ( $path ) {
			return rtrim( str_replace( '\\', '/', $path ), '/' );
		};

		// Symlinked plugin/theme directories report their resolved path in the
		// error, so compare both spellings (see path_variants()).
		$files = self::path_variants( $file );

		// Must-use plugins (checked before the generic plugins directory).
		if ( defined( 'WPMU_PLUGIN_DIR' ) && self::path_is_inside( $files, WPMU_PLUGIN_DIR ) ) {
			return 'mu-plugin';
		}

		// Regular plugins.
		if ( defined( 'WP_PLUGIN_DIR' ) && self::path_is_inside( $files, WP_PLUGIN_DIR ) ) {
			return 'plugin';
		}

		// Themes.
		$theme_root = '';
		if ( function_exists( 'get_theme_root' ) ) {
			$theme_root = $normalize( get_theme_root() );
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$theme_root = $normalize( WP_CONTENT_DIR ) . '/themes';
		}
		if ( '' !== $theme_root && self::path_is_inside( $files, $theme_root ) ) {
			return 'theme';
		}

		// Individually symlinked plugins/themes (a dev-setup staple) resolve to a
		// path outside wp-content entirely, so the root checks above miss them.
		// Resolve each direct child of the roots before giving up.
		if ( defined( 'WPMU_PLUGIN_DIR' ) && self::matches_symlinked_child( $files, WPMU_PLUGIN_DIR ) ) {
			return 'mu-plugin';
		}
		if ( defined( 'WP_PLUGIN_DIR' ) && self::matches_symlinked_child( $files, WP_PLUGIN_DIR ) ) {
			return 'plugin';
		}
		if ( '' !== $theme_root && self::matches_symlinked_child( $files, $theme_root ) ) {
			return 'theme';
		}

		// Drop-ins live directly in the wp-content root.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content_dir = $normalize( WP_CONTENT_DIR );
			$dropins     = array(
				'advanced-cache.php',
				'object-cache.php',
				'db.php',
				'db-error.php',
				'fatal-error-handler.php',
				'maintenance.php',
				'php-error.php',
				'sunrise.php',
				'blog-deleted.php',
				'blog-inactive.php',
				'blog-suspended.php',
			);
			foreach ( $dropins as $name ) {
				if ( array_intersect( $files, self::path_variants( $content_dir . '/' . $name ) ) ) {
					return 'drop-in';
				}
			}
		}

		// WordPress core files.
		if ( defined( 'ABSPATH' ) ) {
			$abspath = $normalize( ABSPATH );
			if ( self::path_is_inside( $files, $abspath . '/wp-includes' ) || self::path_is_inside( $files, $abspath . '/wp-admin' ) ) {
				return 'core';
			}
		}

		return 'unknown';
	}

	/**
	 * Get all active plugins
	 *
	 * @return array List of active plugins
	 */
	protected function get_active_plugins() {
		// Make sure we have access to WordPress functions
		if ( ! function_exists( 'get_option' ) ) {
			if ( file_exists( ABSPATH . 'wp-includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-includes/plugin.php';
			}
			if ( file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
		}

		// Get active plugins
		return get_option( 'active_plugins', array() );
	}

	/**
	 * Deactivate a plugin and log the error
	 *
	 * @param string $plugin_base Plugin base name
	 * @param array $error Error information
	 */
	protected function deactivate_plugin( $plugin_base, $error ) {
		// Make sure we have access to WordPress functions
		if ( ! function_exists( 'deactivate_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Without deactivate_plugins() we cannot act; let the caller attribute the
		// fatal without deactivating.
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			return null;
		}

		// Deactivate the plugin
		deactivate_plugins( $plugin_base );
		//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( "Fatal Plugin Auto Deactivator: Auto-deactivated plugin: {$plugin_base} due to fatal error in: {$error['file']}" );

		// Store deactivated plugin info for admin notice
		$this->store_deactivated_plugin_info( $plugin_base, $error );

		return $this->build_plugin_result( $plugin_base, $error, true, 'deactivated' );
	}

	/**
	 * Resolve a plugin's display name and version from its header, with fallbacks.
	 *
	 * @param string $plugin_base Plugin basename.
	 * @return array Associative array with at least Name and Version keys.
	 */
	protected function get_plugin_header( $plugin_base ) {
		$plugin_data = array(
			'Name'        => $plugin_base,
			'PluginURI'   => '',
			'Description' => '',
			'Version'     => '',
			'Author'      => '',
		);

		if ( ! function_exists( 'get_plugin_data' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( function_exists( 'get_plugin_data' ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_base ) ) {
			$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_base, false, false );
			if ( empty( $data['Name'] ) ) {
				$data['Name'] = $plugin_base;
			}
			$plugin_data = $data;
		}

		return $plugin_data;
	}

	/**
	 * Build the outcome array passed to the logger and the error page.
	 *
	 * @param string $plugin_base Plugin basename.
	 * @param array  $error       Error information.
	 * @param bool   $deactivated Whether the plugin was actually deactivated.
	 * @param string $status      One of: deactivated, log_only, protected, unavailable.
	 * @return array
	 */
	protected function build_plugin_result( $plugin_base, $error, $deactivated, $status ) {
		$plugin_data = $this->get_plugin_header( $plugin_base );

		return array(
			'plugin_base'    => $plugin_base,
			'plugin_name'    => $plugin_data['Name'],
			'plugin_version' => $plugin_data['Version'],
			'error'          => $error,
			'deactivated'    => (bool) $deactivated,
			'status'         => $status,
		);
	}

	/**
	 * Store information about the deactivated plugin for admin notices
	 *
	 * @param string $plugin_base Plugin base name
	 * @param array $error Error information
	 */
	protected function store_deactivated_plugin_info( $plugin_base, $error ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		// Store for admin notice (temporary storage, cleared after displaying)
		$deactivated_plugins   = get_option( 'fpad_deactivated_plugins', array() );
		$deactivated_plugins[] = array(
			'plugin' => $plugin_base,
			'error'  => $error,
			'time'   => time(),
		);
		update_option( 'fpad_deactivated_plugins', $deactivated_plugins );
	}

	/**
	 * Add an entry to the permanent error log.
	 *
	 * Called for every detected fatal error so administrators can review it on
	 * the Fatal Plugin Log page, whether or not a plugin could be attributed and
	 * deactivated.
	 *
	 * Identical, repeated fatals are coalesced into a single entry with an
	 * occurrence count, so one looping error cannot churn the 100-entry cap and
	 * evict distinct incidents.
	 *
	 * @param array      $error         Error information
	 * @param array|null $plugin_result Outcome from maybe_deactivate_plugin(), or null if no plugin was identified
	 */
	protected function add_to_deactivation_log( $error, $plugin_result = null ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		// Get the current log
		$deactivation_log = get_option( 'fpad_deactivation_log', array() );
		if ( ! is_array( $deactivation_log ) ) {
			$deactivation_log = array();
		}

		// Resolve plugin details and outcome from the result, if any.
		$plugin_base = $plugin_result ? $plugin_result['plugin_base'] : '';
		$plugin_name = $plugin_result ? $plugin_result['plugin_name'] : '';
		$deactivated = $plugin_result ? ! empty( $plugin_result['deactivated'] ) : false;
		$status      = $plugin_result ? $plugin_result['status'] : 'unattributed';

		$now = time();

		$message = $this->cap_error_message( isset( $error['message'] ) ? (string) $error['message'] : '' );

		// Create a new log entry. The extra context (request URL, PHP/WP version) is
		// read from constants/superglobals only, so it stays shutdown-safe.
		$log_entry = array(
			'plugin'      => $plugin_base,
			'plugin_name' => $plugin_name,
			'deactivated' => $deactivated,
			'status'      => $status,
			'error_type'  => $error['type'],
			'error_msg'   => $message,
			'error_file'  => $error['file'],
			'error_line'  => $error['line'],
			'time'        => $now,
			'first_time'  => $now,
			'count'       => 1,
			'date'        => gmdate( 'Y-m-d H:i:s' ),
			'request_uri' => $this->current_request_uri(),
			'php_version' => PHP_VERSION,
			'wp_version'  => isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '',
		);

		// Coalesce an identical, already-logged fatal instead of inserting a duplicate.
		$fingerprint = $this->log_fingerprint( $log_entry );
		foreach ( $deactivation_log as $index => $existing ) {
			if ( $this->log_fingerprint( $existing ) === $fingerprint ) {
				$existing_count          = isset( $existing['count'] ) ? (int) $existing['count'] : 1;
				$log_entry['count']      = $existing_count + 1;
				$log_entry['first_time'] = isset( $existing['first_time'] )
					? $existing['first_time']
					: ( isset( $existing['time'] ) ? $existing['time'] : $now );
				unset( $deactivation_log[ $index ] );
				$deactivation_log = array_values( $deactivation_log );
				break;
			}
		}

		// Add to the log, newest (most recently seen) first; cap to prevent bloat.
		array_unshift( $deactivation_log, $log_entry );
		if ( count( $deactivation_log ) > 100 ) {
			$deactivation_log = array_slice( $deactivation_log, 0, 100 );
		}

		// Update the log
		update_option( 'fpad_deactivation_log', $deactivation_log );
	}

	/**
	 * Bound an error message so no consumer can bloat storage or payloads.
	 *
	 * Used by both the log writer and the alert pipeline, so the two stay
	 * consistent and their fingerprints agree. Cut on a character boundary so a
	 * multibyte UTF-8 sequence isn't split.
	 *
	 * @param string $message Raw error message.
	 * @return string Message capped at 2000 characters.
	 */
	protected function cap_error_message( $message ) {
		if ( strlen( $message ) > 2000 ) {
			if ( function_exists( 'mb_substr' ) ) {
				$message = mb_substr( $message, 0, 2000, 'UTF-8' );
			} else {
				$message = substr( $message, 0, 2000 );
				// Drop a trailing partial multibyte sequence left by the byte-wise cut.
				$message = preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', $message );
				$message = preg_replace( '/[\x80-\xBF]+$/', '', $message );
			}
			$message .= '…';
		}

		return $message;
	}

	/**
	 * Fingerprint a log entry so identical, repeated fatals can be coalesced.
	 *
	 * @param array $entry A log entry (new or existing).
	 * @return string
	 */
	protected function log_fingerprint( $entry ) {
		$parts = array(
			isset( $entry['error_type'] ) ? $entry['error_type'] : '',
			isset( $entry['error_file'] ) ? $entry['error_file'] : '',
			isset( $entry['error_line'] ) ? $entry['error_line'] : '',
			isset( $entry['error_msg'] ) ? $entry['error_msg'] : '',
			isset( $entry['plugin'] ) ? $entry['plugin'] : '',
			isset( $entry['status'] ) ? $entry['status'] : '',
		);

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Read the current request URI, sanitized and bounded, in a shutdown-safe way.
	 *
	 * @return string
	 */
	protected function current_request_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri = (string) $_SERVER['REQUEST_URI'];
		$uri = function_exists( 'wp_unslash' ) ? wp_unslash( $uri ) : stripslashes( $uri );

		if ( function_exists( 'sanitize_text_field' ) ) {
			$uri = sanitize_text_field( $uri );
		} else {
			// Pure-PHP fallback for the partially-loaded shutdown context.
			$uri = preg_replace( '/[^\x20-\x7E]/', '', str_replace( array( '<', '>', '"', "'" ), '', $uri ) );
		}

		return strlen( $uri ) > 255 ? substr( $uri, 0, 255 ) : $uri;
	}

	/**
	 * Send instant alerts (email/webhook) for a detected fatal, best effort.
	 *
	 * Runs after the error page has been rendered and flushed, so a slow or
	 * broken transport can only delay the process, never the visitor. Wrapped in
	 * its own Throwable guard: wp_mail()/wp_remote_post() execute third-party
	 * hook code (SMTP plugins, HTTP filters) that may throw in the half-broken
	 * request state after a fatal, and that must not skip handle()'s exit.
	 * Alert strings built here are intentionally untranslated, like the page.
	 *
	 * @param array      $error         Error information.
	 * @param array|null $plugin_result Outcome from maybe_deactivate_plugin(), or null.
	 */
	protected function maybe_notify( $error, $plugin_result ) {
		try {
			$this->send_alerts( $error, $plugin_result );
		} catch ( Throwable $e ) {
			// Alerting must never break the shutdown flow around it.
		}
	}

	/**
	 * The actual alert pipeline behind maybe_notify().
	 *
	 * The cooldown state is kept per fingerprint AND per channel: when one
	 * channel sends directly while the other could only be queued (transports
	 * load at different points in WordPress' boot), a single shared timestamp
	 * would let the sent channel's stamp suppress the queued channel's delivery
	 * forever on a persistently crashing site.
	 *
	 * @param array      $error         Error information.
	 * @param array|null $plugin_result Outcome from maybe_deactivate_plugin(), or null.
	 */
	protected function send_alerts( $error, $plugin_result ) {
		$settings = $this->get_settings();

		$email_enabled   = ! empty( $settings['notify_email'] );
		$webhook_enabled = ! empty( $settings['notify_webhook'] ) && '' !== $settings['notify_webhook_url'];

		if ( ! $email_enabled && ! $webhook_enabled ) {
			return;
		}

		$status = $plugin_result ? $plugin_result['status'] : 'unattributed';
		if ( ! in_array( $status, $settings['notify_statuses'], true ) ) {
			return;
		}

		// Bound the message exactly like the log does (shared cap_error_message),
		// so queue rows, emails, and webhook bodies cannot bloat — and so the
		// alert fingerprint below matches the log's for the same fatal.
		$error['message'] = $this->cap_error_message( isset( $error['message'] ) ? (string) $error['message'] : '' );

		// Rate-limit on the same identity the log uses to coalesce repeats, so one
		// looping fatal cannot flood a channel.
		$fingerprint = $this->log_fingerprint(
			array(
				'error_type' => isset( $error['type'] ) ? $error['type'] : '',
				'error_file' => isset( $error['file'] ) ? $error['file'] : '',
				'error_line' => isset( $error['line'] ) ? $error['line'] : '',
				'error_msg'  => isset( $error['message'] ) ? $error['message'] : '',
				'plugin'     => $plugin_result ? $plugin_result['plugin_base'] : '',
				'status'     => $status,
			)
		);

		$now   = time();
		$state = array();
		if ( function_exists( 'get_option' ) ) {
			$state = get_option( 'fpad_alert_state', array() );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
		}

		$incident = ( isset( $state[ $fingerprint ] ) && is_array( $state[ $fingerprint ] ) )
			? $state[ $fingerprint ]
			: array();

		$cooldown    = (int) $settings['notify_cooldown'];
		$webhook_due = $webhook_enabled
			&& ( ! isset( $incident['webhook'] ) || ( $now - (int) $incident['webhook'] ) >= $cooldown );
		$email_due   = $email_enabled
			&& ( ! isset( $incident['email'] ) || ( $now - (int) $incident['email'] ) >= $cooldown );

		if ( ! $webhook_due && ! $email_due ) {
			return;
		}

		$dirty = false;

		if ( $webhook_due ) {
			$payload = ( 'slack' === $settings['notify_webhook_format'] )
				? $this->build_webhook_slack_payload( $error, $plugin_result, $status )
				: $this->build_webhook_json_payload( $error, $plugin_result, $status );

			$body = function_exists( 'wp_json_encode' )
				? wp_json_encode( $payload )
				//phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				: json_encode( $payload );

			if ( function_exists( 'wp_remote_post' ) ) {
				// The attempt (not delivery proof) stamps the cooldown: a looping
				// fatal must not retry a broken transport on every request.
				$incident['webhook'] = $now;
				$dirty               = true;
				try {
					// Non-blocking so a slow endpoint cannot hold the connection open.
					wp_remote_post(
						$settings['notify_webhook_url'],
						$this->webhook_request_args( $settings['notify_webhook_url'], $body )
					);
				} catch ( Throwable $e ) {
					// A hooked HTTP layer may throw mid-shutdown; the attempt counts.
				}
			} else {
				// Transport not loaded yet (very early fatal): let FPAD_Notifier
				// deliver it on the next normal request.
				$this->queue_alert( 'webhook', $body, '', $fingerprint, $now );
			}
		}

		if ( $email_due ) {
			$subject = $this->build_alert_subject( $plugin_result, $status );
			$message = $this->build_alert_email_body( $error, $plugin_result, $status );

			if ( function_exists( 'wp_mail' ) ) {
				$to = $this->alert_recipients( $settings );
				if ( '' !== $to ) {
					// Attempt-first stamping, same rationale as the webhook branch.
					$incident['email'] = $now;
					$dirty             = true;
					try {
						wp_mail( $to, $subject, $message );
					} catch ( Throwable $e ) {
						// An SMTP plugin's hook may throw mid-shutdown; the attempt counts.
					}
				}
			} else {
				$this->queue_alert( 'email', $message, $subject, $fingerprint, $now );
			}
		}

		if ( $dirty && function_exists( 'update_option' ) ) {
			// Prune stale fingerprints so the state option cannot grow unbounded.
			foreach ( $state as $fp => $channels ) {
				$latest = 0;
				foreach ( (array) $channels as $ts ) {
					$latest = max( $latest, (int) $ts );
				}
				if ( ( $now - $latest ) > 604800 ) { // Older than 7 days.
					unset( $state[ $fp ] );
				}
			}
			$state[ $fingerprint ] = $incident;
			update_option( 'fpad_alert_state', $state, false );
		}
	}

	/**
	 * Arguments for an alert webhook POST, hardened for unattended use: never
	 * follow redirects, and re-validate non-loopback URLs at request time
	 * (reject_unsafe_urls) so a DNS change after save cannot re-point the
	 * webhook at an internal host. Loopback targets skip that check because
	 * wp_http_validate_url() always rejects them — they are an explicit,
	 * documented local-development choice (mirrors the save-time rule in
	 * FPAD_Admin::handle_settings_save()).
	 *
	 * @param string $url  Webhook URL.
	 * @param string $body JSON request body.
	 * @return array
	 */
	protected function webhook_request_args( $url, $body ) {
		$host     = function_exists( 'wp_parse_url' )
			? wp_parse_url( $url, PHP_URL_HOST )
			//phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			: parse_url( $url, PHP_URL_HOST );
		$loopback = in_array( $host, array( 'localhost', '127.0.0.1' ), true );

		return array(
			'timeout'            => 3,
			'blocking'           => false,
			'redirection'        => 0,
			'reject_unsafe_urls' => ! $loopback,
			'body'               => $body,
			'headers'            => array( 'Content-Type' => 'application/json' ),
		);
	}

	/**
	 * Queue an alert whose transport function was unavailable at shutdown, for
	 * FPAD_Notifier to deliver on a later, fully-loaded request.
	 *
	 * @param string $channel     'email' or 'webhook'.
	 * @param string $payload     Rendered payload (email body / webhook JSON body).
	 * @param string $subject     Email subject ('' for webhook items).
	 * @param string $fingerprint Alert fingerprint, for the drain's cooldown re-check.
	 * @param int    $now         Current timestamp.
	 */
	protected function queue_alert( $channel, $payload, $subject, $fingerprint, $now ) {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$queue = get_option( 'fpad_alert_queue', array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		// A looping early fatal must not fill the queue with copies of itself and
		// evict other pending alerts.
		foreach ( $queue as $item ) {
			if ( is_array( $item )
				&& isset( $item['channel'], $item['fingerprint'] )
				&& $channel === $item['channel']
				&& $fingerprint === $item['fingerprint'] ) {
				return;
			}
		}

		$entry = array(
			'channel' => $channel,
			'payload' => $payload,
		);
		if ( 'email' === $channel ) {
			$entry['subject'] = $subject;
		}
		$entry['fingerprint'] = $fingerprint;
		$entry['time']        = $now;

		$queue[] = $entry;
		if ( count( $queue ) > 20 ) {
			$queue = array_slice( $queue, -20 );
		}

		update_option( 'fpad_alert_queue', $queue, false );
	}

	/**
	 * Resolve the alert email recipients: configured list, or the admin email.
	 *
	 * @param array $settings Normalized settings from get_settings().
	 * @return string Comma-separated recipients, or '' when none can be resolved.
	 */
	protected function alert_recipients( $settings ) {
		if ( '' !== $settings['notify_email_to'] ) {
			return $settings['notify_email_to'];
		}

		return function_exists( 'get_option' ) ? (string) get_option( 'admin_email' ) : '';
	}

	/**
	 * Alert email subject: "[{site_name}] Fatal error: {plugin|unattributed} {verb}".
	 *
	 * @param array|null $plugin_result Outcome from maybe_deactivate_plugin(), or null.
	 * @param string     $status        Outcome status.
	 * @return string
	 */
	protected function build_alert_subject( $plugin_result, $status ) {
		$site_name   = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '';
		$plugin_name = ( $plugin_result && '' !== $plugin_result['plugin_name'] )
			? $plugin_result['plugin_name']
			: 'unattributed';

		return '[' . $site_name . '] Fatal error: ' . $plugin_name . ' ' . $this->status_verb( $status );
	}

	/**
	 * Plain-text alert email body.
	 *
	 * Field order mirrors FPAD_Admin::build_report() (sync pair): the handler
	 * cannot call the admin class at shutdown, so the layout is duplicated here,
	 * plus the log-page link required for alerts.
	 *
	 * @param array      $error         Error information.
	 * @param array|null $plugin_result Outcome from maybe_deactivate_plugin(), or null.
	 * @param string     $status        Outcome status.
	 * @return string
	 */
	protected function build_alert_email_body( $error, $plugin_result, $status ) {
		$plugin_base = $plugin_result ? $plugin_result['plugin_base'] : '';
		$plugin_name = $plugin_result ? $plugin_result['plugin_name'] : '';

		$lines   = array();
		$lines[] = 'Plugin: ' . ( '' !== $plugin_name ? $plugin_name : 'n/a' )
			. ( '' !== $plugin_base ? ' (' . $plugin_base . ')' : '' );
		$lines[] = 'Status: ' . $status;
		$lines[] = 'Source: ' . $this->detect_error_source( $error );
		$lines[] = 'Error: ' . $this->error_type_name( isset( $error['type'] ) ? $error['type'] : 0 )
			. ': ' . ( isset( $error['message'] ) ? $error['message'] : '' );
		$lines[] = 'File: ' . ( isset( $error['file'] ) ? $error['file'] : '' )
			. ':' . ( isset( $error['line'] ) ? $error['line'] : '' );

		$request_uri = $this->current_request_uri();
		if ( '' !== $request_uri ) {
			$lines[] = 'Request: ' . $request_uri;
		}

		$env = array( 'PHP ' . PHP_VERSION );
		if ( ! empty( $GLOBALS['wp_version'] ) ) {
			$env[] = 'WP ' . $GLOBALS['wp_version'];
		}
		$lines[] = 'Environment: ' . implode( ', ', $env );

		$log_url = '';
		if ( function_exists( 'admin_url' ) ) {
			$log_url = admin_url( 'tools.php?page=fpad-log' );
		} elseif ( '' !== $this->alert_site_url() ) {
			$log_url = $this->alert_site_url() . '/wp-admin/tools.php?page=fpad-log';
		}
		if ( '' !== $log_url ) {
			$lines[] = '';
			$lines[] = 'Log: ' . $log_url;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Webhook payload, generic JSON format (schema per the alerts PRD, FR-4).
	 *
	 * @param array      $error         Error information.
	 * @param array|null $plugin_result Outcome from maybe_deactivate_plugin(), or null.
	 * @param string     $status        Outcome status.
	 * @return array
	 */
	protected function build_webhook_json_payload( $error, $plugin_result, $status ) {
		return array(
			'event'        => 'fpad.fatal_error',
			'site_url'     => $this->alert_site_url(),
			'status'       => $status,
			'deactivated'  => $plugin_result ? ! empty( $plugin_result['deactivated'] ) : false,
			'plugin'       => $plugin_result ? $plugin_result['plugin_base'] : '',
			'plugin_name'  => $plugin_result ? $plugin_result['plugin_name'] : '',
			'error'        => array(
				'type'    => $this->error_type_name( isset( $error['type'] ) ? $error['type'] : 0 ),
				'message' => isset( $error['message'] ) ? (string) $error['message'] : '',
				'file'    => isset( $error['file'] ) ? (string) $error['file'] : '',
				'line'    => isset( $error['line'] ) ? (int) $error['line'] : 0,
			),
			'request_uri'  => $this->current_request_uri(),
			'php_version'  => PHP_VERSION,
			'wp_version'   => isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '',
			'occurred_at'  => time(),
			'fpad_version' => defined( 'FPAD_VERSION' ) ? FPAD_VERSION : '',
		);
	}

	/**
	 * Webhook payload, Slack-compatible format.
	 *
	 * Severity: 🟠 when the site self-healed (plugin deactivated), 🔴 when it is
	 * likely still broken (protected/log_only/unavailable/unattributed).
	 *
	 * @param array      $error         Error information.
	 * @param array|null $plugin_result Outcome from maybe_deactivate_plugin(), or null.
	 * @param string     $status        Outcome status.
	 * @return array
	 */
	protected function build_webhook_slack_payload( $error, $plugin_result, $status ) {
		$emoji = ( 'deactivated' === $status ) ? '🟠' : '🔴';
		$site  = preg_replace( '#^https?://#', '', $this->alert_site_url() );

		$plugin_name = $plugin_result ? $plugin_result['plugin_name'] : '';

		switch ( $status ) {
			case 'deactivated':
				$action = 'automatically deactivated';
				break;
			case 'protected':
				$action = 'NOT deactivated (protected)';
				break;
			case 'log_only':
				$action = 'logged only';
				break;
			default:
				$action = 'could not be deactivated';
				break;
		}

		if ( '' !== $plugin_name ) {
			$headline = $emoji . ' ' . $site . ': Fatal error in ' . $plugin_name . ' — ' . $action . '.';
		} else {
			$headline = $emoji . ' ' . $site . ': Fatal error — could not be attributed to a plugin.';
		}

		$detail = $this->error_type_name( isset( $error['type'] ) ? $error['type'] : 0 )
			. ': ' . ( isset( $error['message'] ) ? $error['message'] : '' )
			. ' in ' . ( isset( $error['file'] ) ? $error['file'] : '' )
			. ':' . ( isset( $error['line'] ) ? $error['line'] : '' );

		return array( 'text' => $headline . "\n" . $detail );
	}

	/**
	 * Human-facing verb for an outcome status, used in the alert subject.
	 *
	 * @param string $status Outcome status.
	 * @return string
	 */
	protected function status_verb( $status ) {
		switch ( $status ) {
			case 'deactivated':
				return 'deactivated';
			case 'protected':
				return 'NOT deactivated (protected)';
			case 'unavailable':
				return 'could not be deactivated';
		}

		// log_only and unattributed fatals were recorded but nothing was disabled.
		return 'logged only';
	}

	/**
	 * PHP error-type constant name (e.g. "E_ERROR") for alert payloads.
	 *
	 * @param int $type PHP error type constant value.
	 * @return string
	 */
	protected function error_type_name( $type ) {
		switch ( $type ) {
			case E_ERROR:
				return 'E_ERROR';
			case E_PARSE:
				return 'E_PARSE';
			case E_CORE_ERROR:
				return 'E_CORE_ERROR';
			case E_COMPILE_ERROR:
				return 'E_COMPILE_ERROR';
			case E_USER_ERROR:
				return 'E_USER_ERROR';
			case E_RECOVERABLE_ERROR:
				return 'E_RECOVERABLE_ERROR';
		}

		return 'E_UNKNOWN';
	}

	/**
	 * Best-effort site URL for alert payloads: home_url() when available, a
	 * scheme + HTTP_HOST fallback otherwise, '' as a last resort.
	 *
	 * @return string
	 */
	protected function alert_site_url() {
		if ( function_exists( 'home_url' ) ) {
			return home_url();
		}

		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$scheme = ( isset( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
			// The Host header is request-controlled; keep only characters a URL
			// host can legally contain (the whitelist regex is the sanitizer, and
			// it strips backslashes, which is all unslashing would do here).
			//phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$host = preg_replace( '/[^A-Za-z0-9\.\-\:\[\]]/', '', (string) $_SERVER['HTTP_HOST'] );

			return $scheme . '://' . $host;
		}

		return '';
	}

	/**
	 * Display a custom error page with warning and reload button
	 *
	 * Renders and flushes the page but does NOT exit — handle() exits after the
	 * post-page work (alert sends) so those can never delay the visitor.
	 *
	 * @param array      $error         Error information
	 * @param array|null $plugin_result Outcome from maybe_deactivate_plugin(), or null
	 * @return bool Whether the page was rendered (false when headers were already sent)
	 */
	protected function display_custom_error_page( $error, $plugin_result ) {
		// If output already started (common in shutdown), don't append a broken page
		// mid-stream or trigger "headers already sent" warnings — leave the partial
		// response as-is, mirroring WordPress core's own fatal handler guard.
		if ( headers_sent() ) {
			return false;
		}

		// Set the HTTP status code
		http_response_code( 500 );

		// Get error type as string
		$error_type = 'Unknown Error';
		switch ( $error['type'] ) {
			case E_ERROR:
				$error_type = 'Fatal Error';
				break;
			case E_PARSE:
				$error_type = 'Parse Error';
				break;
			case E_CORE_ERROR:
				$error_type = 'Core Error';
				break;
			case E_COMPILE_ERROR:
				$error_type = 'Compile Error';
				break;
			case E_USER_ERROR:
				$error_type = 'User Error';
				break;
			case E_RECOVERABLE_ERROR:
				$error_type = 'Recoverable Error';
				break;
		}

		// Get site name and home URL
		$site_name = 'WordPress Site';
		$home_url  = '/';
		if ( function_exists( 'get_bloginfo' ) ) {
			$site_name = get_bloginfo( 'name' );
			$home_url  = home_url();
		}

		// Prepare plugin information. Name/Version come from plugin headers (author
		// controlled), so escape them before they reach the HTML.
		$plugin_info = '';
		if ( $plugin_result && ! empty( $plugin_result['deactivated'] ) ) {
			$plugin_name    = esc_html( $plugin_result['plugin_name'] );
			$plugin_version = $plugin_result['plugin_version'] ? ' v' . esc_html( $plugin_result['plugin_version'] ) : '';
			$plugin_info    = '<p>The plugin <strong>' . $plugin_name . $plugin_version . '</strong> has been automatically deactivated to prevent further errors.</p>';
		}

		// Tailor the messaging to the detected source of the error and whether we
		// were actually able to take corrective action. Only a fatal attributed to
		// a regular plugin can be auto-deactivated; everything else is reported
		// honestly as requiring manual attention.
		$source = $this->detect_error_source( $error );

		switch ( $source ) {
			case 'plugin':
				if ( $plugin_result && ! empty( $plugin_result['deactivated'] ) ) {
					$intro_message   = 'A fatal error was caused by a plugin on your website. The Fatal Plugin Auto Deactivator identified the plugin and automatically deactivated it to resolve the issue.';
					$generic_message = 'A plugin caused a technical error. The issue has been resolved by automatically deactivating the problematic plugin.';
					$closing_message = 'You can now safely reload the page to continue browsing the site.';
				} elseif ( $plugin_result && 'protected' === $plugin_result['status'] ) {
					$intro_message   = 'A fatal error was caused by a plugin on your website. It is on your protected list, so it was not deactivated automatically and needs manual attention.';
					$generic_message = 'A plugin caused a technical error. It was not deactivated automatically because it is on your protected list and may require manual attention.';
					$closing_message = 'You can try reloading the page, but the error may persist until the plugin issue is fixed manually.';
				} elseif ( $plugin_result && 'log_only' === $plugin_result['status'] ) {
					$intro_message   = 'A fatal error was caused by a plugin on your website. Automatic deactivation is turned off (log-only mode), so the plugin was recorded but not deactivated and needs manual attention.';
					$generic_message = 'A plugin caused a technical error. Automatic deactivation is turned off, so it was only logged and may require manual attention.';
					$closing_message = 'You can try reloading the page, but the error may persist until the plugin issue is fixed manually.';
				} else {
					$intro_message   = 'A fatal error appears to have been caused by a plugin, but it could not be deactivated automatically. The plugin may need to be disabled manually.';
					$generic_message = 'A plugin caused a technical error, but it could not be deactivated automatically and may require manual attention.';
					$closing_message = 'You can try reloading the page, but the error may persist until the plugin is disabled manually.';
				}
				break;

			case 'theme':
				$intro_message   = 'A fatal error appears to be related to your active theme. Themes cannot be deactivated automatically, so this issue could not be resolved for you.';
				$generic_message = 'A technical error appears to originate from your theme and could not be resolved automatically. It may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the theme issue is fixed manually.';
				break;

			case 'mu-plugin':
				$intro_message   = 'A fatal error appears to originate from a must-use (MU) plugin. Must-use plugins cannot be deactivated automatically, so this issue could not be resolved for you.';
				$generic_message = 'A technical error appears to originate from a must-use plugin and could not be resolved automatically. It may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the must-use plugin is fixed manually.';
				break;

			case 'drop-in':
				$intro_message   = 'A fatal error appears to be related to a WordPress drop-in. Drop-ins cannot be deactivated automatically, so this issue could not be resolved for you.';
				$generic_message = 'A technical error appears to originate from a drop-in and could not be resolved automatically. It may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the drop-in issue is fixed manually.';
				break;

			case 'core':
				$intro_message   = 'A fatal error appears to be related to WordPress core. Core errors cannot be resolved automatically and usually require manual attention.';
				$generic_message = 'A technical error appears to originate from WordPress core and could not be resolved automatically. It may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the underlying issue is fixed manually.';
				break;

			default:
				$intro_message   = 'A fatal error occurred on your website. The Fatal Plugin Auto Deactivator could not attribute it to a specific plugin, so it could not be resolved automatically.';
				$generic_message = 'A technical error occurred. Its source could not be determined automatically, so it could not be resolved and may require manual attention.';
				$closing_message = 'You can try reloading the page, but the error may persist until the underlying issue is fixed manually.';
				break;
		}

		// Decide whether to expose technical detail on the public page. Default: only
		// when WP_DEBUG is on AND on-screen display is not explicitly disabled, so the
		// recommended production setup (WP_DEBUG=true, WP_DEBUG_DISPLAY=false: log but
		// don't display) does not leak file paths to visitors. The FPAD_SHOW_ERROR_DETAILS
		// constant is an explicit override either way.
		if ( defined( 'FPAD_SHOW_ERROR_DETAILS' ) ) {
			$show_details = (bool) FPAD_SHOW_ERROR_DETAILS;
		} else {
			$show_details = defined( 'WP_DEBUG' ) && WP_DEBUG
				&& ! ( defined( 'WP_DEBUG_DISPLAY' ) && false === WP_DEBUG_DISPLAY );
		}

		// Output the error page
		echo '<!DOCTYPE html>
		<html lang="en">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>' . esc_html( $error_type ) . ' - ' . esc_html( $site_name ) . '</title>
			<style>
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: #f1f1f1;
					color: #444;
					line-height: 1.5;
					margin: 0;
					padding: 0;
				}
				.fpad_error_container {
					max-width: 800px;
					margin: 50px auto;
					padding: 30px;
					background: #fff;
					border-radius: 5px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.1);
				}
				.fpad_error_header {
					background: #dc3232;
					color: #fff;
					padding: 15px 20px;
					margin: -30px -30px 20px;
					border-radius: 5px 5px 0 0;
					display: flex;
					align-items: center;
					justify-content: space-between;
				}
				.fpad_error_header h1 {
					margin: 0;
					font-size: 20px;
					font-weight: 600;
				}
				.fpad_error_details {
					background: #f8f8f8;
					padding: 15px;
					border-radius: 3px;
					margin: 20px 0;
					border-left: 4px solid #ddd;
					overflow-x: auto;
				}
				.fpad_error_message {
					font-family: monospace;
					margin: 0;
					word-break: break-word;
					white-space: pre-wrap;
				}
				.fpad_error_location {
					margin-top: 10px;
					font-size: 14px;
					color: #666;
				}
				.fpad_button {
					display: inline-block;
					padding: 8px 16px;
					background: #0073aa;
					color: #fff;
					text-decoration: none;
					border-radius: 3px;
					cursor: pointer;
					font-size: 14px;
					margin-right: 10px;
				}
				.fpad_button:hover {
					background: #005d8c;
				}
				.fpad_button.fpad_secondary {
					background: #f7f7f7;
					color: #555;
					border: 1px solid #ccc;
				}
				.fpad_button.fpad_secondary:hover {
					background: #f0f0f0;
				}
				.fpad_actions {
					margin-top: 25px;
				}
			</style>
		</head>
		<body>
			<div class="fpad_error_container">
				<div class="fpad_error_header">
					<h1>' . esc_html( $error_type ) . ' Detected</h1>
				</div>
				<p>' . esc_html( $intro_message ) . '</p>' .
		     //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		     ( $show_details ? $plugin_info . '
				<div class="fpad_error_details">
					<p class="fpad_error_message">' . esc_html( $error['message'] ) . '</p>
					<p class="fpad_error_location">File: ' . esc_html( $error['file'] ) . ' on line ' . esc_html( $error['line'] ) . '</p>
				</div>' : '<div class="fpad_error_details">
					<p>' . esc_html( $generic_message ) . '</p>
				</div>' ) . '
				<p>' . esc_html( $closing_message ) . '</p>
				<div class="fpad_actions">
					<a href="' . esc_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) ) . '" class="fpad_button">Reload Page</a>
					<a href="' . esc_url( $home_url ) . '" class="fpad_button fpad_secondary">Go to Homepage</a>
				</div>
			</div>
		</body>
		</html>';

		// Push the page to the client now, so the alert sends that follow happen
		// on an already-delivered response.
		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		} else {
			$level = ob_get_level();
			while ( $level > 0 ) {
				//phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
				if ( ! @ob_end_flush() ) {
					break;
				}
				$new_level = ob_get_level();
				if ( $new_level >= $level ) {
					break; // A non-removable buffer; stop rather than loop.
				}
				$level = $new_level;
			}
		}
		flush();

		return true;
	}
}

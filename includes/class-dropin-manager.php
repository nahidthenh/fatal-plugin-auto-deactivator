<?php
/**
 * Drop-in Manager for Fatal Plugin Auto Deactivator
 *
 * This class handles the creation and removal of the fatal-error-handler.php drop-in.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class FPAD_Dropin_Manager
 */
class FPAD_Dropin_Manager {

	/**
	 * Marker string used to recognize a drop-in this plugin owns.
	 *
	 * @var string
	 */
	const OWNERSHIP_MARKER = 'FPAD_Fatal_Error_Handler';

	/**
	 * Content-version token present in the current drop-in source (both the
	 * tracked file and the generator heredoc — sync pair). An installed drop-in
	 * that is ours but lacks this token predates the current source and gets
	 * refreshed by refresh_if_stale().
	 *
	 * @var string
	 */
	const DROPIN_VERSION_TOKEN = 'fpad-dropin-version: 3';

	/**
	 * The path to the drop-in file in the wp-content directory
	 *
	 * @var string
	 */
	protected $dropin_path;

	/**
	 * The path to the source file for the drop-in
	 *
	 * @var string
	 */
	protected $source_path;

	/**
	 * WordPress Filesystem API
	 *
	 * @var WP_Filesystem_Base
	 */
	protected $filesystem;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->dropin_path = WP_CONTENT_DIR . '/fatal-error-handler.php';
		$this->source_path = FPAD_PLUGIN_DIR . 'includes/fatal-error-handler-dropin.php';
		$this->init_filesystem();
	}

	/**
	 * Initialize the WordPress Filesystem API
	 *
	 * @return bool True if filesystem was initialized successfully, false otherwise
	 */
	protected function init_filesystem() {
		global $wp_filesystem;

		// Include the file.php if it's not already included
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Initialize the filesystem
		$initialized = WP_Filesystem();

		if ( $initialized ) {
			$this->filesystem = $wp_filesystem;
		} else {
			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Fatal Plugin Auto Deactivator: Failed to initialize filesystem' );
		}

		return $initialized;
	}

	/**
	 * Install the drop-in file
	 *
	 * @return bool True on success, false on failure
	 */
	public function install_dropin() {
		// Bail safely if the filesystem could not be initialized (e.g. a host that
		// requires FTP/SSH credentials). Otherwise the $this->filesystem call below
		// would fatal on null — defeating the purpose of a crash-prevention plugin.
		if ( ! $this->filesystem ) {
			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Fatal Plugin Auto Deactivator: Filesystem unavailable; cannot install the drop-in' );

			return false;
		}

		// Create the drop-in source file if it doesn't exist
		if ( ! file_exists( $this->source_path ) ) {
			$this->create_dropin_source();
		}

		// Check if we can write to the wp-content directory
		if ( ! $this->filesystem->is_writable( WP_CONTENT_DIR ) ) {
			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Fatal Plugin Auto Deactivator: Cannot write to wp-content directory' );

			return false;
		}

		// Copy the drop-in file
		$result = copy( $this->source_path, $this->dropin_path );

		if ( $result ) {
			// Set the same permissions as the source file
			$perms = fileperms( $this->source_path );
			$this->filesystem->chmod( $this->dropin_path, $perms );
		}

		return $result;
	}

	/**
	 * Remove the drop-in file
	 *
	 * @return bool True on success, false on failure
	 */
	public function remove_dropin() {
		// Only remove a drop-in we own; never touch a foreign one.
		if ( file_exists( $this->dropin_path ) && $this->dropin_is_ours() ) {
			return wp_delete_file( $this->dropin_path );
		}

		return true;
	}

	/**
	 * Check if our drop-in is installed.
	 *
	 * @return bool True if installed and owned by this plugin, false otherwise
	 */
	public function is_dropin_installed() {
		return file_exists( $this->dropin_path ) && $this->dropin_is_ours();
	}

	/**
	 * Describe the current protection status, for admin surfacing.
	 *
	 * @return string One of: active, foreign, missing, unwritable, no_filesystem.
	 */
	public function get_status() {
		if ( file_exists( $this->dropin_path ) ) {
			return $this->dropin_is_ours() ? 'active' : 'foreign';
		}

		if ( ! $this->filesystem ) {
			return 'no_filesystem';
		}

		if ( ! $this->filesystem->is_writable( WP_CONTENT_DIR ) ) {
			return 'unwritable';
		}

		return 'missing';
	}

	/**
	 * Verify protection end-to-end, for the watchdog and admin surfaces.
	 *
	 * Wraps get_status() with two checks it cannot see: core's global kill
	 * switch (WP_DISABLE_FATAL_ERROR_HANDLER) and a drop-in whose require
	 * target no longer resolves. The four non-active get_status() results
	 * pass through unchanged.
	 *
	 * @return array {
	 *     @type string $status One of: active, foreign, missing, unwritable,
	 *                          no_filesystem, disabled, stranded.
	 *     @type string $detail Short technical detail for logs/alerts
	 *                          (deliberately untranslated).
	 * }
	 */
	public function verify_protection() {
		// Checked first: when core's kill switch is defined truthy, WordPress
		// never invokes any fatal-error-handler drop-in, so every other status
		// would be misleading.
		if ( defined( 'WP_DISABLE_FATAL_ERROR_HANDLER' ) && WP_DISABLE_FATAL_ERROR_HANDLER ) {
			return array(
				'status' => 'disabled',
				'detail' => 'WP_DISABLE_FATAL_ERROR_HANDLER is defined truthy',
			);
		}

		$status = $this->get_status();

		if ( 'active' !== $status ) {
			$details = array(
				'foreign'       => 'Drop-in exists but is not ours: ' . $this->dropin_path,
				'missing'       => 'Drop-in not installed: ' . $this->dropin_path,
				'unwritable'    => 'wp-content directory is not writable: ' . WP_CONTENT_DIR,
				'no_filesystem' => 'WP_Filesystem could not be initialized',
			);

			return array(
				'status' => $status,
				'detail' => isset( $details[ $status ] ) ? $details[ $status ] : $status,
			);
		}

		// The drop-in is ours — but would its require target resolve at crash
		// time? Compute the class-file path the way the DROP-IN itself computes
		// it (relative to WP_CONTENT_DIR), deliberately NOT via the
		// FPAD_PLUGIN_DIR constant: on symlinked installs the two can differ,
		// and it is the drop-in's own path that must resolve when a fatal hits.
		// This is a sync pair with the FPAD_PLUGIN_DIR definition in
		// includes/fatal-error-handler-dropin.php (and its generator).
		$handler_path = WP_CONTENT_DIR . '/plugins/fatal-plugin-auto-deactivator/includes/class-fatal-error-handler.php';

		if ( ! is_file( $handler_path ) || ! is_readable( $handler_path ) ) {
			return array(
				'status' => 'stranded',
				'detail' => 'Drop-in require target is not a readable file: ' . $handler_path,
			);
		}

		return array(
			'status' => 'active',
			'detail' => '',
		);
	}

	/**
	 * Refresh an installed drop-in of ours whose content predates the current
	 * source (missing DROPIN_VERSION_TOKEN).
	 *
	 * The upgrader hook only covers dashboard/CLI updates; git, rsync, and
	 * Composer deploys replace the plugin directory without firing it, leaving
	 * the wp-content copy stale until this runs (admin_init check or watchdog).
	 *
	 * @return bool Whether a refresh was performed and succeeded.
	 */
	public function refresh_if_stale() {
		if ( ! $this->is_dropin_installed() ) {
			return false;
		}

		$content = $this->read_dropin();
		if ( is_string( $content ) && false === strpos( $content, self::DROPIN_VERSION_TOKEN ) ) {
			$this->remove_dropin();

			return (bool) $this->install_dropin();
		}

		return false;
	}

	/**
	 * Read the installed drop-in's contents, or false when it can't be read.
	 *
	 * @return string|false
	 */
	protected function read_dropin() {
		if ( ! file_exists( $this->dropin_path ) || ! is_readable( $this->dropin_path ) ) {
			return false;
		}

		return file_get_contents( $this->dropin_path );
	}

	/**
	 * Whether the installed drop-in is one this plugin owns.
	 *
	 * @return bool
	 */
	protected function dropin_is_ours() {
		$content = $this->read_dropin();

		return is_string( $content ) && false !== strpos( $content, self::OWNERSHIP_MARKER );
	}

	/**
	 * Create the drop-in source file
	 */
	protected function create_dropin_source() {
		// Make sure the includes directory exists
		if ( ! is_dir( FPAD_PLUGIN_DIR . 'includes' ) ) {
			$this->filesystem->mkdir( FPAD_PLUGIN_DIR . 'includes', FS_CHMOD_DIR );
		}

		// Get the content of the fatal error handler class
		$handler_path = FPAD_PLUGIN_DIR . 'includes/class-fatal-error-handler.php';
		if ( ! file_exists( $handler_path ) ) {
			//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Fatal Plugin Auto Deactivator: Fatal error handler class not found' );

			return false;
		}

		// Create the drop-in source file. This must stay in sync with the committed
		// includes/fatal-error-handler-dropin.php: derive paths relative to the
		// drop-in's own location and define QM_DISABLE_ERROR_HANDLER, so a regenerated
		// drop-in behaves identically to the shipped one.
		$dropin_content = '<?php
/**
 * WordPress Fatal Error Handler
 *
 * This drop-in is part of the Fatal Plugin Auto Deactivator plugin.
 * It automatically deactivates plugins that cause fatal errors.
 *
 * @package Fatal_Plugin_Auto_Deactivator
 */

// Define constants needed for WordPress if they are not already defined.
if ( ! defined( \'ABSPATH\' ) ) {
	define( \'ABSPATH\', dirname( dirname( __FILE__ ) ) . \'/\' );
}

// The drop-in lives in wp-content, so the plugin directory is wp-content/plugins/...
if ( ! defined( \'FPAD_PLUGIN_DIR\' ) ) {
	define( \'FPAD_PLUGIN_DIR\', dirname( __FILE__ ) . \'/plugins/fatal-plugin-auto-deactivator/\' );
}

// Reserve a memory buffer the handler frees on entry, so logging and alerts
// still work when the fatal is an out-of-memory error.
$GLOBALS[\'fpad_reserved_memory\'] = str_repeat( \'x\', 256 * 1024 );

// Own an output buffer whenever PHP will print errors to the page: it prints
// the fatal itself before any shutdown handler runs, which would otherwise pin
// the response to HTTP 200 and leave a raw stack trace above our page.
$fpad_ini_display  = strtolower( (string) ini_get( \'display_errors\' ) );
$fpad_shows_errors = ! in_array( $fpad_ini_display, array( \'\', \'0\', \'off\', \'false\', \'stderr\' ), true );
if ( defined( \'WP_DEBUG\' ) && WP_DEBUG ) {
	$fpad_shows_errors = ! defined( \'WP_DEBUG_DISPLAY\' ) || false !== WP_DEBUG_DISPLAY;
}
if ( $fpad_shows_errors ) {
	ob_start();
}
unset( $fpad_ini_display, $fpad_shows_errors );

// Include the fatal error handler class.
if ( ! class_exists( \'FPAD_Fatal_Error_Handler\' ) ) {
	require_once FPAD_PLUGIN_DIR . \'includes/class-fatal-error-handler.php\';
}

// Avoid a conflict with the Query Monitor error handler.
define( \'QM_DISABLE_ERROR_HANDLER\', true );

// fpad-dropin-version: 3 — see includes/fatal-error-handler-dropin.php.

// Return an instance of our custom error handler.
return new FPAD_Fatal_Error_Handler();
';

		file_put_contents( $this->source_path, $dropin_content );
		$this->filesystem->chmod( $this->source_path, 0644 );

		return true;
	}
}

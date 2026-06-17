<?php
/**
 * Logger.
 *
 * Lightweight file logger for debugging request interception and routing.
 * Writes to a log file inside the WordPress uploads directory (never the
 * plugin folder), and is disabled by default so it stays silent in production.
 *
 * @package Koneek
 */

namespace Koneek\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appends timestamped debug entries to a log file in the uploads directory.
 */
class Logger {

	/**
	 * Subdirectory (under uploads) where logs are stored.
	 *
	 * Named after the plugin slug for multisite/one-off compatibility.
	 *
	 * @var string
	 */
	private const LOG_DIR = 'koneek-multi-provider-ai-gateway';

	/**
	 * Log file name.
	 *
	 * @var string
	 */
	private const LOG_FILE = 'koneek.log';

	/**
	 * Cached absolute path to the log file.
	 *
	 * @var string|null
	 */
	private static ?string $log_file = null;

	/**
	 * Whether logging is currently enabled.
	 *
	 * Logging is disabled by default. It can be turned on for troubleshooting by
	 * returning true from the 'koneek_logging_enabled' filter.
	 *
	 * @return bool
	 */
	private static function isEnabled(): bool {
		/**
		 * Filters whether Koneek debug logging is enabled.
		 *
		 * Disabled by default. Return true to enable file logging while
		 * troubleshooting.
		 *
		 * @param bool $enabled Whether logging is enabled.
		 */
		return (bool) apply_filters( 'koneek_logging_enabled', false );
	}

	/**
	 * Resolves the absolute path to the log file inside the uploads directory.
	 *
	 * Creates the log subdirectory (and a protective index.html) on first use.
	 * Returns an empty string when the uploads directory is unavailable.
	 *
	 * @return string Absolute path to the log file, or '' on failure.
	 */
	private static function getLogFile(): string {
		if ( null !== self::$log_file ) {
			return self::$log_file;
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			self::$log_file = '';
			return self::$log_file;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::LOG_DIR;

		if ( ! wp_mkdir_p( $dir ) ) {
			self::$log_file = '';
			return self::$log_file;
		}

		// Drop a silent index file so the directory cannot be browsed.
		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			self::writeFile( $index, '', false );
		}

		self::$log_file = trailingslashit( $dir ) . self::LOG_FILE;

		return self::$log_file;
	}

	/**
	 * Writes content to a file using the WordPress Filesystem API.
	 *
	 * @param string $path   Absolute file path.
	 * @param string $content Content to write.
	 * @param bool   $append Whether to append (true) or overwrite (false).
	 * @return void
	 */
	private static function writeFile( string $path, string $content, bool $append ): void {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Initialize the direct filesystem (no FTP credentials prompt) for
		// our own uploads subdirectory.
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			return;
		}

		if ( $append && $wp_filesystem->exists( $path ) ) {
			$existing = (string) $wp_filesystem->get_contents( $path );
			$content  = $existing . $content;
		}

		$wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
	}

	/**
	 * Writes a log entry.
	 *
	 * @param string $message The message to record.
	 * @param array  $context Optional structured context appended as JSON.
	 * @return void
	 */
	public static function log( string $message, array $context = array() ): void {
		if ( ! self::isEnabled() ) {
			return;
		}

		$log_file = self::getLogFile();

		if ( '' === $log_file ) {
			return;
		}

		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$line      = "[{$timestamp}] {$message}";

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		$line .= "\n";

		self::writeFile( $log_file, $line, true );
	}
}

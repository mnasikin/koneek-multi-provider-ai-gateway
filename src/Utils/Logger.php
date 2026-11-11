<?php
/**
 * Records recent gateway diagnostics in the site's database.
 *
 * @package Koneek
 */

namespace Koneek\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	public const ENABLED_OPTION = 'koneek_logging_enabled';
	private const LOG_OPTION = 'koneek_recent_log';
	private const MAX_LOG_LENGTH = 65536;

	/** @var string[]|null Buffered lines for the active gateway request. */
	private static ?array $activity = null;

	/**
	 * Use the admin preference once saved, with the legacy debug configuration as a default.
	 */
	public static function isEnabled(): bool {
		$setting = get_option( self::ENABLED_OPTION, false );
		if ( false !== $setting ) {
			return '1' === (string) $setting;
		}

		return (bool) apply_filters( 'koneek_logging_enabled', defined( 'KONEEK_DEBUG' ) && KONEEK_DEBUG );
	}

	/**
	 * Returns complete log lines, newest first.
	 */
	public static function getRecentLog(): string {
		if ( ! self::isEnabled() ) {
			return '';
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the latest history after concurrent appends or clears.
		$log = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOG_OPTION )
		);

		// Each entry starts with a newline. Ignore any partial line left by trimming.
		$lines = explode( "\n", $log );
		array_shift( $lines );

		return implode( "\n", array_reverse( $lines ) );
	}

	/**
	 * Removes the stored log history.
	 */
	public static function clear(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The log row is managed directly and has no cached copy.
		return false !== $wpdb->delete( $wpdb->options, array( 'option_name' => self::LOG_OPTION ), array( '%s' ) );
	}

	/**
	 * Records a gateway request and all its fallback attempts as one block.
	 *
	 * @return mixed The gateway result.
	 */
	public static function recordActivity( callable $callback ) {
		if ( null !== self::$activity || ! self::isEnabled() ) {
			return $callback();
		}

		self::$activity = array();
		try {
			return $callback();
		} finally {
			$lines          = self::$activity;
			self::$activity = null;

			if ( ! empty( $lines ) && self::isEnabled() ) {
				self::append( "\n---" . implode( '', $lines ) . "\n---" );
			}
		}
	}

	/**
	 * Appends a timestamped event with diagnostic metadata.
	 */
	public static function log( string $message, array $context = array() ): void {
		if ( ! self::isEnabled() ) {
			return;
		}

		$line = "\n[" . gmdate( 'Y-m-d H:i:s' ) . ' UTC] ' . $message;
		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( $context );
		}

		if ( null !== self::$activity ) {
			self::$activity[] = $line;
			return;
		}

		self::append( "\n---" . $line . "\n---" );
	}

	private static function append( string $entry ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Append and cap history atomically to preserve entries from concurrent requests.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				 VALUES (%s, %s, 'no')
				 ON DUPLICATE KEY UPDATE option_value = RIGHT(CONCAT(option_value, %s), %d)",
				self::LOG_OPTION,
				$entry,
				$entry,
				self::MAX_LOG_LENGTH
			)
		);
	}
}

<?php
/**
 * Encryption Helper.
 *
 * Provides functions to encrypt and decrypt sensitive data (such as API keys)
 * using OpenSSL and WordPress security salts.
 *
 * @package Koneek
 */

namespace Koneek\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles symmetric encryption and decryption for sensitive options.
 */
class Encryption {

	/**
	 * Encrypts a plain text string.
	 *
	 * Uses AES-256-CBC with a dynamic key derived from WordPress auth salts.
	 * Returns a base64 encoded string containing the IV and ciphertext.
	 *
	 * @param string $value The raw value to encrypt.
	 * @return string The encrypted value (base64 string) or empty string on failure.
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$key = self::get_key();
		$method = 'AES-256-CBC';
		$iv_length = openssl_cipher_iv_length( $method );
		$iv = openssl_random_pseudo_bytes( $iv_length );

		$encrypted = openssl_encrypt( $value, $method, $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return '';
		}

		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Decrypts a base64-encoded encrypted string.
	 *
	 * If decryption fails or the input is not base64 encoded, returns the
	 * original input value to ensure backward compatibility with plain text keys.
	 *
	 * @param string $value The encrypted value (base64 string).
	 * @return string The decrypted raw value, or the original value on failure.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$decoded = base64_decode( $value, true );
		if ( false === $decoded ) {
			return $value;
		}

		$method    = 'AES-256-CBC';
		$iv_length = openssl_cipher_iv_length( $method );

		if ( strlen( $decoded ) < $iv_length + 1 ) {
			return $value;
		}

		$iv         = substr( $decoded, 0, $iv_length );
		$ciphertext = substr( $decoded, $iv_length );

		$key       = self::get_key();
		$decrypted = openssl_decrypt( $ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $decrypted ) {
			return $value;
		}

		return $decrypted;
	}

	/**
	 * Derives a 32-byte encryption key from WordPress keys/salts.
	 *
	 * @return string The derived key hash.
	 */
	private static function get_key(): string {
		$key = self::get_raw_salt();

		return hash_hmac( 'sha256', $key, 'koneek-encryption-key' );
	}

	/**
	 * Retrieves the raw salt value from WordPress constants.
	 *
	 * @return string The raw salt string.
	 */
	private static function get_raw_salt(): string {
		$key = '';
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$key = SECURE_AUTH_KEY;
		} elseif ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$key = AUTH_KEY;
		} elseif ( function_exists( 'wp_salt' ) ) {
			try {
				$key = wp_salt( 'auth' );
			} catch ( \Exception $e ) {
				$key = 'koneek-fallback-salt';
			}
		}

		if ( empty( $key ) ) {
			$key = 'koneek-fallback-salt-key';
		}

		return $key;
	}
}

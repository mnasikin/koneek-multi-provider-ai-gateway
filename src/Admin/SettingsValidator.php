<?php
/**
 * Settings Validator.
 *
 * Sanitizes and validates all form inputs for the settings page.
 *
 * @package Koneek
 */

namespace Koneek\Admin;

use Koneek\Provider\ProviderFactory;

/**
 * Validates and sanitizes settings form inputs.
 */
class SettingsValidator {

	/**
	 * Validates and sanitizes the provider selection.
	 *
	 * Checks the submitted slug against the list of allowed provider slugs
	 * from ProviderFactory.
	 *
	 * @param string $provider The submitted provider slug.
	 * @return string|null Sanitized slug or null if invalid.
	 */
	public function validateProvider( string $provider ): ?string {
		$available = ProviderFactory::getAvailableProviders();

		if ( ! isset( $available[ $provider ] ) ) {
			return null;
		}

		return $provider;
	}

	/**
	 * Validates and sanitizes the API key.
	 *
	 * Trims whitespace, then checks that the trimmed length is between
	 * 8 and 256 characters inclusive.
	 *
	 * @param string $apiKey The submitted API key.
	 * @return string|null Trimmed key or null if invalid.
	 */
	public function validateApiKey( string $apiKey ): ?string {
		$trimmed = trim( $apiKey );
		$length  = strlen( $trimmed );

		if ( $length < 8 || $length > 256 ) {
			return null;
		}

		return $trimmed;
	}

	/**
	 * Validates and sanitizes the custom API URL.
	 *
	 * Trims the input, checks that it starts with http:// or https://,
	 * then passes it through esc_url_raw(). Rejects the value if the
	 * result is empty or the scheme changed.
	 *
	 * @param string $url The submitted URL.
	 * @return string|null Sanitized URL or null if invalid.
	 */
	public function validateCustomApiUrl( string $url ): ?string {
		$trimmed = trim( $url );

		if ( ! preg_match( '#^https?://#i', $trimmed ) ) {
			return null;
		}

		$sanitized = esc_url_raw( $trimmed );

		if ( empty( $sanitized ) ) {
			return null;
		}

		if ( ! preg_match( '#^https?://#i', $sanitized ) ) {
			return null;
		}

		return $sanitized;
	}

	/**
	 * Validates and sanitizes the connection name.
	 *
	 * The name is optional. When provided it may only contain letters, digits
	 * and hyphens (the hyphen is the sole allowed symbol), because the name is
	 * used to derive the API key option name in the database (e.g.
	 * koneek_api_key_custom_openai_nvidia). Whitespace is trimmed first.
	 *
	 * @param string $name The submitted connection name.
	 * @return string|null Sanitized name (possibly empty) or null if invalid.
	 */
	public function validateName( string $name ): ?string {
		$trimmed = trim( $name );

		if ( '' === $trimmed ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z0-9-]+$/', $trimmed ) ) {
			return null;
		}

		return sanitize_text_field( $trimmed );
	}

	/**
	 * Validates and sanitizes the model name.
	 *
	 * Trims the input, rejects whitespace-only strings, then applies
	 * sanitize_text_field() and returns the result.
	 *
	 * @param string $model The submitted model identifier.
	 * @return string|null Sanitized model or null if invalid.
	 */
	public function validateModel( string $model ): ?string {
		$trimmed = trim( $model );

		if ( '' === $trimmed ) {
			return null;
		}

		$sanitized = sanitize_text_field( $trimmed );

		if ( '' === $sanitized ) {
			return null;
		}

		return $sanitized;
	}
}

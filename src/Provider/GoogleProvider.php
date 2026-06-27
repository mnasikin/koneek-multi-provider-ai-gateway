<?php
/**
 * Google Provider.
 *
 * @package Koneek
 */

namespace Koneek\Provider;

/**
 * Google provider implementation.
 *
 * Provides configuration for the Google Generative Language API including
 * predefined models and the base URL for API requests.
 */
class GoogleProvider implements ProviderInterface {

	/**
	 * Returns the provider identifier slug.
	 *
	 * @return string
	 */
	public function getSlug(): string {
		return 'google';
	}

	/**
	 * Returns the display name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Gemini';
	}

	/**
	 * Returns the API base URL for this provider.
	 *
	 * @return string
	 */
	public function getBaseUrl(): string {
		return 'https://generativelanguage.googleapis.com/v1beta';
	}

	/**
	 * Returns the list of predefined model identifiers with readable labels.
	 *
	 * @return array<string, string>
	 */
	public function getPredefinedModels(): array {
		return array(
			'gemini-3.5-flash'      => 'Gemini 3.5 Flash',
			'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro Preview',
			'gemini-3.1-flash'	=> 'Gemini 3.1 Flash',
			'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash Lite',
			'gemini-3.1-flash-lite-preview' => 'Gemini 3.1 Flash Lite Preview',
			'gemini-3-flash-preview' => 'Gemini 3 Flash Preview',
			'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
			'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
			'gemini-2.5-flash-lite'      => 'Gemini 2.5 Flash Lite',
			'gemini-2.0-flash'      => 'Gemini 2.0 Flash (Deprecated)',
		);
	}

	/**
	 * Returns the provider class to register with the WordPress AI Client.
	 *
	 * @return string
	 */
	public function getAiClientProviderClass(): string {
		return 'AI\\Client\\Provider\\Google';
	}
}

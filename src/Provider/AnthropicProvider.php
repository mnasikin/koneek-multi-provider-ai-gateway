<?php
/**
 * Anthropic Provider.
 *
 * @package Koneek
 */

namespace Koneek\Provider;

/**
 * Anthropic provider implementation.
 *
 * Provides configuration for the Anthropic API including predefined models
 * and the base URL for API requests.
 */
class AnthropicProvider implements ProviderInterface {

	/**
	 * Returns the provider identifier slug.
	 *
	 * @return string
	 */
	public function getSlug(): string {
		return 'anthropic';
	}

	/**
	 * Returns the display name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Anthropic';
	}

	/**
	 * Returns the API base URL for this provider.
	 *
	 * @return string
	 */
	public function getBaseUrl(): string {
		return 'https://api.anthropic.com/v1';
	}

	/**
	 * Returns the list of predefined model identifiers with readable labels.
	 *
	 * @return array<string, string>
	 */
	public function getPredefinedModels(): array {
		return array(
			'claude-opus-4-7'   => 'Claude Opus 4.7',
			'claude-opus-4-6'   => 'Claude Opus 4.6',
			'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
			'claude-opus-4-5'   => 'Claude Opus 4.5',
			'claude-sonnet-4-5' => 'Claude Sonnet 4.5',
			'claude-haiku-4-5'  => 'Claude Haiku 4.5',
			'claude-opus-4-1'   => 'Claude Opus 4.1',
			'claude-opus-4-0'   => 'Claude Opus 4',
			'claude-sonnet-4-0' => 'Claude Sonnet 4',
			'claude-3-7-sonnet' => 'Claude Sonnet 3.7',
			'claude-3-5-sonnet' => 'Claude Sonnet 3.5',
			'claude-3-5-haiku'  => 'Claude Haiku 3.5',
			'claude-3-opus'   => 'Claude Opus 3',
			'claude-3-sonnet' => 'Claude Sonnet 3',
			'claude-3-haiku'  => 'Claude Haiku 3',
		);
	}

	/**
	 * Returns the provider class to register with the WordPress AI Client.
	 *
	 * @return string
	 */
	public function getAiClientProviderClass(): string {
		return 'AI\\Client\\Provider\\Anthropic';
	}
}

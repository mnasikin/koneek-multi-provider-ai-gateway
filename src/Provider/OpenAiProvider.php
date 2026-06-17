<?php
/**
 * OpenAI Provider.
 *
 * @package Koneek
 */

namespace Koneek\Provider;

/**
 * OpenAI provider implementation.
 *
 * Provides configuration for the OpenAI API including predefined models
 * and the base URL for API requests.
 */
class OpenAiProvider implements ProviderInterface {

	/**
	 * Returns the provider identifier slug.
	 *
	 * @return string
	 */
	public function getSlug(): string {
		return 'openai';
	}

	/**
	 * Returns the display name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'OpenAI';
	}

	/**
	 * Returns the API base URL for this provider.
	 *
	 * @return string
	 */
	public function getBaseUrl(): string {
		return 'https://api.openai.com/v1';
	}

	/**
	 * Returns the list of predefined model identifiers with readable labels.
	 *
	 * @return array<string, string>
	 */
	public function getPredefinedModels(): array {
		return array(
			'gpt-5.5'      => 'GPT 5.5',
			'gpt-5.4'      => 'GPT 5.4',
			'gpt-5.4-mini' => 'GPT 5.4 Mini',
			'gpt-5.4-nano' => 'GPT 5.4 Nano',
			'gpt-5.2'      => 'GPT 5.2',
			'gpt-5.1'      => 'GPT 5.1',
			'gpt-5'        => 'GPT 5',
			'gpt-5-mini'   => 'GPT 5 Mini',
			'gpt-5-nano'   => 'GPT 5 Nano',
			'gpt-4.1'      => 'GPT 4.1',
			'gpt-4.1-mini' => 'GPT 4.1 Mini',
			'gpt-4.1-nano' => 'GPT 4.1 Nano',
			'gpt-4o'       => 'GPT 4o',
			'gpt-4o-mini'  => 'GPT 4o Mini',
		);
	}

	/**
	 * Returns the provider class to register with the WordPress AI Client.
	 *
	 * @return string
	 */
	public function getAiClientProviderClass(): string {
		return 'AI\\Client\\Provider\\OpenAI';
	}
}

<?php
/**
 * Custom Provider.
 *
 * @package Koneek
 */

namespace Koneek\Provider;

/**
 * Custom OpenAI-compatible provider implementation.
 *
 * Supports any API endpoint that follows the OpenAI chat completions format.
 * The provider accepts a user-defined API URL and returns no predefined models.
 */
class CustomProvider implements ProviderInterface {

	/**
	 * The provider slug identifier.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * The custom API base URL.
	 *
	 * @var string
	 */
	private string $custom_api_url;

	/**
	 * The compatibility mode.
	 *
	 * @var string
	 */
	private string $compatibility;

	/**
	 * Provider display name.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Constructor.
	 *
	 * @param string $slug           The provider slug (e.g., 'custom_openai').
	 * @param string $custom_api_url The custom API base URL.
	 * @param string $compatibility  The compatibility mode: 'openai'.
	 * @param string $name           Provider display name.
	 */
	public function __construct( string $slug, string $custom_api_url, string $compatibility = 'openai', string $name = 'OpenAI Compatible' ) {
		$this->slug           = $slug;
		$this->custom_api_url = $custom_api_url;
		$this->compatibility  = $compatibility;
		$this->name           = $name;
	}

	/**
	 * Returns the provider identifier slug.
	 *
	 * @return string
	 */
	public function getSlug(): string {
		return $this->slug;
	}

	/**
	 * Returns the display name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * Returns the custom API base URL.
	 *
	 * @return string
	 */
	public function getBaseUrl(): string {
		return $this->custom_api_url;
	}

	/**
	 * Returns an empty array since custom providers have no predefined models.
	 *
	 * @return array<string, string>
	 */
	public function getPredefinedModels(): array {
		return array();
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

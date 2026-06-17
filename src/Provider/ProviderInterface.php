<?php
/**
 * Provider Interface.
 *
 * @package Koneek
 */

namespace Koneek\Provider;

/**
 * Interface for AI provider implementations.
 */
interface ProviderInterface {
	/**
	 * Returns the provider identifier slug.
	 *
	 * @return string
	 */
	public function getSlug(): string;

	/**
	 * Returns the display name.
	 *
	 * @return string
	 */
	public function getName(): string;

	/**
	 * Returns the API base URL for this provider.
	 *
	 * @return string
	 */
	public function getBaseUrl(): string;

	/**
	 * Returns the list of predefined model identifiers with readable labels.
	 *
	 * @return array<string, string> Associative array of model_id => display_label.
	 */
	public function getPredefinedModels(): array;

	/**
	 * Returns the provider class to register with the WordPress AI Client.
	 *
	 * @return string
	 */
	public function getAiClientProviderClass(): string;
}

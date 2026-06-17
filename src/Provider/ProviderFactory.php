<?php
/**
 * Provider Factory.
 *
 * Creates provider instances from stored configuration and provides
 * the list of available provider definitions for the settings UI.
 *
 * @package Koneek
 */

namespace Koneek\Provider;

/**
 * Factory class for creating provider instances.
 */
class ProviderFactory {

	/**
	 * Hardcoded provider definitions.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $providers = array(
		'openai'        => array(
			'label'    => 'OpenAI',
			'type'     => 'known',
			'base_url' => 'https://api.openai.com/v1',
			'models'   => array( 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo' ),
		),
		'anthropic'     => array(
			'label'    => 'Anthropic',
			'type'     => 'known',
			'base_url' => 'https://api.anthropic.com/v1',
			'models'   => array( 'claude-sonnet-4-20250514', 'claude-3-5-haiku-20241022', 'claude-3-opus-20240229' ),
		),
		'google'        => array(
			'label'    => 'Google',
			'type'     => 'known',
			'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
			'models'   => array( 'gemini-3.5-flash', 'gemini-3.1-pro-preview', 'gemini-3.1-flash-lite', 'gemini-2.5-pro', 'gemini-2.5-flash', 'gemini-2.0-flash' ),
		),
		'custom_openai' => array(
			'label'         => 'OpenAI Compatible',
			'type'          => 'custom',
			'compatibility' => 'openai',
		),
	);

	/**
	 * Creates a provider instance from the stored provider slug.
	 *
	 * @param string $slug         The provider identifier.
	 * @param string $custom_api_url Optional custom API URL for custom providers.
	 * @return ProviderInterface|null Null if slug is unrecognized.
	 */
	public static function create( string $slug, string $custom_api_url = '' ): ?ProviderInterface {
		if ( ! isset( self::$providers[ $slug ] ) ) {
			return null;
		}

		$definition = self::$providers[ $slug ];

		if ( 'known' === $definition['type'] ) {
			switch ( $slug ) {
				case 'openai':
					return new OpenAiProvider();
				case 'anthropic':
					return new AnthropicProvider();
				case 'google':
					return new GoogleProvider();
			}
		}

		if ( 'custom' === $definition['type'] ) {
			return new CustomProvider( $slug, $custom_api_url, $definition['compatibility'] );
		}

		return null;
	}

	/**
	 * Returns all available provider definitions for the settings UI.
	 *
	 * @return array<string, array{label: string, type: string}>
	 */
	public static function getAvailableProviders(): array {
		return self::$providers;
	}
}

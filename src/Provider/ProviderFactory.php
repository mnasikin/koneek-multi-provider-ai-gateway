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
			'models'   => array(),
		),
		'anthropic'     => array(
			'label'    => 'Anthropic',
			'type'     => 'known',
			'base_url' => 'https://api.anthropic.com/v1',
			'models'   => array(),
		),
		'google'        => array(
			'label'        => 'Gemini',
			'type'         => 'known',
			'base_url'     => 'https://generativelanguage.googleapis.com/v1beta',
			'models'       => array(),
			'manual_model' => true,
			'timeout'      => true,
		),
		'openrouter'    => array(
			'label'         => 'OpenRouter',
			'type'          => 'compatible',
			'base_url'      => 'https://openrouter.ai/api/v1',
			'compatibility' => 'openai',
			'manual_model'  => true,
			'timeout'       => true,
		),
		'groq'          => array(
			'label'         => 'Groq',
			'type'          => 'compatible',
			'base_url'      => 'https://api.groq.com/openai/v1',
			'compatibility' => 'openai',
			'manual_model'  => true,
			'timeout'       => true,
		),
		'nvidia_nim'    => array(
			'label'         => 'NVIDIA NIM',
			'type'          => 'compatible',
			'base_url'      => 'https://integrate.api.nvidia.com/v1',
			'compatibility' => 'openai',
			'manual_model'  => true,
			'timeout'       => true,
		),
		'cerebras'      => array(
			'label'         => 'Cerebras',
			'type'          => 'compatible',
			'base_url'      => 'https://api.cerebras.ai/v1',
			'compatibility' => 'openai',
			'manual_model'  => true,
			'timeout'       => true,
		),
		'sambanova'     => array(
			'label'         => 'SambaNova',
			'type'          => 'compatible',
			'base_url'      => 'https://api.sambanova.ai/v1',
			'compatibility' => 'openai',
			'manual_model'  => true,
			'timeout'       => true,
		),
		'opencode_zen'  => array(
			'label'         => 'OpenCode Zen',
			'type'          => 'compatible',
			'base_url'      => 'https://opencode.ai/zen/v1',
			'compatibility' => 'openai',
			'manual_model'  => true,
			'timeout'       => true,
		),
		'opencode_go'   => array(
			'label'         => 'OpenCode Go',
			'type'          => 'compatible',
			'base_url'      => 'https://opencode.ai/zen/go/v1',
			'compatibility' => 'openai',
			'manual_model'  => true,
			'timeout'       => true,
		),
		'custom_openai' => array(
			'label'         => 'OpenAI Compatible',
			'type'          => 'custom',
			'compatibility' => 'openai',
			'manual_model'  => true,
			'custom_url'    => true,
			'timeout'       => true,
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

		if ( 'custom' === $definition['type'] || 'compatible' === $definition['type'] ) {
			$base_url = 'custom' === $definition['type']
				? $custom_api_url
				: (string) $definition['base_url'];

			return new CustomProvider( $slug, $base_url, $definition['compatibility'], $definition['label'] );
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

	/**
	 * Indicates whether a provider uses a free-text model field.
	 *
	 * @param string $slug Provider identifier.
	 * @return bool
	 */
	public static function usesManualModel( string $slug ): bool {
		return ! empty( self::$providers[ $slug ]['manual_model'] );
	}

	/**
	 * Indicates whether a provider requires a user-supplied API URL.
	 *
	 * @param string $slug Provider identifier.
	 * @return bool
	 */
	public static function requiresCustomUrl( string $slug ): bool {
		return ! empty( self::$providers[ $slug ]['custom_url'] );
	}

	/**
	 * Indicates whether a provider exposes a configurable timeout.
	 *
	 * @param string $slug Provider identifier.
	 * @return bool
	 */
	public static function supportsTimeout( string $slug ): bool {
		return ! empty( self::$providers[ $slug ]['timeout'] );
	}
}

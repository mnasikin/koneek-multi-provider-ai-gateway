<?php
/**
 * Koneek OpenAI-compatible AI Client provider.
 *
 * @package Koneek
 */

namespace Koneek\AiClient\Provider;

use Koneek\AiClient\Metadata\KoneekOpenAiModelMetadataDirectory;
use Koneek\AiClient\Models\KoneekOpenAiTextGenerationModel;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal OpenAI-compatible provider so Koneek can work without provider plugins.
 */
class KoneekOpenAiProvider extends AbstractApiProvider {

	/**
	 * {@inheritDoc}
	 */
	protected static function baseUrl(): string {
		return 'https://api.openai.com/v1';
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModel( ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata ): ModelInterface {
		foreach ( $modelMetadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new KoneekOpenAiTextGenerationModel( $modelMetadata, $providerMetadata );
			}
		}

		throw new RuntimeException( 'Unsupported Koneek model capabilities.' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$args = array(
			'koneek',
			'Koneek',
			ProviderTypeEnum::cloud(),
			admin_url( 'options-general.php?page=koneek_settings' ),
			RequestAuthenticationMethod::apiKey(),
		);

		if ( version_compare( AiClient::VERSION, '1.2.0', '>=' ) ) {
			$args[] = __( 'Connect WordPress to OpenAI, Anthropic, Google, and OpenAI-compatible providers through Koneek.', 'koneek-multi-provider-ai-gateway' );
		}

		if ( version_compare( AiClient::VERSION, '1.3.0', '>=' ) ) {
			$args[] = dirname( __DIR__, 3 ) . '/assets/images/koneek_icon.svg';
		}

		return new ProviderMetadata( ...$args );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ListModelsApiBasedProviderAvailability( static::modelMetadataDirectory() );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new KoneekOpenAiModelMetadataDirectory();
	}
}

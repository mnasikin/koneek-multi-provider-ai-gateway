<?php
/**
 * Koneek OpenAI-compatible model metadata directory.
 *
 * @package Koneek
 */

namespace Koneek\AiClient\Metadata;

use Koneek\AiClient\Provider\KoneekOpenAiProvider;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Treats models returned through Koneek's synthetic /models response as text models.
 */
class KoneekOpenAiModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {

	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		$url = add_query_arg( 'koneek_gateway', '1', KoneekOpenAiProvider::url( $path ) );

		return new Request(
			$method,
			$url,
			$headers,
			$data
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		$response_data = $response->getData();

		if ( ! isset( $response_data['data'] ) || ! is_array( $response_data['data'] ) || empty( $response_data['data'] ) ) {
			throw ResponseException::fromMissingData( 'Koneek OpenAI Compatible', 'data' );
		}

		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);

		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);

		$models = array();

		foreach ( $response_data['data'] as $model_data ) {
			if ( ! is_array( $model_data ) || empty( $model_data['id'] ) ) {
				continue;
			}

			$model_id = (string) $model_data['id'];
			$models[] = new ModelMetadata( $model_id, $model_id, $capabilities, $options );
		}

		if ( empty( $models ) ) {
			throw ResponseException::fromMissingData( 'Koneek OpenAI Compatible', 'data[].id' );
		}

		return $models;
	}
}

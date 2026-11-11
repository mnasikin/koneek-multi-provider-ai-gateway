<?php
/**
 * Koneek OpenAI-compatible text generation model.
 *
 * @package Koneek
 */

namespace Koneek\AiClient\Models;

use Koneek\AiClient\Provider\KoneekOpenAiProvider;
use Koneek\Utils\Logger;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends text prompts through the OpenAI Responses API shape.
 */
class KoneekOpenAiTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface {

	/**
	 * {@inheritDoc}
	 */
	final public function generateTextResult( array $prompt ): GenerativeAiResult {
		$request = new Request(
			HttpMethodEnum::POST(),
			KoneekOpenAiProvider::url( 'responses' ),
			array( 'Content-Type' => 'application/json' ),
			$this->prepareGenerateTextParams( $prompt ),
			$this->getRequestOptions()
		);

		$request  = $this->getRequestAuthentication()->authenticateRequest( $request );
		$response = $this->getHttpTransporter()->send( $request );

		ResponseUtil::throwIfNotSuccessful( $response );

		return $this->parseResponseToGenerativeAiResult( $response );
	}

	/**
	 * Prepares OpenAI Responses API parameters.
	 *
	 * @param list<Message> $prompt Prompt messages.
	 * @return array<string, mixed>
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$config = $this->getConfig();

		$params = array(
			'model' => $this->metadata()->getId(),
			'input' => $this->prepareInputParam( $prompt ),
		);

		$system_instruction = $config->getSystemInstruction();
		if ( $system_instruction ) {
			$params['instructions'] = $system_instruction;
		}

		$max_tokens = $config->getMaxTokens();
		if ( null !== $max_tokens ) {
			$params['max_output_tokens'] = $max_tokens;
		}

		$temperature = $config->getTemperature();
		if ( null !== $temperature ) {
			$params['temperature'] = $temperature;
		}

		$top_p = $config->getTopP();
		if ( null !== $top_p ) {
			$params['top_p'] = $top_p;
		}

		foreach ( $config->getCustomOptions() as $key => $value ) {
			if ( ! isset( $params[ $key ] ) ) {
				$params[ $key ] = $value;
			}
		}

		return $params;
	}

	/**
	 * Converts SDK messages to Responses API input items.
	 *
	 * @param list<Message> $messages Prompt messages.
	 * @return list<array<string, mixed>>
	 */
	protected function prepareInputParam( array $messages ): array {
		$input = array();

		foreach ( $messages as $message ) {
			if ( ! $message instanceof Message ) {
				continue;
			}

			$parts = array();
			foreach ( $message->getParts() as $part ) {
				if ( ! $part instanceof MessagePart || ! $part->getType()->isText() ) {
					continue;
				}

				$parts[] = array(
					'type' => $message->getRole()->isModel() ? 'output_text' : 'input_text',
					'text' => $part->getText(),
				);
			}

			if ( empty( $parts ) ) {
				continue;
			}

			$input[] = array(
				'role' => $message->getRole()->isModel() ? 'assistant' : 'user',
				'content' => $parts,
			);
		}

		return $input;
	}

	/**
	 * Parses a Responses API response into an SDK result.
	 *
	 * @param Response $response Provider response.
	 * @return GenerativeAiResult
	 */
	protected function parseResponseToGenerativeAiResult( Response $response ): GenerativeAiResult {
		$response_data = $response->getData();

		if ( ! is_array( $response_data ) ) {
			Logger::log(
				'AI Client response data was not an array',
				array(
					'type' => gettype( $response_data ),
				)
			);
			throw ResponseException::fromInvalidData( esc_html( $this->providerMetadata()->getName() ), 'response', 'The value must be an array.' );
		}

		$text = '';

		if ( isset( $response_data['output_text'] ) && is_string( $response_data['output_text'] ) && '' !== $response_data['output_text'] ) {
			$text = $response_data['output_text'];
		}

		if ( '' === $text && isset( $response_data['output'] ) && is_array( $response_data['output'] ) ) {
			$text = $this->extractTextFromOutput( $response_data['output'] );
		}

		if ( '' === $text && isset( $response_data['choices'] ) && is_array( $response_data['choices'] ) ) {
			$text = $this->extractTextFromChoices( $response_data['choices'] );
		}

		Logger::log(
			'Response parsed',
			array(
				'text_length' => strlen( $text ),
			)
		);

		if ( '' === $text ) {
			throw ResponseException::fromMissingData( esc_html( $this->providerMetadata()->getName() ), 'generated text' );
		}

		$message = new Message( MessageRoleEnum::model(), array( new MessagePart( $text ) ) );
		$usage   = isset( $response_data['usage'] ) && is_array( $response_data['usage'] ) ? $response_data['usage'] : array();

		$token_usage = new TokenUsage(
			(int) ( $usage['input_tokens'] ?? 0 ),
			(int) ( $usage['output_tokens'] ?? 0 ),
			(int) ( $usage['total_tokens'] ?? 0 )
		);

		return new GenerativeAiResult(
			isset( $response_data['id'] ) ? (string) $response_data['id'] : '',
			array( new Candidate( $message, FinishReasonEnum::stop() ) ),
			$token_usage,
			$this->providerMetadata(),
			$this->metadata(),
			$response_data
		);
	}

	/**
	 * Extracts text from Responses API output items.
	 *
	 * @param array<int, mixed> $output Output items.
	 * @return string
	 */
	private function extractTextFromOutput( array $output ): string {
		$chunks = array();

		foreach ( $output as $item ) {
			if ( ! is_array( $item ) || empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}

			foreach ( $item['content'] as $content ) {
				if ( is_array( $content ) && isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$chunks[] = $content['text'];
				}
			}
		}

		return implode( "\n", $chunks );
	}

	/**
	 * Extracts text from a Chat Completions-style response.
	 *
	 * @param array<int, mixed> $choices Choice items.
	 * @return string
	 */
	private function extractTextFromChoices( array $choices ): string {
		$chunks = array();

		foreach ( $choices as $choice ) {
			if ( ! is_array( $choice ) ) {
				continue;
			}

			if ( isset( $choice['message']['content'] ) ) {
				$chunks[] = $this->stringifyContent( $choice['message']['content'] );
				continue;
			}

			if ( isset( $choice['text'] ) && is_string( $choice['text'] ) ) {
				$chunks[] = $choice['text'];
				continue;
			}

			if ( isset( $choice['delta']['content'] ) ) {
				$chunks[] = $this->stringifyContent( $choice['delta']['content'] );
			}
		}

		return trim( implode( "\n", array_filter( $chunks ) ) );
	}

	/**
	 * Flattens OpenAI-compatible content values into text.
	 *
	 * @param mixed $content Content value.
	 * @return string
	 */
	private function stringifyContent( $content ): string {
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$chunks = array();

		foreach ( $content as $item ) {
			if ( is_string( $item ) ) {
				$chunks[] = $item;
				continue;
			}

			if ( is_array( $item ) && isset( $item['text'] ) && is_string( $item['text'] ) ) {
				$chunks[] = $item['text'];
			}
		}

		return trim( implode( "\n", $chunks ) );
	}
}

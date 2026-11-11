<?php
/**
 * Chat Translator.
 *
 * Converts chat/generation requests and responses between the three supported
 * API families (OpenAI, Anthropic, Google) using a normalized intermediate
 * representation. This lets the gateway accept a request in whatever format the
 * WordPress AI Client SDK emits, then route it to any configured connection —
 * even one for a different provider — and translate the answer back.
 *
 * Normalized request shape:
 *   array{
 *     system: string,
 *     messages: array<int, array{role: string, content: string}>,
 *     max_tokens: int|null,
 *     temperature: float|null,
 *     top_p: float|null,
 *   }
 *
 * Normalized response shape:
 *   array{ id: string, text: string, usage: array{input_tokens:int, output_tokens:int, total_tokens:int} }
 *
 * @package Koneek
 */

namespace Koneek\Gateway;

use Koneek\Connection\Connection;
use Koneek\Provider\OpenAiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless format conversion helpers for the gateway.
 */
class ChatTranslator {

	/**
	 * Default token cap used when the incoming request does not specify one
	 * and the target provider (e.g. Anthropic) requires it.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_TOKENS = 4096;

	/**
	 * Detects the API family for an incoming request URL/path.
	 *
	 * @param string $url  The full request URL.
	 * @param string $path The request path.
	 * @return string|null One of 'openai', 'anthropic', 'google', or null.
	 */
	public static function detectFamily( string $url, string $path ): ?string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( false !== strpos( $host, 'anthropic.com' ) || false !== strpos( $path, '/messages' ) ) {
			return 'anthropic';
		}

		if ( false !== strpos( $host, 'generativelanguage.googleapis.com' ) || false !== strpos( $path, ':generateContent' ) || false !== strpos( $path, ':streamGenerateContent' ) ) {
			return 'google';
		}

		if ( false !== strpos( $host, 'api.openai.com' ) || false !== strpos( $path, '/responses' ) || false !== strpos( $path, '/chat/completions' ) ) {
			return 'openai';
		}

		return null;
	}

	/**
	 * Determines the OpenAI sub-format ('responses' or 'chat') from a path.
	 *
	 * @param string $path The request path.
	 * @return string 'responses' or 'chat'.
	 */
	public static function openAiSubFormat( string $path ): string {
		return ( false !== strpos( $path, '/responses' ) ) ? 'responses' : 'chat';
	}

	/**
	 * Indicates whether the given path is a model-listing request.
	 *
	 * @param string $path The request path.
	 * @return bool
	 */
	public static function isModelListing( string $path ): bool {
		return (bool) preg_match( '#/models(\?|$)#', $path );
	}

	/**
	 * Indicates whether the given path/family is a generation endpoint.
	 *
	 * @param string $family The API family.
	 * @param string $path   The request path.
	 * @return bool
	 */
	public static function isGenerationRequest( string $family, string $path ): bool {
		switch ( $family ) {
			case 'anthropic':
				return false !== strpos( $path, '/messages' );
			case 'google':
				return false !== strpos( $path, ':generateContent' ) || false !== strpos( $path, ':streamGenerateContent' );
			case 'openai':
				return false !== strpos( $path, '/responses' ) || false !== strpos( $path, '/chat/completions' );
		}

		return false;
	}

	/**
	 * Parses an incoming request body into the normalized representation.
	 *
	 * @param string $family The incoming API family.
	 * @param string $path   The request path.
	 * @param array  $args   The wp_remote request arguments.
	 * @return array The normalized request.
	 */
	public static function parseIncoming( string $family, string $path, array $args ): array {
		$body = array();
		if ( ! empty( $args['body'] ) && is_string( $args['body'] ) ) {
			$decoded = json_decode( $args['body'], true );
			if ( is_array( $decoded ) ) {
				$body = $decoded;
			}
		}

		switch ( $family ) {
			case 'anthropic':
				return self::parseAnthropicRequest( $body );
			case 'google':
				return self::parseGoogleRequest( $body );
			case 'openai':
			default:
				return ( 'responses' === self::openAiSubFormat( $path ) )
					? self::parseOpenAiResponsesRequest( $body )
					: self::parseOpenAiChatRequest( $body );
		}
	}

	/**
	 * Builds an outgoing request for a target connection from a normalized request.
	 *
	 * @param Connection $connection The target connection (with API key).
	 * @param array      $normalized The normalized request.
	 * @return array{url: string, args: array} The endpoint URL and request args.
	 */
	public static function buildOutgoing( Connection $connection, array $normalized ): array {
		switch ( $connection->getApiFamily() ) {
			case 'anthropic':
				return self::buildAnthropicRequest( $connection, $normalized );
			case 'google':
				return self::buildGoogleRequest( $connection, $normalized );
			case 'openai':
			default:
				return self::buildOpenAiChatRequest( $connection, $normalized );
		}
	}

	/**
	 * Parses a provider response into the normalized representation.
	 *
	 * @param string $family   The API family of the response.
	 * @param string $body_raw The raw response body.
	 * @return array|null Normalized response, or null when it cannot be parsed.
	 */
	public static function parseProviderResponse( string $family, string $body_raw ): ?array {
		$decoded = json_decode( $body_raw, true );

		if ( ! is_array( $decoded ) ) {
			$parsed = 'openai' === $family
				? self::parseOpenAiSseResponse( $body_raw )
				: null;
		} else {
			switch ( $family ) {
				case 'anthropic':
					$parsed = self::parseAnthropicResponse( $decoded );
					break;
				case 'google':
					$parsed = self::parseGoogleResponse( $decoded );
					break;
				case 'openai':
				default:
					$parsed = self::parseOpenAiResponse( $decoded );
					break;
			}
		}

		if ( ! is_array( $parsed ) || '' === trim( (string) ( $parsed['text'] ?? '' ) ) ) {
			return null;
		}

		return $parsed;
	}

	/**
	 * Builds a response in the incoming family/sub-format from a normalized response.
	 *
	 * @param string $family     The incoming API family the SDK expects back.
	 * @param string $sub_format The OpenAI sub-format ('responses'|'chat'); ignored otherwise.
	 * @param array  $normalized The normalized response.
	 * @return array The decoded response body array.
	 */
	public static function buildIncomingResponse( string $family, string $sub_format, array $normalized ): array {
		switch ( $family ) {
			case 'anthropic':
				return self::buildAnthropicResponse( $normalized );
			case 'google':
				return self::buildGoogleResponse( $normalized );
			case 'openai':
			default:
				return ( 'responses' === $sub_format )
					? self::buildOpenAiResponsesResponse( $normalized )
					: self::buildOpenAiChatResponse( $normalized );
		}
	}

	/* --------------------------------------------------------------------- *
	 * OpenAI parsing/building.
	 * --------------------------------------------------------------------- */

	/**
	 * Parses an OpenAI Chat Completions request body.
	 *
	 * @param array $body Decoded request body.
	 * @return array Normalized request.
	 */
	private static function parseOpenAiChatRequest( array $body ): array {
		$system   = '';
		$messages = array();

		foreach ( (array) ( $body['messages'] ?? array() ) as $message ) {
			$role    = (string) ( $message['role'] ?? 'user' );
			$content = self::stringifyContent( $message['content'] ?? '' );

			if ( 'system' === $role || 'developer' === $role ) {
				$system = '' === $system ? $content : $system . "\n" . $content;
				continue;
			}

			$messages[] = array(
				'role'    => 'assistant' === $role ? 'assistant' : 'user',
				'content' => $content,
			);
		}

		$max_tokens = $body['max_completion_tokens'] ?? $body['max_tokens'] ?? null;

		return self::normalized(
			$system,
			$messages,
			null !== $max_tokens ? (int) $max_tokens : null,
			isset( $body['temperature'] ) ? (float) $body['temperature'] : null,
			isset( $body['top_p'] ) ? (float) $body['top_p'] : null
		);
	}

	/**
	 * Parses an OpenAI Responses API request body.
	 *
	 * @param array $body Decoded request body.
	 * @return array Normalized request.
	 */
	private static function parseOpenAiResponsesRequest( array $body ): array {
		$system   = isset( $body['instructions'] ) ? (string) $body['instructions'] : '';
		$messages = array();

		$input = $body['input'] ?? array();

		if ( is_string( $input ) ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => $input,
			);
		} elseif ( is_array( $input ) ) {
			foreach ( $input as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$role  = (string) ( $item['role'] ?? 'user' );
				$parts = array();

				if ( isset( $item['content'] ) && is_array( $item['content'] ) ) {
					foreach ( $item['content'] as $content_item ) {
						if ( isset( $content_item['text'] ) ) {
							$parts[] = (string) $content_item['text'];
						}
					}
				} elseif ( isset( $item['content'] ) && is_string( $item['content'] ) ) {
					$parts[] = $item['content'];
				} elseif ( isset( $item['text'] ) ) {
					$parts[] = (string) $item['text'];
				}

				if ( empty( $parts ) ) {
					continue;
				}

				$messages[] = array(
					'role'    => 'assistant' === $role ? 'assistant' : 'user',
					'content' => implode( "\n", $parts ),
				);
			}
		}

		return self::normalized(
			$system,
			$messages,
			isset( $body['max_output_tokens'] ) ? (int) $body['max_output_tokens'] : null,
			isset( $body['temperature'] ) ? (float) $body['temperature'] : null,
			isset( $body['top_p'] ) ? (float) $body['top_p'] : null
		);
	}

	/**
	 * Builds an OpenAI Chat Completions request for a connection.
	 *
	 * @param Connection $connection The target connection.
	 * @param array      $normalized The normalized request.
	 * @return array{url: string, args: array}
	 */
	private static function buildOpenAiChatRequest( Connection $connection, array $normalized ): array {
		$messages = array();

		if ( '' !== $normalized['system'] ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $normalized['system'],
			);
		}

		foreach ( $normalized['messages'] as $message ) {
			$messages[] = $message;
		}

		$model             = $connection->getModel();
		$gpt6              = 'openai' === $connection->getProvider() && OpenAiProvider::isGpt6Model( $model );
		$token_limit_field = $gpt6 ? 'max_completion_tokens' : 'max_tokens';
		$payload           = array(
			'model'            => $model,
			'messages'         => $messages,
			$token_limit_field => $normalized['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
		);

		if ( ! $gpt6 && null !== $normalized['temperature'] ) {
			$payload['temperature'] = $normalized['temperature'];
		}
		if ( ! $gpt6 && null !== $normalized['top_p'] ) {
			$payload['top_p'] = $normalized['top_p'];
		}

		return array(
			'url'  => $connection->getBaseUrl() . '/chat/completions',
			'args' => array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $connection->getApiKey(),
				),
				'body'    => wp_json_encode( $payload ),
			),
		);
	}

	/**
	 * Parses an OpenAI Chat Completions response.
	 *
	 * @param array $decoded Decoded response.
	 * @return array Normalized response.
	 */
	private static function parseOpenAiResponse( array $decoded ): array {
		$text = '';

		if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
			$text = self::stringifyContent( $decoded['choices'][0]['message']['content'] );
		}

		if ( '' === $text && isset( $decoded['choices'][0]['text'] ) ) {
			$text = (string) $decoded['choices'][0]['text'];
		}

		if ( '' === $text && isset( $decoded['choices'][0]['delta']['content'] ) ) {
			$text = self::stringifyContent( $decoded['choices'][0]['delta']['content'] );
		}

		if ( '' === $text && isset( $decoded['output_text'] ) ) {
			$text = (string) $decoded['output_text'];
		}

		$usage = $decoded['usage'] ?? array();

		return self::normalizedResponse(
			(string) ( $decoded['id'] ?? '' ),
			$text,
			(int) ( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0 ),
			(int) ( $usage['total_tokens'] ?? 0 )
		);
	}

	/**
	 * Parses an OpenAI-compatible SSE response into one completed response.
	 * Reasoning deltas are intentionally ignored; only final content is output.
	 *
	 * @param string $body_raw Raw SSE response body.
	 * @return array|null Normalized response, or null when no SSE events exist.
	 */
	private static function parseOpenAiSseResponse( string $body_raw ): ?array {
		$events      = preg_split( '/\r?\n\r?\n/', trim( $body_raw ) );
		$id          = '';
		$text        = '';
		$final_text  = '';
		$usage       = array();
		$event_count = 0;

		foreach ( $events as $event ) {
			$data_lines = array();

			foreach ( preg_split( '/\r?\n/', $event ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$data_lines[] = ltrim( substr( $line, 5 ) );
				}
			}

			if ( empty( $data_lines ) ) {
				continue;
			}

			$data = implode( "\n", $data_lines );
			if ( '[DONE]' === trim( $data ) ) {
				continue;
			}

			$decoded = json_decode( $data, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			++$event_count;
			if ( '' === $id && isset( $decoded['id'] ) ) {
				$id = (string) $decoded['id'];
			}

			$choice = $decoded['choices'][0] ?? array();
			if ( isset( $choice['delta']['content'] ) ) {
				$text .= self::stringifyContent( $choice['delta']['content'] );
			}
			if ( isset( $choice['message']['content'] ) ) {
				$final_text = self::stringifyContent( $choice['message']['content'] );
			} elseif ( isset( $choice['text'] ) ) {
				$final_text = (string) $choice['text'];
			}

			if ( isset( $decoded['output_text'] ) && is_string( $decoded['output_text'] ) ) {
				$final_text = $decoded['output_text'];
			}
			if ( 'response.output_text.delta' === ( $decoded['type'] ?? '' ) && isset( $decoded['delta'] ) ) {
				$text .= (string) $decoded['delta'];
			}

			if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
				$usage = $decoded['usage'];
			}
		}

		if ( 0 === $event_count ) {
			return null;
		}

		if ( '' === $text ) {
			$text = $final_text;
		}

		return self::normalizedResponse(
			$id,
			$text,
			(int) ( $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0 ),
			(int) ( $usage['total_tokens'] ?? 0 )
		);
	}

	/**
	 * Builds an OpenAI Chat Completions response body.
	 *
	 * @param array $normalized Normalized response.
	 * @return array
	 */
	private static function buildOpenAiChatResponse( array $normalized ): array {
		return array(
			'id'      => '' !== $normalized['id'] ? $normalized['id'] : 'chatcmpl_' . wp_generate_uuid4(),
			'object'  => 'chat.completion',
			'created' => time(),
			'choices' => array(
				array(
					'index'         => 0,
					'message'       => array(
						'role'    => 'assistant',
						'content' => $normalized['text'],
					),
					'finish_reason' => 'stop',
				),
			),
			'usage'   => array(
				'prompt_tokens'     => $normalized['usage']['input_tokens'],
				'completion_tokens' => $normalized['usage']['output_tokens'],
				'total_tokens'      => $normalized['usage']['total_tokens'],
			),
		);
	}

	/**
	 * Builds an OpenAI Responses API response body.
	 *
	 * @param array $normalized Normalized response.
	 * @return array
	 */
	private static function buildOpenAiResponsesResponse( array $normalized ): array {
		$output = array(
			array(
				'type'    => 'message',
				'id'      => 'msg_' . wp_generate_uuid4(),
				'role'    => 'assistant',
				'status'  => 'completed',
				'content' => array(
					array(
						'type' => 'output_text',
						'text' => $normalized['text'],
					),
				),
			),
		);

		return array(
			'id'          => '' !== $normalized['id'] ? $normalized['id'] : 'resp_' . wp_generate_uuid4(),
			'object'      => 'response',
			'status'      => 'completed',
			'output'      => $output,
			'output_text' => $normalized['text'],
			'usage'       => array(
				'input_tokens'  => $normalized['usage']['input_tokens'],
				'output_tokens' => $normalized['usage']['output_tokens'],
				'total_tokens'  => $normalized['usage']['total_tokens'],
			),
		);
	}

	/* --------------------------------------------------------------------- *
	 * Anthropic parsing/building.
	 * --------------------------------------------------------------------- */

	/**
	 * Parses an Anthropic Messages request body.
	 *
	 * @param array $body Decoded request body.
	 * @return array Normalized request.
	 */
	private static function parseAnthropicRequest( array $body ): array {
		$system   = isset( $body['system'] ) ? self::stringifyContent( $body['system'] ) : '';
		$messages = array();

		foreach ( (array) ( $body['messages'] ?? array() ) as $message ) {
			$role    = (string) ( $message['role'] ?? 'user' );
			$content = self::stringifyContent( $message['content'] ?? '' );

			$messages[] = array(
				'role'    => 'assistant' === $role ? 'assistant' : 'user',
				'content' => $content,
			);
		}

		return self::normalized(
			$system,
			$messages,
			isset( $body['max_tokens'] ) ? (int) $body['max_tokens'] : null,
			isset( $body['temperature'] ) ? (float) $body['temperature'] : null,
			isset( $body['top_p'] ) ? (float) $body['top_p'] : null
		);
	}

	/**
	 * Builds an Anthropic Messages request for a connection.
	 *
	 * @param Connection $connection The target connection.
	 * @param array      $normalized The normalized request.
	 * @return array{url: string, args: array}
	 */
	private static function buildAnthropicRequest( Connection $connection, array $normalized ): array {
		$model             = $connection->getModel();
		$supports_sampling = ! preg_match( '/^claude-(?:(?:fable|mythos|opus|sonnet|haiku)-5(?:-|$)|opus-4-[78](?:-|$))/', $model );
		$payload           = array(
			'model'      => $model,
			'messages'   => $normalized['messages'],
			'max_tokens' => $normalized['max_tokens'] ?? self::DEFAULT_MAX_TOKENS,
		);

		if ( '' !== $normalized['system'] ) {
			$payload['system'] = $normalized['system'];
		}
		if ( $supports_sampling && null !== $normalized['temperature'] ) {
			$payload['temperature'] = $normalized['temperature'];
		}
		if ( $supports_sampling && null !== $normalized['top_p'] ) {
			$payload['top_p'] = $normalized['top_p'];
		}

		return array(
			'url'  => $connection->getBaseUrl() . '/messages',
			'args' => array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $connection->getApiKey(),
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $payload ),
			),
		);
	}

	/**
	 * Parses an Anthropic Messages response.
	 *
	 * @param array $decoded Decoded response.
	 * @return array Normalized response.
	 */
	private static function parseAnthropicResponse( array $decoded ): array {
		$text = '';

		foreach ( (array) ( $decoded['content'] ?? array() ) as $block ) {
			if ( isset( $block['text'] ) ) {
				$text .= (string) $block['text'];
			}
		}

		$usage  = $decoded['usage'] ?? array();
		$input  = (int) ( $usage['input_tokens'] ?? 0 );
		$output = (int) ( $usage['output_tokens'] ?? 0 );

		return self::normalizedResponse(
			(string) ( $decoded['id'] ?? '' ),
			$text,
			$input,
			$output,
			$input + $output
		);
	}

	/**
	 * Builds an Anthropic Messages response body.
	 *
	 * @param array $normalized Normalized response.
	 * @return array
	 */
	private static function buildAnthropicResponse( array $normalized ): array {
		return array(
			'id'            => '' !== $normalized['id'] ? $normalized['id'] : 'msg_' . wp_generate_uuid4(),
			'type'          => 'message',
			'role'          => 'assistant',
			'model'         => '',
			'stop_reason'   => 'end_turn',
			'stop_sequence' => null,
			'content'       => array(
				array(
					'type' => 'text',
					'text' => $normalized['text'],
				),
			),
			'usage'         => array(
				'input_tokens'  => $normalized['usage']['input_tokens'],
				'output_tokens' => $normalized['usage']['output_tokens'],
			),
		);
	}

	/* --------------------------------------------------------------------- *
	 * Google parsing/building.
	 * --------------------------------------------------------------------- */

	/**
	 * Parses a Google generateContent request body.
	 *
	 * @param array $body Decoded request body.
	 * @return array Normalized request.
	 */
	private static function parseGoogleRequest( array $body ): array {
		$system = '';

		if ( isset( $body['systemInstruction']['parts'] ) && is_array( $body['systemInstruction']['parts'] ) ) {
			$parts = array();
			foreach ( $body['systemInstruction']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$parts[] = (string) $part['text'];
				}
			}
			$system = implode( "\n", $parts );
		}

		$messages = array();

		foreach ( (array) ( $body['contents'] ?? array() ) as $content ) {
			$role  = (string) ( $content['role'] ?? 'user' );
			$parts = array();

			foreach ( (array) ( $content['parts'] ?? array() ) as $part ) {
				if ( isset( $part['text'] ) ) {
					$parts[] = (string) $part['text'];
				}
			}

			if ( empty( $parts ) ) {
				continue;
			}

			$messages[] = array(
				'role'    => 'model' === $role ? 'assistant' : 'user',
				'content' => implode( "\n", $parts ),
			);
		}

		$generation_config = $body['generationConfig'] ?? array();

		return self::normalized(
			$system,
			$messages,
			isset( $generation_config['maxOutputTokens'] ) ? (int) $generation_config['maxOutputTokens'] : null,
			isset( $generation_config['temperature'] ) ? (float) $generation_config['temperature'] : null,
			isset( $generation_config['topP'] ) ? (float) $generation_config['topP'] : null
		);
	}

	/**
	 * Builds a Google generateContent request for a connection.
	 *
	 * @param Connection $connection The target connection.
	 * @param array      $normalized The normalized request.
	 * @return array{url: string, args: array}
	 */
	private static function buildGoogleRequest( Connection $connection, array $normalized ): array {
		$contents = array();

		foreach ( $normalized['messages'] as $message ) {
			$contents[] = array(
				'role'  => 'assistant' === $message['role'] ? 'model' : 'user',
				'parts' => array(
					array( 'text' => $message['content'] ),
				),
			);
		}

		$payload = array(
			'contents' => $contents,
		);

		if ( '' !== $normalized['system'] ) {
			$payload['systemInstruction'] = array(
				'parts' => array(
					array( 'text' => $normalized['system'] ),
				),
			);
		}

		$supports_sampling = ! preg_match( '/^gemini-(?:4-|3\.(?:[678]-flash|5-flash-lite)(?:-|$))/', $connection->getModel() );
		$generation_config = array();
		if ( null !== $normalized['max_tokens'] ) {
			$generation_config['maxOutputTokens'] = $normalized['max_tokens'];
		}
		if ( $supports_sampling && null !== $normalized['temperature'] ) {
			$generation_config['temperature'] = $normalized['temperature'];
		}
		if ( $supports_sampling && null !== $normalized['top_p'] ) {
			$generation_config['topP'] = $normalized['top_p'];
		}
		if ( ! empty( $generation_config ) ) {
			$payload['generationConfig'] = $generation_config;
		}

		$url = $connection->getBaseUrl() . '/models/' . rawurlencode( $connection->getModel() ) . ':generateContent?key=' . rawurlencode( $connection->getApiKey() );

		return array(
			'url'  => $url,
			'args' => array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			),
		);
	}

	/**
	 * Parses a Google generateContent response.
	 *
	 * @param array $decoded Decoded response.
	 * @return array Normalized response.
	 */
	private static function parseGoogleResponse( array $decoded ): array {
		$text = '';

		if ( isset( $decoded['candidates'][0]['content']['parts'] ) && is_array( $decoded['candidates'][0]['content']['parts'] ) ) {
			foreach ( $decoded['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= (string) $part['text'];
				}
			}
		}

		$usage = $decoded['usageMetadata'] ?? array();

		return self::normalizedResponse(
			(string) ( $decoded['responseId'] ?? '' ),
			$text,
			(int) ( $usage['promptTokenCount'] ?? 0 ),
			(int) ( $usage['candidatesTokenCount'] ?? 0 ),
			(int) ( $usage['totalTokenCount'] ?? 0 )
		);
	}

	/**
	 * Builds a Google generateContent response body.
	 *
	 * @param array $normalized Normalized response.
	 * @return array
	 */
	private static function buildGoogleResponse( array $normalized ): array {
		return array(
			'candidates'    => array(
				array(
					'content'      => array(
						'role'  => 'model',
						'parts' => array(
							array( 'text' => $normalized['text'] ),
						),
					),
					'finishReason' => 'STOP',
					'index'        => 0,
				),
			),
			'usageMetadata' => array(
				'promptTokenCount'     => $normalized['usage']['input_tokens'],
				'candidatesTokenCount' => $normalized['usage']['output_tokens'],
				'totalTokenCount'      => $normalized['usage']['total_tokens'],
			),
		);
	}

	/* --------------------------------------------------------------------- *
	 * Helpers.
	 * --------------------------------------------------------------------- */

	/**
	 * Assembles a normalized request array.
	 *
	 * @param string     $system      The system instruction.
	 * @param array      $messages    The role/content message list.
	 * @param int|null   $max_tokens  Token cap.
	 * @param float|null $temperature Sampling temperature.
	 * @param float|null $top_p       Nucleus sampling value.
	 * @return array
	 */
	private static function normalized( string $system, array $messages, ?int $max_tokens, ?float $temperature, ?float $top_p ): array {
		if ( empty( $messages ) ) {
			$messages = array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			);
		}

		return array(
			'system'      => $system,
			'messages'    => $messages,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'top_p'       => $top_p,
		);
	}

	/**
	 * Assembles a normalized response array.
	 *
	 * @param string $id     The provider response id.
	 * @param string $text   The generated text.
	 * @param int    $input  Input token count.
	 * @param int    $output Output token count.
	 * @param int    $total  Total token count.
	 * @return array
	 */
	private static function normalizedResponse( string $id, string $text, int $input, int $output, int $total ): array {
		return array(
			'id'    => $id,
			'text'  => $text,
			'usage' => array(
				'input_tokens'  => $input,
				'output_tokens' => $output,
				'total_tokens'  => $total > 0 ? $total : ( $input + $output ),
			),
		);
	}

	/**
	 * Flattens a message "content" value (string or content-block array) to text.
	 *
	 * @param mixed $content The content value.
	 * @return string
	 */
	private static function stringifyContent( $content ): string {
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( is_array( $content ) ) {
			$parts = array();
			foreach ( $content as $item ) {
				if ( is_string( $item ) ) {
					$parts[] = $item;
				} elseif ( is_array( $item ) && isset( $item['text'] ) ) {
					$parts[] = (string) $item['text'];
				}
			}

			return implode( "\n", $parts );
		}

		return '';
	}
}

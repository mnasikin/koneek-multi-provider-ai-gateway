<?php
/**
 * Fallback Gateway.
 *
 * Intercepts outbound AI generation requests made by the WordPress AI Client
 * SDK and routes them through the configured connections in priority order.
 *
 * The first connection (lowest priority number) is tried first. If it times out
 * or returns a retryable error, the gateway automatically falls back to the next
 * connection — including connections for a different provider — translating the
 * request and response formats as needed.
 *
 * @package Koneek
 */

namespace Koneek\Gateway;

use Koneek\Connection\Connection;
use Koneek\Connection\ConnectionRepository;
use Koneek\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes generation requests across connections with automatic fallback.
 */
class FallbackGateway {

	/**
	 * Priority for the pre_http_request interception hook.
	 *
	 * @var int
	 */
	private const HOOK_PRIORITY = 3;

	/**
	 * The connection repository.
	 *
	 * @var ConnectionRepository
	 */
	private ConnectionRepository $repository;

	/**
	 * Re-entrancy guard so our own outbound calls are not intercepted again.
	 *
	 * @var bool
	 */
	private bool $in_flight = false;

	/**
	 * Constructor.
	 *
	 * @param ConnectionRepository $repository The connection repository.
	 */
	public function __construct( ConnectionRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Registers the interception hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'maybeIntercept' ), self::HOOK_PRIORITY, 3 );
	}

	/**
	 * Inspects an outbound HTTP request and routes it through the fallback chain
	 * when it targets a supported AI generation or model-listing endpoint.
	 *
	 * @param mixed  $preempt Current short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed Pass-through, or a synthetic response array / WP_Error.
	 */
	public function maybeIntercept( $preempt, $args, $url ) {
		if ( false !== $preempt || $this->in_flight ) {
			return $preempt;
		}

		if ( ! is_array( $args ) ) {
			return $preempt;
		}

		// Requests explicitly flagged as internal (e.g. the admin "Test
		// Connection" probe) must reach their target directly, not be routed
		// through the fallback chain.
		if ( ! empty( $args['koneek_internal'] ) ) {
			return $preempt;
		}

		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$family = ChatTranslator::detectFamily( $url, $path );

		if ( null === $family ) {
			return $preempt;
		}

		// Only act when at least one connection has a usable key; otherwise
		// leave the request untouched so other plugins/providers still work.
		$connections = $this->usableConnections();

		if ( empty( $connections ) ) {
			return $preempt;
		}

		// Synthetic model listing so the SDK recognizes text-generation support,
		// but only for a family we actually manage a connection for.
		if ( ChatTranslator::isModelListing( $path ) ) {
			if ( ! $this->managesFamily( $connections, $family ) ) {
				return $preempt;
			}
			return $this->modelListingResponse( $family );
		}

		if ( ! ChatTranslator::isGenerationRequest( $family, $path ) ) {
			return $preempt;
		}

		$incoming_sub_format = ChatTranslator::openAiSubFormat( $path );
		$normalized          = ChatTranslator::parseIncoming( $family, $path, $args );

		return $this->dispatch( $connections, $family, $incoming_sub_format, $normalized, $args );
	}

	/**
	 * Returns all connections that have a usable API key, priority-sorted.
	 *
	 * @return Connection[]
	 */
	private function usableConnections(): array {
		$usable = array();

		foreach ( $this->repository->all() as $connection ) {
			if ( '' !== $connection->getApiKey() ) {
				$usable[] = $connection;
			}
		}

		return $usable;
	}

	/**
	 * Indicates whether any of the given connections targets a family.
	 *
	 * @param Connection[] $connections The connections.
	 * @param string       $family      The API family to check.
	 * @return bool
	 */
	private function managesFamily( array $connections, string $family ): bool {
		foreach ( $connections as $connection ) {
			if ( $connection->getApiFamily() === $family ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tries each connection in priority order until one succeeds.
	 *
	 * @param Connection[] $connections         The connections (priority sorted).
	 * @param string       $incoming_family     The family the SDK expects back.
	 * @param string       $incoming_sub_format The OpenAI sub-format expected back.
	 * @param array        $normalized          The normalized request.
	 * @param array        $original_args       The original request args.
	 * @return array|\WP_Error A synthetic response or the last error.
	 */
	private function dispatch( array $connections, string $incoming_family, string $incoming_sub_format, array $normalized, array $original_args ) {
		$last_error    = null;
		$attempt_count = 0;

		foreach ( $connections as $connection ) {
			if ( '' === $connection->getApiKey() ) {
				Logger::log(
					'Skipping connection without key',
					array(
						'id'       => $connection->getId(),
						'priority' => $connection->getPriority(),
					)
				);
				continue;
			}

			++$attempt_count;

			$result = $this->sendToConnection( $connection, $normalized, $original_args );

			if ( $this->isSuccess( $result ) ) {
				$response_family = $connection->getApiFamily();
				$body_raw        = wp_remote_retrieve_body( $result );
				$parsed          = ChatTranslator::parseProviderResponse( $response_family, $body_raw );

				Logger::log(
					'Provider response received',
					array(
						'id'             => $connection->getId(),
						'provider'       => $connection->getProvider(),
						'status'         => wp_remote_retrieve_response_code( $result ),
						'body_length'    => strlen( $body_raw ),
						'body_preview'   => substr( $body_raw, 0, 1000 ),
						'parsed_summary' => is_array( $parsed )
							? array(
								'text_length' => strlen( (string) ( $parsed['text'] ?? '' ) ),
								'text_preview' => substr( (string) ( $parsed['text'] ?? '' ), 0, 500 ),
								'usage'       => $parsed['usage'] ?? array(),
							)
							: null,
					)
				);

				if ( null === $parsed ) {
					Logger::log(
						'Connection succeeded but response was unparseable; falling back',
						array( 'id' => $connection->getId() )
					);
					$last_error = new \WP_Error( 'koneek_unparseable_response', 'Provider returned an unreadable response.' );
					continue;
				}

				Logger::log(
					'Connection succeeded',
					array(
						'id'       => $connection->getId(),
						'priority' => $connection->getPriority(),
						'attempt'  => $attempt_count,
					)
				);

				$body = ChatTranslator::buildIncomingResponse( $incoming_family, $incoming_sub_format, $parsed );

				return $this->syntheticResponse( $body );
			}

			$last_error = is_wp_error( $result )
				? $result
				: new \WP_Error(
					'koneek_http_' . wp_remote_retrieve_response_code( $result ),
					$this->extractError( $result )
				);

			Logger::log(
				'Connection failed; trying next',
				array(
					'id'       => $connection->getId(),
					'priority' => $connection->getPriority(),
					'error'    => $last_error->get_error_message(),
				)
			);
		}

		if ( null === $last_error ) {
			$last_error = new \WP_Error( 'koneek_no_connection', __( 'No usable AI connection is configured.', 'koneek-multi-provider-ai-gateway' ) );
		}

		return $last_error;
	}

	/**
	 * Sends a normalized request to a single connection.
	 *
	 * @param Connection $connection    The target connection.
	 * @param array      $normalized    The normalized request.
	 * @param array      $original_args The original request args.
	 * @return array|\WP_Error The wp_remote response.
	 */
	private function sendToConnection( Connection $connection, array $normalized, array $original_args ) {
		$built = ChatTranslator::buildOutgoing( $connection, $normalized );

		$args            = $built['args'];
		$args['timeout'] = $this->resolveTimeout( $connection, $original_args );

		Logger::log(
			'Dispatching to connection',
			array(
				'id'       => $connection->getId(),
				'provider' => $connection->getProvider(),
				'model'    => $connection->getModel(),
				'url'      => $built['url'],
				'timeout'  => $args['timeout'],
				'payload'  => $this->summarizeJsonForLog( (string) ( $args['body'] ?? '' ) ),
			)
		);

		$this->in_flight = true;
		$response        = wp_remote_request( $built['url'], $args );
		$this->in_flight = false;

		return $response;
	}

	/**
	 * Determines whether a wp_remote response is a usable success.
	 *
	 * @param array|\WP_Error $response The response.
	 * @return bool
	 */
	private function isSuccess( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 200 && $code < 300;
	}

	/**
	 * Resolves the timeout for a connection request.
	 *
	 * Uses the connection's configured timeout when set, otherwise a generous
	 * default that honors any larger value already on the request.
	 *
	 * @param Connection $connection    The connection.
	 * @param array      $original_args The original request args.
	 * @return int Timeout in seconds.
	 */
	private function resolveTimeout( Connection $connection, array $original_args ): int {
		$default = 180;

		if ( $connection->getTimeout() > 0 ) {
			$timeout = $connection->getTimeout();
		} else {
			$current = isset( $original_args['timeout'] ) ? (int) $original_args['timeout'] : 0;
			$timeout = max( $default, $current );
		}

		/**
		 * Filters the per-request timeout (seconds) for gateway requests.
		 *
		 * @param int        $timeout    The timeout in seconds.
		 * @param Connection $connection The connection being used.
		 */
		$timeout = (int) apply_filters( 'koneek_request_timeout', $timeout, $connection );

		return $timeout > 0 ? $timeout : $default;
	}

	/**
	 * Builds a synthetic successful wp_remote-style response.
	 *
	 * @param array $body The response body array.
	 * @return array
	 */
	private function syntheticResponse( array $body ): array {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $body ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Creates a compact, non-secret summary of a JSON request/response body.
	 *
	 * @param string $json Raw JSON body.
	 * @return array<string, mixed>
	 */
	private function summarizeJsonForLog( string $json ): array {
		if ( '' === $json ) {
			return array(
				'length' => 0,
				'keys'   => array(),
			);
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'length'  => strlen( $json ),
				'preview' => substr( $json, 0, 1000 ),
			);
		}

		return array(
			'length'  => strlen( $json ),
			'keys'    => array_keys( $decoded ),
			'summary' => $this->summarizeValueForLog( $decoded ),
		);
	}

	/**
	 * Recursively summarizes a value for logs.
	 *
	 * @param mixed $value The value to summarize.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	private function summarizeValueForLog( $value, int $depth = 0 ) {
		if ( is_string( $value ) ) {
			return array(
				'type'    => 'string',
				'length'  => strlen( $value ),
				'preview' => substr( $value, 0, 500 ),
			);
		}

		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return array( 'type' => gettype( $value ) );
		}

		$summary = array(
			'type'  => 'array',
			'keys'  => array_slice( array_keys( $value ), 0, 20 ),
			'count' => count( $value ),
		);

		if ( $depth >= 3 ) {
			return $summary;
		}

		foreach ( array_slice( $value, 0, 8, true ) as $key => $item ) {
			$summary['items'][ $key ] = $this->summarizeValueForLog( $item, $depth + 1 );
		}

		return $summary;
	}

	/**
	 * Builds a synthetic model-listing response for a family.
	 *
	 * @param string $family The API family.
	 * @return array
	 */
	private function modelListingResponse( string $family ): array {
		$models = array();
		foreach ( $this->repository->allWithoutKeys() as $connection ) {
			if ( $connection->getApiFamily() === $family && '' !== $connection->getModel() ) {
				$models[ $connection->getModel() ] = true;
			}
		}

		$model_ids = array_keys( $models );

		if ( 'google' === $family ) {
			$data = array();
			foreach ( $model_ids as $model_id ) {
				$data[] = array(
					'name'                       => 'models/' . $model_id,
					'supportedGenerationMethods' => array( 'generateContent' ),
				);
			}
			if ( empty( $data ) ) {
				$data[] = array(
					'name'                       => 'models/gemini-2.5-flash',
					'supportedGenerationMethods' => array( 'generateContent' ),
				);
			}

			return $this->syntheticResponse( array( 'models' => $data ) );
		}

		if ( 'anthropic' === $family ) {
			$data = array();
			foreach ( $model_ids as $model_id ) {
				$data[] = array(
					'id'   => $model_id,
					'type' => 'model',
				);
			}
			if ( empty( $data ) ) {
				$data[] = array(
					'id'   => 'claude-sonnet-4-5',
					'type' => 'model',
				);
			}

			return $this->syntheticResponse( array( 'data' => $data ) );
		}

		// OpenAI family. Include a known model so capability detection passes.
		$data = array();
		foreach ( $model_ids as $model_id ) {
			$data[] = array(
				'id'       => $model_id,
				'object'   => 'model',
				'created'  => time(),
				'owned_by' => 'koneek',
			);
		}
		$data[] = array(
			'id'       => 'gpt-4o',
			'object'   => 'model',
			'created'  => time(),
			'owned_by' => 'openai',
		);

		return $this->syntheticResponse(
			array(
				'object' => 'list',
				'data'   => $data,
			)
		);
	}

	/**
	 * Extracts a human-readable error message from a failed provider response.
	 *
	 * @param array $response The wp_remote response.
	 * @return string
	 */
	private function extractError( array $response ): string {
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$decoded = json_decode( $body, true );

		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['error']['message'] ) ) {
				return sprintf( 'HTTP %s: %s', $code, $decoded['error']['message'] );
			}
			if ( isset( $decoded['error']['type'] ) ) {
				return sprintf( 'HTTP %s: %s', $code, $decoded['error']['type'] );
			}
			if ( isset( $decoded['message'] ) ) {
				return sprintf( 'HTTP %s: %s', $code, $decoded['message'] );
			}
		}

		return sprintf( 'HTTP %s', $code );
	}
}

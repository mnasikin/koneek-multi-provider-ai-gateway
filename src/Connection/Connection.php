<?php
/**
 * Connection value object.
 *
 * Represents a single configured AI provider connection: which provider it
 * uses, its priority in the fallback chain, the selected model, an optional
 * custom endpoint URL, and (at runtime) the decrypted API key.
 *
 * @package Koneek
 */

namespace Koneek\Connection;

use Koneek\Provider\ProviderFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable description of one provider connection.
 */
class Connection {

	/**
	 * Storage identifier / API key option suffix (e.g. "openai", "openai_2").
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Provider slug (e.g. "openai", "anthropic", "google", "custom_openai").
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * User-defined display name for the connection (optional).
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Priority in the fallback chain. Lower numbers are tried first.
	 *
	 * @var int
	 */
	private int $priority;

	/**
	 * Selected model identifier.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Custom API base URL (only used by custom providers).
	 *
	 * @var string
	 */
	private string $custom_api_url;

	/**
	 * Per-connection request timeout in seconds. 0 means "use default".
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Decrypted API key. Empty until resolved at runtime.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $id             Storage identifier / key suffix.
	 * @param string $provider       Provider slug.
	 * @param int    $priority       Fallback priority (lower = first).
	 * @param string $model          Selected model identifier.
	 * @param string $custom_api_url Custom API base URL (custom providers only).
	 * @param int    $timeout        Per-connection timeout in seconds (0 = default).
	 * @param string $api_key        Decrypted API key (optional).
	 * @param string $name           User-defined display name (optional).
	 */
	public function __construct(
		string $id,
		string $provider,
		int $priority,
		string $model,
		string $custom_api_url = '',
		int $timeout = 0,
		string $api_key = '',
		string $name = ''
	) {
		$this->id             = $id;
		$this->provider       = $provider;
		$this->priority       = $priority;
		$this->model          = $model;
		$this->custom_api_url = $custom_api_url;
		$this->timeout        = $timeout;
		$this->api_key        = $api_key;
		$this->name           = $name;
	}

	/**
	 * Returns the storage identifier (API key option suffix).
	 *
	 * @return string
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * Returns the provider slug.
	 *
	 * @return string
	 */
	public function getProvider(): string {
		return $this->provider;
	}

	/**
	 * Returns the user-defined display name (may be empty).
	 *
	 * @return string
	 */
	public function getName(): string {
		return $this->name;
	}

	/**
	 * Returns a display label for the connection.
	 *
	 * Uses the user-defined name when set, otherwise falls back to the
	 * provider's label.
	 *
	 * @return string
	 */
	public function getDisplayName(): string {
		if ( '' !== trim( $this->name ) ) {
			return $this->name;
		}

		$provider = ProviderFactory::create( $this->provider, $this->custom_api_url );

		if ( null !== $provider ) {
			return $provider->getName();
		}

		return $this->provider;
	}

	/**
	 * Returns the fallback priority (lower = tried first).
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return $this->priority;
	}

	/**
	 * Returns the selected model identifier.
	 *
	 * @return string
	 */
	public function getModel(): string {
		return $this->model;
	}

	/**
	 * Returns the custom API base URL.
	 *
	 * @return string
	 */
	public function getCustomApiUrl(): string {
		return $this->custom_api_url;
	}

	/**
	 * Returns the per-connection timeout in seconds (0 = use default).
	 *
	 * @return int
	 */
	public function getTimeout(): int {
		return $this->timeout;
	}

	/**
	 * Returns the decrypted API key.
	 *
	 * @return string
	 */
	public function getApiKey(): string {
		return $this->api_key;
	}

	/**
	 * Returns a copy of this connection with the given API key attached.
	 *
	 * @param string $api_key The decrypted API key.
	 * @return self
	 */
	public function withApiKey( string $api_key ): self {
		return new self(
			$this->id,
			$this->provider,
			$this->priority,
			$this->model,
			$this->custom_api_url,
			$this->timeout,
			$api_key,
			$this->name
		);
	}

	/**
	 * Resolves the effective API base URL for this connection.
	 *
	 * Custom providers use their configured URL; known providers use the
	 * provider definition's base URL.
	 *
	 * @return string
	 */
	public function getBaseUrl(): string {
		if ( '' !== $this->custom_api_url ) {
			return rtrim( $this->custom_api_url, '/' );
		}

		$provider = ProviderFactory::create( $this->provider, $this->custom_api_url );

		if ( null === $provider ) {
			return '';
		}

		return rtrim( $provider->getBaseUrl(), '/' );
	}

	/**
	 * Returns the API "family" used to translate requests for this connection.
	 *
	 * One of: "openai", "anthropic", "google".
	 *
	 * @return string
	 */
	public function getApiFamily(): string {
		switch ( $this->provider ) {
			case 'anthropic':
				return 'anthropic';
			case 'google':
				return 'google';
			case 'openai':
			case 'custom_openai':
			default:
				return 'openai';
		}
	}

	/**
	 * Serializes the connection metadata (without the API key) for storage.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'id'             => $this->id,
			'provider'       => $this->provider,
			'name'           => $this->name,
			'priority'       => $this->priority,
			'model'          => $this->model,
			'custom_api_url' => $this->custom_api_url,
			'timeout'        => $this->timeout,
		);
	}

	/**
	 * Builds a connection from a stored metadata array.
	 *
	 * @param array<string, mixed> $data The stored metadata.
	 * @return self
	 */
	public static function fromArray( array $data ): self {
		return new self(
			(string) ( $data['id'] ?? '' ),
			(string) ( $data['provider'] ?? '' ),
			(int) ( $data['priority'] ?? 1 ),
			(string) ( $data['model'] ?? '' ),
			(string) ( $data['custom_api_url'] ?? '' ),
			(int) ( $data['timeout'] ?? 0 ),
			'',
			(string) ( $data['name'] ?? '' )
		);
	}
}

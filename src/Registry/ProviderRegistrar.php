<?php
/**
 * Provider Registrar.
 *
 * Makes the configured connection credentials available to the WordPress
 * Connectors API at runtime so the AI plugin can use them, without persisting
 * keys into the shared connector options.
 *
 * With multiple connections configured, every distinct provider that has at
 * least one connection is registered with the AI Client SDK and reported as
 * having credentials. Actual request routing and priority fallback across the
 * individual connections is handled by the FallbackGateway.
 *
 * @package Koneek
 */

namespace Koneek\Registry;

use Koneek\Connection\Connection;
use Koneek\Connection\ConnectionRepository;
use Koneek\AiClient\Provider\KoneekOpenAiProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes configured provider credentials to the WordPress Connectors API.
 */
class ProviderRegistrar {

	/**
	 * Map of our provider slugs to the WordPress connector IDs.
	 *
	 * The connector option name is derived as: connectors_ai_{connector_id}_api_key
	 *
	 * @var array<string, string>
	 */
	private const PROVIDER_TO_CONNECTOR_MAP = array(
		'openai'        => 'openai',
		'anthropic'     => 'anthropic',
		'google'        => 'google',
		'openrouter'    => 'openai',
		'groq'          => 'openai',
		'nvidia_nim'    => 'openai',
		'cerebras'      => 'openai',
		'sambanova'     => 'openai',
		'opencode_zen'  => 'openai',
		'opencode_go'   => 'openai',
		'custom_openai' => 'openai',
	);

	/**
	 * Connector IDs that the AI HTTP guard may infer from preset base URLs.
	 *
	 * These are approval aliases only. Koneek does not register these presets as
	 * separate AI Client providers; they continue to use the OpenAI-compatible
	 * request path through the Koneek gateway.
	 *
	 * @var array<string, string>
	 */
	private const PRESET_APPROVAL_CONNECTOR_MAP = array(
		'openrouter'   => 'openrouter',
		'groq'         => 'groq',
		'nvidia_nim'   => 'nvidia_nim',
		'cerebras'     => 'cerebras',
		'sambanova'    => 'sambanova',
		'opencode_zen' => 'opencode_zen',
		'opencode_go'  => 'opencode_go',
	);

	/**
	 * Map of connector IDs to their provider plugin paths and class names.
	 *
	 * @var array<string, array{plugin_dir: string, autoload: string, class: string}>
	 */
	private const PROVIDER_PLUGIN_MAP = array(
		'openai'    => array(
			'plugin_dir' => 'ai-provider-for-openai',
			'autoload'   => 'src/autoload.php',
			'class'      => 'WordPress\\OpenAiAiProvider\\Provider\\OpenAiProvider',
		),
		'anthropic' => array(
			'plugin_dir' => 'ai-provider-for-anthropic',
			'autoload'   => 'src/autoload.php',
			'class'      => 'WordPress\\AnthropicAiProvider\\Provider\\AnthropicProvider',
		),
		'google'    => array(
			'plugin_dir' => 'ai-provider-for-google',
			'autoload'   => 'src/autoload.php',
			'class'      => 'WordPress\\GoogleAiProvider\\Provider\\GoogleProvider',
		),
	);

	/**
	 * Internal provider classes used when optional provider plugins are absent.
	 *
	 * @var array<string, class-string>
	 */
	private const INTERNAL_PROVIDER_MAP = array(
		'koneek' => KoneekOpenAiProvider::class,
	);

	/**
	 * The connection repository.
	 *
	 * @var ConnectionRepository
	 */
	private ConnectionRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param ConnectionRepository|null $repository Optional repository (created if omitted).
	 */
	public function __construct( ?ConnectionRepository $repository = null ) {
		$this->repository = $repository ?? new ConnectionRepository();
	}

	/**
	 * Exposes configured credentials to the WordPress Connectors API at runtime
	 * and registers each configured provider with the AI Client SDK.
	 *
	 * @return void
	 */
	public function register(): void {
		// Register Koneek as its own connector even before a connection is saved.
		$this->registerProviderWithAiClient( 'koneek' );
		$this->approveConnectorForAiPlugin( 'koneek' );
		$this->clearPendingApprovals( 'koneek' );

		$connections = $this->repository->all();

		if ( empty( $connections ) ) {
			return;
		}

		$primary = null;
		foreach ( $connections as $connection ) {
			if ( '' !== $connection->getApiKey() ) {
				$primary = $connection;
				break;
			}
		}

		if ( null !== $primary ) {
			$this->hookConnectorKeyFilter( 'connectors_ai_koneek_api_key', $primary->getApiKey() );
		}

		// First connection (by priority) for each connector supplies its key.
		$primary_for_connector = array();

		foreach ( $connections as $connection ) {
			if ( '' === $connection->getApiKey() ) {
				continue;
			}

			$preset_connector_id = self::PRESET_APPROVAL_CONNECTOR_MAP[ $connection->getProvider() ] ?? null;
			if ( null !== $preset_connector_id ) {
				$this->approveConnectorForAiPlugin( $preset_connector_id );
				$this->clearPendingApprovals( $preset_connector_id );
			}

			$connector_id = $this->getConnectorId( $connection->getProvider() );

			if ( null === $connector_id ) {
				continue;
			}

			if ( ! isset( $primary_for_connector[ $connector_id ] ) ) {
				$primary_for_connector[ $connector_id ] = $connection;
			}
		}

		foreach ( $primary_for_connector as $connector_id => $connection ) {
			$sanitized_id = str_replace( '-', '_', $connector_id );
			$option_name  = "connectors_ai_{$sanitized_id}_api_key";

			$this->hookConnectorKeyFilter( $option_name, $connection->getApiKey() );
			$this->registerProviderWithAiClient( $connector_id );
			$this->approveConnectorForAiPlugin( $connector_id );
			$this->clearPendingApprovals( $connector_id );
		}
	}

	/**
	 * Hooks the read filter for a shared connector option so a stored key is
	 * returned at runtime without being written to the database.
	 *
	 * The key is only supplied when the option has no stored value, so a
	 * credential configured by another plugin always takes precedence.
	 *
	 * @param string $option_name The shared connector option name.
	 * @param string $api_key     The decrypted key to supply.
	 * @return void
	 */
	private function hookConnectorKeyFilter( string $option_name, string $api_key ): void {
		static $hooked = array();

		if ( isset( $hooked[ $option_name ] ) ) {
			return;
		}

		$hooked[ $option_name ] = true;

		$supply_key = static function ( $value ) use ( $api_key ) {
			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}

			if ( ! empty( $value ) ) {
				return $value;
			}

			return $api_key;
		};

		add_filter( "option_{$option_name}", $supply_key );
		add_filter( "default_option_{$option_name}", $supply_key );
	}

	/**
	 * Registers the provider class with the AI Client SDK.
	 *
	 * @param string $connector_id The connector ID (openai, anthropic, google).
	 * @return void
	 */
	private function registerProviderWithAiClient( string $connector_id ): void {
		if ( ! class_exists( 'WordPress\\AiClient\\AiClient' ) ) {
			return;
		}

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();

		if ( $registry->hasProvider( $connector_id ) ) {
			return;
		}

		$provider_class = $this->resolveProviderClass( $connector_id );

		if ( null === $provider_class ) {
			return;
		}

		$registry->registerProvider( $provider_class );
	}

	/**
	 * Resolves the provider class for a connector.
	 *
	 * Prefers the official provider plugin when installed, then falls back to
	 * Koneek's internal OpenAI-compatible provider.
	 *
	 * @param string $connector_id The connector ID.
	 * @return class-string|null Provider class name, or null if unavailable.
	 */
	private function resolveProviderClass( string $connector_id ): ?string {
		if ( isset( self::PROVIDER_PLUGIN_MAP[ $connector_id ] ) ) {
			$provider_info  = self::PROVIDER_PLUGIN_MAP[ $connector_id ];
			$plugin_dir     = WP_PLUGIN_DIR . '/' . $provider_info['plugin_dir'];
			$autoload_file  = $plugin_dir . '/' . $provider_info['autoload'];
			$provider_class = $provider_info['class'];

			if ( ! class_exists( $provider_class, false ) && file_exists( $autoload_file ) ) {
				require_once $autoload_file;
			}

			if ( class_exists( $provider_class ) ) {
				return $provider_class;
			}
		}

		$provider_class = self::INTERNAL_PROVIDER_MAP[ $connector_id ] ?? null;

		if ( null === $provider_class || ! class_exists( $provider_class ) ) {
			return null;
		}

		return $provider_class;
	}

	/**
	 * Approves the AI plugin and related callers to use the specified connector.
	 *
	 * @param string $connector_id The connector ID to approve.
	 * @return void
	 */
	private function approveConnectorForAiPlugin( string $connector_id ): void {
		$callers = array(
			'ai/ai.php',
			'koneek-multi-provider-ai-gateway/koneek-plugin.php',
			'ai-provider-for-google/plugin.php',
			'ai-provider-for-openai/plugin.php',
			'ai-provider-for-anthropic/plugin.php',
		);

		$store_class = 'WordPress\\AI\\Connector_Approval\\Approvals_Store';
		if ( class_exists( $store_class ) ) {
			$store = new $store_class();
			foreach ( $callers as $caller_basename ) {
				if ( ! $store->is_approved( $caller_basename, $connector_id ) ) {
					$store->set_approval( $caller_basename, $connector_id, true );
				}
			}
			return;
		}

		$option_key = 'wpai_connector_approvals';
		$approvals  = get_option( $option_key, array() );
		if ( ! is_array( $approvals ) ) {
			$approvals = array();
		}

		foreach ( $callers as $caller_basename ) {
			if ( ! empty( $approvals[ $caller_basename ][ $connector_id ] ) ) {
				continue;
			}

			if ( ! isset( $approvals[ $caller_basename ] ) || ! is_array( $approvals[ $caller_basename ] ) ) {
				$approvals[ $caller_basename ] = array();
			}

			$approvals[ $caller_basename ][ $connector_id ] = true;
		}

		update_option( $option_key, $approvals, false );
	}

	/**
	 * Gets the WordPress connector ID for a provider slug.
	 *
	 * @param string $provider_slug Our internal provider slug.
	 * @return string|null The connector ID, or null if not mappable.
	 */
	private function getConnectorId( string $provider_slug ): ?string {
		return self::PROVIDER_TO_CONNECTOR_MAP[ $provider_slug ] ?? null;
	}

	/**
	 * Clears pending requests only for callers already approved for the connector.
	 *
	 * @param string $connector_id The connector ID to clear pending entries for.
	 * @return void
	 */
	private function clearPendingApprovals( string $connector_id ): void {
		$store_class = 'WordPress\\AI\\Connector_Approval\\Approvals_Store';
		if ( class_exists( $store_class ) ) {
			$store = new $store_class();
			foreach ( $store->get_pending() as $key => $entry ) {
				$caller = $entry['caller_basename'] ?? '';
				if ( ( $entry['connector_id'] ?? '' ) === $connector_id && is_string( $caller ) && '' !== $caller && $store->is_approved( $caller, $connector_id ) ) {
					$store->remove_pending( $key );
				}
			}
			return;
		}

		$option_key = 'wpai_connector_approval_pending';
		$pending    = get_option( $option_key, array() );

		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}

		$changed   = false;
		$approvals = get_option( 'wpai_connector_approvals', array() );

		foreach ( $pending as $key => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_connector = $entry['connector_id'] ?? '';

			$caller = $entry['caller_basename'] ?? '';
			if ( $entry_connector === $connector_id && is_string( $caller ) && ! empty( $approvals[ $caller ][ $connector_id ] ) ) {
				unset( $pending[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( $option_key, $pending, false );
		}
	}
}

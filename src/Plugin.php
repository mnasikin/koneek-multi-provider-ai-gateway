<?php
/**
 * Plugin bootstrap class.
 *
 * Hooks the plugin's components into the WordPress lifecycle.
 *
 * @package Koneek
 */

namespace Koneek;

use Koneek\Admin\ConnectionTester;
use Koneek\Admin\SettingsPage;
use Koneek\Connection\ConnectionRepository;
use Koneek\Gateway\FallbackGateway;
use Koneek\Registry\ProviderRegistrar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class that bootstraps all components.
 */
class Plugin {

	/**
	 * The connection repository.
	 *
	 * @var ConnectionRepository
	 */
	private ConnectionRepository $repository;

	/**
	 * The provider registrar instance.
	 *
	 * @var ProviderRegistrar
	 */
	private ProviderRegistrar $registrar;

	/**
	 * The settings page instance.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings_page;

	/**
	 * The connection tester instance.
	 *
	 * @var ConnectionTester
	 */
	private ConnectionTester $connection_tester;

	/**
	 * The fallback gateway instance.
	 *
	 * @var FallbackGateway
	 */
	private FallbackGateway $gateway;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repository        = new ConnectionRepository();
		$this->registrar         = new ProviderRegistrar( $this->repository );
		$this->settings_page     = new SettingsPage( $this->repository );
		$this->connection_tester = new ConnectionTester();
		$this->gateway           = new FallbackGateway( $this->repository );
	}

	/**
	 * Bootstraps the plugin by hooking into WordPress lifecycle.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'init', array( $this->registrar, 'register' ), 5 );
		add_action( 'init', array( $this, 'refreshAiProviderData' ), 21 );
		add_action( 'admin_menu', array( $this->settings_page, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this->settings_page, 'enqueueScripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueConnectorsIntegration' ), 20 );
		add_filter( 'script_module_data_koneek-connectors-admin', array( $this, 'filterConnectorsModuleData' ) );
		add_filter( 'script_module_data_ai-wp-admin', array( $this, 'filterAiAdminModuleData' ), 20 );

		// Register the per-card save/delete AJAX handlers unconditionally so they
		// are available during admin-ajax.php requests.
		$this->settings_page->registerAjaxHandlers();

		// Register AJAX handler for connection testing.
		$this->connection_tester->register();

		// Route AI generation requests through the priority/fallback gateway.
		$this->gateway->register();

		// Tell the AI plugin we have credentials available.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter is defined by the WordPress AI plugin.
		add_filter( 'wpai_has_ai_credentials', array( $this, 'filterHasAiCredentials' ), 10, 2 );

		// Bypass the valid-credentials check; the gateway validates at request time.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter is defined by the WordPress AI plugin.
		add_filter( 'wpai_pre_has_valid_credentials_check', array( $this, 'filterValidCredentials' ) );

		// Prefer the models configured in Koneek when the AI plugin checks text-generation support.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filter is defined by the WordPress AI plugin.
		add_filter( 'wpai_preferred_text_models', array( $this, 'filterPreferredTextModels' ) );

		// Bypass the Connector Approval guard for our configured connectors.
		// This runs at priority 4 (before the guard at priority 5).
		add_filter( 'pre_http_request', array( $this, 'bypassConnectorApproval' ), 4, 3 );
	}

	/**
	 * Filters the AI plugin's credential check to report our stored credentials.
	 *
	 * @param bool  $has_credentials Whether credentials are available.
	 * @param array $connectors      The registered connectors.
	 * @return bool True if we have at least one usable connection.
	 */
	public function filterHasAiCredentials( bool $has_credentials, array $connectors ): bool {
		if ( $has_credentials ) {
			return true;
		}

		return $this->repository->hasUsableConnection()
			|| '' !== (string) get_option( 'connectors_ai_koneek_api_key', '' );
	}

	/**
	 * Bypasses the valid-credentials check when we have a usable connection.
	 *
	 * The default check lists models from the provider, which may fail for
	 * custom/compatible providers even though generation works. Since the
	 * gateway intercepts and validates requests at runtime, report valid when
	 * any connection has a key.
	 *
	 * @param bool|null $valid Whether valid credentials are available. Null = use default check.
	 * @return bool|null True to bypass, otherwise the original value.
	 */
	public function filterValidCredentials( $valid ) {
		if (
			$this->repository->hasUsableConnection()
			|| '' !== (string) get_option( 'connectors_ai_koneek_api_key', '' )
		) {
			return true;
		}

		return $valid;
	}

	/**
	 * Refreshes the AI plugin's frontend provider flag after connector auth loads.
	 *
	 * The AI plugin registers this global at init priority 20. Koneek refreshes
	 * it one priority later so editor features see the runtime Koneek credentials.
	 *
	 * @return void
	 */
	public function refreshAiProviderData(): void {
		if ( ! class_exists( 'WordPress\\AI\\Asset_Loader' ) ) {
			return;
		}

		$has_provider = $this->repository->hasUsableConnection()
			|| '' !== (string) get_option( 'connectors_ai_koneek_api_key', '' );

		\WordPress\AI\Asset_Loader::add_global_data(
			'ProviderData',
			array(
				'hasProvider'   => $has_provider,
				'connectorsUrl' => admin_url( 'options-connectors.php' ),
			)
		);
	}

	/**
	 * Reports Koneek credentials to the AI plugin settings module.
	 *
	 * The AI settings screen reads these module-data flags directly instead of
	 * the global provider data used by editor features.
	 *
	 * @param array<string, mixed> $data Existing AI settings module data.
	 * @return array<string, mixed> Module data with Koneek availability applied.
	 */
	public function filterAiAdminModuleData( array $data ): array {
		if (
			! $this->repository->hasUsableConnection()
			&& '' === (string) get_option( 'connectors_ai_koneek_api_key', '' )
		) {
			return $data;
		}

		$data['hasCredentials']      = true;
		$data['hasValidCredentials'] = true;

		return $data;
	}

	/**
	 * Adds Koneek's configured text models to the AI plugin's preferred models.
	 *
	 * The AI plugin checks text-generation support before making the HTTP
	 * request that the gateway intercepts. If its default preferred models do
	 * not match the models configured in Koneek, the request can fail before the
	 * gateway gets a chance to route it.
	 *
	 * @param array<int, array{string, string}> $preferred_models Current preferred models.
	 * @return array<int, array{string, string}> Preferred models with Koneek models first.
	 */
	public function filterPreferredTextModels( array $preferred_models ): array {
		$this->registrar->register();

		$models = array();
		$seen   = array();
		$has_connector_key = '' !== (string) get_option( 'connectors_ai_koneek_api_key', '' );

		if ( $this->repository->hasUsableConnection() || $has_connector_key ) {
			$models[]                = array( 'koneek', 'gpt-4o' );
			$seen['koneek::gpt-4o'] = true;
		}

		foreach ( $this->repository->all() as $connection ) {
			if ( '' === $connection->getApiKey() || '' === $connection->getModel() ) {
				continue;
			}

			$family = $connection->getApiFamily();

			if ( 'openai' === $family && ! isset( $seen['openai::gpt-4o'] ) ) {
				$models[]                = array( 'openai', 'gpt-4o' );
				$seen['openai::gpt-4o'] = true;
			}

			$key = $family . '::' . $connection->getModel();
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$models[]     = array( $family, $connection->getModel() );
			$seen[ $key ] = true;
		}

		foreach ( $preferred_models as $preferred_model ) {
			if ( ! is_array( $preferred_model ) || count( $preferred_model ) < 2 ) {
				continue;
			}

			$provider = (string) $preferred_model[0];
			$model    = (string) $preferred_model[1];
			$key      = $provider . '::' . $model;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$models[]     = array( $provider, $model );
			$seen[ $key ] = true;
		}

		return $models;
	}

	/**
	 * Ensures the Connector Approval guard won't block requests from the AI plugin.
	 *
	 * @param mixed  $preempt Current short-circuit value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed Pass-through (never blocks).
	 */
	public function bypassConnectorApproval( $preempt, $args, $url ) {
		// Only run the registration once per page load.
		static $registered = false;
		if ( ! $registered ) {
			$registered = true;
			$this->registrar->register();
		}

		return $preempt;
	}

	/**
	 * Loads Koneek's multi-connection summary on the Connectors screen.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueueConnectorsIntegration( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( 'options-connectors.php' !== $hook_suffix && ( ! $screen || 'options-connectors' !== $screen->id ) ) {
			return;
		}

		$plugin_file = dirname( __DIR__ ) . '/koneek-plugin.php';
		$script_path = dirname( __DIR__ ) . '/assets/js/koneek-connectors.js';
		$style_path  = dirname( __DIR__ ) . '/assets/css/koneek-connectors.css';

		wp_enqueue_script_module(
			'koneek-connectors-admin',
			plugins_url( 'assets/js/koneek-connectors.js', $plugin_file ),
			array(
				array(
					'id'     => '@wordpress/connectors',
					'import' => 'static',
				),
			),
			file_exists( $script_path ) ? (string) filemtime( $script_path ) : false
		);

		wp_enqueue_style(
			'koneek-connectors-admin',
			plugins_url( 'assets/css/koneek-connectors.css', $plugin_file ),
			array(),
			file_exists( $style_path ) ? (string) filemtime( $style_path ) : false
		);
	}

	/**
	 * Supplies connection names and labels to the Connectors screen module.
	 *
	 * @param array<string, mixed> $data Existing module data.
	 * @return array<string, mixed> Module data with Koneek connection details.
	 */
	public function filterConnectorsModuleData( array $data ): array {
		$connections = array();

		foreach ( $this->repository->all() as $connection ) {
			if ( '' === $connection->getApiKey() ) {
				continue;
			}

			$name = trim( $connection->getName() );
			if ( '' === $name ) {
				$name = $connection->getDisplayName();
			}

			$connections[] = array(
				'id'   => $connection->getId(),
				'name' => $name,
			);
		}

		$data['connections'] = $connections;
		$data['settingsUrl']  = admin_url( 'options-general.php?page=koneek_settings' );
		$data['manageLabel'] = __( 'Manage connections', 'koneek-multi-provider-ai-gateway' );
		$data['setupLabel']  = __( 'Set up', 'koneek-multi-provider-ai-gateway' );
		$data['savedLabel']  = __( 'Saved API keys', 'koneek-multi-provider-ai-gateway' );
		$data['emptyLabel']  = __( 'No API keys saved.', 'koneek-multi-provider-ai-gateway' );

		return $data;
	}
}

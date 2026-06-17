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
		add_action( 'admin_menu', array( $this->settings_page, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this->settings_page, 'enqueueScripts' ) );

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

		return $this->repository->hasUsableConnection();
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
		if ( $this->repository->hasUsableConnection() ) {
			return true;
		}

		return $valid;
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
}

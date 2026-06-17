<?php
/**
 * Settings Page.
 *
 * Registers and renders the plugin settings page under the WordPress Settings
 * menu. Each provider connection is an independent card that is saved or
 * removed on its own via AJAX (there is no single global "save everything"
 * submit).
 *
 * @package Koneek
 */

namespace Koneek\Admin;

use Koneek\Connection\Connection;
use Koneek\Connection\ConnectionRepository;
use Koneek\Provider\ProviderFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the plugin settings page in the WordPress admin.
 */
class SettingsPage {

	/**
	 * Custom provider slugs that require the custom API URL field.
	 *
	 * @var string[]
	 */
	private const CUSTOM_PROVIDER_SLUGS = array( 'custom_openai' );

	/**
	 * The settings page hook suffix.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * The connection repository.
	 *
	 * @var ConnectionRepository
	 */
	private ConnectionRepository $repository;

	/**
	 * The settings renderer instance.
	 *
	 * @var SettingsRenderer
	 */
	private SettingsRenderer $renderer;

	/**
	 * The settings validator instance.
	 *
	 * @var SettingsValidator
	 */
	private SettingsValidator $validator;

	/**
	 * Constructor.
	 *
	 * @param ConnectionRepository|null $repository Optional repository.
	 */
	public function __construct( ?ConnectionRepository $repository = null ) {
		$this->repository = $repository ?? new ConnectionRepository();
		$this->renderer   = new SettingsRenderer();
		$this->validator  = new SettingsValidator();
	}

	/**
	 * Registers the settings page under the WordPress Settings menu.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->hook_suffix = add_options_page(
			__( 'Koneek', 'koneek-multi-provider-ai-gateway' ),
			__( 'Koneek', 'koneek-multi-provider-ai-gateway' ),
			'manage_options',
			'koneek_settings',
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the per-card AJAX handlers.
	 *
	 * Called unconditionally during boot (not on admin_init), because
	 * admin-ajax.php requests do not reliably fire admin_init. Registering here
	 * guarantees the handlers are available when the AJAX call arrives.
	 *
	 * @return void
	 */
	public function registerAjaxHandlers(): void {
		add_action( 'wp_ajax_koneek_save_connection', array( $this, 'handleSaveConnection' ) );
		add_action( 'wp_ajax_koneek_delete_connection', array( $this, 'handleDeleteConnection' ) );
	}

	/**
	 * Handles the AJAX request to save (insert/update) a single connection.
	 *
	 * @return void
	 */
	public function handleSaveConnection(): void {
		if ( ! check_ajax_referer( 'koneek_save_connection', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'koneek-multi-provider-ai-gateway' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'koneek-multi-provider-ai-gateway' ) ) );
		}

		$raw = isset( $_POST['connection'] ) && is_array( $_POST['connection'] )
			? map_deep( wp_unslash( $_POST['connection'] ), 'sanitize_text_field' )
			: array();

		$result = $this->sanitizeRow( $raw );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$saved = $this->repository->upsert( $result );

		// Sync credentials with the WordPress Connectors API immediately.
		$registrar = new \Koneek\Registry\ProviderRegistrar( $this->repository );
		$registrar->register();

		$key_set = ( '' !== $this->repository->getKey( $saved->getId() ) );

		// Return the full priority map so the UI can refresh badges for any
		// connections that were bumped by a priority collision.
		$priorities = array();
		foreach ( $this->repository->allWithoutKeys() as $connection ) {
			$priorities[] = array(
				'id'       => $connection->getId(),
				'priority' => $connection->getPriority(),
			);
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Connection saved.', 'koneek-multi-provider-ai-gateway' ),
				'id'          => $saved->getId(),
				'provider'    => $saved->getProvider(),
				'name'        => $saved->getName(),
				'displayName' => $saved->getDisplayName(),
				'priority'    => $saved->getPriority(),
				'model'       => $saved->getModel(),
				'keySet'      => $key_set,
				'priorities'  => $priorities,
			)
		);
	}

	/**
	 * Handles the AJAX request to delete a single connection.
	 *
	 * @return void
	 */
	public function handleDeleteConnection(): void {
		if ( ! check_ajax_referer( 'koneek_save_connection', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'koneek-multi-provider-ai-gateway' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'koneek-multi-provider-ai-gateway' ) ) );
		}

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		if ( '' !== $id ) {
			$this->repository->delete( $id );

			$registrar = new \Koneek\Registry\ProviderRegistrar( $this->repository );
			$registrar->register();
		}

		wp_send_json_success( array( 'message' => __( 'Connection removed.', 'koneek-multi-provider-ai-gateway' ) ) );
	}

	/**
	 * Sanitizes and validates a single submitted connection row.
	 *
	 * @param array $raw The raw row data from $_POST.
	 * @return array|\WP_Error A normalized row, or WP_Error when invalid.
	 */
	private function sanitizeRow( array $raw ) {
		$provider = isset( $raw['provider'] ) ? sanitize_text_field( $raw['provider'] ) : '';

		$valid_provider = $this->validator->validateProvider( $provider );
		if ( null === $valid_provider ) {
			return new \WP_Error( 'koneek_invalid_provider', __( 'Invalid provider selection.', 'koneek-multi-provider-ai-gateway' ) );
		}

		$existing_id = isset( $raw['existing_id'] ) ? sanitize_text_field( $raw['existing_id'] ) : '';
		$priority    = isset( $raw['priority'] ) ? (int) $raw['priority'] : 1;
		$timeout     = isset( $raw['timeout'] ) ? (int) $raw['timeout'] : 0;

		$name = $this->validator->validateName( isset( $raw['name'] ) ? (string) $raw['name'] : '' );
		if ( null === $name ) {
			return new \WP_Error( 'koneek_invalid_name', __( 'The name may only contain letters, numbers, and hyphens.', 'koneek-multi-provider-ai-gateway' ) );
		}

		$is_custom_provider = in_array( $valid_provider, self::CUSTOM_PROVIDER_SLUGS, true );

		// Resolve model value.
		if ( $is_custom_provider ) {
			$model_value = isset( $raw['model_text'] ) ? sanitize_text_field( $raw['model_text'] ) : '';
		} else {
			$model_dropdown = isset( $raw['model'] ) ? sanitize_text_field( $raw['model'] ) : '';
			$custom_model   = isset( $raw['custom_model'] ) ? sanitize_text_field( $raw['custom_model'] ) : '';
			$model_value    = ( 'custom' === $model_dropdown ) ? $custom_model : $model_dropdown;
		}

		$valid_model = $this->validator->validateModel( $model_value );
		if ( null === $valid_model ) {
			return new \WP_Error( 'koneek_invalid_model', __( 'A valid model name is required.', 'koneek-multi-provider-ai-gateway' ) );
		}

		// Resolve custom API URL.
		$custom_api_url = '';
		if ( $is_custom_provider ) {
			$submitted_url = isset( $raw['custom_api_url'] ) ? esc_url_raw( $raw['custom_api_url'] ) : '';
			if ( '' === $submitted_url ) {
				return new \WP_Error( 'koneek_missing_custom_url', __( 'A custom API URL is required for OpenAI-compatible providers.', 'koneek-multi-provider-ai-gateway' ) );
			}

			$valid_url = $this->validator->validateCustomApiUrl( $submitted_url );
			if ( null === $valid_url ) {
				return new \WP_Error( 'koneek_invalid_custom_url', __( 'A valid URL with an HTTP or HTTPS scheme is required for custom providers.', 'koneek-multi-provider-ai-gateway' ) );
			}

			$custom_api_url = $valid_url;
		}

		// Resolve API key: use the newly submitted key, else carry the stored one.
		$api_key_raw = isset( $raw['api_key'] ) ? trim( (string) $raw['api_key'] ) : '';
		$api_key     = '';

		if ( '' !== $api_key_raw ) {
			$valid_key = $this->validator->validateApiKey( $api_key_raw );
			if ( null === $valid_key ) {
				return new \WP_Error( 'koneek_invalid_api_key', __( 'API key must be between 8 and 256 characters.', 'koneek-multi-provider-ai-gateway' ) );
			}
			$api_key = $valid_key;
		} elseif ( '' !== $existing_id ) {
			$api_key = $this->repository->getKey( $existing_id );
		}

		if ( '' === $api_key ) {
			return new \WP_Error( 'koneek_missing_api_key', __( 'An API key is required.', 'koneek-multi-provider-ai-gateway' ) );
		}

		return array(
			'existing_id'    => $existing_id,
			'provider'       => $valid_provider,
			'name'           => $name,
			'priority'       => max( 1, $priority ),
			'model'          => $valid_model,
			'custom_api_url' => $custom_api_url,
			'timeout'        => max( 0, $timeout ),
			'api_key'        => $api_key,
		);
	}

	/**
	 * Renders the settings page HTML.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connections = $this->repository->allWithoutKeys();
		$test_nonce  = wp_create_nonce( 'koneek_test_connection' );

		echo '<div class="wrap koneek_settings_wrap">';
		echo '<div class="koneek_header">';
		echo '<h1 class="koneek_title">' . esc_html( get_admin_page_title() ) . '</h1>';
		echo '<p class="koneek_subtitle">' . esc_html__( 'Add one or more AI providers. Requests use the lowest priority number first and fall back to the next when a provider times out or errors. Each card is saved on its own.', 'koneek-multi-provider-ai-gateway' ) . '</p>';
		echo '</div>';

		echo '<div id="koneek_connections" class="koneek_connections">';

		if ( empty( $connections ) ) {
			$this->renderer->renderConnectionRow( 0, null, $test_nonce );
		} else {
			foreach ( $connections as $index => $connection ) {
				$key_set = ( '' !== $this->repository->getKey( $connection->getId() ) );
				$this->renderer->renderConnectionRow( $index, $connection, $test_nonce, $key_set );
			}
		}

		echo '</div>'; // .koneek_connections

		echo '<div class="koneek_actions_row">';
		echo '<button type="button" id="koneek_add_connection" class="button button-secondary koneek_button_add">';
		echo '<span class="koneek_add_icon" aria-hidden="true">+</span> ';
		echo esc_html__( 'Add Provider', 'koneek-multi-provider-ai-gateway' );
		echo '</button>';
		echo '</div>';

		// Hidden template row used by JS to add new connections.
		echo '<script type="text/template" id="koneek_connection_template">';
		$this->renderer->renderConnectionRow( '__INDEX__', null, $test_nonce );
		echo '</script>';

		echo '</div>'; // .wrap
	}

	/**
	 * Enqueues the JavaScript and CSS for the settings page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueueScripts( string $hook ): void {
		if ( $this->hook_suffix !== $hook ) {
			return;
		}

		$plugin_dir_url = plugin_dir_url( dirname( __DIR__ ) );

		wp_enqueue_style(
			'koneek-settings',
			$plugin_dir_url . 'src/assets/css/koneek-settings.css',
			array(),
			'1.5.0'
		);

		wp_enqueue_script(
			'koneek-settings',
			$plugin_dir_url . 'src/assets/js/koneek-settings.js',
			array(),
			'1.5.0',
			true
		);

		$providers  = ProviderFactory::getAvailableProviders();
		$model_data = array();
		$labels     = array();

		foreach ( $providers as $slug => $definition ) {
			$labels[ $slug ]     = $definition['label'];
			$provider_instance   = ProviderFactory::create( $slug );
			$model_data[ $slug ] = ( null !== $provider_instance ) ? $provider_instance->getPredefinedModels() : array();
		}

		wp_localize_script(
			'koneek-settings',
			'koneek_settings_data',
			array(
				'providerModels'  => $model_data,
				'providerLabels'  => $labels,
				'customProviders' => self::CUSTOM_PROVIDER_SLUGS,
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'saveNonce'       => wp_create_nonce( 'koneek_save_connection' ),
				'strings'         => array(
					'removeConfirm'  => __( 'Remove this provider connection?', 'koneek-multi-provider-ai-gateway' ),
					'removeUnsaved'  => __( 'Discard this provider connection?', 'koneek-multi-provider-ai-gateway' ),
					'custom'         => __( 'Custom', 'koneek-multi-provider-ai-gateway' ),
					'saving'         => __( 'Saving…', 'koneek-multi-provider-ai-gateway' ),
					'saved'          => __( 'Saved', 'koneek-multi-provider-ai-gateway' ),
					'save'           => __( 'Save', 'koneek-multi-provider-ai-gateway' ),
					'requestFailed'  => __( 'Request failed: ', 'koneek-multi-provider-ai-gateway' ),
					'invalidName'    => __( 'The name may only contain letters, numbers, and hyphens.', 'koneek-multi-provider-ai-gateway' ),
				),
			)
		);
	}
}

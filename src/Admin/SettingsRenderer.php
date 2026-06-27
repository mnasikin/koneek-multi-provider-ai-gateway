<?php
/**
 * Settings Renderer.
 *
 * Renders the HTML for a single provider connection card on the settings page.
 * Each card has a collapsible header showing its user-defined name (or provider
 * label) and priority, and a body carrying provider, API key, model, and (for
 * custom providers) endpoint URL and timeout, plus its own Test/Save/Remove
 * controls.
 *
 * @package Koneek
 */

namespace Koneek\Admin;

use Koneek\Connection\Connection;
use Koneek\Provider\ProviderFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders HTML output for connection cards.
 */
class SettingsRenderer {

	/**
	 * Renders a single connection card.
	 *
	 * @param int|string      $index      The row index (or "__INDEX__" for the JS template).
	 * @param Connection|null $connection The stored connection, or null for a blank row.
	 * @param string          $test_nonce Nonce for the test-connection AJAX call.
	 * @param bool            $key_set    Whether a key is already stored for this connection.
	 * @return void
	 */
	public function renderConnectionRow( $index, ?Connection $connection, string $test_nonce, bool $key_set = false ): void {
		$provider       = null !== $connection ? $connection->getProvider() : 'openai';
		$name           = null !== $connection ? $connection->getName() : '';
		$priority       = null !== $connection ? $connection->getPriority() : ( is_int( $index ) ? $index + 1 : 1 );
		$model          = null !== $connection ? $connection->getModel() : '';
		$custom_api_url = null !== $connection ? $connection->getCustomApiUrl() : '';
		$timeout        = null !== $connection ? $connection->getTimeout() : 0;
		$existing_id    = null !== $connection ? $connection->getId() : '';
		$display_name   = null !== $connection ? $connection->getDisplayName() : $this->providerLabel( $provider );

		$uses_manual_model = ProviderFactory::usesManualModel( $provider );
		$requires_url      = ProviderFactory::requiresCustomUrl( $provider );
		$supports_timeout  = ProviderFactory::supportsTimeout( $provider );
		$is_saved          = ( '' !== $existing_id );

		// Saved cards start collapsed so the list stays compact.
		$collapsed = $is_saved;

		$id = 'koneek_conn_' . $index;

		$card_class  = 'koneek_card koneek_connection';
		$card_class .= $is_saved ? '' : ' koneek_unsaved';
		$card_class .= $collapsed ? ' koneek_collapsed' : '';

		echo '<div class="' . esc_attr( $card_class ) . '" data-index="' . esc_attr( (string) $index ) . '" data-id="' . esc_attr( $existing_id ) . '">';

		// Header: collapse toggle (caret + priority + name/model) + remove.
		echo '<div class="koneek_card_header koneek_connection_header">';

		echo '<button type="button" class="koneek_collapse_toggle" aria-expanded="' . ( $collapsed ? 'false' : 'true' ) . '">';
		echo '<span class="koneek_caret" aria-hidden="true"></span>';
		echo '<span class="koneek_priority_badge" title="' . esc_attr__( 'Priority', 'koneek-multi-provider-ai-gateway' ) . '">' . esc_html( (string) $priority ) . '</span>';
		echo '<span class="koneek_toggle_text">';
		echo '<span class="koneek_connection_title koneek_display_name">' . esc_html( $display_name ) . '</span>';
		echo '<span class="koneek_connection_subtitle koneek_model_label">' . esc_html( '' !== $model ? $model : $this->providerLabel( $provider ) ) . '</span>';
		echo '</span>';
		echo '</button>';

		echo '<button type="button" class="koneek_button_remove" aria-label="' . esc_attr__( 'Remove connection', 'koneek-multi-provider-ai-gateway' ) . '">' . esc_html__( 'Remove', 'koneek-multi-provider-ai-gateway' ) . '</button>';
		echo '</div>';

		echo '<div class="koneek_card_collapsible">';
		echo '<div class="koneek_card_body">';

		echo '<input type="hidden" class="koneek_existing_id" value="' . esc_attr( $existing_id ) . '" />';

		// Name + Priority.
		echo '<div class="koneek_field_row">';
		echo '<label class="koneek_field_label" for="' . esc_attr( $id . '_name' ) . '">' . esc_html__( 'Name', 'koneek-multi-provider-ai-gateway' ) . '</label>';
		echo '<div class="koneek_field_input koneek_field_name_priority">';
		echo '<input type="text" id="' . esc_attr( $id . '_name' ) . '" class="koneek_input koneek_input_name" value="' . esc_attr( $name ) . '" maxlength="60" pattern="[A-Za-z0-9-]+" title="' . esc_attr__( 'Letters, numbers, and hyphens only.', 'koneek-multi-provider-ai-gateway' ) . '" placeholder="' . esc_attr__( 'e.g. nvidia', 'koneek-multi-provider-ai-gateway' ) . '" />';
		echo '<span class="koneek_priority_inline">';
		echo '<label class="koneek_priority_label" for="' . esc_attr( $id . '_priority' ) . '">' . esc_html__( 'Priority', 'koneek-multi-provider-ai-gateway' ) . '</label>';
		echo '<input type="number" min="1" step="1" class="koneek_input koneek_input_priority" id="' . esc_attr( $id . '_priority' ) . '" value="' . esc_attr( (string) $priority ) . '" />';
		echo '</span>';
		echo '</div>';
		echo '</div>';

		// Provider.
		echo '<div class="koneek_field_row">';
		echo '<label class="koneek_field_label" for="' . esc_attr( $id . '_provider' ) . '">' . esc_html__( 'Provider', 'koneek-multi-provider-ai-gateway' ) . '</label>';
		echo '<div class="koneek_field_input">';
		$this->renderProviderSelect( $id, $provider );
		echo '</div>';
		echo '</div>';

		// API Key.
		echo '<div class="koneek_field_row">';
		echo '<label class="koneek_field_label" for="' . esc_attr( $id . '_api_key' ) . '">' . esc_html__( 'API Key', 'koneek-multi-provider-ai-gateway' ) . '</label>';
		echo '<div class="koneek_field_input">';
		$this->renderApiKeyInput( $id, $key_set );
		echo '</div>';
		echo '</div>';

		// Model.
		echo '<div class="koneek_field_row">';
		echo '<label class="koneek_field_label" for="' . esc_attr( $id . '_model' ) . '">' . esc_html__( 'Model', 'koneek-multi-provider-ai-gateway' ) . '</label>';
		echo '<div class="koneek_field_input">';
		$this->renderModelField( $id, $provider, $model );
		echo '</div>';
		echo '</div>';

		// Custom API URL (custom providers only).
		$url_style = $requires_url ? '' : 'display:none';
		echo '<div class="koneek_field_row koneek_row_custom_url" style="' . esc_attr( $url_style ) . '">';
		echo '<label class="koneek_field_label" for="' . esc_attr( $id . '_custom_api_url' ) . '">' . esc_html__( 'API URL', 'koneek-multi-provider-ai-gateway' ) . '</label>';
		echo '<div class="koneek_field_input">';
		echo '<input type="url" id="' . esc_attr( $id . '_custom_api_url' ) . '" class="koneek_input koneek_input_wide koneek_input_custom_url" value="' . esc_url( $custom_api_url ) . '" maxlength="2048" placeholder="' . esc_attr__( 'https://your-api-endpoint.com/v1', 'koneek-multi-provider-ai-gateway' ) . '" />';
		echo '</div>';
		echo '</div>';

		// Timeout (custom providers only, compact).
		$timeout_style = $supports_timeout ? '' : 'display:none';
		echo '<div class="koneek_field_row koneek_row_timeout" style="' . esc_attr( $timeout_style ) . '">';
		echo '<label class="koneek_field_label" for="' . esc_attr( $id . '_timeout' ) . '">' . esc_html__( 'Timeout (s)', 'koneek-multi-provider-ai-gateway' ) . '</label>';
		echo '<div class="koneek_field_input">';
		echo '<input type="number" min="0" step="1" id="' . esc_attr( $id . '_timeout' ) . '" class="koneek_input koneek_input_timeout" value="' . esc_attr( (string) $timeout ) . '" placeholder="0" title="' . esc_attr__( 'Seconds before falling back to the next provider. 0 uses the default (180s).', 'koneek-multi-provider-ai-gateway' ) . '" />';
		echo '</div>';
		echo '</div>';

		echo '</div>'; // .koneek_card_body

		// Footer: Test + Save + status.
		echo '<div class="koneek_card_footer">';
		echo '<button type="button" class="button button-secondary koneek_button_test" data-nonce="' . esc_attr( $test_nonce ) . '">' . esc_html__( 'Test Connection', 'koneek-multi-provider-ai-gateway' ) . '</button>';
		echo '<button type="button" class="button button-primary koneek_button_save">' . esc_html__( 'Save', 'koneek-multi-provider-ai-gateway' ) . '</button>';
		echo '<span class="spinner koneek_spinner" style="float:none;margin:0;"></span>';
		echo '<span class="koneek_result koneek_card_status"></span>';
		echo '</div>';

		echo '</div>'; // .koneek_card_collapsible

		echo '</div>'; // .koneek_connection
	}

	/**
	 * Returns the human label for a provider slug.
	 *
	 * @param string $slug The provider slug.
	 * @return string
	 */
	private function providerLabel( string $slug ): string {
		$providers = ProviderFactory::getAvailableProviders();

		return isset( $providers[ $slug ]['label'] ) ? (string) $providers[ $slug ]['label'] : $slug;
	}

	/**
	 * Renders the provider <select> for a connection.
	 *
	 * @param string $id      Field id prefix.
	 * @param string $current Current provider slug.
	 * @return void
	 */
	private function renderProviderSelect( string $id, string $current ): void {
		$providers = ProviderFactory::getAvailableProviders();

		echo '<select id="' . esc_attr( $id . '_provider' ) . '" class="koneek_select koneek_select_provider">';
		foreach ( $providers as $slug => $definition ) {
			echo '<option value="' . esc_attr( $slug ) . '"' . selected( $slug, $current, false ) . '>';
			echo esc_html( $definition['label'] );
			echo '</option>';
		}
		echo '</select>';
	}

	/**
	 * Renders the API key input for a connection.
	 *
	 * @param string $id      Field id prefix.
	 * @param bool   $key_set Whether a key is already stored.
	 * @return void
	 */
	private function renderApiKeyInput( string $id, bool $key_set ): void {
		$placeholder = $key_set
			? '••••••••••••••••'
			: esc_attr__( 'Enter API key', 'koneek-multi-provider-ai-gateway' );

		echo '<input type="password" id="' . esc_attr( $id . '_api_key' ) . '" class="koneek_input koneek_input_api_key" value="" placeholder="' . esc_attr( $placeholder ) . '" maxlength="256" autocomplete="off" />';
	}

	/**
	 * Renders the model selector for a connection.
	 *
	 * Known providers get a dropdown (with a "Custom" escape hatch); custom
	 * providers get a free-text input.
	 *
	 * @param string $id       Field id prefix.
	 * @param string $provider Current provider slug.
	 * @param string $model    Current model identifier.
	 * @return void
	 */
	private function renderModelField( string $id, string $provider, string $model ): void {
		$uses_manual_model = ProviderFactory::usesManualModel( $provider );

		$provider_instance = ProviderFactory::create( $provider );
		$models            = ( null !== $provider_instance ) ? $provider_instance->getPredefinedModels() : array();
		$is_custom_model   = ( '' !== $model && ! array_key_exists( $model, $models ) );

		// Dropdown (known providers).
		$dropdown_style = $uses_manual_model ? 'display:none' : '';
		echo '<div class="koneek_model_dropdown_wrap" style="' . esc_attr( $dropdown_style ) . '">';
		echo '<select id="' . esc_attr( $id . '_model' ) . '" class="koneek_select koneek_select_model">';
		foreach ( $models as $model_id => $model_label ) {
			echo '<option value="' . esc_attr( $model_id ) . '"' . selected( $model_id, $model, false ) . '>';
			echo esc_html( $model_label );
			echo '</option>';
		}
		echo '<option value="custom"' . selected( $is_custom_model && ! $uses_manual_model, true, false ) . '>' . esc_html__( 'Custom', 'koneek-multi-provider-ai-gateway' ) . '</option>';
		echo '</select>';
		echo '</div>';

		// Custom model name input (known providers, when "Custom" chosen).
		$custom_model_style = ( $is_custom_model && ! $uses_manual_model ) ? '' : 'display:none';
		echo '<div class="koneek_custom_model_wrap" style="' . esc_attr( $custom_model_style ) . '">';
		echo '<input type="text" class="koneek_input koneek_input_custom_model" value="' . esc_attr( ( $is_custom_model && ! $uses_manual_model ) ? $model : '' ) . '" maxlength="100" placeholder="' . esc_attr__( 'Enter custom model name', 'koneek-multi-provider-ai-gateway' ) . '" />';
		echo '</div>';

		// Free-text model input (custom providers).
		$text_style = $uses_manual_model ? '' : 'display:none';
		echo '<div class="koneek_model_text_wrap" style="' . esc_attr( $text_style ) . '">';
		echo '<input type="text" class="koneek_input koneek_input_model_text" value="' . esc_attr( $uses_manual_model ? $model : '' ) . '" maxlength="256" placeholder="' . esc_attr__( 'Enter model name', 'koneek-multi-provider-ai-gateway' ) . '" />';
		echo '</div>';
	}
}

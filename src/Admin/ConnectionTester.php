<?php

/**
 * Connection Tester.
 *
 * Handles AJAX requests to test API connectivity before saving settings.
 *
 * @package Koneek
 */

namespace Koneek\Admin;

use Koneek\Provider\ProviderFactory;

if (!defined('ABSPATH'))
	exit;
/**
 * Tests API connections for configured providers.
 */
class ConnectionTester
{

	/**
	 * Registers the AJAX handler.
	 *
	 * @return void
	 */
	public function register(): void
	{
		add_action('wp_ajax_koneek_test_connection', array($this, 'handleTestConnection'));
	}

	/**
	 * Handles the AJAX test connection request.
	 *
	 * Validates the nonce, reads the submitted provider/key/model/url,
	 * and attempts a lightweight API call to verify connectivity.
	 *
	 * @return void
	 */
	public function handleTestConnection(): void
	{
		// Verify nonce.
		if (!check_ajax_referer('koneek_test_connection', 'nonce', false)) {
			wp_send_json_error(array('message' => __('Security check failed.', 'koneek-multi-provider-ai-gateway')));
		}

		// Check capability.
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'koneek-multi-provider-ai-gateway')));
		}

		$provider_slug = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
		$api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
		$model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
		$custom_api_url = isset($_POST['custom_api_url']) ? esc_url_raw(wp_unslash($_POST['custom_api_url'])) : '';
		$existing_id = isset($_POST['existing_id']) ? sanitize_text_field(wp_unslash($_POST['existing_id'])) : '';

		// If API key is empty, fall back to the stored key for this connection.
		if ('' === $api_key && '' !== $existing_id) {
			$repository = new \Koneek\Connection\ConnectionRepository();
			$api_key    = $repository->getKey($existing_id);
		}

		if ('' === $provider_slug || '' === $api_key || '' === $model) {
			wp_send_json_error(array('message' => __('Provider, API key, and model are required.', 'koneek-multi-provider-ai-gateway')));
		}

		// Create provider instance.
		$provider = ProviderFactory::create($provider_slug, $custom_api_url);

		if (null === $provider) {
			wp_send_json_error(array('message' => __('Invalid provider.', 'koneek-multi-provider-ai-gateway')));
		}

		$base_url = $provider->getBaseUrl();

		// Perform the test request based on provider type.
		$result = $this->testProviderConnection($provider_slug, $base_url, $api_key, $model);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
		}

		wp_send_json_success(array('message' => $result));
	}

	/**
	 * Tests the connection to a provider's API.
	 *
	 * Sends a minimal request to verify the API key and model are valid.
	 *
	 * @param string $provider_slug The provider slug.
	 * @param string $base_url      The API base URL.
	 * @param string $api_key       The API key.
	 * @param string $model         The model identifier.
	 * @return string|\WP_Error Success message or WP_Error on failure.
	 */
	private function testProviderConnection(string $provider_slug, string $base_url, string $api_key, string $model)
	{
		switch ($provider_slug) {
			case 'openai':
			case 'custom_openai':
				return $this->testOpenAiCompatible($base_url, $api_key, $model);

			case 'anthropic':
				return $this->testAnthropic($base_url, $api_key, $model);

			case 'google':
				return $this->testGoogle($base_url, $api_key, $model);

			default:
				return new \WP_Error('unknown_provider', __('Unknown provider type.', 'koneek-multi-provider-ai-gateway'));
		}
	}

	/**
	 * Tests an OpenAI-compatible API endpoint.
	 *
	 * Sends a minimal chat completion request with max_tokens=1.
	 *
	 * @param string $base_url The API base URL.
	 * @param string $api_key  The API key.
	 * @param string $model    The model identifier.
	 * @return string|\WP_Error Success message or error.
	 */
	private function testOpenAiCompatible(string $base_url, string $api_key, string $model)
	{
		$url = rtrim($base_url, '/') . '/chat/completions';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'koneek_internal' => true,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'model' => $model,
						'messages' => array(
							array(
								'role' => 'user',
								'content' => 'Hi',
							),
						),
						'max_tokens' => 1,
					)
				),
			)
		);

		return $this->parseResponse($response);
	}

	/**
	 * Tests an Anthropic API endpoint.
	 *
	 * Sends a minimal messages request with max_tokens=1.
	 *
	 * @param string $base_url The API base URL.
	 * @param string $api_key  The API key.
	 * @param string $model    The model identifier.
	 * @return string|\WP_Error Success message or error.
	 */
	private function testAnthropic(string $base_url, string $api_key, string $model)
	{
		$url = rtrim($base_url, '/') . '/messages';

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'koneek_internal' => true,
				'headers' => array(
					'x-api-key' => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'model' => $model,
						'messages' => array(
							array(
								'role' => 'user',
								'content' => 'Hi',
							),
						),
						'max_tokens' => 1,
					)
				),
			)
		);

		return $this->parseResponse($response);
	}

	/**
	 * Tests a Google Generative Language API endpoint.
	 *
	 * Sends a minimal generateContent request.
	 *
	 * @param string $base_url The API base URL.
	 * @param string $api_key  The API key.
	 * @param string $model    The model identifier.
	 * @return string|\WP_Error Success message or error.
	 */
	private function testGoogle(string $base_url, string $api_key, string $model)
	{
		$url = rtrim($base_url, '/') . '/models/' . $model . ':generateContent?key=' . $api_key;

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'koneek_internal' => true,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode(
					array(
						'contents' => array(
							array(
								'parts' => array(
									array('text' => 'Hi'),
								),
							),
						),
						'generationConfig' => array(
							'maxOutputTokens' => 1,
						),
					)
				),
			)
		);

		return $this->parseResponse($response);
	}

	/**
	 * Parses an API response and returns a success message or error.
	 *
	 * @param array|\WP_Error $response The wp_remote response.
	 * @return string|\WP_Error Success message or error.
	 */
	private function parseResponse($response)
	{
		if (is_wp_error($response)) {
			return new \WP_Error(
				'connection_failed',
				sprintf(
					/* translators: %s: error message */
					__('Connection failed: %s', 'koneek-multi-provider-ai-gateway'),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		if (200 === $code) {
			return __('Connection successful! API key and model are valid.', 'koneek-multi-provider-ai-gateway');
		}

		// Try to extract error message from response body.
		$decoded = json_decode($body, true);
		$error_message = '';

		if (is_array($decoded)) {
			if (isset($decoded['error']['message'])) {
				$error_message = $decoded['error']['message'];
			} elseif (isset($decoded['error']['type'])) {
				$error_message = $decoded['error']['type'];
			} elseif (isset($decoded['message'])) {
				$error_message = $decoded['message'];
			}
		}

		if ('' !== $error_message) {
			return new \WP_Error(
				'api_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: error message */
					__('API error (HTTP %1$d): %2$s', 'koneek-multi-provider-ai-gateway'),
					$code,
					$error_message
				)
			);
		}

		return new \WP_Error(
			'api_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__('API returned HTTP %d.', 'koneek-multi-provider-ai-gateway'),
				$code
			)
		);
	}
}

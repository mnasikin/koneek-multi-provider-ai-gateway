# Koneek - Multi-Provider AI Gateway

A multi-provider AI gateway for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. It allows you to connect WordPress to various AI models (OpenAI, Google Gemini, Anthropic Claude, or custom OpenAI-compatible endpoints) using a single, secure configuration — with priority-based automatic fallback across connections.

## Features

- **Multiple Provider Connections:** Configure any number of connections — including several keys for the same provider — each with its own model and priority.
- **Priority-Based Fallback:** Requests try the lowest priority number first; on timeout or error, Koneek automatically retries the next connection in order. Fallback works across different providers thanks to an automatic request/response format translation layer (OpenAI ⇄ Anthropic ⇄ Google).
- **Multi-Provider Support:** Seamless integration with OpenAI, Google Gemini, Anthropic Claude, and custom OpenAI-compatible providers (like Groq, Together AI, NVIDIA, etc.).
- **Secure Key Storage:** API keys are encrypted using strong AES-256-CBC encryption derived from WordPress secret salts (`wp-config.php`) before being saved in `wp_options`. Each connection's key lives in its own option, named after the provider and the connection's name (`koneek_api_key_openai`, `koneek_api_key_custom_openai_nvidia`, `koneek_api_key_anthropic`, ...).
- **Per-Connection Timeout:** Control how long to wait before falling back to the next provider.
- **Automatic Integration:** Automatically syncs and registers credentials with the WordPress Connectors API, auto-approving connection permissions for AI plugins.
- **OpenAI Compatibility Layer:** Intercepts and redirects standard API calls to custom providers, converting newer Responses API formats back to standard Chat Completions formats automatically.
- **Flexible Model Mapping:** Easily select predefined models or enter your own custom model names.

## How It Works

This plugin does **not** perform AI content generation itself. It is a connector/bridge that registers your provider credentials with the WordPress AI Client SDK and routes the SDK's outbound requests through your configured connections.

When the WordPress AI Client makes a generation request, Koneek intercepts it (via the `pre_http_request` filter), normalizes it, and dispatches it to the highest-priority connection. If that connection times out or returns an error, Koneek translates the request into the next connection's API format and retries — continuing down the priority list until one succeeds or all are exhausted. The successful provider's response is translated back into the format the SDK expects.

**Provider API URLs** (e.g., `api.openai.com`, `api.anthropic.com`, `generativelanguage.googleapis.com`) are used for:

1. **Provider metadata** — Base URL configuration passed to the WordPress AI Client registry so the SDK knows where to route requests.
2. **Connection testing** — An admin-only "Test Connection" feature that sends a minimal request (`max_tokens=1`) to validate your API key and model before saving.
3. **Request routing** — Koneek sends the actual generation request to the configured connection's endpoint (a provider URL or a custom OpenAI-compatible URL).

## Key Storage Scheme

Connection metadata (provider, priority, model, URL, timeout) is stored in the `koneek_connections` option. Each connection's encrypted API key is stored separately, in an option named after the provider and the connection's name:

- With a name: `koneek_api_key_{provider}_{name}` (e.g. a `custom_openai` connection named `nvidia` is stored in `koneek_api_key_custom_openai_nvidia`)
- Without a name: `koneek_api_key_{provider}` (e.g. `koneek_api_key_openai`)
- If two connections would resolve to the same option name, the later one is suffixed with `_2`, `_3`, ...

The name may only contain letters, numbers, and hyphens (the hyphen is the only allowed symbol), and is lowercased when used in the option name.

Existing single-provider configurations from earlier versions are migrated to this scheme automatically.

## Requirements

- PHP 7.4 or higher
- WordPress 7.0 or higher
    - Requires the [WordPress AI / PHP AI Client](https://github.com/WordPress/php-ai-client) plugin to be installed and activated.

## Installation

1. Upload the plugin files to `/wp-content/plugins/koneek-multi-provider-ai-gateway/`
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Settings > Koneek** in your WordPress admin menu.
4. Click "Add Provider" to create a connection, enter the API key, choose a model, and set a priority. Add more connections to build a fallback chain, then save.

## External Services

This plugin connects to third-party AI provider APIs for connection testing and credential registration purposes. It does not perform AI content generation directly — all actual AI generation is handled by the WordPress AI Client SDK.

### OpenAI API

Used to validate API keys and test connectivity when the administrator clicks "Test Connection" on the settings page. A minimal request (`max_tokens=1`) is sent to verify the API key and model are valid. The API URL is also registered with the WordPress AI Client SDK so it can route generation requests.

- **Data sent:** API key, model name, and a single-word test prompt ("Hi") with `max_tokens=1`.
- **When:** Only when an administrator manually clicks "Test Connection" on the plugin settings page, or when the WordPress AI Client SDK makes generation requests through the registered provider.

Service provided by OpenAI, LLC.
[Terms of use](https://openai.com/policies/terms-of-use) | [Privacy policy](https://openai.com/policies/privacy-policy)

### Anthropic API

Used to validate API keys and test connectivity when the administrator clicks "Test Connection" on the settings page. A minimal request (`max_tokens=1`) is sent to verify the API key and model are valid. The API URL is also registered with the WordPress AI Client SDK so it can route generation requests.

- **Data sent:** API key, model name, and a single-word test prompt ("Hi") with `max_tokens=1`.
- **When:** Only when an administrator manually clicks "Test Connection" on the plugin settings page, or when the WordPress AI Client SDK makes generation requests through the registered provider.

Service provided by Anthropic PBC.
[Terms of use](https://www.anthropic.com/legal/consumer-terms) | [Privacy policy](https://www.anthropic.com/legal/privacy)

### Google Generative Language API (Gemini)

Used to validate API keys and test connectivity when the administrator clicks "Test Connection" on the settings page. A minimal request (`maxOutputTokens=1`) is sent to verify the API key and model are valid. The API URL is also registered with the WordPress AI Client SDK so it can route generation requests.

- **Data sent:** API key, model name, and a single-word test prompt ("Hi") with `maxOutputTokens=1`.
- **When:** Only when an administrator manually clicks "Test Connection" on the plugin settings page, or when the WordPress AI Client SDK makes generation requests through the registered provider.

Service provided by Google LLC.
[Terms of use](https://ai.google.dev/gemini-api/terms) | [Privacy policy](https://policies.google.com/privacy)

### Custom OpenAI-Compatible Endpoints

When configured with a custom provider (e.g., Groq, Together AI, NVIDIA), the plugin redirects API requests from the WordPress AI Client SDK to the user-specified endpoint URL. The same test connection behavior applies.

- **Data sent:** API key, model name, and prompts as determined by the WordPress AI Client SDK or the test connection feature.
- **When:** Only when an administrator manually clicks "Test Connection," or when the WordPress AI Client SDK makes generation requests through the registered provider.

The terms of service and privacy policy depend on the specific third-party service configured by the site administrator.

## License

AGPL-3.0-or-later

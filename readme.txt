=== Koneek - OpenAI-Compatible Provider ===
Contributors: silversh
Tags: connector, ai connector, wordpress ai, openai compatible, ai
Requires at least: 7.0
Requires Plugins: ai
Tested up to: 7.0
Stable tag: 2.1.0
Requires PHP: 7.4
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html
Donate link: https://paypal.me/storedot2


A multi-provider AI gateway for the WordPress WordPress Connectors AI with priority-based automatic fallback & OpenAI-Compatible API.

== Description ==

Koneek AI connects WordPress to OpenAI, Google Gemini, Anthropic Claude, and any OpenAI-compatible endpoint, all from a single plugin. No need to install separate provider plugins for each service. Built on top of the WordPress Connectors API, API keys are encrypted before being stored in the database.

Add as many provider connections as you like, each with its own priority. When the WordPress AI Client makes a request, Koneek routes it to the lowest priority number first and automatically falls back to the next connection if that provider times out or returns an error — even falling back across different providers (for example, OpenAI first, then Anthropic, then Google).


**Features:**

* **Multiple Provider Connections:** Configure any number of connections — including several keys for the same provider — each with its own model and priority.
* **Priority-Based Fallback:** Requests try priority 1 first; on timeout or error, Koneek automatically retries the next connection in order. Fallback works across different providers thanks to an automatic request/response format translation layer.
* **Multi-Provider Support:** Seamless integration with OpenAI, Google Gemini, Anthropic Claude, and custom OpenAI-compatible providers (like Groq, Together AI, NVIDIA, etc.).
* **Secure Storage:** API keys are securely stored in the database using strong AES-256-CBC encryption derived from your WordPress secret salts (`wp-config.php`). Each connection's key is stored in its own option, named after the provider and the connection's name (`koneek_api_key_openai`, `koneek_api_key_custom_openai_nvidia`, `koneek_api_key_anthropic`, ...).
* **Per-Connection Timeout:** Set how long to wait before falling back to the next provider.
* **OpenAI Compatibility Layer:** Automatically redirects standard API calls to custom providers, converting newer Responses API formats back to standard Chat Completions.
* **Automatic Integration:** Automatically syncs and registers credentials with the WordPress Connectors API, auto-approving connection permissions for AI plugins.
* **Flexible Model Mapping:** Choose from predefined models or enter your own custom model names.

**Requirements:**

* PHP 7.4 or higher
* OpenAI API key, Gemini API Key, Anthropic API Key, or Any OpenAI Compatible API Key

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/koneek-multi-provider-ai-gateway/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to **Settings > Koneek**, add one or more provider connections, set each connection's API key, model, and priority, then save.

== Screenshots ==

1. Setting page with multiple AI Provider
2. AI Log when using OpenAI-Compatible API Key
3. Post title Generation using OpenAI-Compatible API Key
4. Post content replacements using OpenAI-Compatible API Key

== How It Works ==

This plugin does **not** perform AI content generation directly. It is a connector/bridge that registers your provider credentials with the WordPress AI Client SDK.

**Provider API URLs** (e.g., `api.openai.com`, `api.anthropic.com`, `generativelanguage.googleapis.com`) are used exclusively for:

1. **Provider metadata** — Base URL configuration passed to the WordPress AI Client registry so the SDK knows where to route requests.
2. **Connection testing** — An admin-only "Test Connection" feature that sends a minimal request (`max_tokens=1`) to validate your API key and model before saving. This is not used for content generation.
3. **Custom provider URL rewriting** — For OpenAI-compatible endpoints, the plugin intercepts requests from the WordPress AI Client and redirects them to your custom URL.

All actual AI generation is handled by the WordPress AI Client SDK (`WordPress\AiClient\AiClient`). This plugin simply ensures the correct provider is registered and credentials are available.

There are no third-party AI SDKs in `composer.json`. The only HTTP calls to provider APIs originate from the connection tester (admin-only, lightweight validation).

== Frequently Asked Questions ==

= How do I configure different AI providers? =

On the settings page, click "Add Provider" to create a connection. For each connection, select the provider (OpenAI, Google Gemini, Anthropic, or Custom OpenAI-compatible), enter the API key, choose a model, and set a priority number. Add more connections to build a fallback chain.

= How does the priority fallback work? =

Each connection has a priority number. When the WordPress AI Client makes a request, Koneek tries the connection with the lowest priority number first. If that provider times out or returns an error, it automatically retries the next connection in priority order. Because Koneek translates between the OpenAI, Anthropic, and Google request/response formats, fallback works even when the next connection uses a different provider.

= How are multiple keys stored? =

Each connection's API key is stored in its own encrypted option, named after the provider and the connection's name. A named connection uses `koneek_api_key_{provider}_{name}` (e.g. a `custom_openai` connection named `nvidia` is stored in `koneek_api_key_custom_openai_nvidia`), while an unnamed connection uses `koneek_api_key_{provider}` (e.g. `koneek_api_key_openai`). The name may only contain letters, numbers, and hyphens, and is lowercased in the option name. If two connections would resolve to the same option name, the later one is numbered: `_2`, `_3`, and so on.

= Are my API keys stored securely? =

Yes. All API keys are encrypted using AES-256-CBC before they are saved to the database. The encryption key is dynamically derived from your WordPress secret salts (defined in your `wp-config.php`), meaning your keys remain secure even if your database is compromised.

= Does this plugin work without the PHP AI Client? =

No, this plugin requires the PHP AI Client plugin to be installed and activated. It acts as a gateway that registers your credentials with the WordPress Connectors API.

== External Services ==

This plugin connects to third-party AI provider APIs for connection testing and credential registration purposes. It does not perform AI content generation directly — all actual AI generation is handled by the WordPress AI Client SDK.

= OpenAI API =

Used to validate API keys and test connectivity when the administrator clicks "Test Connection" on the settings page. A minimal request (max_tokens=1) is sent to verify the API key and model are valid. The API URL is also registered with the WordPress AI Client SDK so it can route generation requests.

Data sent: API key, model name, and a single-word test prompt ("Hi") with max_tokens=1.
When: Only when an administrator manually clicks "Test Connection" on the plugin settings page, or when the WordPress AI Client SDK makes generation requests through the registered provider.

Service provided by OpenAI, LLC.
Terms of use: https://openai.com/policies/terms-of-use
Privacy policy: https://openai.com/policies/privacy-policy

= Anthropic API =

Used to validate API keys and test connectivity when the administrator clicks "Test Connection" on the settings page. A minimal request (max_tokens=1) is sent to verify the API key and model are valid. The API URL is also registered with the WordPress AI Client SDK so it can route generation requests.

Data sent: API key, model name, and a single-word test prompt ("Hi") with max_tokens=1.
When: Only when an administrator manually clicks "Test Connection" on the plugin settings page, or when the WordPress AI Client SDK makes generation requests through the registered provider.

Service provided by Anthropic PBC.
Terms of use: https://www.anthropic.com/legal/consumer-terms
Privacy policy: https://www.anthropic.com/legal/privacy

= Google Generative Language API (Gemini) =

Used to validate API keys and test connectivity when the administrator clicks "Test Connection" on the settings page. A minimal request (maxOutputTokens=1) is sent to verify the API key and model are valid. The API URL is also registered with the WordPress AI Client SDK so it can route generation requests.

Data sent: API key, model name, and a single-word test prompt ("Hi") with maxOutputTokens=1.
When: Only when an administrator manually clicks "Test Connection" on the plugin settings page, or when the WordPress AI Client SDK makes generation requests through the registered provider.

Service provided by Google LLC.
Terms of use: https://ai.google.dev/gemini-api/terms
Privacy policy: https://policies.google.com/privacy

= Custom OpenAI-Compatible Endpoints =

When configured with a custom provider (e.g., Groq, Together AI, NVIDIA), the plugin redirects API requests from the WordPress AI Client SDK to the user-specified endpoint URL. The same test connection behavior applies.

Data sent: API key, model name, and prompts as determined by the WordPress AI Client SDK or the test connection feature.
When: Only when an administrator manually clicks "Test Connection," or when the WordPress AI Client SDK makes generation requests through the registered provider.

The terms of service and privacy policy depend on the specific third-party service configured by the site administrator.

== Changelog ==

= 2.1.0 =

* Fix Koneek that still relying on  the separate OpenAI provider plugin.
* Added model preference integration so the WordPress AI plugin can detect Koneek-configured text generation models.
* Improved OpenAI-compatible request routing and response parsing for Responses API and Chat Completions formats.

= 2.0.0 =

* Added support to save multiple API Keys with priority order.
* Redesigned the settings UI into compact, independent provider cards.
* Collapsed connections now display as a compact tile grid showing the name and model, so many providers fit without long stacked cards.
* An expanded connection spans the full width for editing; collapsed tiles flow side by side.
* Added an optional custom name for each connection, shown in the card header.
* Cards are now collapsible. Saved connections start collapsed, showing just the name and priority, so the list stays compact.



= 1.1.0 =

* Added support for multiple provider connections, each with its own model and priority.
* Added priority-based automatic fallback: requests try priority 1 first and fall back to the next connection on timeout or error.
* Added cross-provider fallback via an automatic OpenAI/Anthropic/Google request and response translation layer.
* Added per-connection request timeout.

= 2.1.0 =

* Fix Koneek that still relying on  the separate OpenAI provider plugin.

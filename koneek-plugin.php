<?php
/**
 * Plugin Name: Koneek - OpenAI-Compatible Provider
 * Plugin URI: https://wordpress.org/plugins/koneek-multi-provider-ai-gateway/
 * Description: A multi-provider AI gateway for the WordPress WordPress Connectors AI with priority-based automatic fallback & OpenAI-Compatible API.
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Version: 2.2.0
 * Author: silversh
 * Author URI: https://github.com/mnasikin
 * License: AGPL-3.0-or-later
 * License URI: https://spdx.org/licenses/AGPL-3.0-or-later.html
 * Text Domain: koneek-multi-provider-ai-gateway
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

require_once __DIR__ . '/src/autoload.php';

$koneek_plugin = new \Koneek\Plugin();
$koneek_plugin->boot();

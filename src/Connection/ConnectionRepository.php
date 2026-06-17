<?php
/**
 * Connection Repository.
 *
 * Persists and retrieves the list of configured provider connections.
 *
 * Connection metadata (provider, priority, model, URL, timeout) is stored as a
 * single array in the "koneek_connections" option. Each connection's API key is
 * stored separately and encrypted, in an option named per the required scheme:
 *
 *   - With a user-defined name:  koneek_api_key_{provider}_{name-slug}
 *   - Without a name:            koneek_api_key_{provider}
 *   - On a name/provider clash:  the base id is suffixed with _2, _3, ...
 *
 * The name slug is the user-defined name lowercased with everything except
 * letters, digits and hyphens removed, e.g. a "custom_openai" connection named
 * "NVIDIA" is stored in koneek_api_key_custom_openai_nvidia.
 *
 * @package Koneek
 */

namespace Koneek\Connection;

use Koneek\Provider\ProviderFactory;
use Koneek\Utils\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the configured connection list.
 */
class ConnectionRepository {

	/**
	 * Option that stores the connection metadata array.
	 *
	 * @var string
	 */
	public const CONNECTIONS_OPTION = 'koneek_connections';

	/**
	 * Prefix for per-connection API key options.
	 *
	 * @var string
	 */
	public const KEY_OPTION_PREFIX = 'koneek_api_key_';

	/**
	 * Returns all configured connections sorted by ascending priority, each
	 * with its decrypted API key attached.
	 *
	 * @return Connection[]
	 */
	public function all(): array {
		$connections = $this->allWithoutKeys();

		$resolved = array();
		foreach ( $connections as $connection ) {
			$resolved[] = $connection->withApiKey( $this->getKey( $connection->getId() ) );
		}

		return $resolved;
	}

	/**
	 * Returns all configured connections sorted by ascending priority, without
	 * resolving API keys (suitable for rendering the settings UI).
	 *
	 * @return Connection[]
	 */
	public function allWithoutKeys(): array {
		$stored = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! is_array( $stored ) || empty( $stored ) ) {
			$stored = $this->migrateLegacy();
		}

		$connections = array();
		foreach ( $stored as $row ) {
			if ( is_array( $row ) && '' !== (string) ( $row['id'] ?? '' ) ) {
				$connections[] = Connection::fromArray( $row );
			}
		}

		usort(
			$connections,
			static function ( Connection $a, Connection $b ): int {
				return $a->getPriority() <=> $b->getPriority();
			}
		);

		return $connections;
	}

	/**
	 * Returns the connection with the lowest priority value (tried first), or
	 * null when none are configured.
	 *
	 * @return Connection|null
	 */
	public function primary(): ?Connection {
		$all = $this->all();

		foreach ( $all as $connection ) {
			if ( '' !== $connection->getApiKey() ) {
				return $connection;
			}
		}

		return $all[0] ?? null;
	}

	/**
	 * Indicates whether at least one connection has a usable API key.
	 *
	 * @return bool
	 */
	public function hasUsableConnection(): bool {
		foreach ( $this->all() as $connection ) {
			if ( '' !== $connection->getApiKey() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the decrypted API key for a connection id, or empty string.
	 *
	 * @param string $id The connection id (key option suffix).
	 * @return string
	 */
	public function getKey( string $id ): string {
		if ( '' === $id ) {
			return '';
		}

		return Encryption::decrypt( (string) get_option( self::KEY_OPTION_PREFIX . $id, '' ) );
	}

	/**
	 * Returns the option name that stores the API key for a connection id.
	 *
	 * @param string $id The connection id.
	 * @return string
	 */
	public function keyOptionName( string $id ): string {
		return self::KEY_OPTION_PREFIX . $id;
	}

	/**
	 * Persists a set of connection rows.
	 *
	 * Each input row is an associative array with keys:
	 *   provider, priority, model, custom_api_url, timeout, api_key.
	 *
	 * Connection ids (and therefore key option names) are assigned here based on
	 * the required naming scheme: "{provider}_{name-slug}" when a name is given,
	 * otherwise the bare provider slug, with collisions resolved by a numeric
	 * suffix. When a row omits an api_key but matches an existing connection id,
	 * the previously stored key is preserved.
	 *
	 * @param array<int, array<string, mixed>> $rows The submitted connection rows.
	 * @return Connection[] The saved connections (with assigned ids).
	 */
	public function save( array $rows ): array {
		$existing_ids = $this->currentKeyIds();

		// Assign ids using the provider + name-slug scheme.
		$prepared      = array();
		$used_ids      = array();

		foreach ( $rows as $index => $row ) {
			$provider = (string) ( $row['provider'] ?? '' );
			if ( '' === $provider ) {
				continue;
			}

			$name = (string) ( $row['name'] ?? '' );
			$base = $this->baseId( $provider, $name );

			// Resolve collisions against ids already assigned in this batch.
			$id = $base;
			$n  = 2;
			while ( isset( $used_ids[ $id ] ) ) {
				$id = $base . '_' . $n;
				++$n;
			}

			$prepared[] = array(
				'id'             => $id,
				'provider'       => $provider,
				'name'           => $name,
				'priority'       => max( 1, (int) ( $row['priority'] ?? ( $index + 1 ) ) ),
				'model'          => (string) ( $row['model'] ?? '' ),
				'custom_api_url' => (string) ( $row['custom_api_url'] ?? '' ),
				'timeout'        => max( 0, (int) ( $row['timeout'] ?? 0 ) ),
				'api_key'        => (string) ( $row['api_key'] ?? '' ),
			);

			$used_ids[ $id ] = true;
		}

		// Persist API keys.
		foreach ( $prepared as $row ) {
			$id      = $row['id'];
			$api_key = $row['api_key'];

			if ( '' !== $api_key ) {
				update_option( $this->keyOptionName( $id ), Encryption::encrypt( $api_key ) );
			} elseif ( ! in_array( $id, $existing_ids, true ) ) {
				// New connection with no key supplied: store an empty value.
				update_option( $this->keyOptionName( $id ), '' );
			}
			// Otherwise (existing id, blank key) keep the previously stored key.
		}

		// Remove key options for connections that no longer exist.
		foreach ( $existing_ids as $old_id ) {
			if ( ! isset( $used_ids[ $old_id ] ) ) {
				delete_option( $this->keyOptionName( $old_id ) );
			}
		}

		// Persist metadata (without keys).
		$metadata = array();
		foreach ( $prepared as $row ) {
			unset( $row['api_key'] );
			$metadata[] = $row;
		}

		update_option( self::CONNECTIONS_OPTION, $metadata );

		$connections = array();
		foreach ( $metadata as $row ) {
			$connections[] = Connection::fromArray( $row );
		}

		return $connections;
	}

	/**
	 * Inserts or updates a single connection without disturbing the others.
	 *
	 * Used by the per-card AJAX save. The row is an associative array with keys:
	 *   existing_id, provider, priority, model, custom_api_url, timeout, api_key.
	 *
	 * Id assignment follows the required naming scheme: the id is derived from
	 * the provider slug and the user-defined name as "{provider}_{name-slug}"
	 * (e.g. koneek_api_key_custom_openai_nvidia). When no name is given the bare
	 * provider slug is used, and any collision is resolved by appending _2, _3,
	 * ... Editing a connection in a way that changes its id (a new name or
	 * provider) migrates the stored key to the new option name and removes the
	 * old one.
	 *
	 * @param array<string, mixed> $row The submitted connection row.
	 * @return Connection The saved connection (with its assigned id).
	 */
	public function upsert( array $row ): Connection {
		$provider    = (string) ( $row['provider'] ?? '' );
		$name        = (string) ( $row['name'] ?? '' );
		$existing_id = (string) ( $row['existing_id'] ?? '' );

		$existing = $this->allWithoutKeys();

		// Locate the connection currently being edited, if any.
		$current = null;
		if ( '' !== $existing_id ) {
			foreach ( $existing as $connection ) {
				if ( $connection->getId() === $existing_id ) {
					$current = $connection;
					break;
				}
			}
		}

		// Derive the id from the provider and user-defined name, e.g.
		// koneek_api_key_custom_openai_nvidia. Editing a card that changes the
		// id migrates the stored key below.
		$desired_base = $this->baseId( $provider, $name );
		$old_id       = ( null !== $current ) ? $current->getId() : null;
		$id           = $this->uniqueId( $desired_base, $existing, $old_id );

		// Persist the API key under the (possibly new) id.
		$api_key = (string) ( $row['api_key'] ?? '' );
		if ( '' !== $api_key ) {
			update_option( $this->keyOptionName( $id ), Encryption::encrypt( $api_key ) );
		} elseif ( null !== $old_id && $old_id !== $id ) {
			// Editing with no new key supplied: carry the stored key to the new id.
			update_option( $this->keyOptionName( $id ), (string) get_option( $this->keyOptionName( $old_id ), '' ) );
		} elseif ( null === $current ) {
			// Brand-new connection with no key supplied yet.
			update_option( $this->keyOptionName( $id ), '' );
		}
		// Otherwise (existing id, blank key, unchanged id) keep the stored key.

		// Drop the stale key option when the id changed during an edit.
		if ( null !== $old_id && $old_id !== $id ) {
			delete_option( $this->keyOptionName( $old_id ) );
		}

		$meta = array(
			'id'             => $id,
			'provider'       => $provider,
			'name'           => (string) ( $row['name'] ?? '' ),
			'priority'       => max( 1, (int) ( $row['priority'] ?? 1 ) ),
			'model'          => (string) ( $row['model'] ?? '' ),
			'custom_api_url' => (string) ( $row['custom_api_url'] ?? '' ),
			'timeout'        => max( 0, (int) ( $row['timeout'] ?? 0 ) ),
		);

		// Merge into the stored list (replace the edited row or append).
		$metadata = array();
		$replaced = false;
		foreach ( $existing as $connection ) {
			if ( null !== $current && $connection->getId() === $current->getId() ) {
				$metadata[] = $meta;
				$replaced   = true;
			} else {
				$metadata[] = $connection->toArray();
			}
		}
		if ( ! $replaced ) {
			$metadata[] = $meta;
		}

		// Resolve priority collisions: the just-saved connection keeps its
		// requested priority, and any other connection that shares that number
		// is pushed to the end of the chain (made last).
		$metadata = $this->resolvePriorityCollisions( $metadata, $id, (int) $meta['priority'] );

		usort(
			$metadata,
			static function ( array $a, array $b ): int {
				return ( (int) $a['priority'] ) <=> ( (int) $b['priority'] );
			}
		);

		update_option( self::CONNECTIONS_OPTION, $metadata );

		$saved_meta = $meta;
		foreach ( $metadata as $candidate ) {
			if ( ( $candidate['id'] ?? '' ) === $id ) {
				$saved_meta = $candidate;
				break;
			}
		}

		return Connection::fromArray( $saved_meta );
	}

	/**
	 * Pushes connections that collide with a given priority to the end.
	 *
	 * The connection identified by $keep_id retains $priority. Every other
	 * connection that currently has the same priority value is reassigned to a
	 * new number after the current maximum, in their existing order, so the
	 * previously-occupying connection(s) become last in the fallback chain.
	 *
	 * @param array<int, array<string, mixed>> $metadata The connection metadata rows.
	 * @param string                           $keep_id  Id of the connection that keeps $priority.
	 * @param int                              $priority The priority being claimed.
	 * @return array<int, array<string, mixed>> The adjusted metadata.
	 */
	private function resolvePriorityCollisions( array $metadata, string $keep_id, int $priority ): array {
		// Any other connection sharing this priority?
		$has_collision = false;
		foreach ( $metadata as $row ) {
			if ( ( $row['id'] ?? '' ) !== $keep_id && (int) ( $row['priority'] ?? 0 ) === $priority ) {
				$has_collision = true;
				break;
			}
		}

		if ( ! $has_collision ) {
			return $metadata;
		}

		// Highest priority currently in use (so bumped rows go after everyone).
		$max = 0;
		foreach ( $metadata as $row ) {
			$max = max( $max, (int) ( $row['priority'] ?? 0 ) );
		}

		$next = $max + 1;
		foreach ( $metadata as &$row ) {
			if ( ( $row['id'] ?? '' ) !== $keep_id && (int) ( $row['priority'] ?? 0 ) === $priority ) {
				$row['priority'] = $next;
				++$next;
			}
		}
		unset( $row );

		return $metadata;
	}

	/**
	 * Builds the base id for a connection from its provider and user-defined name.
	 *
	 * The id is "{provider}_{name-slug}" when a name is supplied, otherwise the
	 * bare provider slug. The name slug is the name lowercased with every
	 * character except letters, digits and hyphens removed, e.g. a "custom_openai"
	 * connection named "NVIDIA" yields "custom_openai_nvidia".
	 *
	 * @param string $provider The provider slug.
	 * @param string $name     The user-defined connection name (may be empty).
	 * @return string
	 */
	private function baseId( string $provider, string $name ): string {
		$slug = $this->slugifyName( $name );

		return ( '' === $slug ) ? $provider : $provider . '_' . $slug;
	}

	/**
	 * Normalizes a user-defined name into an id slug.
	 *
	 * Lowercases the name and strips everything except ASCII letters, digits and
	 * hyphens. Leading/trailing hyphens and repeated hyphens are collapsed.
	 *
	 * @param string $name The user-defined name.
	 * @return string The slug, or an empty string when nothing usable remains.
	 */
	private function slugifyName( string $name ): string {
		$slug = strtolower( trim( $name ) );
		$slug = preg_replace( '/[^a-z0-9-]+/', '', $slug );
		$slug = preg_replace( '/-+/', '-', (string) $slug );

		return trim( (string) $slug, '-' );
	}

	/**
	 * Ensures an id is unique among the existing connections.
	 *
	 * When the desired base id is already in use, a numeric suffix (_2, _3, ...)
	 * is appended until a free id is found. The connection currently being
	 * edited (if any) is excluded so re-saving it keeps its id.
	 *
	 * @param string       $base       The desired base id.
	 * @param Connection[] $existing   The currently stored connections.
	 * @param string|null  $exclude_id Optional id to ignore (the row being edited).
	 * @return string
	 */
	private function uniqueId( string $base, array $existing, ?string $exclude_id ): string {
		$used = array();
		foreach ( $existing as $connection ) {
			if ( null !== $exclude_id && $connection->getId() === $exclude_id ) {
				continue;
			}
			$used[ $connection->getId() ] = true;
		}

		if ( ! isset( $used[ $base ] ) ) {
			return $base;
		}

		$n = 2;
		while ( true ) {
			$candidate = $base . '_' . $n;
			if ( ! isset( $used[ $candidate ] ) ) {
				return $candidate;
			}
			++$n;
		}
	}

	/**
	 * Deletes a single connection by id, removing its key option and metadata.
	 *
	 * @param string $id The connection id to remove.
	 * @return void
	 */
	public function delete( string $id ): void {
		delete_option( $this->keyOptionName( $id ) );

		$metadata = array();
		foreach ( $this->allWithoutKeys() as $connection ) {
			if ( $connection->getId() === $id ) {
				continue;
			}
			$metadata[] = $connection->toArray();
		}

		update_option( self::CONNECTIONS_OPTION, $metadata );
	}

	/**
	 * Returns the ids of all connections currently stored.
	 *
	 * @return string[]
	 */
	private function currentKeyIds(): array {
		$ids = array();
		foreach ( $this->allWithoutKeys() as $connection ) {
			$ids[] = $connection->getId();
		}

		return $ids;
	}

	/**
	 * Migrates legacy single-connection options into the new structure.
	 *
	 * Earlier versions stored a single connection in koneek_provider /
	 * koneek_api_key / koneek_model / koneek_custom_api_url. When no connection
	 * list exists yet, convert those into the first connection so existing
	 * installs keep working after the upgrade.
	 *
	 * @return array<int, array<string, mixed>> Connection metadata rows.
	 */
	private function migrateLegacy(): array {
		$legacy_provider = (string) get_option( 'koneek_provider', '' );
		$legacy_key_enc  = (string) get_option( 'koneek_api_key', '' );

		if ( '' === $legacy_provider && '' === $legacy_key_enc ) {
			return array();
		}

		if ( '' === $legacy_provider ) {
			$legacy_provider = 'openai';
		}

		if ( null === ProviderFactory::create( $legacy_provider ) ) {
			return array();
		}

		$id = $legacy_provider;

		$metadata = array(
			array(
				'id'             => $id,
				'provider'       => $legacy_provider,
				'priority'       => 1,
				'model'          => (string) get_option( 'koneek_model', '' ),
				'custom_api_url' => (string) get_option( 'koneek_custom_api_url', '' ),
				'timeout'        => 0,
			),
		);

		// Move the encrypted key to the new per-connection option name.
		if ( '' !== $legacy_key_enc && '' === (string) get_option( $this->keyOptionName( $id ), '' ) ) {
			update_option( $this->keyOptionName( $id ), $legacy_key_enc );
		}

		update_option( self::CONNECTIONS_OPTION, $metadata );

		return $metadata;
	}
}

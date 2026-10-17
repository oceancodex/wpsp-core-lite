<?php

namespace WPSPCORELITE\App\Cache\Stores;

use Closure;
use WPSPCORELITE\App\Cache\CachePrefix;
use WPSPCORELITE\App\Cache\Concerns\RetrievesMultipleKeys;
use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Exceptions\LockTimeoutException;
use WPSPCORELITE\App\Cache\Locks\Lock;
use WPSPCORELITE\App\Cache\Locks\ObjectCacheLock;
use WPSPCORELITE\App\Cache\Locks\TransientLock;

/**
 * Cache store on the WordPress Transients API (driver "transient").
 *
 * - No persistent object cache: rows in wp_options (Laravel "database" driver equivalent).
 * - Redis / Memcached object-cache.php drop-in: WordPress sends transients there automatically.
 * - 'network' => true: site transients (shared by every site of a multisite network).
 *
 * Values are stored as ['v' => value, 'e' => expiresAt] so false / 0 / '' can be cached
 * (get_transient() returns false on a miss) and increment() keeps the original expiry.
 *
 * Every key is "{prefix}.{key}" (default "wpsp_lite_.{key}"); flush() only removes this plugin's keys,
 * even when another plugin's prefix starts with the same characters (see CachePrefix).
 */
class TransientStore extends TaggableStore implements LockProvider {

	use RetrievesMultipleKeys {
		many as protected manyOneByOne;
	}

	/**
	 * Without an object cache, a transient with expiration 0 is autoloaded on EVERY request.
	 * "Forever" therefore means 10 years in that mode.
	 */
	public const FOREVER_FALLBACK = 315360000;

	protected string  $prefix;
	protected string  $base;
	protected bool    $network;
	protected array   $lockLottery;
	protected int     $lockTimeout;
	protected bool    $atomicIncrements;
	protected ?string $lockGroup;
	protected ?string $namespaceVersion = null;

	/**
	 * @param array{network?: bool, lock_lottery?: array, lock_timeout?: int, atomic_increments?: bool, lock_group?: string} $options
	 */
	public function __construct(string $prefix = CachePrefix::DEFAULT_PREFIX, array $options = []) {
		$this->prefix           = CachePrefix::keyPrefix($prefix);
		$this->base             = CachePrefix::base($prefix);
		$this->network          = (bool) ($options['network'] ?? false);
		$this->lockLottery      = $options['lock_lottery'] ?? [2, 100];
		$this->lockTimeout      = (int) ($options['lock_timeout'] ?? 86400);
		$this->atomicIncrements = (bool) ($options['atomic_increments'] ?? true);
		$this->lockGroup        = $options['lock_group'] ?? null;
	}

	/*
	 * Store contract.
	 */

	public function get($key): mixed {
		$payload = $this->payload($key);
		return $payload === null ? null : $payload['v'];
	}

	/**
	 * Loads every row in one query (wp_prime_option_caches) before reading the keys.
	 */
	public function many(array $keys): array {
		$this->primeOptionCaches($keys);
		return $this->manyOneByOne($keys);
	}

	public function put($key, $value, $seconds): bool {
		$seconds = max(1, (int) $seconds);
		return $this->write($key, $value, time() + $seconds, $seconds);
	}

	/**
	 * Store an item only if it doesn't exist (atomic, like Laravel's DatabaseStore::add()).
	 */
	public function add($key, $value, $seconds): bool {
		$seconds = max(1, (int) $seconds);
		$name    = $this->transientKey($key);
		$payload = ['v' => $value, 'e' => time() + $seconds];

		if ($this->usingObjectCache()) {
			return (bool) wp_cache_add($name, $payload, $this->objectCacheGroup(), $seconds);
		}

		if ($this->payload($key) !== null) {
			return false;
		}

		if ($this->usesSitemeta()) {
			// wp_sitemeta has no unique key: no atomic insert possible.
			return $this->put($key, $value, $seconds);
		}

		global $wpdb;

		$timeoutName = $this->timeoutPrefix() . $name;
		$valueName   = $this->valuePrefix() . $name;

		// Remove an expired timeout row left behind, then claim the key by inserting the timeout row.
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d",
			$timeoutName,
			time()
		));

		$wpdb->query($wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$timeoutName,
			(string) $payload['e']
		));

		if ((int) $wpdb->rows_affected !== 1) {
			return false;
		}

		$wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
			$valueName,
			maybe_serialize($payload)
		));

		$this->forgetOptionCaches([$timeoutName, $valueName]);

		return true;
	}

	public function increment($key, $value = 1): int|bool {
		return $this->atomically($key, fn() => $this->incrementOrDecrement($key, $value, fn($current, $value) => $current + $value));
	}

	public function decrement($key, $value = 1): int|bool {
		return $this->atomically($key, fn() => $this->incrementOrDecrement($key, $value, fn($current, $value) => $current - $value));
	}

	public function forever($key, $value): bool {
		return $this->write($key, $value, 0, $this->foreverTtl());
	}

	/**
	 * Laravel 12 Cache::touch(): set a new expiration on an existing item.
	 */
	public function touch($key, $seconds): bool {
		$payload = $this->payload($key);

		if ($payload === null) {
			return false;
		}

		return $this->put($key, $payload['v'], $seconds);
	}

	public function forget($key): bool {
		$name = $this->transientKey($key);

		return (bool) ($this->network ? delete_site_transient($name) : delete_transient($name));
	}

	/**
	 * Remove every item of this plugin, never other plugins' transients.
	 */
	public function flush(): bool {
		if ($this->prefix === '') {
			return false; // Refuse to wipe every transient of the site.
		}

		if ($this->usingObjectCache()) {
			// Object caches can't delete by prefix: switch to a fresh key namespace.
			$this->namespaceVersion = CachePrefix::newVersion();
			$this->setRaw($this->versionKey(), $this->namespaceVersion, 0);
			return true;
		}

		global $wpdb;

		$valueLike   = $wpdb->esc_like($this->valuePrefix() . $this->prefix) . '%';
		$timeoutLike = $wpdb->esc_like($this->timeoutPrefix() . $this->prefix) . '%';

		if ($this->usesSitemeta()) {
			$result = $wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)",
				get_current_network_id(),
				$valueLike,
				$timeoutLike
			));
			$this->flushRuntimeCacheGroup('site-options');
		}
		else {
			$result = $wpdb->query($wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$valueLike,
				$timeoutLike
			));
			$this->flushRuntimeCacheGroup('options');
		}

		return $result !== false;
	}

	public function getPrefix(): string {
		return $this->prefix;
	}

	public function setPrefix(string $prefix): void {
		$this->prefix           = CachePrefix::keyPrefix($prefix);
		$this->base             = CachePrefix::base($prefix);
		$this->namespaceVersion = null;
	}

	/*
	 * Locks.
	 */

	public function lock($name, $seconds = 0, $owner = null): LockContract {
		if ($this->usingObjectCache()) {
			return new ObjectCacheLock($this->prefix . $name, $seconds, $owner, $this->lockGroup ?? $this->base . '_cache_locks');
		}

		return new TransientLock(
			$this->lockTable(),
			$this->prefix . 'lock' . CachePrefix::SEPARATOR,
			$name,
			$seconds,
			$owner,
			$this->lockLottery,
			$this->lockTimeout
		);
	}

	public function restoreLock($name, $owner): LockContract {
		return $this->lock($name, 0, $owner);
	}

	/*
	 * Internals.
	 */

	protected function payload($key): ?array {
		$raw = $this->getRaw($this->transientKey($key));

		if (!is_array($raw) || !array_key_exists('v', $raw)) {
			return null;
		}

		$expiresAt = (int) ($raw['e'] ?? 0);

		if ($expiresAt !== 0 && $expiresAt <= time()) {
			$this->forget($key);
			return null;
		}

		return ['v' => $raw['v'], 'e' => $expiresAt];
	}

	protected function write($key, $value, int $expiresAt, int $ttl): bool {
		$name    = $this->transientKey($key);
		$payload = ['v' => $value, 'e' => $expiresAt];

		if ($this->setRaw($name, $payload, $ttl)) {
			return true;
		}

		// update_option() returns false when the stored value is identical; that is still a success.
		return $this->getRaw($name) == $payload;
	}

	protected function incrementOrDecrement($key, $value, Closure $callback): int|bool {
		$payload = $this->payload($key);

		if ($payload === null) {
			$new = $callback(0, (int) $value);
			return $this->forever($key, $new) ? $new : false;
		}

		$new = $callback((int) $payload['v'], (int) $value);
		$ttl = $payload['e'] === 0 ? $this->foreverTtl() : max(1, $payload['e'] - time());

		return $this->write($key, $new, $payload['e'], $ttl) ? $new : false;
	}

	/**
	 * Run a read-modify-write under a short lock (Laravel's DatabaseStore uses a transaction for this).
	 */
	protected function atomically($key, Closure $callback): mixed {
		if (!$this->atomicIncrements) {
			return $callback();
		}

		$lock = $this->lock('incr:' . $key, 10);

		if ($lock instanceof Lock) {
			$lock->betweenBlockedAttemptsSleepFor(10);
		}

		try {
			return $lock->block(3, $callback);
		}
		catch (LockTimeoutException $e) {
			return $callback();
		}
	}

	protected function getRaw(string $name): mixed {
		return $this->network ? get_site_transient($name) : get_transient($name);
	}

	protected function setRaw(string $name, $value, int $ttl): bool {
		return (bool) ($this->network ? set_site_transient($name, $value, $ttl) : set_transient($name, $value, $ttl));
	}

	protected function transientKey($key): string {
		$base = $this->prefix . $this->namespaceSegment();
		$full = $base . $key;

		// Transient names: 172 chars, site transient names: 167 chars.
		if (strlen($full) > ($this->network ? 167 : 172)) {
			$full = $base . 'h_' . sha1((string) $key);
		}

		return $full;
	}

	protected function namespaceSegment(): string {
		if (!$this->usingObjectCache()) {
			return '';
		}

		if ($this->namespaceVersion === null) {
			$version = $this->getRaw($this->versionKey());

			if (!is_string($version) || $version === '') {
				$version = CachePrefix::newVersion();
				$this->setRaw($this->versionKey(), $version, 0);
			}

			$this->namespaceVersion = $version;
		}

		return $this->namespaceVersion . CachePrefix::SEPARATOR;
	}

	protected function primeOptionCaches(array $keys): void {
		if (count($keys) < 2 || $this->usingObjectCache()) {
			return;
		}

		$names = [];
		foreach ($keys as $key) {
			$name    = $this->transientKey($key);
			$names[] = $this->valuePrefix() . $name;
			$names[] = $this->timeoutPrefix() . $name;
		}

		if ($this->usesSitemeta()) {
			if (function_exists('wp_prime_network_option_caches')) {
				wp_prime_network_option_caches(get_current_network_id(), $names);
			}
		}
		elseif (function_exists('wp_prime_option_caches')) {
			wp_prime_option_caches($names);
		}
	}

	/**
	 * Clear WordPress' per-request option caches after writing rows directly.
	 */
	protected function forgetOptionCaches(array $names): void {
		foreach ($names as $name) {
			wp_cache_delete($name, 'options');
		}

		$notOptions = wp_cache_get('notoptions', 'options');

		if (is_array($notOptions)) {
			$changed = false;

			foreach ($names as $name) {
				if (isset($notOptions[$name])) {
					unset($notOptions[$name]);
					$changed = true;
				}
			}

			if ($changed) {
				wp_cache_set('notoptions', $notOptions, 'options');
			}
		}
	}

	protected function flushRuntimeCacheGroup(string $group): void {
		if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
			wp_cache_flush_group($group);
		}
		elseif (function_exists('wp_cache_flush_runtime')) {
			wp_cache_flush_runtime();
		}
	}

	protected function lockTable(): string {
		global $wpdb;

		// Network cache: locks live in the main site's options table.
		if ($this->network && function_exists('is_multisite') && is_multisite()) {
			return $wpdb->get_blog_prefix(get_main_site_id()) . 'options';
		}

		return $wpdb->options;
	}

	protected function valuePrefix(): string {
		return $this->network ? '_site_transient_' : '_transient_';
	}

	protected function timeoutPrefix(): string {
		return $this->network ? '_site_transient_timeout_' : '_transient_timeout_';
	}

	protected function objectCacheGroup(): string {
		return $this->network ? 'site-transient' : 'transient';
	}

	protected function usesSitemeta(): bool {
		return $this->network && function_exists('is_multisite') && is_multisite();
	}

	protected function versionKey(): string {
		return $this->prefix . '_ns';
	}

	protected function foreverTtl(): int {
		return $this->usingObjectCache() ? 0 : static::FOREVER_FALLBACK;
	}

	protected function usingObjectCache(): bool {
		return function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
	}

}

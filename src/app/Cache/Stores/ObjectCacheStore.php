<?php

namespace WPSPCORELITE\App\Cache\Stores;

use WPSPCORELITE\App\Cache\CachePrefix;
use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Locks\ObjectCacheLock;

/**
 * Cache store on the WordPress object cache API (driver "object"), the equivalent of
 * Laravel's "redis" / "memcached" drivers when a Redis / Memcached object-cache.php drop-in is installed.
 *
 * Without a persistent object cache, WordPress keeps these values for the current request only.
 * Use the "transient" driver in that case.
 *
 * Note: WordPress core's runtime cache floors decrements at 0.
 */
class ObjectCacheStore extends TaggableStore implements LockProvider {

	protected string  $prefix;
	protected string  $group;
	protected string  $lockGroup;
	protected ?string $namespaceVersion = null;

	/**
	 * Keys "{prefix}.{key}" in group "{base}_cache" (default "wpsp_lite_cache"),
	 * locks in group "{base}_cache_locks".
	 */
	public function __construct(string $prefix = CachePrefix::DEFAULT_PREFIX, ?string $group = null, ?string $lockGroup = null) {
		$this->prefix    = CachePrefix::keyPrefix($prefix);
		$this->group     = $group ?: CachePrefix::base($prefix) . '_cache';
		$this->lockGroup = $lockGroup ?: CachePrefix::base($prefix) . '_cache_locks';
	}

	public function get($key): mixed {
		$found = false;
		$value = wp_cache_get($this->itemKey($key), $this->group, false, $found);

		return $found ? $value : null;
	}

	public function many(array $keys): array {
		if (empty($keys)) {
			return [];
		}

		if (!function_exists('wp_cache_get_multiple')) {
			$result = [];
			foreach ($keys as $key) {
				$result[$key] = $this->get($key);
			}
			return $result;
		}

		$map = [];
		foreach ($keys as $key) {
			$map[$this->itemKey($key)] = $key;
		}

		$values = wp_cache_get_multiple(array_keys($map), $this->group);
		$result = [];

		foreach ($map as $itemKey => $key) {
			$value = $values[$itemKey] ?? false;

			// false means "miss" for wp_cache_get_multiple(); double-check in case false was cached.
			$result[$key] = $value === false ? $this->get($key) : $value;
		}

		return $result;
	}

	public function put($key, $value, $seconds): bool {
		return (bool) wp_cache_set($this->itemKey($key), $value, $this->group, max(1, (int) $seconds));
	}

	public function putMany(array $values, $seconds): bool {
		if (empty($values)) {
			return true;
		}

		$seconds = max(1, (int) $seconds);

		if (function_exists('wp_cache_set_multiple')) {
			$data = [];
			foreach ($values as $key => $value) {
				$data[$this->itemKey($key)] = $value;
			}

			return !in_array(false, (array) wp_cache_set_multiple($data, $this->group, $seconds), true);
		}

		$result = true;
		foreach ($values as $key => $value) {
			$result = $this->put($key, $value, $seconds) && $result;
		}

		return $result;
	}

	/**
	 * Atomic on Redis / Memcached drop-ins.
	 */
	public function add($key, $value, $seconds): bool {
		return (bool) wp_cache_add($this->itemKey($key), $value, $this->group, max(1, (int) $seconds));
	}

	public function increment($key, $value = 1): int|bool {
		$itemKey = $this->itemKey($key);
		$result  = wp_cache_incr($itemKey, (int) $value, $this->group);

		if ($result === false) {
			if (wp_cache_add($itemKey, (int) $value, $this->group, 0)) {
				return (int) $value;
			}

			$result = wp_cache_incr($itemKey, (int) $value, $this->group);
		}

		return $result === false ? false : (int) $result;
	}

	public function decrement($key, $value = 1): int|bool {
		return $this->increment($key, (int) $value * -1);
	}

	public function forever($key, $value): bool {
		return (bool) wp_cache_set($this->itemKey($key), $value, $this->group, 0);
	}

	public function touch($key, $seconds): bool {
		$found = false;
		$value = wp_cache_get($this->itemKey($key), $this->group, false, $found);

		if (!$found) {
			return false;
		}

		return $this->put($key, $value, $seconds);
	}

	public function forget($key): bool {
		return (bool) wp_cache_delete($this->itemKey($key), $this->group);
	}

	public function flush(): bool {
		if ($this->supportsGroupFlush()) {
			return (bool) wp_cache_flush_group($this->group);
		}

		$this->namespaceVersion = CachePrefix::newVersion();
		wp_cache_set($this->prefix . '_ns', $this->namespaceVersion, $this->group, 0);

		return true;
	}

	public function lock($name, $seconds = 0, $owner = null): LockContract {
		return new ObjectCacheLock($this->prefix . $name, $seconds, $owner, $this->lockGroup);
	}

	public function restoreLock($name, $owner): LockContract {
		return $this->lock($name, 0, $owner);
	}

	public function getPrefix(): string {
		return $this->prefix;
	}

	public function setPrefix(string $prefix): void {
		$this->prefix           = CachePrefix::keyPrefix($prefix);
		$this->namespaceVersion = null;
	}

	public function getGroup(): string {
		return $this->group;
	}

	/*
	 *
	 */

	protected function itemKey($key): string {
		return $this->prefix . $this->namespaceSegment() . $key;
	}

	protected function namespaceSegment(): string {
		if ($this->supportsGroupFlush()) {
			return '';
		}

		if ($this->namespaceVersion === null) {
			$found   = false;
			$version = wp_cache_get($this->prefix . '_ns', $this->group, false, $found);

			if (!$found || !is_string($version) || $version === '') {
				$version = CachePrefix::newVersion();
				wp_cache_set($this->prefix . '_ns', $version, $this->group, 0);
			}

			$this->namespaceVersion = $version;
		}

		return $this->namespaceVersion . CachePrefix::SEPARATOR;
	}

	protected function supportsGroupFlush(): bool {
		return function_exists('wp_cache_supports') && wp_cache_supports('flush_group');
	}

}

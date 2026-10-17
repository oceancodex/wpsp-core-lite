<?php

namespace WPSPCORELITE\App\Cache\Stores;

use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use WPSPCORELITE\App\Cache\CachePrefix;
use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Locks\CacheLock;

/**
 * Cache store on phpfastcache v8/v9 (Files, Sqlite, Redis, Predis, Memcached, Apcu...).
 * Requires "phpfastcache/phpfastcache" in the plugin's composer.json.
 *
 * Plugins share backends safely without choosing a Redis database:
 *
 * - "namespace" flush (Redis, Predis, Memcached, Apcu... = shared backends):
 *   keys are "{prefix}.{version}.{key}". flush() switches this plugin to a new version, so its old
 *   keys are unreachable at once, then deletes them with getAllItems("{prefix}.{old}.*")
 *   when the driver can list keys (Redis SCAN). Other plugins' keys are never read or deleted.
 *   flushDB() / clear() is never called.
 *
 * - "clear" flush (Files, Sqlite, Leveldb): the pool path is always "{path}/{prefix}",
 *   so the folder belongs to this plugin only and clear() is safe.
 */
class PhpFastCacheStore extends TaggableStore implements LockProvider {

	public const FLUSH_NAMESPACE = 'namespace';
	public const FLUSH_CLEAR     = 'clear';

	/**
	 * PSR-6 reserved characters, rejected by phpfastcache.
	 */
	protected const RESERVED_CHARACTERS = '{}()/\\@:';

	protected const MAX_KEY_LENGTH = 200;

	/**
	 * "Forever" = 10 years (phpfastcache would otherwise apply its defaultTtl, 900s).
	 */
	public const FOREVER_SECONDS = 315360000;

	protected ExtendedCacheItemPoolInterface $pool;
	protected string                         $prefix;
	protected string                         $flushStrategy;
	protected bool                           $purgeOnFlush;
	protected ?string                        $namespaceVersion = null;

	public function __construct(
		ExtendedCacheItemPoolInterface $pool,
		string $prefix = CachePrefix::DEFAULT_PREFIX,
		string $flushStrategy = self::FLUSH_NAMESPACE,
		bool $purgeOnFlush = true
	) {
		$this->pool          = $pool;
		$this->prefix        = CachePrefix::keyPrefix($prefix);
		$this->flushStrategy = $flushStrategy === self::FLUSH_CLEAR ? self::FLUSH_CLEAR : self::FLUSH_NAMESPACE;
		$this->purgeOnFlush  = $purgeOnFlush;
	}

	public function get($key): mixed {
		$item = $this->pool->getItem($this->itemKey($key));
		return $item->isHit() ? $item->get() : null;
	}

	public function many(array $keys): array {
		if (empty($keys)) {
			return [];
		}

		$map = [];
		foreach ($keys as $key) {
			$map[$this->itemKey($key)] = $key;
		}

		$items  = $this->pool->getItems(array_keys($map));
		$result = array_fill_keys($keys, null);

		foreach ($items as $itemKey => $item) {
			if (isset($map[$itemKey]) && $item->isHit()) {
				$result[$map[$itemKey]] = $item->get();
			}
		}

		return $result;
	}

	public function put($key, $value, $seconds): bool {
		$item = $this->pool->getItem($this->itemKey($key));
		$item->set($value)->expiresAfter(max(1, (int) $seconds));

		return $this->pool->save($item);
	}

	public function putMany(array $values, $seconds): bool {
		if (empty($values)) {
			return true;
		}

		$seconds = max(1, (int) $seconds);

		foreach ($values as $key => $value) {
			$item = $this->pool->getItem($this->itemKey($key));
			$item->set($value)->expiresAfter($seconds);
			$this->pool->saveDeferred($item);
		}

		return $this->pool->commit();
	}

	/**
	 * Store an item only if it does not exist yet (not atomic across processes).
	 */
	public function add($key, $value, $seconds): bool {
		if ($this->pool->hasItem($this->itemKey($key))) {
			return false;
		}

		return $this->put($key, $value, $seconds);
	}

	public function increment($key, $value = 1): int|bool {
		$item = $this->pool->getItem($this->itemKey($key));

		if (!$item->isHit()) {
			return $this->forever($key, (int) $value) ? (int) $value : false;
		}

		// The loaded item keeps its original expiration date.
		$new = (int) $item->get() + (int) $value;
		$item->set($new);

		return $this->pool->save($item) ? $new : false;
	}

	public function decrement($key, $value = 1): int|bool {
		return $this->increment($key, (int) $value * -1);
	}

	public function forever($key, $value): bool {
		return $this->put($key, $value, static::FOREVER_SECONDS);
	}

	public function touch($key, $seconds): bool {
		$item = $this->pool->getItem($this->itemKey($key));

		if (!$item->isHit()) {
			return false;
		}

		$item->expiresAfter(max(1, (int) $seconds));

		return $this->pool->save($item);
	}

	public function forget($key): bool {
		return $this->pool->deleteItem($this->itemKey($key));
	}

	/**
	 * Remove this plugin's items only (see class doc).
	 */
	public function flush(): bool {
		if ($this->flushStrategy === self::FLUSH_CLEAR) {
			return $this->pool->clear();
		}

		$oldVersion = $this->currentVersion();
		$newVersion = CachePrefix::newVersion();

		$item = $this->pool->getItem($this->versionKey());
		$item->set($newVersion)->expiresAfter(static::FOREVER_SECONDS);

		if (!$this->pool->save($item)) {
			return false;
		}

		$this->namespaceVersion = $newVersion;

		if ($this->purgeOnFlush) {
			$this->purgeVersion($oldVersion);
		}

		return true;
	}

	public function lock($name, $seconds = 0, $owner = null): LockContract {
		return new CacheLock($this, $name, $seconds, $owner);
	}

	public function restoreLock($name, $owner): LockContract {
		return $this->lock($name, 0, $owner);
	}

	public function getPool(): ExtendedCacheItemPoolInterface {
		return $this->pool;
	}

	public function getPrefix(): string {
		return $this->prefix;
	}

	public function getFlushStrategy(): string {
		return $this->flushStrategy;
	}

	/*
	 *
	 */

	protected function itemKey($key): string {
		$base = $this->prefix . $this->namespaceSegment();
		$key  = (string) $key;

		if (strpbrk($key, static::RESERVED_CHARACTERS) !== false || strlen($base . $key) > static::MAX_KEY_LENGTH) {
			$key = 'h_' . sha1($key);
		}

		return $base . $key;
	}

	protected function namespaceSegment(): string {
		if ($this->flushStrategy === self::FLUSH_CLEAR) {
			return '';
		}

		return $this->currentVersion() . CachePrefix::SEPARATOR;
	}

	protected function currentVersion(): string {
		if ($this->namespaceVersion === null) {
			$item    = $this->pool->getItem($this->versionKey());
			$version = $item->isHit() ? $item->get() : null;

			if (!is_string($version) || $version === '') {
				$version = CachePrefix::newVersion();
				$item->set($version)->expiresAfter(static::FOREVER_SECONDS);
				$this->pool->save($item);
			}

			$this->namespaceVersion = $version;
		}

		return $this->namespaceVersion;
	}

	protected function versionKey(): string {
		return $this->prefix . '_ns';
	}

	/**
	 * Delete every key of an old namespace version (drivers that can list keys: Redis, Predis, Rediscluster, Memory).
	 * Drivers that can't (Memcached, Apcu...) keep the orphans until their TTL / LRU eviction.
	 */
	protected function purgeVersion(string $version): void {
		$pattern = $this->prefix . $version . CachePrefix::SEPARATOR . '*';
		$max     = defined(ExtendedCacheItemPoolInterface::class . '::MAX_ALL_KEYS_COUNT') ? ExtendedCacheItemPoolInterface::MAX_ALL_KEYS_COUNT : 9999;

		try {
			for ($round = 0; $round < 1000; $round++) {
				$keys = [];

				foreach ($this->pool->getAllItems($pattern) as $index => $item) {
					$keys[] = is_string($index) ? $index : $item->getKey();
				}

				if (empty($keys)) {
					break;
				}

				$this->pool->deleteItems($keys);

				if (count($keys) < $max) {
					break;
				}
			}
		}
		catch (\Throwable $e) {
			// Listing keys is not supported by this driver: orphans expire by TTL.
		}
	}

}

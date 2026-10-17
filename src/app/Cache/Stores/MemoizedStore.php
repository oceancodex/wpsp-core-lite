<?php

namespace WPSPCORELITE\App\Cache\Stores;

use BadMethodCallException;
use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Contracts\Store;
use WPSPCORELITE\App\Cache\Repository;

/**
 * Mirror of Illuminate\Cache\MemoizedStore (Cache::memo()):
 * keeps values read during the current request in memory, so repeated
 * reads of the same key don't hit the underlying store again.
 */
class MemoizedStore implements Store, LockProvider {

	protected array      $cache = [];
	protected string     $name;
	protected Repository $repository;

	public function __construct(string $name, Repository $repository) {
		$this->name       = $name;
		$this->repository = $repository;
	}

	public function get($key): mixed {
		$memoKey = $this->memoKey($key);

		if (array_key_exists($memoKey, $this->cache)) {
			return $this->cache[$memoKey];
		}

		return $this->cache[$memoKey] = $this->repository->get($key);
	}

	public function many(array $keys): array {
		$missing = [];

		foreach ($keys as $key) {
			if (!array_key_exists($this->memoKey($key), $this->cache)) {
				$missing[] = $key;
			}
		}

		if (!empty($missing)) {
			foreach ($this->repository->many($missing) as $key => $value) {
				$this->cache[$this->memoKey($key)] = $value;
			}
		}

		$result = [];
		foreach ($keys as $key) {
			$result[$key] = $this->cache[$this->memoKey($key)];
		}

		return $result;
	}

	public function put($key, $value, $seconds): bool {
		unset($this->cache[$this->memoKey($key)]);
		return $this->repository->put($key, $value, $seconds);
	}

	public function putMany(array $values, $seconds): bool {
		foreach ($values as $key => $value) {
			unset($this->cache[$this->memoKey($key)]);
		}

		return $this->repository->putMany($values, $seconds);
	}

	public function add($key, $value, $seconds): bool {
		unset($this->cache[$this->memoKey($key)]);
		return $this->repository->add($key, $value, $seconds);
	}

	public function increment($key, $value = 1): int|bool {
		unset($this->cache[$this->memoKey($key)]);
		return $this->repository->increment($key, $value);
	}

	public function decrement($key, $value = 1): int|bool {
		unset($this->cache[$this->memoKey($key)]);
		return $this->repository->decrement($key, $value);
	}

	public function forever($key, $value): bool {
		unset($this->cache[$this->memoKey($key)]);
		return $this->repository->forever($key, $value);
	}

	public function touch($key, $seconds): bool {
		return $this->repository->touch($key, $seconds);
	}

	public function forget($key): bool {
		unset($this->cache[$this->memoKey($key)]);
		return $this->repository->forget($key);
	}

	public function flush(): bool {
		$this->cache = [];
		return $this->repository->getStore()->flush();
	}

	public function lock($name, $seconds = 0, $owner = null): LockContract {
		$store = $this->repository->getStore();

		if (!$store instanceof LockProvider) {
			throw new BadMethodCallException('This cache store does not support locks.');
		}

		return $store->lock($name, $seconds, $owner);
	}

	public function restoreLock($name, $owner): LockContract {
		return $this->lock($name, 0, $owner);
	}

	public function getPrefix(): string {
		return $this->repository->getStore()->getPrefix();
	}

	protected function memoKey($key): string {
		return $this->getPrefix() . $key;
	}

}

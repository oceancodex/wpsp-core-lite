<?php

namespace WPSPCORELITE\App\Cache\Stores;

use Closure;
use RuntimeException;
use Throwable;
use WPSPCORELITE\App\Cache\CacheManager;
use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Contracts\Store;
use WPSPCORELITE\App\Cache\Events\CacheFailedOver;
use WPSPCORELITE\App\Cache\Events\Dispatcher;

/**
 * Mirror of Illuminate\Cache\FailoverStore (Laravel 12 "failover" driver).
 *
 * 'failover' => ['driver' => 'failover', 'stores' => ['phpfastcache', 'transient']]
 *
 * Every call goes to the first store; when it throws, the next store is used
 * and a CacheFailedOver event is dispatched.
 */
class FailoverStore extends TaggableStore implements LockProvider {

	protected CacheManager $cache;
	protected ?Dispatcher  $events;
	protected array        $stores;

	public function __construct(CacheManager $cache, ?Dispatcher $events, array $stores) {
		$this->cache  = $cache;
		$this->events = $events;
		$this->stores = array_values($stores);
	}

	public function get($key): mixed {
		return $this->attempt(fn(Store $store) => $store->get($key));
	}

	public function many(array $keys): array {
		return $this->attempt(fn(Store $store) => $store->many($keys));
	}

	public function put($key, $value, $seconds): bool {
		return $this->attempt(fn(Store $store) => $store->put($key, $value, $seconds));
	}

	public function putMany(array $values, $seconds): bool {
		return $this->attempt(fn(Store $store) => $store->putMany($values, $seconds));
	}

	public function add($key, $value, $seconds): bool {
		return $this->attempt(function (Store $store) use ($key, $value, $seconds) {
			if (method_exists($store, 'add')) {
				return $store->add($key, $value, $seconds);
			}

			return is_null($store->get($key)) ? $store->put($key, $value, $seconds) : false;
		});
	}

	public function increment($key, $value = 1): int|bool {
		return $this->attempt(fn(Store $store) => $store->increment($key, $value));
	}

	public function decrement($key, $value = 1): int|bool {
		return $this->attempt(fn(Store $store) => $store->decrement($key, $value));
	}

	public function forever($key, $value): bool {
		return $this->attempt(fn(Store $store) => $store->forever($key, $value));
	}

	public function touch($key, $seconds): bool {
		return $this->attempt(function (Store $store) use ($key, $seconds) {
			if (method_exists($store, 'touch')) {
				return $store->touch($key, $seconds);
			}

			$value = $store->get($key);

			return $value === null ? false : $store->put($key, $value, $seconds);
		});
	}

	public function forget($key): bool {
		return $this->attempt(fn(Store $store) => $store->forget($key));
	}

	public function flush(): bool {
		return $this->attempt(fn(Store $store) => $store->flush());
	}

	public function lock($name, $seconds = 0, $owner = null): LockContract {
		return $this->attempt(function (Store $store) use ($name, $seconds, $owner) {
			if (!$store instanceof LockProvider) {
				throw new RuntimeException('This cache store does not support locks.');
			}

			return $store->lock($name, $seconds, $owner);
		});
	}

	public function restoreLock($name, $owner): LockContract {
		return $this->lock($name, 0, $owner);
	}

	public function getPrefix(): string {
		return $this->attempt(fn(Store $store) => $store->getPrefix());
	}

	public function getStores(): array {
		return $this->stores;
	}

	/*
	 *
	 */

	protected function attempt(Closure $callback): mixed {
		$lastException = null;

		foreach ($this->stores as $name) {
			try {
				return $callback($this->cache->store($name)->getStore());
			}
			catch (Throwable $e) {
				$lastException = $e;
				$this->events?->dispatch(new CacheFailedOver($name, $e));
			}
		}

		throw $lastException ?? new RuntimeException('No failover cache stores are configured.');
	}

}

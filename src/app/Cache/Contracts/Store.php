<?php

namespace WPSPCORELITE\App\Cache\Contracts;

/**
 * Mirror of Illuminate\Contracts\Cache\Store.
 */
interface Store {

	/**
	 * Retrieve an item from the cache by key. Null means "miss".
	 */
	public function get($key): mixed;

	/**
	 * Retrieve multiple items from the cache by key.
	 * Items not found in the cache will have a null value.
	 */
	public function many(array $keys): array;

	/**
	 * Store an item in the cache for a given number of seconds.
	 */
	public function put($key, $value, $seconds): bool;

	/**
	 * Store multiple items in the cache for a given number of seconds.
	 */
	public function putMany(array $values, $seconds): bool;

	/**
	 * Increment the value of an item in the cache.
	 */
	public function increment($key, $value = 1): int|bool;

	/**
	 * Decrement the value of an item in the cache.
	 */
	public function decrement($key, $value = 1): int|bool;

	/**
	 * Store an item in the cache indefinitely.
	 */
	public function forever($key, $value): bool;

	/**
	 * Remove an item from the cache.
	 */
	public function forget($key): bool;

	/**
	 * Remove all items from the cache.
	 */
	public function flush(): bool;

	/**
	 * Get the cache key prefix.
	 */
	public function getPrefix(): string;

}

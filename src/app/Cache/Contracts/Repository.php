<?php

namespace WPSPCORELITE\App\Cache\Contracts;

use Closure;

/**
 * Mirror of Illuminate\Contracts\Cache\Repository (including the PSR-16 methods it inherits),
 * declared without the psr/simple-cache dependency.
 */
interface Repository {

	public function has($key): bool;

	public function get($key, $default = null): mixed;

	public function getMultiple(iterable $keys, $default = null): iterable;

	public function set($key, $value, $ttl = null): bool;

	public function setMultiple(iterable $values, $ttl = null): bool;

	public function delete($key): bool;

	public function deleteMultiple(iterable $keys): bool;

	public function clear(): bool;

	public function pull($key, $default = null): mixed;

	public function put($key, $value, $ttl = null): bool;

	public function add($key, $value, $ttl = null): bool;

	public function increment($key, $value = 1): int|bool;

	public function decrement($key, $value = 1): int|bool;

	public function forever($key, $value): bool;

	public function remember($key, $ttl, Closure $callback): mixed;

	public function sear($key, Closure $callback): mixed;

	public function rememberForever($key, Closure $callback): mixed;

	public function forget($key): bool;

	public function getStore(): Store;

}

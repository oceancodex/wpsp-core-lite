<?php

namespace WPSPCORELITE\App\Cache;

use ArrayAccess;
use BackedEnum;
use BadMethodCallException;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use UnitEnum;
use WPSPCORELITE\App\Cache\Concerns\Macroable;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Contracts\Repository as RepositoryContract;
use WPSPCORELITE\App\Cache\Contracts\Store;
use WPSPCORELITE\App\Cache\Events\CacheFlushed;
use WPSPCORELITE\App\Cache\Events\CacheFlushFailed;
use WPSPCORELITE\App\Cache\Events\CacheFlushing;
use WPSPCORELITE\App\Cache\Events\CacheHit;
use WPSPCORELITE\App\Cache\Events\CacheMissed;
use WPSPCORELITE\App\Cache\Events\Dispatcher;
use WPSPCORELITE\App\Cache\Events\ForgettingKey;
use WPSPCORELITE\App\Cache\Events\KeyForgetFailed;
use WPSPCORELITE\App\Cache\Events\KeyForgotten;
use WPSPCORELITE\App\Cache\Events\KeyWriteFailed;
use WPSPCORELITE\App\Cache\Events\KeyWritten;
use WPSPCORELITE\App\Cache\Events\RetrievingKey;
use WPSPCORELITE\App\Cache\Events\RetrievingManyKeys;
use WPSPCORELITE\App\Cache\Events\WritingKey;
use WPSPCORELITE\App\Cache\Events\WritingManyKeys;
use WPSPCORELITE\App\Cache\Exceptions\InvalidArgumentException;

/**
 * Mirror of Illuminate\Cache\Repository (Laravel 12), without Illuminate / Symfony / Carbon.
 *
 * $ttl: int seconds | DateInterval | DateTimeInterface | null (forever). Keys may be strings or enums.
 *
 * @method \WPSPCORELITE\App\Cache\Contracts\Lock lock(string $name, int $seconds = 0, ?string $owner = null)
 * @method \WPSPCORELITE\App\Cache\Contracts\Lock restoreLock(string $name, string $owner)
 */
class Repository implements ArrayAccess, RepositoryContract {

	use Macroable;

	protected Store       $store;
	protected array       $config;
	protected ?Dispatcher $events = null;

	/**
	 * The default number of seconds to store items (offsetSet, tags).
	 */
	protected ?int $default = 3600;

	/**
	 * Deferred refreshes registered during this request (flexible()).
	 */
	protected static array $deferred = [];

	public function __construct(Store $store, array $config = []) {
		$this->store  = $store;
		$this->config = $config;

		if (array_key_exists('ttl', $config)) {
			$this->default = $config['ttl'] === null ? null : (int) $config['ttl'];
		}
	}

	/*
	 * Retrieving.
	 */

	/**
	 * Determine if an item exists in the cache.
	 */
	public function has($key): bool {
		return !is_null($this->get($key));
	}

	/**
	 * Determine if an item doesn't exist in the cache.
	 */
	public function missing($key): bool {
		return !$this->has($key);
	}

	/**
	 * Retrieve an item from the cache by key. An array of keys is passed to many().
	 */
	public function get($key, $default = null): mixed {
		if (is_array($key)) {
			return $this->many($key);
		}

		$key = $this->normalizeKey($key);

		$this->fire(fn() => new RetrievingKey($this->getName(), $key));

		$value = $this->store->get($this->itemKey($key));

		if (is_null($value)) {
			$this->fire(fn() => new CacheMissed($this->getName(), $key));

			$value = static::value($default);
		}
		else {
			$this->fire(fn() => new CacheHit($this->getName(), $key, $value));
		}

		return $value;
	}

	/**
	 * Retrieve multiple items: ['a', 'b'] or ['a' => 'default', 'b' => fn() => 'default'].
	 */
	public function many(array $keys): array {
		$defaults = [];

		foreach ($keys as $key => $value) {
			if (is_string($key)) {
				$defaults[$key] = $value;
			}
			else {
				$defaults[$this->normalizeKey($value)] = null;
			}
		}

		if (empty($defaults)) {
			return [];
		}

		$this->fire(fn() => new RetrievingManyKeys($this->getName(), array_map('strval', array_keys($defaults))));

		$itemKeys = [];
		foreach (array_keys($defaults) as $key) {
			$itemKeys[$key] = $this->itemKey($key);
		}

		$values = $this->store->many(array_values($itemKeys));
		$result = [];

		foreach ($itemKeys as $key => $itemKey) {
			$value = $values[$itemKey] ?? null;

			if (is_null($value)) {
				$this->fire(fn() => new CacheMissed($this->getName(), $key));

				$value = static::value($defaults[$key]);
			}
			else {
				$this->fire(fn() => new CacheHit($this->getName(), $key, $value));
			}

			$result[$key] = $value;
		}

		return $result;
	}

	/**
	 * PSR-16 getMultiple().
	 */
	public function getMultiple(iterable $keys, $default = null): iterable {
		$defaults = [];

		foreach ($keys as $key) {
			$defaults[$this->normalizeKey($key)] = $default;
		}

		return $this->many($defaults);
	}

	/**
	 * Retrieve an item from the cache and delete it.
	 */
	public function pull($key, $default = null): mixed {
		$value = $this->get($key, $default);

		$this->forget($key);

		return $value;
	}

	/*
	 * Typed retrieval (Laravel 12).
	 */

	public function string($key, $default = null): string {
		$value = $this->get($key, $default);

		if (!is_string($value)) {
			throw new InvalidArgumentException(sprintf('Cache value for key [%s] must be a string, %s given.', $this->normalizeKey($key), gettype($value)));
		}

		return $value;
	}

	public function integer($key, $default = null): int {
		$value = $this->get($key, $default);

		if (is_int($value)) {
			return $value;
		}

		if (filter_var($value, FILTER_VALIDATE_INT) !== false) {
			return (int) $value;
		}

		throw new InvalidArgumentException(sprintf('Cache value for key [%s] must be an integer, %s given.', $this->normalizeKey($key), gettype($value)));
	}

	public function float($key, $default = null): float {
		$value = $this->get($key, $default);

		if (is_float($value)) {
			return $value;
		}

		if (filter_var($value, FILTER_VALIDATE_FLOAT) !== false) {
			return (float) $value;
		}

		throw new InvalidArgumentException(sprintf('Cache value for key [%s] must be a float, %s given.', $this->normalizeKey($key), gettype($value)));
	}

	public function boolean($key, $default = null): bool {
		$value = $this->get($key, $default);

		if (!is_bool($value)) {
			throw new InvalidArgumentException(sprintf('Cache value for key [%s] must be a boolean, %s given.', $this->normalizeKey($key), gettype($value)));
		}

		return $value;
	}

	public function array($key, $default = null): array {
		$value = $this->get($key, $default);

		if (!is_array($value)) {
			throw new InvalidArgumentException(sprintf('Cache value for key [%s] must be an array, %s given.', $this->normalizeKey($key), gettype($value)));
		}

		return $value;
	}

	/*
	 * Writing.
	 */

	/**
	 * Store an item in the cache. $ttl null = forever. put(['a' => 1, 'b' => 2], $ttl) = putMany().
	 */
	public function put($key, $value = null, $ttl = null): bool {
		if (is_array($key)) {
			return $this->putMany($key, $value);
		}

		if ($ttl === null) {
			return $this->forever($key, $value);
		}

		$seconds = $this->getSeconds($ttl);

		if ($seconds <= 0) {
			return $this->forget($key);
		}

		$key = $this->normalizeKey($key);

		$this->fire(fn() => new WritingKey($this->getName(), $key, $value, $seconds));

		$result = $this->store->put($this->itemKey($key), $value, $seconds);

		$this->fire(fn() => $result
			? new KeyWritten($this->getName(), $key, $value, $seconds)
			: new KeyWriteFailed($this->getName(), $key, $value, $seconds));

		return $result;
	}

	/**
	 * PSR-16 set().
	 */
	public function set($key, $value, $ttl = null): bool {
		return $this->put($key, $value, $ttl);
	}

	/**
	 * Store multiple items in the cache for a given number of seconds.
	 */
	public function putMany(array $values, $ttl = null): bool {
		if ($ttl === null) {
			return $this->putManyForever($values);
		}

		$seconds = $this->getSeconds($ttl);

		if ($seconds <= 0) {
			return $this->deleteMultiple(array_keys($values));
		}

		$this->fire(fn() => new WritingManyKeys($this->getName(), array_map('strval', array_keys($values)), array_values($values), $seconds));

		$items = [];
		foreach ($values as $key => $value) {
			$items[$this->itemKey($key)] = $value;
		}

		$result = $this->store->putMany($items, $seconds);

		foreach ($values as $key => $value) {
			$this->fire(fn() => $result
				? new KeyWritten($this->getName(), $key, $value, $seconds)
				: new KeyWriteFailed($this->getName(), $key, $value, $seconds));
		}

		return $result;
	}

	/**
	 * Store multiple items in the cache indefinitely.
	 */
	protected function putManyForever(array $values): bool {
		$result = true;

		foreach ($values as $key => $value) {
			if (!$this->forever($key, $value)) {
				$result = false;
			}
		}

		return $result;
	}

	/**
	 * PSR-16 setMultiple().
	 */
	public function setMultiple(iterable $values, $ttl = null): bool {
		return $this->putMany(is_array($values) ? $values : iterator_to_array($values), $ttl);
	}

	/**
	 * Store an item in the cache if the key does not exist (atomic on stores with add()).
	 */
	public function add($key, $value, $ttl = null): bool {
		$seconds = null;

		if ($ttl !== null) {
			$seconds = $this->getSeconds($ttl);

			if ($seconds <= 0) {
				return false;
			}

			if (method_exists($this->store, 'add')) {
				return $this->store->add($this->itemKey($this->normalizeKey($key)), $value, $seconds);
			}
		}

		if (is_null($this->get($key))) {
			return $this->put($key, $value, $seconds);
		}

		return false;
	}

	public function increment($key, $value = 1): int|bool {
		return $this->store->increment($this->itemKey($this->normalizeKey($key)), $value);
	}

	public function decrement($key, $value = 1): int|bool {
		return $this->store->decrement($this->itemKey($this->normalizeKey($key)), $value);
	}

	/**
	 * Store an item in the cache indefinitely.
	 */
	public function forever($key, $value): bool {
		$key = $this->normalizeKey($key);

		$this->fire(fn() => new WritingKey($this->getName(), $key, $value));

		$result = $this->store->forever($this->itemKey($key), $value);

		$this->fire(fn() => $result
			? new KeyWritten($this->getName(), $key, $value)
			: new KeyWriteFailed($this->getName(), $key, $value));

		return $result;
	}

	/**
	 * Set a new expiration on an existing item ($ttl null = forever).
	 */
	public function touch($key, $ttl = null): bool {
		$key = $this->normalizeKey($key);

		if ($ttl === null) {
			$value = $this->store->get($this->itemKey($key));
			return $value === null ? false : $this->store->forever($this->itemKey($key), $value);
		}

		$seconds = $this->getSeconds($ttl);

		if ($seconds <= 0) {
			return $this->forget($key);
		}

		if (method_exists($this->store, 'touch')) {
			return $this->store->touch($this->itemKey($key), $seconds);
		}

		$value = $this->store->get($this->itemKey($key));

		return $value === null ? false : $this->store->put($this->itemKey($key), $value, $seconds);
	}

	/*
	 * Remember.
	 */

	/**
	 * Get an item from the cache, or execute the Closure and store the result.
	 * $ttl may be a Closure receiving the computed value.
	 */
	public function remember($key, $ttl, Closure $callback): mixed {
		$value = $this->get($key);

		if (!is_null($value)) {
			return $value;
		}

		$value = $callback();

		$this->put($key, $value, static::value($ttl, $value));

		return $value;
	}

	/**
	 * Get an item from the cache, or execute the Closure and store the result forever.
	 */
	public function sear($key, Closure $callback): mixed {
		return $this->rememberForever($key, $callback);
	}

	public function rememberForever($key, Closure $callback): mixed {
		$value = $this->get($key);

		if (!is_null($value)) {
			return $value;
		}

		$this->forever($key, $value = $callback());

		return $value;
	}

	/**
	 * Stale-while-revalidate (Laravel 11+ Cache::flexible()).
	 *
	 * Cache::flexible('stats', [300, 900], fn() => heavy());
	 * - fresher than 300s : cached value.
	 * - 300s - 900s       : stale value now, refreshed after the response (shutdown hook).
	 * - older / missing   : computed now.
	 *
	 * @param array{0: int|DateInterval|DateTimeInterface, 1: int|DateInterval|DateTimeInterface} $ttl
	 * @param array{seconds?: int, owner?: string}|null $lock
	 */
	public function flexible($key, array $ttl, $callback, ?array $lock = null, bool $alwaysDefer = false): mixed {
		$key        = $this->normalizeKey($key);
		$createdKey = "illuminate:cache:flexible:created:{$key}";

		$values  = $this->many([$key, $createdKey]);
		$value   = $values[$key];
		$created = $values[$createdKey];

		if ($value === null || $created === null) {
			$value = static::value($callback);

			$this->putMany([
				$key        => $value,
				$createdKey => time(),
			], $ttl[1]);

			return $value;
		}

		if (($created + $this->getSeconds($ttl[0])) > time()) {
			return $value;
		}

		$refresh = function () use ($key, $createdKey, $ttl, $callback, $lock, $created) {
			$run = function () use ($key, $createdKey, $ttl, $callback, $created) {
				if ($created !== $this->get($createdKey)) {
					return;
				}

				$this->putMany([
					$key        => static::value($callback),
					$createdKey => time(),
				], $ttl[1]);
			};

			if ($this->store instanceof LockProvider) {
				$this->store
					->lock("illuminate:cache:flexible:lock:{$key}", $lock['seconds'] ?? 0, $lock['owner'] ?? null)
					->get($run);
			}
			else {
				$run();
			}
		};

		$this->defer($refresh, spl_object_id($this->store) . ":illuminate:cache:flexible:{$key}", $alwaysDefer);

		return $value;
	}

	/*
	 * Removing.
	 */

	/**
	 * Remove an item from the cache.
	 */
	public function forget($key): bool {
		$key = $this->normalizeKey($key);

		$this->fire(fn() => new ForgettingKey($this->getName(), $key));

		$result = $this->store->forget($this->itemKey($key));

		$this->fire(fn() => $result
			? new KeyForgotten($this->getName(), $key)
			: new KeyForgetFailed($this->getName(), $key));

		return $result;
	}

	public function delete($key): bool {
		return $this->forget($key);
	}

	public function deleteMultiple(iterable $keys): bool {
		$result = true;

		foreach ($keys as $key) {
			if (!$this->forget($key)) {
				$result = false;
			}
		}

		return $result;
	}

	/**
	 * Remove every item of this store (prefix-scoped on the transient driver).
	 */
	public function clear(): bool {
		$this->fire(fn() => new CacheFlushing($this->getName()));

		$result = $this->store->flush();

		$this->fire(fn() => $result
			? new CacheFlushed($this->getName())
			: new CacheFlushFailed($this->getName()));

		return $result;
	}

	public function flush(): bool {
		return $this->clear();
	}

	/*
	 * Tags.
	 */

	/**
	 * Begin executing a new tags operation.
	 */
	public function tags($names): TaggedCache {
		if (!$this->supportsTags()) {
			throw new BadMethodCallException('This cache store does not support tagging.');
		}

		/** @var TaggedCache $cache */
		$cache = $this->store->tags(is_array($names) ? $names : func_get_args());

		$cache->config = $this->config;

		if ($this->events) {
			$cache->setEventDispatcher($this->events);
		}

		return $cache->setDefaultCacheTime($this->default);
	}

	public function supportsTags(): bool {
		return method_exists($this->store, 'tags');
	}

	/*
	 * Accessors.
	 */

	public function getName(): ?string {
		return $this->config['store'] ?? null;
	}

	public function getDefaultCacheTime(): ?int {
		return $this->default;
	}

	public function setDefaultCacheTime(?int $seconds): static {
		$this->default = $seconds;
		return $this;
	}

	public function getStore(): Store {
		return $this->store;
	}

	public function setStore(Store $store): static {
		$this->store = $store;
		return $this;
	}

	public function getEventDispatcher(): ?Dispatcher {
		return $this->events;
	}

	public function setEventDispatcher(?Dispatcher $events): static {
		$this->events = $events;
		return $this;
	}

	/*
	 * ArrayAccess.
	 */

	public function offsetExists($key): bool {
		return $this->has($key);
	}

	public function offsetGet($key): mixed {
		return $this->get($key);
	}

	public function offsetSet($key, $value): void {
		$this->put($key, $value, $this->default);
	}

	public function offsetUnset($key): void {
		$this->forget($key);
	}

	/*
	 * Internals.
	 */

	/**
	 * Format the key for a cache item (TaggedCache adds the tag namespace).
	 */
	protected function itemKey($key): string {
		return (string) $key;
	}

	/**
	 * BackedEnum => value, UnitEnum => name, anything else => string.
	 */
	protected function normalizeKey($key): string {
		if ($key instanceof BackedEnum) {
			return (string) $key->value;
		}

		if ($key instanceof UnitEnum) {
			return $key->name;
		}

		return (string) $key;
	}

	/**
	 * Calculate the number of seconds for the given TTL.
	 */
	protected function getSeconds($ttl): int {
		$duration = $ttl;

		if ($duration instanceof DateInterval) {
			$duration = (new DateTimeImmutable())->add($duration);
		}

		if ($duration instanceof DateTimeInterface) {
			$duration = $duration->getTimestamp() - time();
		}

		$duration = (int) $duration;

		return $duration > 0 ? $duration : 0;
	}

	/**
	 * Run a callback once per request after the response (WordPress "shutdown" hook).
	 * 'finish_request' => true in the store config sends the response first (PHP-FPM).
	 */
	protected function defer(Closure $callback, string $name, bool $always = false): void {
		if (isset(static::$deferred[$name])) {
			return;
		}

		static::$deferred[$name] = true;

		$finishRequest = !empty($this->config['finish_request']);

		$run = static function () use ($callback, $name, $finishRequest) {
			unset(static::$deferred[$name]);

			if ($finishRequest && function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
			}

			$callback();
		};

		if (function_exists('add_action') && function_exists('did_action') && !did_action('shutdown')) {
			add_action('shutdown', $run, PHP_INT_MAX);
		}
		else {
			register_shutdown_function($run);
		}
	}

	/**
	 * Build and dispatch an event only when a dispatcher is set (no cost otherwise).
	 */
	protected function fire(Closure $factory): void {
		if ($this->events) {
			$this->event($factory());
		}
	}

	/**
	 * Fire an event for this cache instance (TaggedCache adds the tags).
	 */
	protected function event(object $event): void {
		$this->events?->dispatch($event);
	}

	protected static function value($value, ...$args): mixed {
		return $value instanceof Closure ? $value(...$args) : $value;
	}

	/**
	 * Macros first, then the store (lock(), restoreLock(), getPool()...).
	 */
	public function __call($method, $parameters) {
		if (static::hasMacro($method)) {
			return $this->callMacro($method, $parameters);
		}

		if (!method_exists($this->store, $method)) {
			throw new BadMethodCallException(sprintf('Method %s::%s does not exist.', static::class, $method));
		}

		return $this->store->$method(...$parameters);
	}

	public function __clone() {
		$this->store = clone $this->store;
	}

}

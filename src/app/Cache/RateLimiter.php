<?php

namespace WPSPCORELITE\App\Cache;

use BackedEnum;
use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use UnitEnum;
use WPSPCORELITE\App\Cache\Contracts\Repository as CacheContract;

/**
 * Mirror of Illuminate\Cache\RateLimiter (Laravel 12), without Illuminate / Carbon.
 *
 * Counters live in the plugin's own cache (Application binding 'rate.limiter' uses config('cache.limiter')),
 * so every key is already isolated per plugin by the cache prefix:
 *   "{key}"        number of hits in the current window
 *   "{key}:timer"  Unix time when the window ends
 *
 * Atomic between concurrent requests on the transient, object (with a Redis/Memcached drop-in) and file stores,
 * because add() and increment() are atomic there.
 */
class RateLimiter {

	/**
	 * The cache store implementation.
	 */
	protected CacheContract $cache;

	/**
	 * The configured limit object resolvers: name => Closure.
	 *
	 * @var array<string, Closure>
	 */
	protected array $limiters = [];

	public function __construct(CacheContract $cache) {
		$this->cache = $cache;
	}

	/*
	 * Named limiters.
	 */

	/**
	 * Register a named limiter configuration.
	 *
	 * RateLimiter::for('api', fn($request) => Limit::perMinute(60)->by($request->ip()));
	 */
	public function for(BackedEnum|UnitEnum|string $name, Closure $callback): static {
		$this->limiters[$this->resolveLimiterName($name)] = $callback;

		return $this;
	}

	/**
	 * Get the given named rate limiter.
	 */
	public function limiter(BackedEnum|UnitEnum|string $name): ?Closure {
		return $this->limiters[$this->resolveLimiterName($name)] ?? null;
	}

	/**
	 * Names of every registered limiter.
	 */
	public function limiters(): array {
		return array_keys($this->limiters);
	}

	/*
	 * Attempts.
	 */

	/**
	 * Attempt to execute a callback if it's not limited.
	 * Returns false when limited, otherwise the callback's result (true when it returns null).
	 */
	public function attempt($key, $maxAttempts, Closure $callback, $decaySeconds = 60): mixed {
		if ($this->tooManyAttempts($key, $maxAttempts)) {
			return false;
		}

		if (is_null($result = $callback())) {
			$result = true;
		}

		$this->hit($key, $decaySeconds);

		return $result;
	}

	/**
	 * Determine if the given key has been "accessed" too many times.
	 */
	public function tooManyAttempts($key, $maxAttempts): bool {
		if ($this->attempts($key) >= $maxAttempts) {
			if ($this->cache->has($this->cleanRateLimiterKey($key) . ':timer')) {
				return true;
			}

			$this->resetAttempts($key);
		}

		return false;
	}

	/**
	 * Increment (by 1) the counter for a given key for a given decay time.
	 */
	public function hit($key, $decaySeconds = 60): int {
		return $this->increment($key, $decaySeconds);
	}

	/**
	 * Increment the counter for a given key for a given decay time by a given amount.
	 */
	public function increment($key, $decaySeconds = 60, $amount = 1): int {
		$key          = $this->cleanRateLimiterKey($key);
		$decaySeconds = $this->secondsFrom($decaySeconds);

		$this->cache->add($key . ':timer', $this->availableAt($decaySeconds), $decaySeconds);

		$added = $this->cache->add($key, 0, $decaySeconds);

		$hits = (int) $this->cache->increment($key, $amount);

		if (!$added && $hits == 1) {
			$this->cache->put($key, 1, $decaySeconds);
		}

		return $hits;
	}

	/**
	 * Decrement the counter for a given key for a given decay time by a given amount.
	 */
	public function decrement($key, $decaySeconds = 60, $amount = 1): int {
		return $this->increment($key, $decaySeconds, $amount * -1);
	}

	/**
	 * Get the number of attempts for the given key.
	 */
	public function attempts($key): int {
		return (int) $this->cache->get($this->cleanRateLimiterKey($key), 0);
	}

	/**
	 * Reset the number of attempts for the given key.
	 */
	public function resetAttempts($key): bool {
		return $this->cache->forget($this->cleanRateLimiterKey($key));
	}

	/**
	 * Get the number of retries left for the given key.
	 */
	public function remaining($key, $maxAttempts): int {
		return max(0, (int) $maxAttempts - $this->attempts($key));
	}

	/**
	 * Get the number of retries left for the given key (alias of remaining()).
	 */
	public function retriesLeft($key, $maxAttempts): int {
		return $this->remaining($key, $maxAttempts);
	}

	/**
	 * Clear the hits and lockout timer for the given key.
	 */
	public function clear($key): void {
		$key = $this->cleanRateLimiterKey($key);

		$this->resetAttempts($key);

		$this->cache->forget($key . ':timer');
	}

	/**
	 * Get the number of seconds until the "key" is accessible again.
	 */
	public function availableIn($key): int {
		$key = $this->cleanRateLimiterKey($key);

		return max(0, (int) $this->cache->get($key . ':timer') - $this->currentTime());
	}

	/**
	 * Clean the rate limiter key from unicode characters (é => e, ê => e...).
	 */
	public function cleanRateLimiterKey($key): string {
		return (string) preg_replace('/&([a-z])[a-z]+;/i', '$1', htmlentities((string) $key));
	}

	/**
	 * The cache repository holding the counters.
	 */
	public function getCache(): CacheContract {
		return $this->cache;
	}

	/*
	 * Internals.
	 */

	protected function resolveLimiterName(BackedEnum|UnitEnum|string $name): string {
		if ($name instanceof BackedEnum) {
			return (string) $name->value;
		}

		if ($name instanceof UnitEnum) {
			return $name->name;
		}

		return $name;
	}

	/**
	 * int seconds | DateInterval | DateTimeInterface => seconds from now (at least 1).
	 */
	protected function secondsFrom($delay): int {
		if ($delay instanceof DateInterval) {
			$delay = (new DateTimeImmutable())->add($delay);
		}

		if ($delay instanceof DateTimeInterface) {
			$delay = $delay->getTimestamp() - $this->currentTime();
		}

		return max(1, (int) $delay);
	}

	/**
	 * The "available at" UNIX timestamp.
	 */
	protected function availableAt($delay = 0): int {
		return $this->currentTime() + $this->secondsFrom($delay);
	}

	/**
	 * The current system time as a UNIX timestamp.
	 */
	protected function currentTime(): int {
		return time();
	}

}

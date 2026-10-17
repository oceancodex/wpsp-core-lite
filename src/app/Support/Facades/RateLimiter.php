<?php

namespace WPSPCORELITE\App\Support\Facades;

use WPSPCORELITE\App\Cache\Cache as CacheCore;
use WPSPCORELITE\App\Cache\RateLimiter as RateLimiterCore;
use WPSPCORELITE\BaseInstances;

/**
 * Facade cho 'rate.limiter' - mô phỏng Illuminate\Support\Facades\RateLimiter.
 *
 * Gọi static:  RateLimiter::attempt('send-otp:' . $phone, 3, fn() => sendOtp($phone), 600)
 *              RateLimiter::for('api', fn($request) => Limit::perMinute(60)->by($request->ip()))
 * Lấy object:  RateLimiter::getFacadeRoot()  (\WPSPCORELITE\App\Cache\RateLimiter)
 * Thay object: RateLimiter::swap($limiter)
 *
 * Root của facade: $this->funcs->_getApplication('rate.limiter') nếu container đã bind,
 * nếu không thì newRateLimiterInstance() (plugin ghi đè để dùng cache của plugin).
 *
 * @method static \WPSPCORELITE\App\Cache\RateLimiter for(\BackedEnum|\UnitEnum|string $name, \Closure $callback)
 * @method static \Closure|null limiter(\BackedEnum|\UnitEnum|string $name)
 * @method static array limiters()
 * @method static mixed attempt(string $key, int $maxAttempts, \Closure $callback, \DateTimeInterface|\DateInterval|int $decaySeconds = 60)
 * @method static bool tooManyAttempts(string $key, int $maxAttempts)
 * @method static int hit(string $key, \DateTimeInterface|\DateInterval|int $decaySeconds = 60)
 * @method static int increment(string $key, \DateTimeInterface|\DateInterval|int $decaySeconds = 60, int $amount = 1)
 * @method static int decrement(string $key, \DateTimeInterface|\DateInterval|int $decaySeconds = 60, int $amount = 1)
 * @method static int attempts(string $key)
 * @method static bool resetAttempts(string $key)
 * @method static int remaining(string $key, int $maxAttempts)
 * @method static int retriesLeft(string $key, int $maxAttempts)
 * @method static void clear(string $key)
 * @method static int availableIn(string $key)
 * @method static string cleanRateLimiterKey(string $key)
 * @method static \WPSPCORELITE\App\Cache\Contracts\Repository getCache()
 *
 * @see \WPSPCORELITE\App\Cache\RateLimiter
 */
abstract class RateLimiter extends BaseInstances {

	private ?RateLimiterCore $facade = null;

	/*
	 *
	 */

	public function getFacade(): ?RateLimiterCore {
		return $this->facade;
	}

	public function setFacade(?RateLimiterCore $limiter = null) {
		$this->facade = $limiter ?? $this->resolveFromApplication() ?? $this->newRateLimiterInstance();
	}

	/**
	 * The rate limiter used when the container has no 'rate.limiter' binding.
	 * Plugins override this to use their own cache (and therefore their own prefix).
	 */
	protected function newRateLimiterInstance(): RateLimiterCore {
		$cache = new CacheCore();

		return new RateLimiterCore($cache->store($cache->getConfig('limiter') ?: null));
	}

	protected function resolveFromApplication(): ?RateLimiterCore {
		try {
			$limiter = $this->funcs?->_getApplication('rate.limiter');
		}
		catch (\Throwable $e) {
			return null;
		}

		return $limiter instanceof RateLimiterCore ? $limiter : null;
	}

	/*
	 * Facade-level methods (Laravel Facade API).
	 */

	public function _getFacadeRoot(): ?RateLimiterCore {
		return $this->facade;
	}

	public function _swap(RateLimiterCore $limiter): void {
		$this->facade = $limiter;
	}

	/*
	 *
	 */

	public function __call($method, $arguments) {
		return static::__callStatic($method, $arguments);
	}

	public static function __callStatic($method, $arguments) {
		$instance = static::wpspInstance();

		$underlineMethod = '_' . $method;
		if (method_exists($instance, $underlineMethod)) {
			return $instance->$underlineMethod(...$arguments);
		}

		return $instance->getFacade()?->$method(...$arguments);
	}

}

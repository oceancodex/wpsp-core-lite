<?php

namespace WPSPCORELITE\App\Support\Facades;

use WPSPCORELITE\App\Cache\Cache as CacheCore;
use WPSPCORELITE\App\Cache\CacheManager;
use WPSPCORELITE\BaseInstances;

/**
 * Facade cho 'cache' - mô phỏng Illuminate\Support\Facades\Cache.
 *
 * Gọi static:  Cache::get('key'), Cache::remember('key', 300, fn() => ...), Cache::tags('posts')->flush()
 * Chọn store:  Cache::store('transient'), Cache::store('phpfastcache'), Cache::memo()
 * Lấy object:  Cache::getFacadeRoot()   (\WPSPCORELITE\App\Cache\Cache)
 * Thay object: Cache::swap($cache)       (ví dụ trong test: Cache::swap(\WPSPCORELITE\App\Cache\Cache::make(['default' => 'array'])))
 *
 * Root của facade: $this->funcs->_getApplication('cache') nếu container đã bind một CacheManager,
 * nếu không thì newCacheInstance() (plugin ghi đè để đọc config/cache.php của plugin).
 *
 * @method static \WPSPCORELITE\App\Cache\Repository store(?string $name = null)
 * @method static \WPSPCORELITE\App\Cache\Repository driver(?string $driver = null)
 * @method static \WPSPCORELITE\App\Cache\Repository memo(?string $driver = null)
 * @method static \WPSPCORELITE\App\Cache\Repository build(array $config)
 * @method static \WPSPCORELITE\App\Cache\Repository repository(\WPSPCORELITE\App\Cache\Contracts\Store $store, array $config = [])
 * @method static \WPSPCORELITE\App\Cache\CacheManager extend(string $driver, \Closure $callback)
 * @method static \WPSPCORELITE\App\Cache\CacheManager forgetDriver(array|string|null $name = null)
 * @method static void purge(?string $name = null)
 * @method static string getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static mixed getConfig(?string $key = null, mixed $default = null)
 * @method static \WPSPCORELITE\App\Cache\CacheManager setConfig(array $config)
 * @method static \WPSPCORELITE\App\Cache\Events\Dispatcher|null getEventDispatcher()
 * @method static \WPSPCORELITE\App\Cache\CacheManager setEventDispatcher(\WPSPCORELITE\App\Cache\Events\Dispatcher|null $dispatcher)
 * @method static \WPSPCORELITE\App\Cache\CacheManager refreshEventDispatcher()
 * @method static bool has(\UnitEnum|string $key)
 * @method static bool missing(\UnitEnum|string $key)
 * @method static mixed get(array|\UnitEnum|string $key, mixed $default = null)
 * @method static array many(array $keys)
 * @method static iterable getMultiple(iterable $keys, mixed $default = null)
 * @method static mixed pull(\UnitEnum|string $key, mixed $default = null)
 * @method static string string(\UnitEnum|string $key, mixed $default = null)
 * @method static int integer(\UnitEnum|string $key, mixed $default = null)
 * @method static float float(\UnitEnum|string $key, mixed $default = null)
 * @method static bool boolean(\UnitEnum|string $key, mixed $default = null)
 * @method static array array(\UnitEnum|string $key, mixed $default = null)
 * @method static bool put(array|\UnitEnum|string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static bool set(\UnitEnum|string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static bool putMany(array $values, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static bool setMultiple(iterable $values, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static bool add(\UnitEnum|string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static int|bool increment(\UnitEnum|string $key, mixed $value = 1)
 * @method static int|bool decrement(\UnitEnum|string $key, mixed $value = 1)
 * @method static bool forever(\UnitEnum|string $key, mixed $value)
 * @method static bool touch(\UnitEnum|string $key, \DateTimeInterface|\DateInterval|int|null $ttl = null)
 * @method static mixed remember(\UnitEnum|string $key, \Closure|\DateTimeInterface|\DateInterval|int|null $ttl, \Closure $callback)
 * @method static mixed sear(\UnitEnum|string $key, \Closure $callback)
 * @method static mixed rememberForever(\UnitEnum|string $key, \Closure $callback)
 * @method static mixed flexible(\UnitEnum|string $key, array $ttl, callable $callback, array|null $lock = null, bool $alwaysDefer = false)
 * @method static bool forget(\UnitEnum|string $key)
 * @method static bool delete(\UnitEnum|string $key)
 * @method static bool deleteMultiple(iterable $keys)
 * @method static bool clear()
 * @method static bool flush()
 * @method static \WPSPCORELITE\App\Cache\TaggedCache tags(array|mixed $names)
 * @method static bool supportsTags()
 * @method static string|null getName()
 * @method static int|null getDefaultCacheTime()
 * @method static \WPSPCORELITE\App\Cache\Repository setDefaultCacheTime(int|null $seconds)
 * @method static \WPSPCORELITE\App\Cache\Contracts\Store getStore()
 * @method static \WPSPCORELITE\App\Cache\Repository setStore(\WPSPCORELITE\App\Cache\Contracts\Store $store)
 * @method static \WPSPCORELITE\App\Cache\Contracts\Lock lock(string $name, int $seconds = 0, string|null $owner = null)
 * @method static \WPSPCORELITE\App\Cache\Contracts\Lock restoreLock(string $name, string $owner)
 * @method static void macro(string $name, object|callable $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \WPSPCORELITE\App\Cache\CacheManager
 * @see \WPSPCORELITE\App\Cache\Repository
 */
abstract class Cache extends BaseInstances {

	private ?CacheManager $facade = null;

	/*
	 *
	 */

	public function getFacade(): ?CacheManager {
		return $this->facade;
	}

	public function setFacade(?CacheManager $cache = null) {
		$this->facade = $cache ?? $this->resolveFromApplication() ?? $this->newCacheInstance();
	}

	/**
	 * The cache object used when the container has no 'cache' binding.
	 * Plugins override this to build their own Cache class (reads the plugin's config/cache.php).
	 */
	protected function newCacheInstance(): CacheManager {
		return new CacheCore();
	}

	protected function resolveFromApplication(): ?CacheManager {
		try {
			$cache = $this->funcs?->_getApplication('cache');
		}
		catch (\Throwable $e) {
			return null;
		}

		return $cache instanceof CacheManager ? $cache : null;
	}

	/*
	 * Facade-level methods (Laravel Facade API).
	 */

	public function _getFacadeRoot(): ?CacheManager {
		return $this->facade;
	}

	public function _swap(CacheManager $cache): void {
		$this->facade = $cache;
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

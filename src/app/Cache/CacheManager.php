<?php

namespace WPSPCORELITE\App\Cache;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use WPSPCORELITE\App\Cache\Contracts\Factory;
use WPSPCORELITE\App\Cache\Contracts\Store;
use WPSPCORELITE\App\Cache\Events\Dispatcher;
use WPSPCORELITE\App\Cache\Stores\ArrayStore;
use WPSPCORELITE\App\Cache\Stores\FailoverStore;
use WPSPCORELITE\App\Cache\Stores\FileStore;
use WPSPCORELITE\App\Cache\Stores\MemoizedStore;
use WPSPCORELITE\App\Cache\Stores\NullStore;
use WPSPCORELITE\App\Cache\Stores\ObjectCacheStore;
use WPSPCORELITE\App\Cache\Stores\PhpFastCacheStore;
use WPSPCORELITE\App\Cache\Stores\TransientStore;

/**
 * Mirror of Illuminate\Cache\CacheManager.
 *
 * Config has the same shape as Laravel's config/cache.php (see wpsp-lite/config/cache.php):
 * 'default', 'stores' (each with a 'driver'), 'prefix', plus 'events'.
 *
 * Drivers: transient, object, file, phpfastcache, array, null, failover (+ extend()).
 *
 * Isolation between plugins (no coordination needed between plugin authors):
 * - keys are "{prefix}.{key}" (see CachePrefix), so prefixes can't overlap;
 * - folders are always "{path}/{prefix}/...", whatever 'path' the plugin configures;
 * - flush() never wipes a shared backend: Redis / Memcached / Apcu pools are flushed by
 *   switching this plugin's key namespace (see PhpFastCacheStore).
 * Every derived name (lock rows/groups, event hook, folders) comes from the prefix, default "wpsp_lite_".
 *
 * @mixin Repository
 */
class CacheManager implements Factory {

	public const DEFAULT_PREFIX = CachePrefix::DEFAULT_PREFIX;

	/**
	 * phpfastcache drivers that store data under a 'path' (isolated per prefix, flushed with clear()).
	 */
	protected const PATH_DRIVERS = ['files', 'sqlite', 'leveldb'];

	protected array $config;

	/** @var array<string, Repository> */
	protected array $stores = [];

	/** @var array<string, Repository> */
	protected array $memoStores = [];

	/** @var array<string, Closure> */
	protected array $customCreators = [];

	protected ?Dispatcher $dispatcher         = null;
	protected bool        $dispatcherResolved = false;

	public function __construct(array $config = []) {
		$this->config = $this->mergeConfig($this->defaultConfig(), $config);
	}

	/*
	 * Stores.
	 */

	/**
	 * Get a cache store instance by name.
	 */
	public function store(?string $name = null): Repository {
		$name = $name ?: $this->getDefaultDriver();

		return $this->stores[$name] ??= $this->resolve($name);
	}

	/**
	 * Get a cache driver instance (alias of store()).
	 */
	public function driver(?string $driver = null): Repository {
		return $this->store($driver);
	}

	/**
	 * Get a memoized cache driver instance: values read during this request stay in memory.
	 */
	public function memo(?string $driver = null): Repository {
		$driver = $driver ?: $this->getDefaultDriver();

		return $this->memoStores[$driver] ??= $this->repository(
			new MemoizedStore($driver, $this->store($driver)),
			['store' => $driver, 'events' => false]
		);
	}

	/**
	 * Build a cache repository with the given configuration: Cache::build(['driver' => 'array']).
	 */
	public function build(array $config): Repository {
		$config['name'] ??= 'ondemand';

		return $this->resolveFromConfig($config['name'], $config);
	}

	/**
	 * Create a new cache repository with the given implementation.
	 */
	public function repository(Store $store, array $config = []): Repository {
		$repository = new Repository($store, $config);

		if (($config['events'] ?? true) && ($dispatcher = $this->getEventDispatcher())) {
			$repository->setEventDispatcher($dispatcher);
		}

		return $repository;
	}

	/**
	 * Register a custom driver creator Closure: fn(array $config) => Repository ($this = manager).
	 */
	public function extend(string $driver, Closure $callback): static {
		$this->customCreators[$driver] = $callback->bindTo($this, $this);

		return $this;
	}

	/**
	 * Unset the given driver instances.
	 */
	public function forgetDriver(array|string|null $name = null): static {
		$name ??= $this->getDefaultDriver();

		foreach ((array) $name as $cacheName) {
			unset($this->stores[$cacheName], $this->memoStores[$cacheName]);
		}

		return $this;
	}

	/**
	 * Disconnect the given driver and remove it from the local cache.
	 */
	public function purge(?string $name = null): void {
		$name ??= $this->getDefaultDriver();

		unset($this->stores[$name], $this->memoStores[$name]);
	}

	/*
	 * Config.
	 */

	/**
	 * Name of the default store ('default' in config/cache.php).
	 */
	public function getDefaultDriver(): string {
		return (string) ($this->config['default'] ?? 'transient');
	}

	public function setDefaultDriver(string $name): void {
		$this->config['default'] = $name;
	}

	public function getConfig(?string $key = null, mixed $default = null): mixed {
		if ($key === null) {
			return $this->config;
		}

		$value = $this->config;

		foreach (explode('.', $key) as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				return $default;
			}

			$value = $value[$segment];
		}

		return $value;
	}

	/**
	 * Merge new config and drop resolved stores so they are rebuilt with it.
	 */
	public function setConfig(array $config): static {
		$this->config             = $this->mergeConfig($this->config, $config);
		$this->stores             = [];
		$this->memoStores         = [];
		$this->dispatcher         = null;
		$this->dispatcherResolved = false;

		return $this;
	}

	public function getPrefix(?array $config = null): string {
		return (string) ($config['prefix'] ?? $this->config['prefix'] ?? static::DEFAULT_PREFIX);
	}

	public static function phpFastCacheInstalled(): bool {
		return class_exists(\Phpfastcache\CacheManager::class);
	}

	/*
	 * Events.
	 */

	/**
	 * 'events' => false | true (hook "{base}_cache", e.g. "wpsp_lite_cache") | 'hook_name' | Dispatcher instance.
	 */
	public function getEventDispatcher(): ?Dispatcher {
		if (!$this->dispatcherResolved) {
			$this->dispatcherResolved = true;

			$events = $this->config['events'] ?? false;

			if ($events instanceof Dispatcher) {
				$this->dispatcher = $events;
			}
			elseif (is_string($events) && $events !== '') {
				$this->dispatcher = new Dispatcher($events);
			}
			elseif ($events === true) {
				$this->dispatcher = new Dispatcher(CachePrefix::base($this->getPrefix()) . '_cache');
			}
		}

		return $this->dispatcher;
	}

	public function setEventDispatcher(?Dispatcher $dispatcher): static {
		$this->dispatcher         = $dispatcher;
		$this->dispatcherResolved = true;

		return $this->refreshEventDispatcher();
	}

	/**
	 * Re-set the event dispatcher on all resolved cache repositories.
	 */
	public function refreshEventDispatcher(): static {
		foreach ($this->stores as $repository) {
			$repository->setEventDispatcher($this->getEventDispatcher());
		}

		return $this;
	}

	/*
	 * Driver creators.
	 */

	/**
	 * WordPress Transients API (wp_options or the object cache drop-in).
	 */
	protected function createTransientDriver(array $config): Repository {
		$store = new TransientStore($this->getPrefix($config), [
			'network'           => $config['network'] ?? false,
			'lock_lottery'      => $config['lock_lottery'] ?? [2, 100],
			'lock_timeout'      => $config['lock_timeout'] ?? 86400,
			'atomic_increments' => $config['atomic_increments'] ?? true,
			'lock_group'        => $config['lock_group'] ?? null,
		]);

		return $this->repository($store, $config);
	}

	/**
	 * WordPress object cache API (wp_cache_*), for Redis / Memcached drop-ins.
	 */
	protected function createObjectDriver(array $config): Repository {
		$prefix = $this->getPrefix($config);

		return $this->repository(new ObjectCacheStore($prefix, $config['group'] ?? null, $config['lock_group'] ?? null), $config);
	}

	/**
	 * Pure PHP file cache (Laravel "file" driver).
	 * Folder: "{path}/{prefix}/data" ('path' defaults to wp-content/cache).
	 */
	protected function createFileDriver(array $config): Repository {
		$store = new FileStore(
			$this->isolatedPath($config['path'] ?? null, 'data', $config),
			$config['permission'] ?? null,
			$this->getPrefix($config)
		);

		if (!empty($config['lock_path'])) {
			$store->setLockDirectory($this->isolatedPath($config['lock_path'], 'locks', $config));
		}

		return $this->repository($store, $config);
	}

	/**
	 * phpfastcache (Files, Sqlite, Redis, Predis, Memcached, Apcu...). Requires phpfastcache/phpfastcache.
	 */
	protected function createPhpfastcacheDriver(array $config): Repository {
		if (!static::phpFastCacheInstalled()) {
			throw new RuntimeException('Cache store [' . ($config['store'] ?? 'phpfastcache') . '] requires "composer require phpfastcache/phpfastcache".');
		}

		$driver     = (string) ($config['phpfastcache_driver'] ?? 'Files');
		$pathDriver = in_array(strtolower($driver), static::PATH_DRIVERS, true);
		$pool       = $config['pool'] ?? null;

		if ($pool instanceof Closure) {
			$pool = $pool($config);
		}

		if (!$pool) {
			$options = $config['options'] ?? [];

			if (is_array($options) && $pathDriver) {
				// Always "{path}/{prefix}/{driver}": the folder belongs to this plugin only.
				$options['path'] = $this->isolatedPath($options['path'] ?? null, strtolower($driver), $config);

				// Same folder for web requests and WP-CLI / cron (phpfastcache's "auto" key differs between them).
				$options['securityKey'] ??= CachePrefix::base($this->getPrefix($config));
			}

			$pool = \Phpfastcache\CacheManager::getInstance(
				$driver,
				$this->phpFastCacheConfig($driver, $options),
				$config['instance_id'] ?? null
			);
		}
		else {
			// A pool given by the plugin may be shared: never clear() it.
			$pathDriver = false;
		}

		$store = new PhpFastCacheStore(
			$pool,
			$this->getPrefix($config),
			$pathDriver ? PhpFastCacheStore::FLUSH_CLEAR : PhpFastCacheStore::FLUSH_NAMESPACE,
			(bool) ($config['purge_on_flush'] ?? true)
		);

		return $this->repository($store, $config);
	}

	protected function createArrayDriver(array $config): Repository {
		return $this->repository(new ArrayStore((bool) ($config['serialize'] ?? false)), $config);
	}

	protected function createNullDriver(array $config): Repository {
		return $this->repository(new NullStore(), $config);
	}

	/**
	 * 'failover' => ['driver' => 'failover', 'stores' => ['phpfastcache', 'transient']]
	 */
	protected function createFailoverDriver(array $config): Repository {
		$stores = $config['stores'] ?? [];

		if (empty($stores)) {
			throw new InvalidArgumentException('The failover cache store requires a [stores] list.');
		}

		return $this->repository(new FailoverStore($this, $this->getEventDispatcher(), $stores), $config);
	}

	/*
	 * Internals.
	 */

	protected function defaultConfig(): array {
		return [
			'default' => 'transient',
			'prefix'  => static::DEFAULT_PREFIX,
			'events'  => false,
			'stores'  => [
				'transient' => ['driver' => 'transient'],
				'object'    => ['driver' => 'object'],
				'file'      => ['driver' => 'file'],
				'array'     => ['driver' => 'array', 'serialize' => false],
				'null'      => ['driver' => 'null'],
			],
		];
	}

	protected function resolve(string $name): Repository {
		$config = $this->config['stores'][$name] ?? null;

		if ($config === null) {
			throw new InvalidArgumentException("Cache store [{$name}] is not defined.");
		}

		return $this->resolveFromConfig($name, $config);
	}

	protected function resolveFromConfig(string $name, array $config): Repository {
		$config['store'] = $name;

		if (empty($config['driver'])) {
			throw new InvalidArgumentException("Cache store [{$name}] does not have a configured driver.");
		}

		$driver = $config['driver'];

		if (isset($this->customCreators[$driver])) {
			return $this->customCreators[$driver]($config);
		}

		$method = 'create' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $driver))) . 'Driver';

		if (!method_exists($this, $method)) {
			throw new InvalidArgumentException("Driver [{$driver}] is not supported.");
		}

		return $this->{$method}($config);
	}

	/**
	 * Build the phpfastcache config: Phpfastcache\Drivers\{Driver}\Config when it exists,
	 * so driver options (path, host, port, database, password...) are accepted.
	 */
	protected function phpFastCacheConfig(string $driver, array|object $options): object {
		if (is_object($options)) {
			return $options;
		}

		$class = '\\Phpfastcache\\Drivers\\' . ucfirst($driver) . '\\Config';

		if (!class_exists($class)) {
			$class = \Phpfastcache\Config\ConfigurationOption::class;
		}

		return new $class($options);
	}

	/**
	 * "{root}/{prefix base}/{sub}", e.g. wp-content/cache/wpsp_lite/data.
	 * The prefix folder is always added, so two plugins configured with the same 'path' never share files.
	 */
	protected function isolatedPath(?string $root, string $sub, array $config = []): string {
		$root = $root !== null && $root !== '' ? rtrim($root, '/\\') : $this->defaultCacheRoot();

		return $root . '/' . CachePrefix::base($this->getPrefix($config)) . '/' . $sub;
	}

	protected function defaultCacheRoot(): string {
		return defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/cache' : sys_get_temp_dir();
	}

	/**
	 * Recursive merge where list values (e.g. failover 'stores') are replaced, not merged.
	 */
	protected function mergeConfig(array $base, array $override): array {
		foreach ($override as $key => $value) {
			if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
				$base[$key] = $this->mergeConfig($base[$key], $value);
			}
			else {
				$base[$key] = $value;
			}
		}

		return $base;
	}

	/**
	 * Dynamically call the default driver instance: $manager->get('key').
	 */
	public function __call($method, $parameters) {
		return $this->store()->$method(...$parameters);
	}

}

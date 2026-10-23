<?php
/**
 * Created by PhpStorm.
 * User: Khanh
 * Date: 05/10/2026
 * Time: 8:56 CH
 */

namespace WPSPCORELITE\App;

use WPSPCORELITE\App\Auth\Access\Gate;
use WPSPCORELITE\App\Cache\Cache as CacheCore;
use WPSPCORELITE\App\Cache\CacheManager;
use WPSPCORELITE\App\Cache\Contracts\Factory as CacheFactory;
use WPSPCORELITE\App\Cache\Contracts\Repository as CacheRepositoryContract;
use WPSPCORELITE\App\Cache\RateLimiter;
use WPSPCORELITE\App\Cache\Repository as CacheRepository;
use WPSPCORELITE\App\Config\LoadConfiguration;
use WPSPCORELITE\App\Config\Repository as ConfigRepository;
use WPSPCORELITE\App\Filesystem\Filesystem;
use WPSPCORELITE\App\Http\Request as WPSPCORELITE_Request;
use WPSPCORELITE\App\Support\Facades\Facade;

/**
 * Application - mô phỏng Illuminate\Foundation\Application bằng PHP thuần.
 *
 * - Container đầy đủ (kế thừa Container).
 * - Bindings sẵn có: 'app', 'request', 'files', 'config', 'cache', 'cache.store', 'rate.limiter', 'gate', 'commands', 'path.*'.
 * - 'gate' = Gate (Policy / define / capability WordPress) riêng của Application này, dùng cho can: của route
 *   và facade Gate (xem createGate()).
 * - 'cache' = CacheManager riêng của Application này (mỗi plugin một Application => một cache riêng),
 *   config từ config('cache'), prefix mặc định = tên thư mục plugin (xem createCacheManager()).
 * - 'config' nạp lười (lần make đầu tiên) toàn bộ file trong configPath() (mặc định <basePath>/config).
 * - Paths, environment, locale, service providers (kể cả deferred), boot/terminate.
 * - Cấu hình fluent: Application::configure($basePath)->withProviders([...])->withSingletons([...])->create().
 *
 * Service provider: nên extends WPSPCORELITE\App\Support\ServiceProvider (hoặc bất kỳ class
 * nào có register() và/hoặc boot()); thuộc tính public $bindings / $singletons;
 * deferred khi implements DeferrableProvider (có provides() và isDeferred() === true).
 *
 * Thứ tự nạp provider (giống Laravel 11+), chạy một lần trong create() hoặc boot():
 *   1. config('app.providers') nếu có binding 'config', ngược lại DefaultProviders của core.
 *   2. bootstrap/providers.php của plugin (return [Provider::class, ...]).
 *   3. Provider truyền vào withProviders([...]).
 */
class Application extends Container {

	protected $basePath;

	/** name => đường dẫn tuyệt đối do useXPath() đặt; mặc định tính theo basePath. */
	protected $paths = [];

	protected $defaultPaths = [
		'app'       => 'app',
		'bootstrap' => 'bootstrap',
		'config'    => 'config',
		'database'  => 'database',
		'lang'      => 'lang',
		'public'    => 'public',
		'resources' => 'resources',
		'storage'   => 'storage',
	];

	protected $environment;
	protected $locale;

	protected $booted               = false;
	protected $bootingCallbacks     = [];
	protected $bootedCallbacks      = [];
	protected $terminatingCallbacks = [];

	/** class => provider */
	protected $serviceProviders = [];

	/** class => true */
	protected $loadedProviders = [];

	/** service => provider (object|class) */
	protected $deferredServices = [];

	/** Provider chờ registerConfiguredProviders() (từ withProviders()). */
	protected $pendingProviders = [];

	/** Có nạp bootstrap/providers.php hay không. */
	protected $withBootstrapProviders = false;

	/** registerConfiguredProviders() đã chạy chưa. */
	protected $providersRegistered = false;

	public function __construct($basePath = null) {
		if ($basePath) {
			$this->setBasePath($basePath);
		}

		$this->registerBaseBindings();
		$this->registerCoreContainerAliases();
	}

	/*
	 * ---
	 * Configure (giống bootstrap/app.php của Laravel 11+).
	 * ---
	 */

	/**
	 * Giống Laravel: configure() tự gọi withProviders() => bootstrap/providers.php được nạp mặc định.
	 */
	public static function configure($basePath = null) {
		return (new static($basePath ?? getcwd()))->withProviders();
	}

	/**
	 * Thêm provider. Provider được register trong create()/boot() theo đúng thứ tự
	 * (default → bootstrap/providers.php → danh sách này). Nếu gọi sau khi đã
	 * register xong thì register ngay.
	 */
	public function withProviders(array $providers = [], $withBootstrapProviders = true) {
		$this->withBootstrapProviders = $this->withBootstrapProviders || $withBootstrapProviders;

		if ($this->providersRegistered) {
			foreach ($providers as $provider) {
				$this->register($provider);
			}
			return $this;
		}

		$this->pendingProviders = array_merge($this->pendingProviders, $providers);
		return $this;
	}

	public function withBindings(array $bindings) {
		foreach ($bindings as $abstract => $concrete) {
			is_int($abstract) ? $this->bind($concrete) : $this->bind($abstract, $concrete);
		}
		return $this;
	}

	public function withSingletons(array $singletons) {
		foreach ($singletons as $abstract => $concrete) {
			is_int($abstract) ? $this->singleton($concrete) : $this->singleton($abstract, $concrete);
		}
		return $this;
	}

	public function withInstances(array $instances) {
		foreach ($instances as $abstract => $instance) {
			$this->instance($abstract, $instance);
		}
		return $this;
	}

	/**
	 * Đăng ký command: thư mục, tên class hoặc object command.
	 * Gọi không tham số => nạp app/Console/Commands (giống Laravel).
	 */
	public function withCommands(array $commands = []) {
		$commands = $commands ?: [$this->path('Console/Commands')];

		if ($this->resolved('commands')) {
			$this->make('commands')->register($commands);
		}
		else {
			$this->afterResolving('commands', function(Commands $kernel) use ($commands) {
				$kernel->register($commands);
			});
		}
		return $this;
	}

	public function create() {
		$this->registerConfiguredProviders();
		return $this;
	}

	/*
	 * ---
	 * Base bindings.
	 * ---
	 */

	protected function registerBaseBindings() {
		static::setInstance($this);
		Facade::setFacadeApplication($this);

		$this->instance('app', $this);

		$this->singleton('request', function() {
			return WPSPCORELITE_Request::capture();
		});

		// Giống Illuminate\Filesystem\FilesystemServiceProvider::registerNativeFilesystem().
		$this->singleton('files', function() {
			return new Filesystem();
		});

		// Giống Illuminate\Foundation\Bootstrap\LoadConfiguration, nhưng nạp lười:
		// useConfigPath() vẫn có hiệu lực nếu gọi trước lần make('config') đầu tiên.
		$this->singleton('config', function($app) {
			return (new LoadConfiguration())->load($app);
		});

		// Giống Illuminate\Cache\CacheServiceProvider: 'cache' (manager) + 'cache.store' (store mặc định).
		$this->singleton('cache', function($app) {
			return $app->createCacheManager();
		});

		$this->singleton('cache.store', function($app) {
			return $app->make('cache')->driver();
		});

		// Giống Laravel: RateLimiter dùng store config('cache.limiter') của cache plugin này.
		$this->singleton('rate.limiter', function($app) {
			return $app->createRateLimiter();
		});

		// Giống Illuminate\Auth\AuthServiceProvider::registerAccessGate(): Gate riêng của plugin này.
		// RouteTrait (can:, ->can(), controller middleware) và facade Gate đều lấy cùng object này.
		$this->singleton('gate', function($app) {
			return $app->createGate();
		});

		$this->singleton('commands', function($app) {
			return new Commands($app);
		});

		$this->registerRequestSubclassResolving();
	}

	/**
	 * Type-hint class con của Request (vd: Facades\Request, FormRequest) => container
	 * sẽ build object rỗng. Callback này đổ dữ liệu của request hiện tại vào object đó
	 * (query, post, server, headers, files, session, route/user resolver...), giống
	 * cách Laravel xử lý FormRequest.
	 */
	protected function registerRequestSubclassResolving() {
		$this->resolving(function($object, $app) {
			$isRequest = $object instanceof WPSPCORELITE_Request;

			if (!$isRequest) {
				return;
			}

			$current = $app->make('request');

			if ($object !== $current) {
				get_class($object)::createFrom($current, $object);
			}
		});
	}

	protected function registerCoreContainerAliases() {
		$aliases = [
			'app'         => array_unique([self::class, static::class, Container::class]),
			'request'     => [WPSPCORELITE_Request::class],
			'files'       => [Filesystem::class],
			'config'      => [ConfigRepository::class],
			'cache'       => [CacheManager::class, CacheCore::class, CacheFactory::class],
			'cache.store'  => [CacheRepository::class, CacheRepositoryContract::class],
			'rate.limiter' => [RateLimiter::class],
			'gate'         => [Gate::class],
			'commands'    => [Commands::class],
		];

		foreach ($aliases as $key => $list) {
			foreach ($list as $alias) {
				$this->alias($key, $alias);
			}
		}
	}

	/*
	 * ---
	 * Cache.
	 * ---
	 */

	/**
	 * Tạo CacheManager cho Application này.
	 *
	 * - Config: config('cache') (file <configPath>/cache.php của plugin).
	 * - 'class' trong config (tuỳ chọn): class Cache của plugin, vd \WPSPLITE\App\Widen\Cache\Cache::class;
	 *   phải kế thừa \WPSPCORELITE\App\Cache\Cache.
	 * - Không có 'prefix' => lấy theo tên thư mục plugin (basePath): plugins/plugin-cua-toi => "plugin_cua_toi_".
	 *   Hai plugin trên cùng site không thể trùng thư mục => không bao giờ trùng key, lock, thư mục cache,
	 *   kể cả khi cùng được tạo từ template wpsp-lite với cùng config/cache.php.
	 *
	 * Plugin muốn tự tạo cache: $app->singleton('cache', fn($app) => ...) trong provider của plugin.
	 */
	protected function createCacheManager() {
		$config = $this->configValue('cache', []);
		$config = is_array($config) ? $config : [];

		$class = $config['class'] ?? CacheCore::class;
		unset($config['class']);

		if (!is_string($class) || !is_a($class, CacheCore::class, true)) {
			$class = CacheCore::class;
		}

		return new $class($this->basePath(), null, null, ['config' => $config]);
	}

	/**
	 * Tạo RateLimiter cho Application này.
	 *
	 * - Store: config('cache.limiter') (null = store mặc định của cache plugin). Bộ đếm mang prefix cache
	 *   của plugin => hai plugin dùng cùng key ('login:...') vẫn đếm riêng.
	 * - Class: config('cache.limiter_class') (tuỳ chọn), phải kế thừa \WPSPCORELITE\App\Cache\RateLimiter.
	 * - Named limiters (RateLimiter::for()) thuộc về instance này => cũng riêng từng plugin.
	 */
	protected function createRateLimiter() {
		$store = $this->configValue('cache.limiter');
		$class = $this->configValue('cache.limiter_class') ?? RateLimiter::class;

		if (!is_string($class) || !is_a($class, RateLimiter::class, true)) {
			$class = RateLimiter::class;
		}

		return new $class($this->make('cache')->store(is_string($store) && $store !== '' ? $store : null));
	}

	/*
	 * ---
	 * Gate.
	 * ---
	 */

	/**
	 * Tạo Gate cho Application này.
	 *
	 * - User: wp_get_current_user() (khách = null).
	 * - hook_prefix: tên ngắn của plugin (Funcs::_getAppShortName()), không có thì theo tên thư mục plugin
	 *   => filter "{prefix}_gate_result" riêng từng plugin.
	 * - Tự đoán Policy trong {RootNamespace}\App\Policies trước (vd: WPSPLITE\App\Policies\PostPolicy).
	 * - Policy được khởi tạo qua container => constructor của Policy có DI.
	 * - Config (tuỳ chọn, file <configPath>/auth.php):
	 *     'policies'            => [\WP_Post::class => \WPSPLITE\App\Policies\PostPolicy::class],
	 *     'capability_fallback' => true,   // không có Policy / define → user_can()
	 *     'policy_namespaces'   => [...],  // thêm namespace để đoán Policy
	 *     'gate_class'          => \WPSPLITE\App\Widen\Auth\Gate::class, // phải kế thừa \WPSPCORELITE\App\Auth\Access\Gate
	 *
	 * Plugin muốn tự tạo Gate: $app->singleton('gate', fn($app) => ...) trong provider của plugin.
	 */
	protected function createGate() {
		$class = $this->configValue('auth.gate_class') ?? Gate::class;

		if (!is_string($class) || !is_a($class, Gate::class, true)) {
			$class = Gate::class;
		}

		$funcs         = $this->bound('funcs') ? $this->make('funcs') : null;
		$rootNamespace = $this->funcsValue($funcs, '_getRootNamespace');
		$namespaces    = (array)$this->configValue('auth.policy_namespaces', []);

		if ($rootNamespace) {
			array_unshift($namespaces, trim($rootNamespace, '\\') . '\\App\\Policies');
		}

		$policies = $this->configValue('auth.policies', []);

		return new $class(null, [
			'hook_prefix'         => $this->funcsValue($funcs, '_getAppShortName') ?: $this->gateHookPrefix(),
			'capability_fallback' => filter_var($this->configValue('auth.capability_fallback', true), FILTER_VALIDATE_BOOLEAN),
			'policy_namespaces'   => $namespaces,
			'policy_resolver'     => function($policy) {
				return $this->make($policy);
			},
			'policies'            => is_array($policies) ? $policies : [],
		]);
	}

	/**
	 * Gọi 1 method của Funcs, không ném lỗi (Funcs có thể chưa sẵn sàng khi chạy console).
	 */
	protected function funcsValue($funcs, $method) {
		if (!is_object($funcs) || !method_exists($funcs, $method)) {
			return null;
		}

		try {
			$value = $funcs->{$method}();
		}
		catch (\Throwable $e) {
			return null;
		}

		return is_string($value) && $value !== '' ? $value : null;
	}

	/**
	 * Prefix hook dự phòng: tên thư mục plugin (plugins/plugin-cua-toi => "plugin_cua_toi").
	 */
	protected function gateHookPrefix() {
		$name = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', basename((string)$this->basePath())));

		return trim($name, '_') ?: null;
	}

	/*
	 * ---
	 * Paths.
	 * ---
	 */

	public function setBasePath($basePath) {
		$this->basePath = rtrim($basePath, '\/');
		$this->bindPathsInContainer();
		return $this;
	}

	protected function bindPathsInContainer() {
		$this->instance('path', $this->path());
		$this->instance('path.base', $this->basePath());

		foreach (array_keys($this->defaultPaths) as $name) {
			if ($name !== 'app') {
				$this->instance('path.' . $name, $this->namedPath($name));
			}
		}
	}

	protected function namedPath($name, $path = '') {
		$base = $this->paths[$name] ?? $this->basePath($this->defaultPaths[$name]);
		return $this->joinPaths($base, $path);
	}

	protected function useNamedPath($name, $path) {
		$this->paths[$name] = rtrim($path, '\/');
		$this->instance($name === 'app' ? 'path' : 'path.' . $name, $this->paths[$name]);
		return $this;
	}

	public function joinPaths($basePath, $path = '') {
		return $path !== '' && $path !== null ? $basePath . DIRECTORY_SEPARATOR . ltrim($path, '\/') : $basePath;
	}

	public function basePath($path = '') {
		return $this->joinPaths((string)$this->basePath, $path);
	}

	public function path($path = '') {
		return $this->namedPath('app', $path);
	}

	public function bootstrapPath($path = '') {
		return $this->namedPath('bootstrap', $path);
	}

	public function configPath($path = '') {
		return $this->namedPath('config', $path);
	}

	public function databasePath($path = '') {
		return $this->namedPath('database', $path);
	}

	public function langPath($path = '') {
		return $this->namedPath('lang', $path);
	}

	public function publicPath($path = '') {
		return $this->namedPath('public', $path);
	}

	public function resourcePath($path = '') {
		return $this->namedPath('resources', $path);
	}

	public function storagePath($path = '') {
		return $this->namedPath('storage', $path);
	}

	public function useAppPath($path) {
		return $this->useNamedPath('app', $path);
	}

	public function useBootstrapPath($path) {
		return $this->useNamedPath('bootstrap', $path);
	}

	public function useConfigPath($path) {
		return $this->useNamedPath('config', $path);
	}

	public function useDatabasePath($path) {
		return $this->useNamedPath('database', $path);
	}

	public function useLangPath($path) {
		return $this->useNamedPath('lang', $path);
	}

	public function usePublicPath($path) {
		return $this->useNamedPath('public', $path);
	}

	public function useStoragePath($path) {
		return $this->useNamedPath('storage', $path);
	}

	/*
	 * ---
	 * Environment.
	 * ---
	 */

	/**
	 * environment() => tên môi trường; environment('local', 'dev*') => bool.
	 */
	public function environment(...$environments) {
		$env = $this->environment ??= $this->detectDefaultEnvironment();

		if (!$environments) {
			return $env;
		}

		$patterns = is_array($environments[0]) ? $environments[0] : $environments;
		foreach ($patterns as $pattern) {
			if ($pattern === $env || fnmatch($pattern, $env)) {
				return true;
			}
		}
		return false;
	}

	public function detectEnvironment(\Closure $callback) {
		return $this->environment = (string)$callback();
	}

	protected function detectDefaultEnvironment() {
		if (($env = getenv('APP_ENV')) !== false && $env !== '') {
			return $env;
		}
		if (($env = $this->configValue('app.env')) !== null && $env !== '') {
			return (string)$env;
		}
		if (function_exists('wp_get_environment_type')) {
			return wp_get_environment_type();
		}
		return 'production';
	}

	public function isLocal() {
		return $this->environment('local');
	}

	public function isProduction() {
		return $this->environment('production');
	}

	public function runningInConsole() {
		$env = getenv('APP_RUNNING_IN_CONSOLE');
		return $env !== false ? filter_var($env, FILTER_VALIDATE_BOOLEAN) : in_array(PHP_SAPI, ['cli', 'phpdbg'], true);
	}

	public function runningUnitTests() {
		return $this->environment('testing');
	}

	public function hasDebugModeEnabled() {
		$env = getenv('APP_DEBUG');
		if ($env !== false) {
			return filter_var($env, FILTER_VALIDATE_BOOLEAN);
		}
		if (($debug = $this->configValue('app.debug')) !== null) {
			return filter_var($debug, FILTER_VALIDATE_BOOLEAN);
		}
		return defined('WP_DEBUG') && WP_DEBUG;
	}

	/**
	 * Đọc config nếu có, không ném lỗi khi chưa có binding 'config'.
	 */
	protected function configValue($key, $default = null) {
		if (!$this->bound('config')) {
			return $default;
		}

		$config = $this->make('config');

		return is_object($config) && method_exists($config, 'get') ? $config->get($key, $default) : $default;
	}

	/*
	 * ---
	 * Locale.
	 * ---
	 */

	public function getLocale() {
		return $this->locale
			?? $this->configValue('app.locale')
			?? (function_exists('get_locale') ? get_locale() : 'en');
	}

	public function currentLocale() {
		return $this->getLocale();
	}

	public function setLocale($locale) {
		$this->locale = $locale;

		if ($this->resolved('config')) {
			$this->make('config')->set('app.locale', $locale);
		}

		if ($this->resolved('request')) {
			$this->make('request')->setLocale($locale);
		}
	}

	public function isLocale($locale) {
		return $this->getLocale() === $locale;
	}

	/*
	 * ---
	 * Service providers.
	 * ---
	 */

	public function getBootstrapProvidersPath() {
		return $this->bootstrapPath('providers.php');
	}

	/**
	 * Register toàn bộ provider đã cấu hình (chỉ chạy một lần).
	 * Tương đương Illuminate\Foundation\Bootstrap\RegisterProviders + registerConfiguredProviders().
	 */
	public function registerConfiguredProviders() {
		if ($this->providersRegistered) {
			return;
		}

		$this->providersRegistered = true;

		$providers = $this->configuredProviders();

		if ($this->withBootstrapProviders) {
			$path = $this->getBootstrapProvidersPath();

			if (is_file($path)) {
				$providers = array_merge($providers, (array)require $path);
			}
		}

		$providers              = array_merge($providers, $this->pendingProviders);
		$this->pendingProviders = [];

		// register() tự bỏ qua provider đã đăng ký => không bị trùng.
		foreach ($providers as $provider) {
			$this->register($provider);
		}
	}

	/**
	 * config('app.providers') nếu có, ngược lại DefaultProviders của core.
	 */
	protected function configuredProviders() {
		if ($this->bound('config')) {
			$config = $this->make('config');

			if (is_object($config) && method_exists($config, 'get')) {
				$providers = $config->get('app.providers');

				if (is_array($providers) && $providers) {
					return $providers;
				}
			}
		}

		return (new DefaultProviders())->toArray();
	}

	/**
	 * Đăng ký provider (class name hoặc object). Trả về instance provider.
	 */
	public function register($provider, $force = false) {
		if (($registered = $this->getProvider($provider)) && !$force) {
			return $registered;
		}

		if (is_string($provider)) {
			$provider = $this->resolveProvider($provider);
		}

		if ($this->isDeferredProvider($provider) && !$force) {
			$this->addDeferredProvider($provider);
			return $provider;
		}

		if (method_exists($provider, 'register')) {
			$provider->register();
		}

		foreach (['bindings' => false, 'singletons' => true] as $property => $shared) {
			if (isset($provider->{$property}) && is_array($provider->{$property})) {
				foreach ($provider->{$property} as $key => $value) {
					is_int($key) ? $this->bind($value, null, $shared) : $this->bind($key, $value, $shared);
				}
			}
		}

		$this->markAsRegistered($provider);

		if ($this->isBooted()) {
			$this->bootProvider($provider);
		}

		return $provider;
	}

	public function resolveProvider($provider) {
		return new $provider($this);
	}

	public function getProvider($provider) {
		$name = is_string($provider) ? ltrim($provider, '\\') : get_class($provider);
		return $this->serviceProviders[$name] ?? null;
	}

	public function getProviders($provider) {
		return array_values(array_filter($this->serviceProviders, function($value) use ($provider) {
			return $value instanceof $provider;
		}));
	}

	public function getLoadedProviders() {
		return $this->loadedProviders;
	}

	public function providerIsLoaded($provider) {
		return isset($this->loadedProviders[ltrim($provider, '\\')]);
	}

	protected function markAsRegistered($provider) {
		$class = get_class($provider);

		$this->serviceProviders[$class] = $provider;
		$this->loadedProviders[$class]  = true;
	}

	protected function isDeferredProvider($provider) {
		if (!method_exists($provider, 'provides')) {
			return false;
		}

		return method_exists($provider, 'isDeferred') && $provider->isDeferred();
	}

	protected function addDeferredProvider($provider) {
		foreach ((array)$provider->provides() as $service) {
			$this->deferredServices[$service] = $provider;
		}
	}

	public function getDeferredServices() {
		return $this->deferredServices;
	}

	public function addDeferredServices(array $services) {
		$this->deferredServices = array_merge($this->deferredServices, $services);
	}

	public function isDeferredService($service) {
		return isset($this->deferredServices[$service]);
	}

	public function loadDeferredProviders() {
		foreach (array_keys($this->deferredServices) as $service) {
			$this->loadDeferredProvider($service);
		}
	}

	public function loadDeferredProvider($service) {
		if (!$this->isDeferredService($service)) {
			return;
		}

		$provider = $this->deferredServices[$service];

		// Một provider có thể cung cấp nhiều service => gỡ hết trước khi đăng ký.
		$this->deferredServices = array_filter($this->deferredServices, function($value) use ($provider) {
			return $value !== $provider;
		});

		if (!$this->getProvider($provider)) {
			$this->register($provider, true);
		}
	}

	public function make($abstract, array $parameters = []) {
		$abstract = $this->getAlias($abstract);

		if (isset($this->deferredServices[$abstract]) && !isset($this->instances[$abstract])) {
			$this->loadDeferredProvider($abstract);
		}

		return parent::make($abstract, $parameters);
	}

	public function bound($abstract) {
		return $this->isDeferredService($abstract) || parent::bound($abstract);
	}

	/*
	 * ---
	 * Boot / terminate.
	 * ---
	 */

	public function isBooted() {
		return $this->booted;
	}

	public function boot() {
		if ($this->booted) {
			return;
		}

		// Phòng trường hợp không gọi create().
		$this->registerConfiguredProviders();

		$this->fireAppCallbacks($this->bootingCallbacks);

		foreach ($this->serviceProviders as $provider) {
			$this->bootProvider($provider);
		}

		$this->booted = true;

		$this->fireAppCallbacks($this->bootedCallbacks);
	}

	protected function bootProvider($provider) {
		if (method_exists($provider, 'callBootingCallbacks')) {
			$provider->callBootingCallbacks();
		}

		if (method_exists($provider, 'boot')) {
			$this->call([$provider, 'boot']);
		}

		if (method_exists($provider, 'callBootedCallbacks')) {
			$provider->callBootedCallbacks();
		}
	}

	public function booting(callable $callback) {
		$this->bootingCallbacks[] = $callback;
		return $this;
	}

	public function booted(callable $callback) {
		$this->bootedCallbacks[] = $callback;

		if ($this->isBooted()) {
			$callback($this);
		}
		return $this;
	}

	protected function fireAppCallbacks(array &$callbacks) {
		$index = 0;
		while ($index < count($callbacks)) {
			$callbacks[$index]($this);
			$index++;
		}
	}

	public function terminating($callback) {
		$this->terminatingCallbacks[] = $callback;
		return $this;
	}

	public function terminate() {
		$index = 0;
		while ($index < count($this->terminatingCallbacks)) {
			$this->call($this->terminatingCallbacks[$index]);
			$index++;
		}
	}

	/*
	 * ---
	 * Console.
	 * ---
	 */

	/**
	 * Chạy CLI: exit($app->handleCommand());
	 */
	public function handleCommand(?array $argv = null) {
		$this->boot();

		$status = $this->make('commands')->run($argv);

		$this->terminate();

		return $status;
	}

	/*
	 * ---
	 * Flush.
	 * ---
	 */

	public function flush() {
		parent::flush();

		$this->paths                  = [];
		$this->bootingCallbacks       = [];
		$this->bootedCallbacks        = [];
		$this->terminatingCallbacks   = [];
		$this->serviceProviders       = [];
		$this->loadedProviders        = [];
		$this->deferredServices       = [];
		$this->pendingProviders       = [];
		$this->withBootstrapProviders = false;
		$this->providersRegistered    = false;
		$this->booted                 = false;
	}

}
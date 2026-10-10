<?php

namespace WPSPCORELITE\App;

use Closure;

/**
 * ServiceProvider - mô phỏng Illuminate\Support\ServiceProvider bằng PHP thuần.
 *
 * Provider con có thể khai báo:
 *   public $bindings   = [Contract::class => Impl::class];
 *   public $singletons = ['foo' => Foo::class];
 * và override register() / boot(). boot() được gọi qua $app->call() nên hỗ trợ DI:
 *   public function boot(Filesystem $files) { ... }
 *
 * Deferred provider: implements DeferrableProvider + override provides().
 */
abstract class ServiceProvider {

	/** @var \WPSPCORELITE\App\Application */
	protected $app;

	/** @var Closure[] */
	protected $bootingCallbacks = [];

	/** @var Closure[] */
	protected $bootedCallbacks = [];

	public function __construct($app) {
		$this->app = $app;
	}

	/**
	 * Đăng ký service vào container. Chỉ bind, không dùng service khác ở đây.
	 */
	public function register() {
		//
	}

	/*
	 * ---
	 * Booting / booted callbacks.
	 * ---
	 */

	public function booting(Closure $callback) {
		$this->bootingCallbacks[] = $callback;
	}

	public function booted(Closure $callback) {
		$this->bootedCallbacks[] = $callback;
	}

	public function callBootingCallbacks() {
		$index = 0;
		while ($index < count($this->bootingCallbacks)) {
			$this->app->call($this->bootingCallbacks[$index]);
			$index++;
		}
	}

	public function callBootedCallbacks() {
		$index = 0;
		while ($index < count($this->bootedCallbacks)) {
			$this->app->call($this->bootedCallbacks[$index]);
			$index++;
		}
	}

	/*
	 * ---
	 * Helpers.
	 * ---
	 */

	/**
	 * Gộp file config mặc định của package vào config hiện tại (config của app được ưu tiên).
	 * Chỉ chạy khi container có binding 'config' với get()/set().
	 */
	protected function mergeConfigFrom($path, $key) {
		if (!$this->app->bound('config')) {
			return;
		}

		$config = $this->app->make('config');

		if (!method_exists($config, 'get') || !method_exists($config, 'set')) {
			return;
		}

		$config->set($key, array_merge(require $path, (array)$config->get($key, [])));
	}

	/**
	 * Nạp file route.
	 */
	protected function loadRoutesFrom($path) {
		require $path;
	}

	/**
	 * Chạy callback sau khi service được resolve (ngay lập tức nếu đã resolve rồi).
	 */
	protected function callAfterResolving($name, Closure $callback) {
		$this->app->afterResolving($name, $callback);

		if ($this->app->resolved($name)) {
			$callback($this->app->make($name), $this->app);
		}
	}

	/**
	 * Đăng ký console command cho provider (thư mục, class hoặc object).
	 */
	public function commands($commands) {
		$commands = is_array($commands) ? $commands : func_get_args();

		if ($this->app->resolved('commands')) {
			$this->app->make('commands')->register($commands);
			return;
		}

		$this->app->afterResolving('commands', function($kernel) use ($commands) {
			$kernel->register($commands);
		});
	}

	/*
	 * ---
	 * Deferred.
	 * ---
	 */

	/**
	 * Danh sách service mà provider deferred cung cấp.
	 */
	public function provides() {
		return [];
	}

	public function isDeferred() {
		return $this instanceof DeferrableProvider;
	}

	/*
	 * ---
	 * Default providers.
	 * ---
	 */

	public static function defaultProviders() {
		return new DefaultProviders();
	}

}
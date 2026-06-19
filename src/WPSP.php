<?php

namespace WPSPCORE;

use Dotenv\Dotenv;
use Illuminate\Auth\AuthManager;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Timebox;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;
use WPSPCORE\App\Http\Middleware\StartSessionIfAuthenticated;
use WPSPCORE\App\View\BladeCompiler;
use WPSPCORE\App\View\Directives\adminpagemetaboxes;

abstract class WPSP extends BaseInstances {

	/** @var null|Application|Container */
	public $application = null;
	public $artisan     = null;
	public $response    = null;

	/*
	 *
	 */

	public function setApplication($basePath, $handleRequest = true) {
		if (class_exists('Illuminate\Foundation\Application')) {
			$commands = $this->getCustomCommands();
			$providers = $this->getConfig('providers');

			$this->application = Application::configure($basePath)
				->withRouting(
					web      : $this->funcs->_getRoutesPath('/original/web.php'),
					api      : $this->funcs->_getRoutesPath('/original/api.php'),
					commands : $this->funcs->_getRoutesPath('/original/console.php'),
					health   : '/up',
	//				apiPrefix: 'api/admin',
				)
				->withMiddleware(function(Middleware $middleware) {
//					$middleware->append(StartSessionIfAuthenticated::class); // Start session trước mọi code (bao gồm cả view share).
//					$middleware->append(StartSession::class);
//					$middleware->append(PreventRequestForgery::class);
//					$middleware->append(VerifyCsrfToken::class);
				})
				->withExceptions(function(Exceptions $exceptions) {})
				->withProviders($providers)
				->withCommands($commands)
				->create();

			$this->setPaths();
			$this->afterSetPaths();
			$this->bootstrap();
			$this->afterBoostrap();
			$this->bindings();
			$this->afterBindings();
			$this->extends();

//			$this->registerBladeDirectives();

			$this->application->boot();

			if ($handleRequest) {
				$this->handleRequest();
			}
		}
		else {
			$this->application = new Container();

//			$this->bootstrap();
			$this->afterBoostrap();
			$this->bindings();
			$this->afterBindings();
			$this->extends();
		}
	}

	public function setApplicationForConsole($basePath) {
		if (class_exists('Illuminate\Foundation\Application')) {
			$commands = $this->getCustomCommands();
			$providers = $this->getConfig('providers');

			$this->application = Application::configure($basePath)
				->withRouting(
					web      : $this->funcs->_getRoutesPath('/original/web.php'),
					api      : $this->funcs->_getRoutesPath('/original/api.php'),
					commands : $this->funcs->_getRoutesPath('/original/console.php'),
					health   : '/up',
//				apiPrefix: 'api/admin',
				)
				->withMiddleware(function(Middleware $middleware) {})
				->withExceptions(function(Exceptions $exceptions) {})
				->withProviders($providers)
				->withCommands($commands)
				->create();

			$this->setPaths();
			$this->afterSetPaths();
			$this->bootstrapConsole();
			$this->afterBoostrapConsole();
			$this->bindingsConsole();
			$this->afterBindingsConsole();
			$this->extendsConsole();

			$this->application->boot();
		}
		else {
			$this->application = new Container();
			$this->afterBoostrapConsole();
			$this->bindingsConsole();
			$this->afterBindingsConsole();

			$this->artisan = new ConsoleApplication(
				$this->application,
				$this->application['events'],
				$this->funcs->_getVersion()
			);

			$commands = $this->getCustomCommands();

			foreach ($commands as $command) {
				$this->artisan->add(new $command);
			}
		}

		return $this->application;
	}

	/*
	 *
	 */

	public function getApplication($abstract = null, $parameters = []) {
		if ($abstract) {
			return $this->application->make($abstract, $parameters);
		}
		return $this->application;
	}

	public function getArtisan() {
		return $this->artisan;
	}

	public function getCustomCommands() {
		$commands = $this->funcs->_getAllClassesInDir(
			'WPSPCORE\App\Console\Commands',
			__DIR__ . '/app/Console/Commands'
		);

		$extendCommands = $this->funcs->_getAllClassesInDir(
			'WPSPCORE\App\Console\Commands\Extends',
			__DIR__ . '/app/Console/Commands/Extends'
		);

		if (!class_exists('Illuminate\Foundation\Application')) {
			$consoleCommands = $this->funcs->_getAllClassesInDir(
				$this->funcs->_getRootNamespace() . '\App\Console\Commands',
				$this->funcs->_getAppPath('/Console/Commands')
			);
		}

		$integrationCommands = $this->funcs->_getAllClassesInDir(
			$this->funcs->_getRootNamespace() . '\App\Widen\Commands',
			$this->funcs->_getAppPath('/Widen/Commands')
		);

		$commands = array_merge($commands, $extendCommands, $consoleCommands ?? [], $integrationCommands);

		return $commands;
	}

	public function getConfig($fileName = null) {
		$config = [];

		if ($fileName) {
			$config = require __DIR__ . '/config/' . $fileName . '.php';
		}

		return $config;
	}

	/*
	 *
	 */

	public function setPaths() {
		$this->application->useAppPath($this->mainPath . '/app');
		$this->application->useLangPath($this->mainPath . '/lang');
		$this->application->useConfigPath($this->mainPath . '/config');
		$this->application->usePublicPath($this->mainPath . '/public');
		$this->application->useStoragePath($this->mainPath . '/storage');
		$this->application->useDatabasePath($this->mainPath . '/database');
		$this->application->useBootstrapPath($this->mainPath . '/bootstrap');
		$this->application->useEnvironmentPath($this->mainPath);
	}

	/*
	 *
	 */

	public function bootstrap() {
		// Environment variables.
		(new LoadEnvironmentVariables)->bootstrap($this->application);

		// Configs.
		(new LoadConfiguration)->bootstrap($this->application);

		// Facades.
		(new RegisterFacades)->bootstrap($this->application);

		// Providers.
		(new RegisterProviders)->bootstrap($this->application);
	}

	public function bootstrapConsole() {
		// Environment variables.
		(new LoadEnvironmentVariables)->bootstrap($this->application);

		// Configs.
		(new LoadConfiguration)->bootstrap($this->application);

		// Facades.
		(new RegisterFacades)->bootstrap($this->application);

		// Providers.
		(new RegisterProviders)->bootstrap($this->application);
	}

	public function bindings() {
		// Request.
		$this->application->instance(Request::class, $this->request);
		$this->application->instance('request', $this->request);

		// Funcs.
		$this->application->instance('funcs', $this->funcs ?? new Funcs($this->mainPath, $this->rootNamespace, $this->prefixEnv, $this->extraParams));

		// Files.
		$this->application->singleton('files', function() { return new Filesystem(); });

		// Storage và Filesystem.
		$this->application->singleton('filesystem', function($app) { return new FilesystemManager($app); });
		$this->application->alias('filesystem', 'storage');
		$this->application->alias('filesystem', FilesystemManager::class);

		if (class_exists('Illuminate\Foundation\Application')) {
			// Process.
			$this->application->singleton('process', function($app) { return $app->make(ProcessFactory::class); });
		}
		else {
			// Env.
			$dotenv = Dotenv::createImmutable($this->mainPath); $dotenv->safeLoad();
			$this->application->instance('env', $_ENV);

			// Config.
			$configs     = [];
			$configFiles = $this->funcs->_getAllFilesInFolder($this->funcs->_getConfigPath());
			foreach ($configFiles as $configFile) {
				$configs[$configFile['name_without_extension']] = require_once($configFile['real_path']);
			}
			$this->application->singleton('config', function($app) use ($configs) {
				return new \Illuminate\Config\Repository($configs);
			});

			// Event.
			$this->application->singleton('events', function($app) {
				return new Dispatcher($app);
			});

			// Session.
//			$this->application->singleton('session', function($app) {
//				return new SessionManager($app);
//			});
//			$this->application->singleton('session.store', function($app) {
//				return $app['session']->driver();
//			});

			// View.
			$this->application->singleton('blade.compiler', function($app) {
				return new BladeCompiler($app['files'], $this->funcs->_getStoragePath('/framework/views'), $this->funcs);
			});
			$this->application->singleton('view.engine.resolver', function($app) {
				$resolver = new EngineResolver();

				$resolver->register('blade', function() use ($app) {
					return new CompilerEngine($app['blade.compiler']);
				});

				return $resolver;
			});
			$this->application->singleton('view.finder', function($app) {
				return new FileViewFinder(
					$app['files'],
					[$this->funcs->_getResourcesPath('/views')]
				);
			});
			$this->application->singleton('view', function($app) {
				return new ViewFactory(
					$app['view.engine.resolver'],
					$app['view.finder'],
					$app['events']
				);
			});
			$this->application->alias('view', \Illuminate\Contracts\View\Factory::class);
			$this->application->alias('view', \Illuminate\View\Factory::class);
			$this->application->alias('view.finder', \Illuminate\View\ViewFinderInterface::class);
			$this->application->alias('blade.compiler', \Illuminate\View\Compilers\BladeCompiler::class);

			// Translation.
//			$this->application->singleton(Loader::class, function($app) {
//				return new FileLoader(
//					$app->make(Filesystem::class),
//					$this->funcs->_getMainPath('/lang'),
//				);
//			});
//			$this->application->singleton('translator', function($app) {
//				return new Translator(
//					$app->make(Loader::class),
//					$this->funcs->_locale(),
//				);
//			});
//			$this->application->alias('translator', Translator::class);
//			$this->application->alias('translator', \Illuminate\Contracts\Translation\Translator::class);
		}
	}

	public function bindingsConsole() {
		// Funcs.
		$this->application->instance('funcs', $this->funcs ?? new Funcs($this->mainPath, $this->rootNamespace, $this->prefixEnv, $this->extraParams));

		// Files.
		$this->application->singleton('files', function() { return new Filesystem(); });

		// Storage và Filesystem.
		$this->application->singleton('filesystem', function($app) { return new FilesystemManager($app); });
		$this->application->alias('filesystem', 'storage');
		$this->application->alias('filesystem', FilesystemManager::class);

		if (class_exists('Illuminate\Foundation\Application')) {
			// Process.
			$this->application->singleton('process', function($app) { return $app->make(ProcessFactory::class); });
		}
		else {
			// Env.
			$dotenv = Dotenv::createImmutable($this->mainPath); $dotenv->safeLoad();
			$this->application->instance('env', $_ENV);

			// Config.
			$configs     = [];
			$configFiles = $this->funcs->_getAllFilesInFolder($this->funcs->_getConfigPath());
			foreach ($configFiles as $configFile) {
				$configs[$configFile['name_without_extension']] = require_once($configFile['real_path']);
			}
			$this->application->singleton('config', function($app) use ($configs) {
				return new \Illuminate\Config\Repository($configs);
			});

			// Event.
			$this->application->singleton('events', function($app) {
				return new Dispatcher($app);
			});

			// View.
			$this->application->singleton('blade.compiler', function($app) {
				return new BladeCompiler($app['files'], $this->funcs->_getStoragePath('/framework/views'), $this->funcs);
			});
			$this->application->singleton('view.engine.resolver', function($app) {
				$resolver = new EngineResolver();

				$resolver->register('blade', function() use ($app) {
					return new CompilerEngine($app['blade.compiler']);
				});

				return $resolver;
			});
			$this->application->singleton('view.finder', function($app) {
				return new FileViewFinder(
					$app['files'],
					[$this->funcs->_getResourcesPath('/views')]
				);
			});
			$this->application->singleton('view', function($app) {
				return new ViewFactory(
					$app['view.engine.resolver'],
					$app['view.finder'],
					$app['events']
				);
			});
			$this->application->alias('view', \Illuminate\Contracts\View\Factory::class);
			$this->application->alias('view', \Illuminate\View\Factory::class);
			$this->application->alias('view.finder', \Illuminate\View\ViewFinderInterface::class);
			$this->application->alias('blade.compiler', \Illuminate\View\Compilers\BladeCompiler::class);

			// Translation.
//			$this->application->singleton(Loader::class, function($app) {
//				return new FileLoader(
//					$app->make(Filesystem::class),
//					$this->funcs->_getMainPath('/lang'),
//				);
//			});
//			$this->application->singleton('translator', function($app) {
//				return new Translator(
//					$app->make(Loader::class),
//					$this->funcs->_locale(),
//				);
//			});
//			$this->application->alias('translator', Translator::class);
//			$this->application->alias('translator', \Illuminate\Contracts\Translation\Translator::class);
		}
	}

	public function extends() {
		// Override SessionGuard để thay đổi remember_web_* thành wpsp_remember_web_*
		$this->overrideRememberCookieName();
	}

	public function extendsConsole() {}

	/*
	 *
	 */

	public function afterSetPaths() {}

	public function afterBoostrap() {}

	public function afterBoostrapConsole() {}

	public function afterBindings() {}

	public function afterBindingsConsole() {}

	/*
	 *
	 */

	public function registerBladeDirectives() {
		$bladeCompiler = $this->application->make('blade.compiler');

		$directiveClasses = [
			adminpagemetaboxes::class,
		];

		foreach ($directiveClasses as $directiveClass) {
			(new $directiveClass(
				$this->mainPath,
				$this->rootNamespace,
				$this->prefixEnv,
				array_merge($this->extraParams, ['funcs' => $this->funcs])
			))->register($bladeCompiler);
		}
	}

	/*
	 *
	 */

	public function handleRequest() {
		// Start session.
		$this->startSessionIfAuthenticated();

		/** @var \Illuminate\Foundation\Http\Kernel $kernel */
//		$kernel         = $this->application->make(Kernel::class);
//		$this->response = $kernel->handle($this->request);
//		$this->response->send();
//		$kernel->terminate($this->request, $this->response);

		$this->afterHandleRequest();
	}

	public function afterHandleRequest() {
		// Share flash data to view.
//		add_action('template_redirect', function() {
//			$this->application->make('view')->share('errors', session('errors'));
			$this->application->booted(function($app) {
				$session = $app['session.store'];
				$view    = $app['view'];

				foreach ($session->get('_flash.new', []) as $key) {
					$view->share($key, $session->get($key));
				}
			});
//		});
	}

	/*
	 *
	 */

	/**
	 * Start session.
	 */
	public function startSessionIfAuthenticated() {
		$middleware = $this->application->make(StartSessionIfAuthenticated::class);
		$middleware->handle($this->request, function($request) {
			return $request;
		}, ['funcs' => $this->funcs]);
	}

	/**
	 * Override SessionGuard để thay đổi remember_web_* thành wpsp_remember_web_*
	 */
	private function overrideRememberCookieName() {
		if (class_exists('Illuminate\Auth\AuthManager')) {
			$this->application->afterResolving('auth', function(AuthManager $auth) {
				$auth->extend('session', function($app, $name, $config) use ($auth) {
					$provider = $auth->createUserProvider($config['provider']);

					$guard = new \WPSPCORE\App\Auth\SessionGuard(
						$name,
						$provider,
						$app['session.store'],
						$app['request'],
						$app->make(Timebox::class),
						true,
						200000,
						$app['funcs'] // truyền funcs trực tiếp
					);

					$guard->setCookieJar($app['cookie']);
					$guard->setRequest($app['request']);

					return $guard;
				});
			});
		}
	}

}
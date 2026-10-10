<?php

namespace WPSPCORELITE;

use WPSPCORELITE\App\Application as WPSPLiteApplication;

abstract class WPSP extends BaseInstances {

	/** @var null|WPSPLiteApplication */
	public $application = null;
	public $response    = null;

	/**
	 * Custom middlewares.
	 */
	public $middlewares = [];

	/*
	 * Bootstrap
	 */

	public function setApplication($basePath, $handleRequest = true) {
		$this->buildApplication($basePath);

		$this->setPaths();
		$this->afterSetPaths();
		$this->bindings();
		$this->afterBindings();

		$this->application->boot();

		if ($handleRequest) {
			$this->handleRequest();
		}
	}

	public function setApplicationForConsole($basePath) {
		$this->buildApplication($basePath);

		$this->setPaths();
		$this->afterSetPaths();
		$this->bindingsConsole();
		$this->afterBindingsConsole();

		$this->application->boot();

		return $this->application;
	}

	public function buildApplication($basePath): void {
		$this->application = WPSPLiteApplication::configure($basePath)
			->withProviders($this->getCustomProviders())
			->withCommands($this->getCustomCommands());
	}

	/*
	 * Getters
	 */

	public function getApplication($abstract = null, $parameters = []) {
		return $abstract
			? $this->application->make($abstract, $parameters)
			: $this->application;
	}

	public function getCustomCommands() {
		return array_merge(
			$this->funcs->_getAllClassesInDir(
				__DIR__ . '/app/Console/Commands',
				'WPSPCORELITE\App\Console\Commands'
			),
			$this->funcs->_getAllClassesInDir(
				__DIR__ . '/app/Console/Commands/Extends',
				'WPSPCORELITE\App\Console\Commands\Extends'
			),
			$this->funcs->_getAllClassesInDir(
				$this->funcs->_getAppPath('/Widen/Commands'),
				$this->funcs->_getRootNamespace() . '\App\Widen\Commands'
			),
			$this->funcs->_getAllClassesInDir(
				$this->funcs->_getAppPath('/Console/Commands'),
				$this->funcs->_getRootNamespace() . '\App\Console\Commands'
			),
		);
	}

	public function getCustomProviders() {
		return array_merge(
			$this->funcs->_getAllClassesInDir(
				__DIR__ . '/app/Providers',
				'WPSPCORELITE\App\Providers'
			),
			$this->funcs->_getAllClassesInDir(
				$this->funcs->_getAppPath('/Providers'),
				$this->funcs->_getRootNamespace() . '\App\Providers'
			),
		);
	}

	public function getConfig($fileName = null) {
		return $fileName ? require __DIR__ . '/config/' . $fileName . '.php' : [];
	}

	/*
	 * Paths
	 */

	public function setPaths() {
		$this->application->useAppPath($this->mainPath . '/app');
		$this->application->useLangPath($this->mainPath . '/lang');
		$this->application->useConfigPath($this->mainPath . '/config');
		$this->application->usePublicPath($this->mainPath . '/public');
		$this->application->useStoragePath($this->mainPath . '/storage');
		$this->application->useDatabasePath($this->mainPath . '/database');
		$this->application->useBootstrapPath($this->mainPath . '/bootstrap');
	}

	/*
	 * Bootstrap / Bindings
	 */

	private function bindingsBase(): void {
		$this->application->instance('request', $this->request);
		$this->application->instance('funcs', $this->funcs ??= new Funcs(
			$this->mainPath,
			$this->rootNamespace,
			$this->prefixEnv,
			$this->extraParams
		));
	}

	public function bindings() {
		$this->bindingsBase();
	}

	public function bindingsConsole() {
		$this->bindingsBase();
	}

	/*
	 * Hooks
	 */

	public function afterSetPaths() {}

	public function afterBoostrap() {}

	public function afterBoostrapConsole() {}

	public function afterBindings() {}

	public function afterBindingsConsole() {}

	/*
	 * Request lifecycle
	 */

	public function handleRequest() {
		$this->beforeHandleRequest();
		$this->applyMiddlewares();
		$this->beforeResponse();
		$this->afterHandleRequest();
	}

	public function beforeHandleRequest() {}

	public function applyMiddlewares() {
		foreach ($this->middlewares as $middleware) {
			$middleware = $this->application->make($middleware);
			$middleware->handle($this->request, fn($request) => $request);
		}
	}

	public function beforeResponse() {}

	public function afterHandleRequest() {}

}
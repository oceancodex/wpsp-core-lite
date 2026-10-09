<?php

namespace WPSPCORELITE\App\Support\Facades\App;

use WPSPCORELITE\App\Application;
use WPSPCORELITE\BaseInstances;

/**
 * @mixin \WPSPCORELITE\App\Application
 */
abstract class App extends BaseInstances {

	private ?Application $facade;

	/*
	 *
	 */

	public function getFacade(): ?Application {
		return $this->facade;
	}

	public function setFacade() {
		$this->facade = $this->funcs->_getApplication();
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
<?php

namespace WPSPCORELITE\App\Artisan;

use WPSPCORELITE\App\Commands;
use WPSPCORELITE\BaseInstances;

/**
 * @mixin \Illuminate\Support\Facades\Artisan
 * @method static string outputHtml()
 * @method static void writeError(string $text)
 * @method static int call(?string $name = null, array $parameters = [])
 */
abstract class Artisan extends BaseInstances {

	private ?Commands $facade;

	/*
	 *
	 */

	public function getFacade(): ?Commands {
		return $this->facade;
	}

	public function setFacade() {
		$this->facade = $this->funcs->_getApplication('commands');
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
<?php

namespace WPSPCORELITE\App\Cloud;

use Illuminate\Foundation\Cloud\CloudManager as IlluminateCloud;
use WPSPCORELITE\BaseInstances;

/**
 * @mixin \Illuminate\Support\Facades\Cloud
 */
abstract class Cloud extends BaseInstances {

	private ?IlluminateCloud $facade;

	/*
	 *
	 */

	public function getFacade(): ?IlluminateCloud {
		return $this->facade;
	}

	public function setFacade() {
		$this->facade = $this->funcs->_getApplication(IlluminateCloud::class);
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
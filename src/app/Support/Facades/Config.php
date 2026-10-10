<?php

namespace WPSPCORELITE\App\Support\Facades;

use WPSPCORELITE\App\Config\Repository as ConfigCore;
use WPSPCORELITE\BaseInstances;

/**
 * Facade cho 'config' - mô phỏng Illuminate\Support\Facades\Config.
 *
 * Gọi static:  Config::get('app.name'), Config::set('app.debug', true), Config::has('services.payment')
 * Lấy object:  $this->funcs->_getApplication('config')
 *
 * Không dùng class này làm type-hint cho DI; hãy type-hint
 * \WPSPCORELITE\App\Config\Repository (giống Laravel: type-hint Illuminate\Config\Repository).
 *
 * @method static bool has(string $key)
 * @method static mixed get(array|string $key, mixed $default = null)
 * @method static array getMany(array $keys)
 * @method static string string(string $key, $default = null)
 * @method static int integer(string $key, $default = null)
 * @method static float float(string $key, $default = null)
 * @method static bool boolean(string $key, $default = null)
 * @method static array array(string $key, $default = null)
 * @method static void set(array|string $key, mixed $value = null)
 * @method static void prepend(string $key, mixed $value)
 * @method static void push(string $key, mixed $value)
 * @method static array all()
 * @method static void macro(string $name, object|callable $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \WPSPCORELITE\App\Config\Repository
 */
abstract class Config extends BaseInstances {

	private ?ConfigCore $facade = null;

	/*
	 *
	 */

	public function getFacade(): ?ConfigCore {
		return $this->facade;
	}

	public function setFacade() {
		$this->facade = $this->funcs->_getApplication('config');
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
<?php

namespace WPSPCORELITE\App\Filesystem;

use BadMethodCallException;
use Closure;
use ReflectionClass;
use ReflectionMethod;

/**
 * Package-free port of Illuminate\Support\Traits\Macroable.
 */
trait Macroable {

	/**
	 * The registered string macros.
	 *
	 * @var array<string, callable|object>
	 */
	protected static $macros = [];

	public static function macro($name, $macro) {
		static::$macros[$name] = $macro;
	}

	/**
	 * Mix another object's public/protected methods into the class.
	 *
	 * @throws \ReflectionException
	 */
	public static function mixin($mixin, $replace = true) {
		$methods = (new ReflectionClass($mixin))->getMethods(
			ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
		);

		foreach ($methods as $method) {
			if ($replace || !static::hasMacro($method->name)) {
				static::macro($method->name, $method->invoke($mixin));
			}
		}
	}

	public static function hasMacro($name) {
		return isset(static::$macros[$name]);
	}

	public static function flushMacros() {
		static::$macros = [];
	}

	/**
	 * @throws BadMethodCallException
	 */
	public static function __callStatic($method, $parameters) {
		if (!static::hasMacro($method)) {
			throw new BadMethodCallException(sprintf(
				'Method %s::%s does not exist.', static::class, $method
			));
		}

		$macro = static::$macros[$method];

		if ($macro instanceof Closure) {
			$macro = $macro->bindTo(null, static::class);
		}

		return $macro(...$parameters);
	}

	/**
	 * @throws BadMethodCallException
	 */
	public function __call($method, $parameters) {
		if (!static::hasMacro($method)) {
			throw new BadMethodCallException(sprintf(
				'Method %s::%s does not exist.', static::class, $method
			));
		}

		$macro = static::$macros[$method];

		if ($macro instanceof Closure) {
			$macro = $macro->bindTo($this, static::class);
		}

		return $macro(...$parameters);
	}

}

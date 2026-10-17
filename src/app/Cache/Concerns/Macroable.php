<?php

namespace WPSPCORELITE\App\Cache\Concerns;

use Closure;
use ReflectionClass;
use ReflectionMethod;

/**
 * Minimal mirror of Illuminate\Support\Traits\Macroable (without __call / __callStatic,
 * the using class decides how to dispatch macros).
 */
trait Macroable {

	protected static array $macros = [];

	public static function macro(string $name, object|callable $macro): void {
		static::$macros[$name] = $macro;
	}

	public static function mixin(object $mixin, bool $replace = true): void {
		$methods = (new ReflectionClass($mixin))->getMethods(
			ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
		);

		foreach ($methods as $method) {
			if ($replace || !static::hasMacro($method->name)) {
				static::macro($method->name, $method->invoke($mixin));
			}
		}
	}

	public static function hasMacro(string $name): bool {
		return isset(static::$macros[$name]);
	}

	public static function flushMacros(): void {
		static::$macros = [];
	}

	protected function callMacro(string $method, array $parameters): mixed {
		$macro = static::$macros[$method];

		if ($macro instanceof Closure) {
			$macro = $macro->bindTo($this, static::class);
		}

		return $macro(...$parameters);
	}

}

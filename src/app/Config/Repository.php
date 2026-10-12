<?php

namespace WPSPCORELITE\App\Config;

use ArrayAccess;
use Closure;
use InvalidArgumentException;
use WPSPCORELITE\App\Filesystem\Macroable;

/**
 * Config Repository - mô phỏng Illuminate\Config\Repository (Laravel 12) bằng PHP thuần.
 *
 * Truy cập bằng "dot notation":
 *   $config->get('app.name', 'default');
 *   $config->get(['app.name', 'app.env' => 'production']);
 *   $config->set('services.payment.key', 'xxx');
 *   $config['app.debug'];
 */
class Repository implements ArrayAccess {

	use Macroable;

	/** @var array */
	protected $items = [];

	public function __construct(array $items = []) {
		$this->items = $items;
	}

	public function has($key) {
		return static::arrayHas($this->items, $key);
	}

	/**
	 * @param array|string $key
	 * @param mixed        $default giá trị hoặc Closure
	 */
	public function get($key, $default = null) {
		if (is_array($key)) {
			return $this->getMany($key);
		}

		return static::arrayGet($this->items, $key, $default);
	}

	/**
	 * getMany(['a.b', 'c.d' => 'default'])
	 */
	public function getMany($keys) {
		$config = [];

		foreach ($keys as $key => $default) {
			if (is_numeric($key)) {
				[$key, $default] = [$default, null];
			}

			$config[$key] = static::arrayGet($this->items, $key, $default);
		}

		return $config;
	}

	/*
	 * ---
	 * Typed getters (Laravel 11+).
	 * ---
	 */

	public function string($key, $default = null) {
		$value = $this->get($key, $default);

		if (!is_string($value)) {
			throw new InvalidArgumentException(sprintf('Configuration value for key [%s] must be a string, %s given.', $key, gettype($value)));
		}

		return $value;
	}

	public function integer($key, $default = null) {
		$value = $this->get($key, $default);

		if (!is_int($value)) {
			throw new InvalidArgumentException(sprintf('Configuration value for key [%s] must be an integer, %s given.', $key, gettype($value)));
		}

		return $value;
	}

	public function float($key, $default = null) {
		$value = $this->get($key, $default);

		if (!is_float($value)) {
			throw new InvalidArgumentException(sprintf('Configuration value for key [%s] must be a float, %s given.', $key, gettype($value)));
		}

		return $value;
	}

	public function boolean($key, $default = null) {
		$value = $this->get($key, $default);

		if (!is_bool($value)) {
			throw new InvalidArgumentException(sprintf('Configuration value for key [%s] must be a boolean, %s given.', $key, gettype($value)));
		}

		return $value;
	}

	public function array($key, $default = null) {
		$value = $this->get($key, $default);

		if (!is_array($value)) {
			throw new InvalidArgumentException(sprintf('Configuration value for key [%s] must be an array, %s given.', $key, gettype($value)));
		}

		return $value;
	}

	/*
	 * ---
	 * Ghi.
	 * ---
	 */

	/**
	 * set('a.b', 1) hoặc set(['a.b' => 1, 'c' => 2])
	 */
	public function set($key, $value = null) {
		$keys = is_array($key) ? $key : [$key => $value];

		foreach ($keys as $k => $v) {
			static::arraySet($this->items, $k, $v);
		}
	}

	public function prepend($key, $value) {
		$array = $this->get($key, []);

		array_unshift($array, $value);

		$this->set($key, $array);
	}

	public function push($key, $value) {
		$array = $this->get($key, []);

		$array[] = $value;

		$this->set($key, $array);
	}

	public function all() {
		return $this->items;
	}

	/*
	 * ---
	 * ArrayAccess.
	 * ---
	 */

	#[\ReturnTypeWillChange]
	public function offsetExists($key) {
		return $this->has($key);
	}

	#[\ReturnTypeWillChange]
	public function offsetGet($key) {
		return $this->get($key);
	}

	#[\ReturnTypeWillChange]
	public function offsetSet($key, $value) {
		$this->set($key, $value);
	}

	#[\ReturnTypeWillChange]
	public function offsetUnset($key) {
		$this->set($key, null);
	}

	/*
	 * ---
	 * Dot-notation helpers (thay Illuminate\Support\Arr).
	 * ---
	 */

	protected static function value($value) {
		return $value instanceof Closure ? $value() : $value;
	}

	protected static function arrayGet(array $array, $key, $default = null) {
		if ($key === null) {
			return $array;
		}

		if (array_key_exists($key, $array)) {
			return $array[$key];
		}

		if (strpos((string)$key, '.') === false) {
			return static::value($default);
		}

		foreach (explode('.', $key) as $segment) {
			if (is_array($array) && array_key_exists($segment, $array)) {
				$array = $array[$segment];
			}
			elseif ($array instanceof ArrayAccess && $array->offsetExists($segment)) {
				$array = $array[$segment];
			}
			else {
				return static::value($default);
			}
		}

		return $array;
	}

	protected static function arrayHas(array $array, $key) {
		if ($key === null || $key === '') {
			return false;
		}

		if (array_key_exists($key, $array)) {
			return true;
		}

		foreach (explode('.', $key) as $segment) {
			if (is_array($array) && array_key_exists($segment, $array)) {
				$array = $array[$segment];
			}
			else {
				return false;
			}
		}

		return true;
	}

	protected static function arraySet(array &$array, $key, $value) {
		if ($key === null) {
			return $array = $value;
		}

		$keys = explode('.', $key);

		foreach ($keys as $i => $segment) {
			if (count($keys) === 1) {
				break;
			}

			unset($keys[$i]);

			if (!isset($array[$segment]) || !is_array($array[$segment])) {
				$array[$segment] = [];
			}

			$array = &$array[$segment];
		}

		$array[array_shift($keys)] = $value;

		return $array;
	}

}
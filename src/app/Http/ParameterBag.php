<?php

namespace WPSPCORELITE\App\Http;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Stringable;
use Traversable;
use UnexpectedValueException;

/**
 * Symfony-style parameter container used for $request->query, ->request,
 * ->cookies, ->attributes and ->server.
 *
 * Getter có kiểu (getString/getInt/getBoolean...) ném UnexpectedValueException
 * khi giá trị là mảng/object - như Symfony InputBag, thay vì "Array to string conversion".
 */
class ParameterBag implements IteratorAggregate, Countable {

	protected array $parameters = [];

	public function __construct(array $parameters = []) {
		$this->parameters = $parameters;
	}

	/** All parameters, or the array stored under $key. */
	public function all(?string $key = null): array {
		if ($key === null) return $this->parameters;

		$value = $this->parameters[$key] ?? [];
		if (!is_array($value)) {
			throw new UnexpectedValueException(sprintf('Unexpected value for parameter "%s": expecting "array", got "%s".', $key, get_debug_type($value)));
		}
		return $value;
	}

	public function keys(): array {
		return array_keys($this->parameters);
	}

	public function replace(array $parameters = []): void {
		$this->parameters = $parameters;
	}

	public function add(array $parameters = []): void {
		$this->parameters = array_replace($this->parameters, $parameters);
	}

	public function get(string $key, mixed $default = null): mixed {
		return array_key_exists($key, $this->parameters) ? $this->parameters[$key] : $default;
	}

	public function set(string $key, mixed $value): void {
		$this->parameters[$key] = $value;
	}

	public function has(string $key): bool {
		return array_key_exists($key, $this->parameters);
	}

	public function remove(string $key): void {
		unset($this->parameters[$key]);
	}

	public function getAlpha(string $key, string $default = ''): string {
		return (string)preg_replace('/[^[:alpha:]]/', '', $this->getString($key, $default));
	}

	public function getAlnum(string $key, string $default = ''): string {
		return (string)preg_replace('/[^[:alnum:]]/', '', $this->getString($key, $default));
	}

	public function getDigits(string $key, string $default = ''): string {
		return (string)preg_replace('/[^[:digit:]]/', '', $this->getString($key, $default));
	}

	public function getString(string $key, string $default = ''): string {
		$value = $this->get($key, $default);
		if ($value === null) return $default;
		if (!is_scalar($value) && !$value instanceof Stringable) {
			throw new UnexpectedValueException(sprintf('Parameter value "%s" cannot be converted to "string".', $key));
		}
		return (string)$value;
	}

	public function getInt(string $key, int $default = 0): int {
		$value = $this->get($key, $default);
		if ($value === null || $value === '') return $default;
		if (!is_scalar($value)) {
			throw new UnexpectedValueException(sprintf('Parameter value "%s" cannot be converted to "int".', $key));
		}
		return (int)$value;
	}

	public function getFloat(string $key, float $default = 0.0): float {
		$value = $this->get($key, $default);
		if ($value === null || $value === '') return $default;
		if (!is_scalar($value)) {
			throw new UnexpectedValueException(sprintf('Parameter value "%s" cannot be converted to "float".', $key));
		}
		return (float)$value;
	}

	public function getBoolean(string $key, bool $default = false): bool {
		$value = $this->get($key, $default);
		if (!is_scalar($value) && $value !== null) {
			throw new UnexpectedValueException(sprintf('Parameter value "%s" cannot be converted to "bool".', $key));
		}
		return filter_var($value ?? $default, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * @template T of \BackedEnum
	 * @param class-string<T> $class
	 * @return ?T
	 */
	public function getEnum(string $key, string $class, ?\BackedEnum $default = null): ?\BackedEnum {
		$value = $this->get($key);
		if ($value === null || !is_scalar($value) || !is_subclass_of($class, \BackedEnum::class)) return $default;

		try {
			return $class::tryFrom($value) ?? $default;
		}
		catch (\TypeError|\ValueError) {
			return $default;
		}
	}

	public function filter(string $key, mixed $default = null, int $filter = FILTER_DEFAULT, array|int $options = []): mixed {
		$value = $this->get($key, $default);

		if (!is_array($options) && $options) $options = ['flags' => $options];
		if (is_array($value) && !isset($options['flags'])) $options['flags'] = FILTER_REQUIRE_ARRAY;

		return filter_var($value, $filter, $options);
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->parameters);
	}

	public function count(): int {
		return count($this->parameters);
	}

}

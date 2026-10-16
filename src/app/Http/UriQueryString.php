<?php

namespace WPSPCORELITE\App\Http;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Stringable;
use Traversable;

/**
 * Query string của một Uri - tương đương Illuminate\Support\UriQueryString.
 *   Uri::of($url)->query()->get('filter.status'), ->integer('page'), ->boolean('debug') ...
 */
class UriQueryString implements Stringable, JsonSerializable, IteratorAggregate, Countable {

	protected array $data;

	public function __construct(protected Uri $uri) {
		$this->data = Arr::parseQuery((string)$uri->rawQuery());
	}

	public function all(mixed $keys = null): array {
		if ($keys === null) return $this->data;

		$results = [];
		foreach (is_array($keys) ? $keys : func_get_args() as $key) {
			Arr::set($results, $key, Arr::get($this->data, $key));
		}
		return $results;
	}

	public function get(?string $key = null, mixed $default = null): mixed {
		return Arr::get($this->data, $key, $default);
	}

	public function keys(): array {
		return array_keys($this->data);
	}

	public function has(string|array ...$keys): bool {
		foreach ($this->keysFrom($keys) as $key) {
			if (!Arr::has($this->data, $key)) return false;
		}
		return true;
	}

	public function hasAny(string|array ...$keys): bool {
		foreach ($this->keysFrom($keys) as $key) {
			if (Arr::has($this->data, $key)) return true;
		}
		return false;
	}

	public function missing(string|array ...$keys): bool {
		return !$this->has($this->keysFrom($keys));
	}

	public function filled(string|array ...$keys): bool {
		foreach ($this->keysFrom($keys) as $key) {
			if ($this->isEmptyValue($this->get($key))) return false;
		}
		return true;
	}

	public function isNotFilled(string|array ...$keys): bool {
		foreach ($this->keysFrom($keys) as $key) {
			if (!$this->isEmptyValue($this->get($key))) return false;
		}
		return true;
	}

	public function string(string $key, string $default = ''): string {
		$value = $this->get($key, $default);
		return is_scalar($value) ? (string)$value : $default;
	}

	public function integer(string $key, int $default = 0): int {
		$value = $this->get($key, $default);
		return is_scalar($value) ? (int)$value : $default;
	}

	public function float(string $key, float $default = 0.0): float {
		$value = $this->get($key, $default);
		return is_scalar($value) ? (float)$value : $default;
	}

	public function boolean(string $key, bool $default = false): bool {
		$value = $this->get($key, $default);
		return is_scalar($value) && filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	public function array(string $key): array {
		return Arr::wrap($this->get($key));
	}

	public function only(string|array ...$keys): array {
		return Arr::only($this->data, $this->keysFrom($keys));
	}

	public function except(string|array ...$keys): array {
		return Arr::except($this->data, $this->keysFrom($keys));
	}

	public function toArray(): array {
		return $this->data;
	}

	/** Query thô như trong URI. */
	public function value(): string {
		return (string)$this->uri->rawQuery();
	}

	public function decode(): string {
		return rawurldecode($this->value());
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->data);
	}

	public function count(): int {
		return count($this->data);
	}

	public function jsonSerialize(): array {
		return $this->data;
	}

	public function __toString(): string {
		return $this->value();
	}

	protected function keysFrom(array $args): array {
		return is_array($args[0] ?? null) ? $args[0] : $args;
	}

	protected function isEmptyValue(mixed $value): bool {
		return !is_bool($value) && !is_array($value) && trim((string)$value) === '';
	}

}

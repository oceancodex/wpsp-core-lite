<?php

namespace WPSPCORELITE\App\Http;

use ArrayIterator;
use Countable;
use DateTimeImmutable;
use DateTimeInterface;
use IteratorAggregate;
use RuntimeException;
use Stringable;
use Traversable;

/**
 * Case-insensitive header container: $request->headers->get('X-Foo').
 * Keys are stored lower-cased with dashes; each header holds a list of values.
 */
class HeaderBag implements IteratorAggregate, Countable, Stringable {

	/** @var array<string, array<int, string|null>> */
	protected array $headers = [];

	protected ?array $cacheControl = null;

	public function __construct(array $headers = []) {
		foreach ($headers as $key => $values) {
			$this->set((string)$key, $values);
		}
	}

	protected static function normalize(string $key): string {
		return strtr(strtolower($key), '_', '-');
	}

	/** All headers as [name => string[]], or the values of one header. */
	public function all(?string $key = null): array {
		if ($key !== null) return $this->headers[static::normalize($key)] ?? [];
		return $this->headers;
	}

	public function keys(): array {
		return array_keys($this->headers);
	}

	public function replace(array $headers = []): void {
		$this->headers      = [];
		$this->cacheControl = null;
		$this->add($headers);
	}

	public function add(array $headers): void {
		foreach ($headers as $key => $values) {
			$this->set((string)$key, $values);
		}
	}

	/** First value of the header, or $default. */
	public function get(string $key, ?string $default = null): ?string {
		$values = $this->all($key);
		if (!$values) return $default;
		return $values[0] === null ? null : (string)$values[0];
	}

	public function set(string $key, string|int|float|array|Stringable|null $values, bool $replace = true): void {
		$key    = static::normalize($key);
		$values = $values === null ? [null] : array_values(array_map(
			fn($v) => $v === null ? null : (string)$v,
			is_array($values) ? $values : [$values]
		));

		if ($replace || !isset($this->headers[$key])) {
			$this->headers[$key] = $values;
		}
		else {
			$this->headers[$key] = array_merge($this->headers[$key], $values);
		}

		if ($key === 'cache-control') $this->cacheControl = null;
	}

	public function has(string $key): bool {
		return array_key_exists(static::normalize($key), $this->headers);
	}

	public function contains(string $key, string $value): bool {
		return in_array($value, $this->all($key), true);
	}

	public function remove(string $key): void {
		$key = static::normalize($key);
		unset($this->headers[$key]);
		if ($key === 'cache-control') $this->cacheControl = null;
	}

	/** Header dạng ngày (RFC 2822 / HTTP-date). */
	public function getDate(string $key, ?DateTimeInterface $default = null): ?DateTimeImmutable {
		$value = $this->get($key);
		if ($value === null) return $default ? DateTimeImmutable::createFromInterface($default) : null;

		$date = DateTimeImmutable::createFromFormat(DATE_RFC2822, $value)
			?: DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value);
		if (!$date) throw new RuntimeException(sprintf('The "%s" HTTP header is not parseable (%s).', $key, $value));
		return $date;
	}

	public function hasCacheControlDirective(string $key): bool {
		return array_key_exists(strtolower($key), $this->cacheControl());
	}

	/** true cho directive không có giá trị (no-cache), chuỗi cho directive có giá trị (max-age=60). */
	public function getCacheControlDirective(string $key): bool|string|null {
		return $this->cacheControl()[strtolower($key)] ?? null;
	}

	protected function cacheControl(): array {
		if ($this->cacheControl !== null) return $this->cacheControl;

		$directives = [];
		foreach (explode(',', implode(',', array_filter($this->all('cache-control')))) as $part) {
			$part = trim($part);
			if ($part === '') continue;
			$pair                                   = explode('=', $part, 2);
			$directives[strtolower(trim($pair[0]))] = isset($pair[1]) ? trim($pair[1], " \"") : true;
		}
		return $this->cacheControl = $directives;
	}

	public function getIterator(): Traversable {
		return new ArrayIterator($this->headers);
	}

	public function count(): int {
		return count($this->headers);
	}

	public function __toString(): string {
		if (!$this->headers) return '';

		$headers = $this->headers;
		ksort($headers);
		$max     = max(array_map('strlen', array_keys($headers))) + 1;
		$content = '';
		foreach ($headers as $name => $values) {
			$name = ucwords($name, '-');
			foreach ($values as $value) $content .= str_pad($name . ':', $max) . ' ' . $value . "\r\n";
		}
		return $content;
	}

}

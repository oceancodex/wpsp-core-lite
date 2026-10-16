<?php

namespace WPSPCORELITE\App\Http;

use ArrayAccess;
use Closure;

/**
 * Helper mảng tối giản, thay cho data_get / data_set / Arr::* của Illuminate.
 * Dùng chung cho Request, Uri, UriQueryString. Không phụ thuộc thư viện ngoài.
 */
final class Arr {

	public static function value(mixed $value, mixed ...$args): mixed {
		return $value instanceof Closure ? $value(...$args) : $value;
	}

	public static function wrap(mixed $value): array {
		if ($value === null) return [];
		return is_array($value) ? $value : [$value];
	}

	/**
	 * data_get(): hỗ trợ "a.b.c", wildcard "items.*.id", array / ArrayAccess / object.
	 * Key có dấu chấm tồn tại nguyên văn (vd. "user.name") được ưu tiên.
	 */
	public static function get(mixed $target, int|string|array|null $key, mixed $default = null): mixed {
		if ($key === null) return $target;

		if (!is_array($key)) {
			if (is_array($target) && array_key_exists($key, $target)) return $target[$key];
			if (!is_string($key) || !str_contains($key, '.')) {
				if ($target instanceof ArrayAccess && isset($target[$key])) return $target[$key];
				return self::value($default);
			}
			$key = explode('.', $key);
		}

		foreach ($key as $i => $segment) {
			unset($key[$i]);

			if ($segment === '*') {
				if (!is_iterable($target)) return self::value($default);
				$result = [];
				foreach ($target as $item) $result[] = self::get($item, $key);
				if (!in_array('*', $key, true)) return $result;
				return array_merge([], ...array_map(fn($r) => is_array($r) ? $r : [$r], $result));
			}

			if (is_array($target) && array_key_exists($segment, $target)) $target = $target[$segment];
			elseif ($target instanceof ArrayAccess && isset($target[$segment])) $target = $target[$segment];
			elseif (is_object($target) && !$target instanceof ArrayAccess && isset($target->{$segment})) $target = $target->{$segment};
			else return self::value($default);
		}

		return $target;
	}

	public static function has(array $array, int|string $key): bool {
		if (array_key_exists($key, $array)) return true;
		if (!is_string($key) || !str_contains($key, '.')) return false;

		foreach (explode('.', $key) as $segment) {
			if (is_array($array) && array_key_exists($segment, $array)) $array = $array[$segment];
			else return false;
		}
		return true;
	}

	public static function set(array &$array, int|string $key, mixed $value): void {
		if (is_int($key) || !str_contains($key, '.')) {
			$array[$key] = $value;
			return;
		}

		$segments = explode('.', $key);
		$last     = array_pop($segments);
		$current  = &$array;
		foreach ($segments as $segment) {
			if (!isset($current[$segment]) || !is_array($current[$segment])) $current[$segment] = [];
			$current = &$current[$segment];
		}
		$current[$last] = $value;
	}

	public static function forget(array &$array, int|string|array $keys): void {
		foreach ((array)$keys as $key) {
			if (array_key_exists($key, $array)) {
				unset($array[$key]);
				continue;
			}
			if (!is_string($key) || !str_contains($key, '.')) continue;

			$parts   = explode('.', $key);
			$last    = array_pop($parts);
			$current = &$array;
			foreach ($parts as $part) {
				if (!isset($current[$part]) || !is_array($current[$part])) {
					unset($current);
					continue 2;
				}
				$current = &$current[$part];
			}
			unset($current[$last], $current);
		}
	}

	public static function only(array $array, array $keys): array {
		$results = [];
		foreach ($keys as $key) {
			if (self::has($array, $key)) self::set($results, $key, self::get($array, $key));
		}
		return $results;
	}

	public static function except(array $array, array|string|int $keys): array {
		self::forget($array, $keys);
		return $array;
	}

	/** Arr::query(): build query string RFC 3986 (null bị bỏ qua, như http_build_query). */
	public static function query(array $query): string {
		return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
	}

	/**
	 * Parse query string mà KHÔNG làm hỏng tên key như parse_str()
	 * (parse_str đổi "a.b" / "a b" thành "a_b"). Hỗ trợ đầy đủ cú pháp mảng "a[b][]".
	 */
	public static function parseQuery(string $query, string $separator = '&'): array {
		$query = ltrim($query, '?');
		if ($query === '') return [];

		$parts = [];
		foreach (explode($separator, $query) as $pair) {
			if ($pair === '') continue;
			if (($nul = strpos($pair, "\0")) !== false) $pair = substr($pair, 0, $nul);

			$eq  = strpos($pair, '=');
			$key = ltrim(urldecode($eq === false ? $pair : substr($pair, 0, $eq)), ' ');
			$val = $eq === false ? '' : substr($pair, $eq);
			if ($key === '') continue;

			// Mã hoá phần tên (trước "[") sang hex để parse_str không đụng vào.
			$bracket = strpos($key, '[');
			$parts[] = $bracket === false || $bracket === 0
				? bin2hex($key) . $val
				: bin2hex(substr($key, 0, $bracket)) . rawurlencode(substr($key, $bracket)) . $val;
		}

		parse_str(implode('&', $parts), $parsed);

		$result = [];
		foreach ($parsed as $key => $value) {
			$key = (string)$key;
			// parse_str đổi "[" không đóng thành "_" => khôi phục lại.
			if (($pos = strpos($key, '_')) !== false) {
				$result[hex2bin(substr($key, 0, $pos)) . '[' . substr($key, $pos + 1)] = $value;
			}
			else {
				$result[hex2bin($key)] = $value;
			}
		}
		return $result;
	}

}

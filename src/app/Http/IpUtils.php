<?php

namespace WPSPCORELITE\App\Http;

/**
 * Tiện ích IP (tương đương Symfony IpUtils): so khớp IPv4/IPv6 với IP hoặc CIDR,
 * nhận diện IP nội bộ, ẩn danh hoá IP.
 */
final class IpUtils {

	public const PRIVATE_SUBNETS = [
		'127.0.0.0/8', '10.0.0.0/8', '192.168.0.0/16', '172.16.0.0/12', '169.254.0.0/16',
		'0.0.0.0/8', '240.0.0.0/4', '::1/128', 'fc00::/7', 'fe80::/10', '::ffff:0:0/96', '::/128',
	];

	private static array $cache = [];

	/** $ips: một IP / CIDR, hoặc danh sách. */
	public static function checkIp(string $requestIp, string|array $ips): bool {
		foreach ((array)$ips as $ip) {
			if (self::matches($requestIp, (string)$ip)) return true;
		}
		return false;
	}

	public static function isPrivateIp(string $ip): bool {
		return self::checkIp($ip, self::PRIVATE_SUBNETS);
	}

	/** Xoá các byte cuối: IPv4 mặc định 1 byte (1.2.3.4 => 1.2.3.0), IPv6 mặc định 8 byte. */
	public static function anonymize(string $ip, int $v4Bytes = 1, int $v6Bytes = 8): string {
		$wrapped = str_starts_with($ip, '[') && str_ends_with($ip, ']');
		$bin     = @inet_pton($wrapped ? substr($ip, 1, -1) : $ip);
		if ($bin === false) return $ip;

		$len   = strlen($bin);
		$bytes = max(0, min($len === 4 ? $v4Bytes : $v6Bytes, $len));
		if ($bytes > 0) $bin = substr($bin, 0, $len - $bytes) . str_repeat("\0", $bytes);

		$out = (string)inet_ntop($bin);
		return $wrapped ? '[' . $out . ']' : $out;
	}

	private static function matches(string $requestIp, string $ip): bool {
		$cacheKey = $requestIp . '|' . $ip;
		if (isset(self::$cache[$cacheKey])) return self::$cache[$cacheKey];
		if (count(self::$cache) > 1000) self::$cache = [];

		return self::$cache[$cacheKey] = self::compute($requestIp, $ip);
	}

	private static function compute(string $requestIp, string $ip): bool {
		$requestBin = @inet_pton($requestIp);
		if ($requestBin === false) return false;

		$bits = null;
		if (str_contains($ip, '/')) {
			[$ip, $bits] = explode('/', $ip, 2);
			if (!ctype_digit($bits)) return false;
			$bits = (int)$bits;
		}

		$subnetBin = @inet_pton($ip);
		if ($subnetBin === false || strlen($subnetBin) !== strlen($requestBin)) return false;

		$max  = strlen($requestBin) * 8;
		$bits ??= $max;
		if ($bits > $max) return false;
		if ($bits === 0) return true;

		$fullBytes = intdiv($bits, 8);
		if ($fullBytes > 0 && strncmp($requestBin, $subnetBin, $fullBytes) !== 0) return false;

		$rest = $bits % 8;
		if ($rest === 0) return true;

		$mask = (0xFF << (8 - $rest)) & 0xFF;
		return (ord($requestBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
	}

}

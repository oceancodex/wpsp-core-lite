<?php

namespace WPSPCORELITE\App\Cache;

/**
 * Builds every name derived from a plugin's cache prefix, so plugins written by different
 * people can share one WordPress install, one Redis database or one cache folder safely.
 *
 * - The prefix is reduced to [A-Za-z0-9_-].
 * - Keys are "{prefix}.{key}": the "." can never appear inside a prefix, so "plugin_a_."
 *   never matches "plugin_a_pro_." (flush() of one plugin can't touch the other).
 *
 * prefix "wpsp_lite_" =>
 *   keyPrefix() "wpsp_lite_."      transient / object / phpfastcache keys, lock rows
 *   base()      "wpsp_lite"        folder name, object cache groups, event hook
 */
final class CachePrefix {

	public const DEFAULT_PREFIX = 'wpsp_lite_';
	public const SEPARATOR      = '.';

	public static function sanitize(?string $prefix): string {
		return (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $prefix);
	}

	/**
	 * Prefix used inside keys: sanitized prefix + ".", or "" for an empty prefix.
	 */
	public static function keyPrefix(?string $prefix): string {
		$prefix = static::sanitize($prefix);

		return $prefix === '' ? '' : $prefix . static::SEPARATOR;
	}

	/**
	 * Name used for folders, groups and hooks: sanitized prefix without trailing "_" / "-".
	 */
	public static function base(?string $prefix): string {
		$base = rtrim(static::sanitize($prefix), '_-');

		return $base === '' ? rtrim(static::DEFAULT_PREFIX, '_') : $base;
	}

	/**
	 * Random namespace version used by flush() on shared backends.
	 */
	public static function newVersion(): string {
		return bin2hex(random_bytes(4));
	}

}

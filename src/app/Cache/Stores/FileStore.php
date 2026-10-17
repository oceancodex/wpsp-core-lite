<?php

namespace WPSPCORELITE\App\Cache\Stores;

use WPSPCORELITE\App\Cache\CachePrefix;
use WPSPCORELITE\App\Cache\Concerns\RetrievesMultipleKeys;
use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Locks\FileLock;

/**
 * Mirror of Illuminate\Cache\FileStore (driver "file"), without Illuminate\Filesystem.
 *
 * File: {directory}/ab/cd/{sha1}.php = "<?php exit; ?>\n" + 10-digit expiration + serialized value.
 * The PHP header keeps the content private even on Nginx (no .htaccess needed).
 * add() and increment() hold an exclusive flock, so they are atomic (FileLock relies on add()).
 * Unlike Laravel's FileStore, tags are supported (same API on every driver).
 */
class FileStore extends TaggableStore implements LockProvider {

	use RetrievesMultipleKeys;

	protected const HEADER       = "<?php exit; ?>\n";
	protected const FOREVER_TIME = 9999999999;

	protected string  $directory;
	protected ?string $lockDirectory = null;
	protected ?int    $filePermission;
	protected string  $prefix;

	public function __construct(string $directory, ?int $filePermission = null, string $prefix = '') {
		$this->directory      = rtrim($directory, '/\\');
		$this->filePermission = $filePermission;
		$this->prefix         = CachePrefix::keyPrefix($prefix);
	}

	public function get($key): mixed {
		return $this->getPayload($key)['data'] ?? null;
	}

	public function put($key, $value, $seconds): bool {
		$this->ensureCacheDirectoryExists($path = $this->path($key));

		$result = @file_put_contents($path, $this->contents($value, $this->expiration((int) $seconds)), LOCK_EX);

		if ($result !== false && $result > 0) {
			$this->ensurePermissionsAreCorrect($path);
			return true;
		}

		return false;
	}

	/**
	 * Store an item only if it doesn't exist (atomic: exclusive flock).
	 */
	public function add($key, $value, $seconds): bool {
		return $this->withExclusiveLock($key, function ($handle, ?array $payload) use ($key, $value, $seconds) {
			if ($payload !== null) {
				return false;
			}

			$this->writeHandle($handle, $this->contents($value, $this->expiration((int) $seconds)));

			return true;
		});
	}

	public function increment($key, $value = 1): int|bool {
		return $this->withExclusiveLock($key, function ($handle, ?array $payload) use ($value) {
			$new    = ((int) ($payload['data'] ?? 0)) + (int) $value;
			$expire = $payload['expire'] ?? static::FOREVER_TIME;

			$this->writeHandle($handle, $this->contents($new, $expire));

			return $new;
		});
	}

	public function decrement($key, $value = 1): int|bool {
		return $this->increment($key, (int) $value * -1);
	}

	public function forever($key, $value): bool {
		return $this->put($key, $value, 0);
	}

	public function touch($key, $seconds): bool {
		return $this->withExclusiveLock($key, function ($handle, ?array $payload) use ($seconds) {
			if ($payload === null) {
				return false;
			}

			$this->writeHandle($handle, $this->contents($payload['data'], $this->expiration((int) $seconds)));

			return true;
		});
	}

	public function forget($key): bool {
		$path = $this->path($key);

		return is_file($path) && @unlink($path);
	}

	/**
	 * Delete every cache directory (the root and its protection files stay).
	 */
	public function flush(): bool {
		if (!is_dir($this->directory)) {
			return false;
		}

		foreach (glob($this->directory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
			if (!$this->deleteDirectory($directory) || is_dir($directory)) {
				return false;
			}
		}

		return true;
	}

	public function lock($name, $seconds = 0, $owner = null): LockContract {
		$directory = $this->lockDirectory ?? $this->directory;

		return new FileLock(new static($directory, $this->filePermission, $this->prefix), $name, $seconds, $owner);
	}

	public function restoreLock($name, $owner): LockContract {
		return $this->lock($name, 0, $owner);
	}

	public function getDirectory(): string {
		return $this->directory;
	}

	public function setDirectory(string $directory): static {
		$this->directory = rtrim($directory, '/\\');
		return $this;
	}

	public function setLockDirectory(?string $lockDirectory): static {
		$this->lockDirectory = $lockDirectory === null ? null : rtrim($lockDirectory, '/\\');
		return $this;
	}

	public function getPrefix(): string {
		return $this->prefix;
	}

	/**
	 * Full path for the given cache key.
	 */
	public function path($key): string {
		$hash  = sha1($this->prefix . $key);
		$parts = array_slice(str_split($hash, 2), 0, 2);

		return $this->directory . '/' . implode('/', $parts) . '/' . $hash . '.php';
	}

	/*
	 *
	 */

	/**
	 * ['data' => mixed, 'time' => seconds left, 'expire' => timestamp] or ['data' => null, 'time' => null].
	 */
	protected function getPayload($key): array {
		$path     = $this->path($key);
		$contents = $this->readFile($path);

		if ($contents === null) {
			return ['data' => null, 'time' => null];
		}

		$payload = $this->parse($contents);

		if ($payload === null) {
			$this->forget($key);
			return ['data' => null, 'time' => null];
		}

		return $payload;
	}

	protected function parse(string $contents): ?array {
		$headerLength = strlen(static::HEADER);

		if (strlen($contents) < $headerLength + 10 || strncmp($contents, static::HEADER, $headerLength) !== 0) {
			return null;
		}

		$expire = (int) substr($contents, $headerLength, 10);

		if (time() >= $expire) {
			return null;
		}

		$serialized = substr($contents, $headerLength + 10);

		try {
			$data = unserialize($serialized);
		}
		catch (\Throwable $e) {
			return null;
		}

		if ($data === false && $serialized !== serialize(false)) {
			return null;
		}

		return ['data' => $data, 'time' => $expire - time(), 'expire' => $expire];
	}

	/**
	 * Open the file with an exclusive lock and call $callback($handle, ?payload).
	 */
	protected function withExclusiveLock($key, callable $callback): mixed {
		$this->ensureCacheDirectoryExists($path = $this->path($key));

		$handle = @fopen($path, 'c+');

		if ($handle === false) {
			return false;
		}

		try {
			if (!flock($handle, LOCK_EX)) {
				return false;
			}

			$contents = stream_get_contents($handle);
			$payload  = ($contents === false || $contents === '') ? null : $this->parse($contents);

			$result = $callback($handle, $payload);

			fflush($handle);
			flock($handle, LOCK_UN);

			$this->ensurePermissionsAreCorrect($path);

			return $result;
		}
		finally {
			fclose($handle);
		}
	}

	protected function writeHandle($handle, string $contents): void {
		ftruncate($handle, 0);
		rewind($handle);
		fwrite($handle, $contents);
	}

	protected function readFile(string $path): ?string {
		if (!is_file($path)) {
			return null;
		}

		$handle = @fopen($path, 'rb');

		if ($handle === false) {
			return null;
		}

		try {
			if (!flock($handle, LOCK_SH)) {
				return null;
			}

			clearstatcache(true, $path);
			$contents = stream_get_contents($handle);
			flock($handle, LOCK_UN);

			return $contents === false ? null : $contents;
		}
		finally {
			fclose($handle);
		}
	}

	protected function contents($value, int $expiration): string {
		return static::HEADER . str_pad((string) $expiration, 10, '0', STR_PAD_LEFT) . serialize($value);
	}

	protected function expiration(int $seconds): int {
		$time = time() + $seconds;

		return ($seconds === 0 || $time > static::FOREVER_TIME) ? static::FOREVER_TIME : $time;
	}

	protected function ensureCacheDirectoryExists(string $path): void {
		$directory = dirname($path);

		if (!is_dir($directory)) {
			@mkdir($directory, 0777, true);
			@chmod($directory, 0777 & ~umask());
		}

		$this->protectDirectory($this->directory);
	}

	/**
	 * index.php + .htaccess at the cache root (defence in depth on Apache).
	 */
	protected function protectDirectory(string $directory): void {
		static $protected = [];

		if (isset($protected[$directory]) || !is_dir($directory)) {
			return;
		}

		$protected[$directory] = true;

		if (!is_file($directory . '/index.php')) {
			@file_put_contents($directory . '/index.php', "<?php\n// Silence is golden.\n");
		}

		if (!is_file($directory . '/.htaccess')) {
			@file_put_contents($directory . '/.htaccess', "Require all denied\nDeny from all\n");
		}
	}

	protected function ensurePermissionsAreCorrect(string $path): void {
		if ($this->filePermission === null || (fileperms($path) & 0777) === $this->filePermission) {
			return;
		}

		@chmod($path, $this->filePermission);
	}

	protected function deleteDirectory(string $directory): bool {
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ($items as $item) {
			$item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		}

		return @rmdir($directory);
	}

}

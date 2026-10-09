<?php

namespace WPSPCORELITE\App\Filesystem;

use ErrorException;
use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Package-free port of Illuminate\Filesystem\Filesystem (Laravel 12).
 * No Illuminate / Symfony dependency.
 *
 * Differences vs Laravel:
 *  - lines() returns a Generator instead of LazyCollection.
 *  - files()/allFiles()/allDirectories() return WPSPCORELITE SplFileInfo
 *    (same API as Symfony's: getRelativePath(), getRelativePathname(), getContents()).
 *  - guessExtension() uses a built-in mime map instead of Symfony MimeTypes.
 */
class Filesystem {

	use Macroable;

	/**
	 * Directory names ignored by the finder-style methods (same as Symfony Finder::ignoreVCS).
	 */
	protected static $vcsPatterns = ['.svn', '_svn', 'CVS', '_darcs', '.arch-params', '.monotone', '.bzr', '.git', '.hg'];

	/*
	 * ------------------------------------------------------------
	 * Existence / reading
	 * ------------------------------------------------------------
	 */

	public function exists($path) {
		return file_exists($path);
	}

	public function missing($path) {
		return !$this->exists($path);
	}

	/**
	 * @throws FileNotFoundException
	 */
	public function get($path, $lock = false) {
		if ($this->isFile($path)) {
			return $lock ? $this->sharedGet($path) : file_get_contents($path);
		}

		throw new FileNotFoundException("File does not exist at path {$path}.");
	}

	/**
	 * @throws FileNotFoundException
	 */
	public function json($path, $flags = 0, $lock = false) {
		return json_decode($this->get($path, $lock), true, 512, $flags);
	}

	public function sharedGet($path) {
		$contents = '';

		$handle = fopen($path, 'rb');

		if ($handle) {
			try {
				if (flock($handle, LOCK_SH)) {
					clearstatcache(true, $path);

					$contents = fread($handle, $this->size($path) ?: 1);

					flock($handle, LOCK_UN);
				}
			}
			finally {
				fclose($handle);
			}
		}

		return $contents;
	}

	/**
	 * @throws FileNotFoundException
	 */
	public function getRequire($path, array $data = []) {
		if ($this->isFile($path)) {
			$__path = $path;
			$__data = $data;

			return (static function () use ($__path, $__data) {
				extract($__data, EXTR_SKIP);

				return require $__path;
			})();
		}

		throw new FileNotFoundException("File does not exist at path {$path}.");
	}

	/**
	 * @throws FileNotFoundException
	 */
	public function requireOnce($path, array $data = []) {
		if ($this->isFile($path)) {
			$__path = $path;
			$__data = $data;

			return (static function () use ($__path, $__data) {
				extract($__data, EXTR_SKIP);

				return require_once $__path;
			})();
		}

		throw new FileNotFoundException("File does not exist at path {$path}.");
	}

	/**
	 * Read a file line by line, lazily.
	 *
	 * @return \Generator<int, string>
	 * @throws FileNotFoundException
	 */
	public function lines($path) {
		if (!$this->isFile($path)) {
			throw new FileNotFoundException("File does not exist at path {$path}.");
		}

		return (static function () use ($path) {
			$file = new \SplFileObject($path);

			$file->setFlags(\SplFileObject::DROP_NEW_LINE);

			while (!$file->eof()) {
				yield $file->fgets();
			}
		})();
	}

	public function hash($path, $algorithm = 'md5') {
		return hash_file($algorithm, $path);
	}

	/*
	 * ------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------
	 */

	public function put($path, $contents, $lock = false) {
		return file_put_contents($path, $contents, $lock ? LOCK_EX : 0);
	}

	/**
	 * Write the contents of a file, replacing it atomically if it already exists.
	 */
	public function replace($path, $content, $mode = null) {
		// If the path already exists and is a symlink, get the real path...
		clearstatcache(true, $path);

		$path = realpath($path) ?: $path;

		$tempPath = tempnam(dirname($path), basename($path));

		// Fix permissions of tempPath because `tempnam()` creates it with permissions set to 0600...
		if (!is_null($mode)) {
			chmod($tempPath, $mode);
		}
		else {
			chmod($tempPath, 0777 - umask());
		}

		file_put_contents($tempPath, $content);

		rename($tempPath, $path);
	}

	public function replaceInFile($search, $replace, $path) {
		file_put_contents($path, str_replace($search, $replace, file_get_contents($path)));
	}

	public function prepend($path, $data) {
		if ($this->exists($path)) {
			return $this->put($path, $data . $this->get($path));
		}

		return $this->put($path, $data);
	}

	public function append($path, $data, $lock = false) {
		return file_put_contents($path, $data, FILE_APPEND | ($lock ? LOCK_EX : 0));
	}

	/**
	 * Get or set UNIX mode of a file or directory.
	 *
	 * @return mixed  bool when setting, string (e.g. "0644") when getting
	 */
	public function chmod($path, $mode = null) {
		if ($mode) {
			return chmod($path, $mode);
		}

		return substr(sprintf('%o', fileperms($path)), -4);
	}

	/**
	 * Delete the file(s) at the given path(s).
	 *
	 * @param string|array $paths
	 */
	public function delete($paths) {
		$paths = is_array($paths) ? $paths : func_get_args();

		$success = true;

		foreach ($paths as $path) {
			try {
				if (@unlink($path)) {
					clearstatcache(false, $path);
				}
				else {
					$success = false;
				}
			}
			catch (ErrorException $e) {
				$success = false;
			}
		}

		return $success;
	}

	public function move($path, $target) {
		return rename($path, $target);
	}

	public function copy($path, $target) {
		return copy($path, $target);
	}

	/**
	 * Create a symlink to the target file or directory. On Windows, a hard link
	 * (file) or junction (directory) is created instead.
	 */
	public function link($target, $link) {
		if (!static::isWindows()) {
			if (function_exists('symlink')) {
				return symlink($target, $link);
			}

			return exec('ln -s ' . escapeshellarg($target) . ' ' . escapeshellarg($link)) !== false;
		}

		$mode = $this->isDirectory($target) ? 'J' : 'H';

		exec("mklink /{$mode} " . escapeshellarg($link) . ' ' . escapeshellarg($target));
	}

	/**
	 * Create a relative symlink to the target file or directory.
	 */
	public function relativeLink($target, $link) {
		$relativeTarget = $this->makePathRelative($target, dirname($link));

		$this->link($this->isFile($target) ? rtrim($relativeTarget, '/') : $relativeTarget, $link);
	}

	/*
	 * ------------------------------------------------------------
	 * Path info
	 * ------------------------------------------------------------
	 */

	public function name($path) {
		return pathinfo($path, PATHINFO_FILENAME);
	}

	public function basename($path) {
		return pathinfo($path, PATHINFO_BASENAME);
	}

	public function dirname($path) {
		return pathinfo($path, PATHINFO_DIRNAME);
	}

	public function extension($path) {
		return pathinfo($path, PATHINFO_EXTENSION);
	}

	/**
	 * Guess the file extension from the mime-type of a given file.
	 *
	 * @return string|null
	 */
	public function guessExtension($path) {
		$mime = $this->mimeType($path);

		if (!$mime) {
			return null;
		}

		return MimeTypes::getExtension($mime);
	}

	public function type($path) {
		return filetype($path);
	}

	/**
	 * @return string|false
	 */
	public function mimeType($path) {
		if (function_exists('finfo_open')) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			$mime  = finfo_file($finfo, $path);
			finfo_close($finfo);

			return $mime;
		}

		if (function_exists('mime_content_type')) {
			return mime_content_type($path);
		}

		return MimeTypes::getMimeType($this->extension($path)) ?? false;
	}

	public function size($path) {
		return filesize($path);
	}

	public function lastModified($path) {
		return filemtime($path);
	}

	/*
	 * ------------------------------------------------------------
	 * Checks
	 * ------------------------------------------------------------
	 */

	public function isDirectory($directory) {
		return is_dir($directory);
	}

	public function isEmptyDirectory($directory, $ignoreDotFiles = false) {
		foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
			$name = $item->getFilename();

			if ($ignoreDotFiles && strpos($name, '.') === 0) {
				continue;
			}

			if ($item->isDir() && in_array($name, static::$vcsPatterns, true)) {
				continue;
			}

			return false;
		}

		return true;
	}

	public function isReadable($path) {
		return is_readable($path);
	}

	public function isWritable($path) {
		return is_writable($path);
	}

	public function hasSameHash($firstFile, $secondFile) {
		$hash = @md5_file($firstFile);

		return $hash && hash_equals($hash, (string)@md5_file($secondFile));
	}

	public function isFile($file) {
		return is_file($file);
	}

	/*
	 * ------------------------------------------------------------
	 * Listing
	 * ------------------------------------------------------------
	 */

	public function glob($pattern, $flags = 0) {
		return glob($pattern, $flags) ?: [];
	}

	/**
	 * Get all of the files in the given directory (non-recursive), sorted by name.
	 *
	 * @return SplFileInfo[]
	 */
	public function files($directory, $hidden = false) {
		return $this->find($directory, true, false, $hidden);
	}

	/**
	 * Get all of the files from the given directory (recursive), sorted by name.
	 *
	 * @return SplFileInfo[]
	 */
	public function allFiles($directory, $hidden = false) {
		return $this->find($directory, true, true, $hidden);
	}

	/**
	 * Get all of the directory paths within a given directory (non-recursive).
	 *
	 * @return string[]
	 */
	public function directories($directory) {
		$directories = [];

		foreach ($this->find($directory, false, false, false) as $dir) {
			$directories[] = $dir->getPathname();
		}

		return $directories;
	}

	/**
	 * Get all the directories within a given directory (recursive).
	 *
	 * @return SplFileInfo[]
	 */
	public function allDirectories($directory) {
		return $this->find($directory, false, true, false);
	}

	/*
	 * ------------------------------------------------------------
	 * Directories
	 * ------------------------------------------------------------
	 */

	public function ensureDirectoryExists($path, $mode = 0755, $recursive = true) {
		if (!$this->isDirectory($path)) {
			$this->makeDirectory($path, $mode, $recursive);
		}
	}

	public function makeDirectory($path, $mode = 0755, $recursive = false, $force = false) {
		if ($force) {
			return @mkdir($path, $mode, $recursive);
		}

		return mkdir($path, $mode, $recursive);
	}

	public function moveDirectory($from, $to, $overwrite = false) {
		if ($overwrite && $this->isDirectory($to) && !$this->deleteDirectory($to)) {
			return false;
		}

		return @rename($from, $to) === true;
	}

	public function copyDirectory($directory, $destination, $options = null) {
		if (!$this->isDirectory($directory)) {
			return false;
		}

		$options = $options ?: FilesystemIterator::SKIP_DOTS;

		// If the destination directory does not actually exist, we will go ahead and
		// create it recursively, which just gets the destination prepared to copy
		// the files over. Once we make the directory we'll proceed the copying.
		$this->ensureDirectoryExists($destination, 0777);

		$items = new FilesystemIterator($directory, $options);

		foreach ($items as $item) {
			$target = $destination . '/' . $item->getBasename();

			if ($item->isDir()) {
				if (!$this->copyDirectory($item->getPathname(), $target, $options)) {
					return false;
				}
			}
			elseif (!$this->copy($item->getPathname(), $target)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Recursively delete a directory. The directory itself may be optionally preserved.
	 */
	public function deleteDirectory($directory, $preserve = false) {
		if (!$this->isDirectory($directory)) {
			return false;
		}

		$items = new FilesystemIterator($directory);

		foreach ($items as $item) {
			// Recurse into real directories; symlinked directories are only unlinked.
			if ($item->isDir() && !$item->isLink()) {
				$this->deleteDirectory($item->getPathname());
			}
			else {
				$this->delete($item->getPathname());
			}
		}

		unset($items);

		if (!$preserve) {
			@rmdir($directory);
		}

		return true;
	}

	/**
	 * Remove all of the directories within a given directory.
	 */
	public function deleteDirectories($directory) {
		$allDirectories = $this->directories($directory);

		if (!empty($allDirectories)) {
			foreach ($allDirectories as $directoryName) {
				$this->deleteDirectory($directoryName);
			}

			return true;
		}

		return false;
	}

	/**
	 * Empty the specified directory of all files and folders.
	 */
	public function cleanDirectory($directory) {
		return $this->deleteDirectory($directory, true);
	}

	/*
	 * ------------------------------------------------------------
	 * Conditionable (when / unless)
	 * ------------------------------------------------------------
	 */

	public function when($value, ?callable $callback = null, ?callable $default = null) {
		$value = $value instanceof \Closure ? $value($this) : $value;

		if ($value) {
			return $callback ? ($callback($this, $value) ?? $this) : $this;
		}

		return $default ? ($default($this, $value) ?? $this) : $this;
	}

	public function unless($value, ?callable $callback = null, ?callable $default = null) {
		$value = $value instanceof \Closure ? $value($this) : $value;

		if (!$value) {
			return $callback ? ($callback($this, $value) ?? $this) : $this;
		}

		return $default ? ($default($this, $value) ?? $this) : $this;
	}

	/*
	 * ------------------------------------------------------------
	 * Internals (replacements for Symfony Finder / Filesystem)
	 * ------------------------------------------------------------
	 */

	/**
	 * Finder-like scan: depth 0 or recursive, files or directories,
	 * ignores VCS dirs, optionally ignores dot files, sorted by pathname.
	 *
	 * @return SplFileInfo[]
	 * @throws \InvalidArgumentException
	 */
	protected function find($directory, $wantFiles, $recursive, $hidden) {
		if (!is_dir($directory)) {
			throw new \InvalidArgumentException(sprintf('The "%s" directory does not exist.', $directory));
		}

		$directory = rtrim($directory, '/\\');
		$vcs       = static::$vcsPatterns;

		$accept = static function (\SplFileInfo $item) use ($hidden, $vcs) {
			$name = $item->getFilename();

			if ($item->isDir() && in_array($name, $vcs, true)) {
				return false;
			}

			if (!$hidden && strpos($name, '.') === 0) {
				return false;
			}

			return true;
		};

		$flags = FilesystemIterator::SKIP_DOTS
			| FilesystemIterator::CURRENT_AS_FILEINFO
			| FilesystemIterator::KEY_AS_PATHNAME;

		if ($recursive) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator($directory, $flags),
					static function ($current) use ($accept) {
						return $accept($current);
					}
				),
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
		}
		else {
			$iterator = new \CallbackFilterIterator(
				new FilesystemIterator($directory, $flags),
				static function ($current) use ($accept) {
					return $accept($current);
				}
			);
		}

		$results    = [];
		$baseLength = strlen($directory) + 1;

		foreach ($iterator as $item) {
			if ($wantFiles ? !$item->isFile() : !$item->isDir()) {
				continue;
			}

			$pathname         = $item->getPathname();
			$relativePathname = substr($pathname, $baseLength);
			$relativePath     = dirname($relativePathname);

			if ($relativePath === '.') {
				$relativePath = '';
			}

			$results[] = new SplFileInfo($pathname, $relativePath, $relativePathname);
		}

		usort($results, static function ($a, $b) {
			return strcmp($a->getPathname(), $b->getPathname());
		});

		return $results;
	}

	/**
	 * Port of Symfony\Component\Filesystem\Filesystem::makePathRelative().
	 * Returns the path of $endPath relative to $startPath (directory), with trailing slash.
	 */
	protected function makePathRelative($endPath, $startPath) {
		$normalize = static function ($path) {
			$path = str_replace('\\', '/', $path);

			$prefix = '';
			if (preg_match('#^([a-zA-Z]:)?/#', $path, $m)) {
				$prefix = strtolower($m[0]);
				$path   = substr($path, strlen($m[0]));
			}

			$parts = [];
			foreach (explode('/', $path) as $segment) {
				if ($segment === '' || $segment === '.') {
					continue;
				}
				if ($segment === '..' && !empty($parts) && end($parts) !== '..') {
					array_pop($parts);
					continue;
				}
				$parts[] = $segment;
			}

			return [$prefix, $parts];
		};

		[$startPrefix, $startParts] = $normalize($startPath);
		[$endPrefix, $endParts] = $normalize($endPath);

		// Different drives on Windows: no relative path possible.
		if ($startPrefix !== $endPrefix) {
			return rtrim(str_replace('\\', '/', $endPath), '/') . '/';
		}

		$common = 0;
		$max    = min(count($startParts), count($endParts));

		while ($common < $max && $startParts[$common] === $endParts[$common]) {
			$common++;
		}

		$up       = str_repeat('../', count($startParts) - $common);
		$down     = implode('/', array_slice($endParts, $common));
		$relative = $up . ($down !== '' ? $down . '/' : '');

		return $relative === '' ? './' : $relative;
	}

	protected static function isWindows() {
		return PHP_OS_FAMILY === 'Windows';
	}

}

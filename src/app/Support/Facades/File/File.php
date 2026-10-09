<?php

namespace WPSPCORELITE\App\Support\Facades\File;

use WPSPCORELITE\App\Filesystem\Filesystem;
use WPSPCORELITE\App\Filesystem\SplFileInfo;

/**
 * Static proxy ("facade") for Filesystem — same role as Illuminate\Support\Facades\File.
 *
 * Usage:
 *   File::exists($path);
 *   File::put($path, 'content', true);
 *   File::allFiles($dir);
 *   File::swap(new FakeFilesystem());   // for testing
 *
 * @method static bool exists(string $path)
 * @method static bool missing(string $path)
 * @method static string get(string $path, bool $lock = false)
 * @method static mixed json(string $path, int $flags = 0, bool $lock = false)
 * @method static string sharedGet(string $path)
 * @method static mixed getRequire(string $path, array $data = [])
 * @method static mixed requireOnce(string $path, array $data = [])
 * @method static \Generator lines(string $path)
 * @method static string|false hash(string $path, string $algorithm = 'md5')
 * @method static int|false put(string $path, string $contents, bool $lock = false)
 * @method static void replace(string $path, string $content, int|null $mode = null)
 * @method static void replaceInFile(array|string $search, array|string $replace, string $path)
 * @method static int|false prepend(string $path, string $data)
 * @method static int|false append(string $path, string $data, bool $lock = false)
 * @method static mixed chmod(string $path, int|null $mode = null)
 * @method static bool delete(string|array $paths)
 * @method static bool move(string $path, string $target)
 * @method static bool copy(string $path, string $target)
 * @method static bool|null link(string $target, string $link)
 * @method static void relativeLink(string $target, string $link)
 * @method static string name(string $path)
 * @method static string basename(string $path)
 * @method static string dirname(string $path)
 * @method static string extension(string $path)
 * @method static string|null guessExtension(string $path)
 * @method static string|false type(string $path)
 * @method static string|false mimeType(string $path)
 * @method static int|false size(string $path)
 * @method static int|false lastModified(string $path)
 * @method static bool isDirectory(string $directory)
 * @method static bool isEmptyDirectory(string $directory, bool $ignoreDotFiles = false)
 * @method static bool isReadable(string $path)
 * @method static bool isWritable(string $path)
 * @method static bool hasSameHash(string $firstFile, string $secondFile)
 * @method static bool isFile(string $file)
 * @method static array glob(string $pattern, int $flags = 0)
 * @method static SplFileInfo[] files(string $directory, bool $hidden = false)
 * @method static SplFileInfo[] allFiles(string $directory, bool $hidden = false)
 * @method static string[] directories(string $directory)
 * @method static SplFileInfo[] allDirectories(string $directory)
 * @method static void ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true)
 * @method static bool makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false)
 * @method static bool moveDirectory(string $from, string $to, bool $overwrite = false)
 * @method static bool copyDirectory(string $directory, string $destination, int|null $options = null)
 * @method static bool deleteDirectory(string $directory, bool $preserve = false)
 * @method static bool deleteDirectories(string $directory)
 * @method static bool cleanDirectory(string $directory)
 * @method static void macro(string $name, object|callable $macro)
 * @method static bool hasMacro(string $name)
 *
 * @see Filesystem
 */
class File {

	/** @var Filesystem|null */
	protected static $instance;

	public static function instance() {
		if (!static::$instance) {
			static::$instance = new Filesystem();
		}

		return static::$instance;
	}

	/**
	 * Replace the underlying instance (e.g. a mock in tests).
	 */
	public static function swap(Filesystem $filesystem) {
		static::$instance = $filesystem;
	}

	public static function __callStatic($method, $arguments) {
		$instance = static::instance();

		// Macro registration methods are static on Filesystem.
		if (in_array($method, ['macro', 'mixin', 'hasMacro', 'flushMacros'], true)) {
			return Filesystem::$method(...$arguments);
		}

		return $instance->$method(...$arguments);
	}

}

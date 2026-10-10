<?php

namespace WPSPCORELITE\App\Support\Facades\File;

use WPSPCORELITE\App\Filesystem\Filesystem as FilesystemCore;
use WPSPCORELITE\BaseInstances;

/**
 * Facade cho 'files' - mô phỏng Illuminate\Support\Facades\File.
 *
 * Gọi static:  File::exists($path), File::get($path), File::put($path, $content), File::allFiles($dir)
 * Lấy object:  File::instance() hoặc $this->funcs->_getApplication('files')
 *
 * Không dùng class này làm type-hint cho DI; hãy type-hint
 * \WPSPCORELITE\App\Filesystem\Filesystem (giống Laravel: type-hint Illuminate\Filesystem\Filesystem).
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
 * @method static \WPSPCORELITE\App\Filesystem\SplFileInfo[] files(string $directory, bool $hidden = false)
 * @method static \WPSPCORELITE\App\Filesystem\SplFileInfo[] allFiles(string $directory, bool $hidden = false)
 * @method static string[] directories(string $directory)
 * @method static \WPSPCORELITE\App\Filesystem\SplFileInfo[] allDirectories(string $directory)
 * @method static void ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true)
 * @method static bool makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false)
 * @method static bool moveDirectory(string $from, string $to, bool $overwrite = false)
 * @method static bool copyDirectory(string $directory, string $destination, int|null $options = null)
 * @method static bool deleteDirectory(string $directory, bool $preserve = false)
 * @method static bool deleteDirectories(string $directory)
 * @method static bool cleanDirectory(string $directory)
 * @method static mixed when($value = null, ?callable $callback = null, ?callable $default = null)
 * @method static mixed unless($value = null, ?callable $callback = null, ?callable $default = null)
 * @method static void macro(string $name, object|callable $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \WPSPCORELITE\App\Filesystem\Filesystem
 */
abstract class File extends BaseInstances {

	private ?FilesystemCore $facade = null;

	/*
	 *
	 */

	public function getFacade(): ?FilesystemCore {
		return $this->facade;
	}

	public function setFacade() {
		$this->facade = $this->funcs->_getApplication('files');
	}

	/*
	 *
	 */

	public function __call($method, $arguments) {
		return static::__callStatic($method, $arguments);
	}

	public static function __callStatic($method, $arguments) {
		$instance = static::wpspInstance();

		$underlineMethod = '_' . $method;
		if (method_exists($instance, $underlineMethod)) {
			return $instance->$underlineMethod(...$arguments);
		}

		return $instance->getFacade()?->$method(...$arguments);
	}

}
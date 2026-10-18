<?php
/**
 * Created by PhpStorm.
 * User: Khanh
 * Date: 14/10/2026
 * Time: 7:45 CH
 */

namespace WPSPCORELITE\App\Config;

use Noodlehaus\AbstractConfig;
use Noodlehaus\Config as NoodlehausConfig;
use WPSPCORELITE\BaseInstances;

/**
 * Wrapper cho hassankhan/config, dùng như config repository của Laravel.
 *
 *   // AppServiceProvider
 *   $this->app->instance('config', Config::instance());
 *
 *   config('app.name');                    // helper tự viết, gọi Config::instance()
 *   config(['app.debug' => true]);         // set
 *   Config::instance()->get('app.name');
 *   app('config')['app.name'];             // ArrayAccess
 *
 * Mỗi file trong thư mục config thành một key theo tên file, giống Laravel:
 *   config/app.php            → app.*
 *   config/services/mail.php  → services.mail.*
 *
 * Hỗ trợ .php, .json, .ini, .xml, .yml/.yaml (yaml cần symfony/yaml).
 */
class Config extends BaseInstances implements \ArrayAccess {

	/** @var AbstractConfig|null */
	protected $repository = null;

	/** @var string[] */
	protected $configPaths = [];

	/** @var string[] */
	protected $extensions = ['php', 'json', 'ini', 'xml', 'yml', 'yaml'];

	/*
	 * ---------------------------------------------------------------
	 * Repository API (cùng tên method với config repository của Laravel)
	 * ---------------------------------------------------------------
	 */

	/**
	 * @param string $key
	 */
	public function has($key) {
		return $this->repository()->has($key);
	}

	/**
	 * @param array|string $key
	 * @param mixed        $default
	 */
	public function get($key, $default = null) {
		if (is_array($key)) {
			return $this->getMany($key);
		}

		$value = $this->repository()->get($key, $default);

		return $value instanceof \Closure && $default === $value ? $value() : $value;
	}

	/**
	 * Lấy nhiều key một lúc, giống Laravel:
	 *   getMany(['app.name', 'app.url' => 'http://localhost'])
	 */
	public function getMany($keys) {
		$config = [];

		foreach ($keys as $key => $default) {
			if (is_numeric($key)) {
				[$key, $default] = [$default, null];
			}

			$config[$key] = $this->repository()->get($key, $default);
		}

		return $config;
	}

	public function all() {
		return $this->repository()->all();
	}

	/**
	 * @param array|string $key
	 * @param mixed        $value
	 */
	public function set($key, $value = null) {
		$keys = is_array($key) ? $key : [$key => $value];

		foreach ($keys as $k => $v) {
			$this->repository()->set($k, $v);
		}
	}

	public function prepend($key, $value) {
		$array = (array)$this->get($key, []);
		array_unshift($array, $value);
		$this->set($key, $array);
	}

	public function push($key, $value) {
		$array   = (array)$this->get($key, []);
		$array[] = $value;
		$this->set($key, $array);
	}

	/*
	 * ---------------------------------------------------------------
	 * Typed getters (giống Laravel 11+)
	 * ---------------------------------------------------------------
	 */

	public function string($key, $default = null) {
		$value = $this->get($key, $default);
		if (!is_string($value)) {
			throw new \InvalidArgumentException("Configuration value for key [{$key}] must be a string.");
		}
		return $value;
	}

	public function integer($key, $default = null) {
		$value = $this->get($key, $default);
		if (!is_int($value)) {
			throw new \InvalidArgumentException("Configuration value for key [{$key}] must be an integer.");
		}
		return $value;
	}

	public function float($key, $default = null) {
		$value = $this->get($key, $default);
		if (!is_float($value)) {
			throw new \InvalidArgumentException("Configuration value for key [{$key}] must be a float.");
		}
		return $value;
	}

	public function boolean($key, $default = null) {
		$value = $this->get($key, $default);
		if (!is_bool($value)) {
			throw new \InvalidArgumentException("Configuration value for key [{$key}] must be a boolean.");
		}
		return $value;
	}

	public function array($key, $default = null) {
		$value = $this->get($key, $default);
		if (!is_array($value)) {
			throw new \InvalidArgumentException("Configuration value for key [{$key}] must be an array.");
		}
		return $value;
	}

	/*
	 * ---------------------------------------------------------------
	 * Mở rộng
	 * ---------------------------------------------------------------
	 */

	/**
	 * Load thêm 1 file config vào key chỉ định (vd: config của plugin cha).
	 *   Config::loadFile('/path/to/plugin.php', 'plugin');
	 */
	public function loadFile($file, $key = null) {
		$key  = $key ?: pathinfo($file, PATHINFO_FILENAME);
		$data = NoodlehausConfig::load($file)->all();

		$current = $this->get($key);
		$this->set($key, is_array($current) ? array_replace_recursive($current, $data) : $data);

		return $this;
	}

	/**
	 * Thêm thư mục config. Thư mục thêm sau ghi đè giá trị trùng của thư mục trước.
	 */
	public function addPath($path) {
		$path = rtrim($path, '/\\');

		if (!in_array($path, $this->getConfigPaths(), true)) {
			$this->configPaths[] = $path;
		}

		// Reload ở lần truy cập tiếp theo.
		$this->repository = null;

		return $this;
	}

	/**
	 * Load lại toàn bộ config từ đĩa.
	 */
	public function reload() {
		$this->repository = null;
		return $this;
	}

	/**
	 * Repository gốc của hassankhan/config (dot-notation get/set/has/merge).
	 *
	 * @return AbstractConfig
	 */
	public function repository() {
		if ($this->repository) return $this->repository;

		$items = [];

		foreach ($this->getConfigPaths() as $path) {
			$items = array_replace_recursive($items, $this->loadDirectory($path));
		}

		// Constructor của Noodlehaus\Config nhận đường dẫn file, không nhận mảng data,
		// nên dùng AbstractConfig (có get/set/has dot-notation) để chứa dữ liệu đã gom.
		$this->repository = new class($items) extends AbstractConfig {};

		return $this->repository;
	}

	/*
	 * ---------------------------------------------------------------
	 * ArrayAccess
	 * ---------------------------------------------------------------
	 */

	public function offsetExists($key): bool {
		return $this->has($key);
	}

	public function offsetGet($key): mixed {
		return $this->get($key);
	}

	public function offsetSet($key, $value): void {
		$this->set($key, $value);
	}

	public function offsetUnset($key): void {
		$this->set($key, null);
	}

	/*
	 * ---------------------------------------------------------------
	 * Internal
	 * ---------------------------------------------------------------
	 */

	protected function getConfigPaths() {
		if (empty($this->configPaths)) {
			$this->configPaths = [rtrim($this->mainPath, '/\\') . '/config'];
		}
		return $this->configPaths;
	}

	/**
	 * Đọc toàn bộ file trong thư mục (kể cả thư mục con),
	 * key theo tên file giống Laravel.
	 */
	protected function loadDirectory($path) {
		$items = [];

		if (!is_dir($path)) return $items;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
		);

		$files = [];

		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if (!$file->isFile()) continue;
			if (!in_array(strtolower($file->getExtension()), $this->extensions, true)) continue;

			$relative = substr($file->getPathname(), strlen($path) + 1);
			$key      = str_replace(['/', '\\'], '.', substr($relative, 0, -strlen($file->getExtension()) - 1));

			$files[$key] = $file->getPathname();
		}

		// Sắp xếp để thứ tự load ổn định giữa các môi trường.
		ksort($files, SORT_NATURAL);

		foreach ($files as $key => $file) {
			$data = NoodlehausConfig::load($file)->all();
			$this->setNested($items, $key, $data);
		}

		return $items;
	}

	protected function setNested(array &$array, $key, $value) {
		$segments = explode('.', $key);
		$ref      = &$array;

		foreach ($segments as $segment) {
			if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
				$ref[$segment] = [];
			}
			$ref = &$ref[$segment];
		}

		$ref = is_array($value) && is_array($ref) ? array_replace_recursive($ref, $value) : $value;
	}

}
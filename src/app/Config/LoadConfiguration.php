<?php

namespace WPSPCORELITE\App\Config;

/**
 * Nạp toàn bộ file config - mô phỏng Illuminate\Foundation\Bootstrap\LoadConfiguration.
 *
 * - Mỗi file <configPath>/xxx.php (return array) => key 'xxx'.
 * - Thư mục con: config/services/payment.php => key 'services.payment'.
 */
class LoadConfiguration {

	/**
	 * @param \WPSPCORELITE\App\Application $app
	 */
	public function load($app) {
		$repository = new Repository();

		foreach ($this->getConfigurationFiles($app->configPath()) as $key => $path) {
			$repository->set($key, static::requireFile($path));
		}

		return $repository;
	}

	/**
	 * @return array<string, string> key => đường dẫn, sắp theo key
	 */
	protected function getConfigurationFiles($configPath) {
		$files = [];

		if (!is_dir($configPath)) {
			return $files;
		}

		$configPath = rtrim(realpath($configPath), '/\\');

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($configPath, \FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			/** @var \SplFileInfo $file */
			if (!$file->isFile() || $file->getExtension() !== 'php') {
				continue;
			}

			$relative  = substr($file->getPathname(), strlen($configPath) + 1);
			$directory = trim(str_replace(['/', '\\'], '.', dirname($relative)), '.');

			$key = ($directory !== '' ? $directory . '.' : '') . $file->getBasename('.php');

			$files[$key] = $file->getPathname();
		}

		ksort($files, SORT_NATURAL);

		return $files;
	}

	/**
	 * require trong scope riêng để file config không đụng biến của loader.
	 */
	protected static function requireFile($__path) {
		$value = require $__path;

		return is_array($value) ? $value : [];
	}

}
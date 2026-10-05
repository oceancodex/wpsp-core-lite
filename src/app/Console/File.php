<?php
/**
 * Created by PhpStorm.
 * User: Khanh
 * Date: 05/10/2026
 * Time: 8:57 CH
 */

namespace WPSPCORE\App\Console;

class File {

	public static function exists($path) {
		return file_exists($path);
	}

	public static function isFile($path) {
		return is_file($path);
	}

	public static function isDirectory($path) {
		return is_dir($path);
	}

	public static function get($path) {
		if (!is_file($path)) {
			throw new \RuntimeException("File does not exist at path {$path}.");
		}
		return file_get_contents($path);
	}

	public static function put($path, $contents, $lock = false) {
		return file_put_contents($path, $contents, $lock ? LOCK_EX : 0);
	}

	public static function append($path, $data) {
		return file_put_contents($path, $data, FILE_APPEND);
	}

	public static function ensureDirectoryExists($path, $mode = 0755, $recursive = true) {
		if (!is_dir($path)) {
			mkdir($path, $mode, $recursive);
		}
	}

	public static function delete($paths) {
		$ok = true;
		foreach ((array)$paths as $path) {
			if (!@unlink($path)) $ok = false;
		}
		return $ok;
	}

	public static function deleteDirectory($dir) {
		if (!is_dir($dir)) return false;
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		return rmdir($dir);
	}

	public static function files($dir) {
		return is_dir($dir) ? array_values(array_filter(glob(rtrim($dir, '/') . '/*'), 'is_file')) : [];
	}

	public static function copy($from, $to) {
		return copy($from, $to);
	}

}

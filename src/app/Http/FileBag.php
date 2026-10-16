<?php

namespace WPSPCORELITE\App\Http;

/**
 * $request->files: accepts raw $_FILES entries (including nested/multiple and PHP 8.1 full_path),
 * our UploadedFile objects, or foreign uploaded-file objects (e.g. Symfony) and stores only
 * UploadedFile instances. Inputs with no file chosen (UPLOAD_ERR_NO_FILE) are dropped.
 */
class FileBag extends ParameterBag {

	protected const FILE_KEYS = ['error', 'full_path', 'name', 'size', 'tmp_name', 'type'];

	public function __construct(array $parameters = []) {
		parent::__construct();
		$this->replace($parameters);
	}

	public function replace(array $files = []): void {
		$this->parameters = [];
		$this->add($files);
	}

	public function set(string $key, mixed $value): void {
		$value = static::convert($value);
		if ($value === null || $value === []) {
			unset($this->parameters[$key]);
			return;
		}
		$this->parameters[$key] = $value;
	}

	public function add(array $files = []): void {
		foreach ($files as $key => $file) {
			$this->set((string)$key, $file);
		}
	}

	protected static function convert(mixed $value): UploadedFile|array|null {
		if ($value instanceof UploadedFile || $value === null) return $value;

		// Object upload từ thư viện khác (Symfony/Laravel UploadedFile...).
		if (is_object($value) && $value instanceof \SplFileInfo && method_exists($value, 'getClientOriginalName')) {
			$path = $value->getPathname();
			return new UploadedFile(
				$path,
				(string)$value->getClientOriginalName(),
				method_exists($value, 'getClientMimeType') ? $value->getClientMimeType() : null,
				method_exists($value, 'getError') ? (int)$value->getError() : UPLOAD_ERR_OK,
				is_file($path) ? (int)filesize($path) : 0,
				!is_uploaded_file($path),
				method_exists($value, 'getClientOriginalPath') ? $value->getClientOriginalPath() : null
			);
		}

		if (!is_array($value)) return null;

		if (static::isFileArray($value)) return static::convertFile($value);

		$result = [];
		foreach ($value as $k => $item) {
			$converted = static::convert($item);
			if ($converted !== null && $converted !== []) $result[$k] = $converted;
		}
		return $result;
	}

	/** Đúng cấu trúc một entry $_FILES (tránh nhầm với input mảng có key "name"). */
	protected static function isFileArray(array $value): bool {
		if (!array_key_exists('name', $value) || !array_key_exists('tmp_name', $value)) return false;
		return !array_diff(array_keys($value), static::FILE_KEYS);
	}

	protected static function convertFile(array $file): UploadedFile|array|null {
		if (!is_array($file['name'])) {
			if ((int)($file['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_NO_FILE) return null;
			return new UploadedFile(
				(string)$file['tmp_name'], (string)$file['name'],
				isset($file['type']) && $file['type'] !== '' ? (string)$file['type'] : null,
				(int)($file['error'] ?? UPLOAD_ERR_OK), (int)($file['size'] ?? 0),
				false,
				isset($file['full_path']) ? (string)$file['full_path'] : null
			);
		}

		$result = [];
		foreach (array_keys($file['name']) as $i) {
			$entry = [];
			foreach (static::FILE_KEYS as $k) {
				if (isset($file[$k]) && is_array($file[$k]) && array_key_exists($i, $file[$k])) $entry[$k] = $file[$k][$i];
			}
			$entry += ['tmp_name' => '', 'error' => UPLOAD_ERR_OK, 'size' => 0];

			$converted = static::convertFile($entry);
			if ($converted !== null && $converted !== []) $result[$i] = $converted;
		}
		return $result;
	}

}

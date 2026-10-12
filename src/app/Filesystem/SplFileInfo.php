<?php

namespace WPSPCORELITE\App\Filesystem;

/**
 * Package-free equivalent of Symfony\Component\Finder\SplFileInfo,
 * returned by Filesystem::files() / allFiles() / allDirectories().
 */
class SplFileInfo extends \SplFileInfo {

	private $relativePath;
	private $relativePathname;

	/**
	 * @param string $file             The file name
	 * @param string $relativePath     The relative path (directory part)
	 * @param string $relativePathname The relative path name (with file name)
	 */
	public function __construct($file, $relativePath, $relativePathname) {
		parent::__construct($file);

		$this->relativePath     = $relativePath;
		$this->relativePathname = $relativePathname;
	}

	/**
	 * Relative path of the containing directory, e.g. "sub/dir" (no trailing slash).
	 */
	public function getRelativePath() {
		return $this->relativePath;
	}

	/**
	 * Relative path including the file name, e.g. "sub/dir/file.php".
	 */
	public function getRelativePathname() {
		return $this->relativePathname;
	}

	public function getFilenameWithoutExtension() {
		return pathinfo($this->getFilename(), PATHINFO_FILENAME);
	}

	/**
	 * @throws \RuntimeException
	 */
	public function getContents() {
		set_error_handler(function ($type, $msg) use (&$error) {
			$error = $msg;
		});

		try {
			$content = file_get_contents($this->getPathname());
		}
		finally {
			restore_error_handler();
		}

		if ($content === false) {
			throw new \RuntimeException($error ?? 'Unable to read file "' . $this->getPathname() . '".');
		}

		return $content;
	}

}

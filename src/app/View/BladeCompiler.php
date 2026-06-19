<?php

namespace WPSPCORE\App\View;

use Illuminate\Filesystem\Filesystem;

class BladeCompiler extends \Illuminate\View\Compilers\BladeCompiler {

	public $funcs = null;

	public function __construct(
		Filesystem $files,
		$cachePath,
		$funcs = null,
		$basePath = '',
		$shouldCache = true,
		$compiledExtension = 'php',
		$shouldCheckTimestamps = true,
	) {
		$this->funcs = $funcs;

		parent::__construct($files, $cachePath, $basePath, $shouldCache, $compiledExtension, $shouldCheckTimestamps);
	}

	protected function compileComponentTags($value) {
		if (!$this->compilesComponentTags) {
			return $value;
		}

		return (new ComponentTagCompiler(
			$this->funcs,
			$this->classComponentAliases,
			$this->classComponentNamespaces,
			$this
		))->compile($value);
	}

}

<?php

namespace WPSPCORE\App\View;

class ComponentTagCompiler extends \Illuminate\View\Compilers\ComponentTagCompiler {

	public $funcs = null;

	public function __construct($funcs = null, array $aliases = [], array $namespaces = [], ?BladeCompiler $blade = null) {
		$this->funcs = $funcs;

		parent::__construct($aliases, $namespaces, $blade);
	}

	public function guessClassName(string $component) {
		$namespace = $this->funcs->_getRootNamespace();

		$class = $this->formatClassName($component);

		return $namespace . 'View\\Components\\' . $class;
	}

}
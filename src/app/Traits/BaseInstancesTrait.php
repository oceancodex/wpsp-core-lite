<?php

namespace WPSPCORELITE\App\Traits;

use WPSPCORELITE\App\Routes\RouteTrait;
use WPSPCORELITE\App\Http\Request as WPSPCORELITE_Request;

/**
 * BaseInstancesTrait.
 *
 * @property \WPSPCORELITE\Funcs      $funcs
 * @property \Illuminate\Http\Request $request
 * @method $this __wpspConstruct
 * @method $this __instanceConstruct
 * @method $this customProperties
 * @method $this afterCustomProperties
 * @method $this afterInstanceConstruct
 */
trait BaseInstancesTrait {

	use RouteTrait;

	public $mainPath      = null;
	public $rootNamespace = null;
	public $prefixEnv     = null;
	public $extraParams   = [];

	public $request       = null;
	public $funcs         = null;

	public function baseInstanceConstruct($mainPath = null, $rootNamespace = null, $prefixEnv = null, $extraParams = []) {
		$this->beforeInstanceConstruct();
		$this->beforeConstruct();

		if ($mainPath) $this->mainPath = $mainPath;
		if ($rootNamespace) $this->rootNamespace = $rootNamespace;
		if ($prefixEnv) $this->prefixEnv = $prefixEnv;
		if ($extraParams) $this->extraParams = $extraParams;

		$this->prepareFuncs();
		$this->prepareRequest();
		$this->afterConstruct();
		$this->baseInstanceCall('__wpspConstruct'); // Mọi params sẽ tự động tạo thành properties cho class.
		$this->baseInstanceCall('__instanceConstruct');
		$this->baseInstanceCall('customProperties');
		$this->baseInstanceCall('afterCustomProperties');
		$this->baseInstanceCall('afterInstanceConstruct');
		$this->baseInstanceCall('afterBaseInstanceConstruct'); // Sử dụng hàm này để prepare args cho Navigation Menu.
	}

	/*
	 *
	 */

	public function baseInstanceCall($method) {
		if (($this->funcs && $this->request) || is_subclass_of($this, \WP_List_Table::class)) {
			if (!method_exists($this, $method)) {
				return null;
			}

			$path        = $this->extraParams['path'] ?? '';
			$fullPath    = $this->extraParams['full_path'] ?? '';
			$requestPath = $this->request->getRequestUri();

			return $this->autoResolveAndCall($path, $fullPath, $requestPath, $this, $method);
		}

		return null;
	}

	/*
	 *
	 */

	public function prepareFuncs() {
		if ($this->funcs) return;

		if (isset($this->extraParams['funcs']) && $this->extraParams['funcs']) {
			if (is_bool($this->extraParams['funcs'])) {
				$this->funcs = new \WPSPCORELITE\Funcs(
					$this->mainPath,
					$this->rootNamespace,
					$this->prefixEnv,
					$this->extraParams
				);
			}
			else {
				$this->funcs = $this->extraParams['funcs'];
			}
		}

		unset($this->extraParams['funcs']);
	}

	public function prepareRequest() {
		if ($this->request) return;

		if ($funcs = $this->funcs) {
			$this->request = (isset($funcs::$request) && $funcs::$request)
				? $funcs::$request
				: $funcs->_getApplication('request');
		}
		else {
			$this->request = WPSPCORELITE_Request::capture();
		}

		unset($this->extraParams['request']);
	}

	/*
	 *
	 */

	public function beforeInstanceConstruct() {}

	public function beforeConstruct() {}

	public function afterConstruct() {}

	/*
	 *
	 */

	public function wpspCall($method, $class = null, $args = []) {
		$path        = $this->extraParams['path'] ?? null;
		$fullPath    = $this->extraParams['full_path'] ?? null;
		$requestPath = $this->request?->getRequestUri();

		if ($class) {
			return $this->autoResolveAndCall($path, $fullPath, $requestPath, $class, $method, $args);
		}

		return $this->autoResolveAndCall($path, $fullPath, $requestPath, $this, $method, $args);
	}

}
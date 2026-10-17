<?php

namespace WPSPCORELITE\App\Cache;

/**
 * Main cache class of WPSPCORELITE (the object behind the Cache facade, like Laravel's 'cache' binding).
 *
 * Constructor follows the WPSPCORELITE instance convention (mainPath, rootNamespace, prefixEnv, extraParams).
 * Config is read, in this order:
 *   1. $extraParams['config'] (array)
 *   2. {mainPath}/config/cache.php (Laravel-style file returning an array)
 *   3. defaults (driver "transient", prefix "wpsp_lite_")
 *
 * Each plugin extends this class (e.g. WPSPLITE\App\Widen\Cache\Cache), loads its own config/cache.php
 * and therefore has its own prefix, stores and lock names.
 */
class Cache extends CacheManager {

	protected ?string $cacheMainPath;
	protected ?string $cacheRootNamespace;
	protected ?string $cachePrefixEnv;
	protected array   $cacheExtraParams;

	public function __construct($mainPath = null, $rootNamespace = null, $prefixEnv = null, $extraParams = []) {
		$this->cacheMainPath      = $mainPath !== null ? (string) $mainPath : null;
		$this->cacheRootNamespace = $rootNamespace !== null ? (string) $rootNamespace : null;
		$this->cachePrefixEnv     = $prefixEnv !== null ? (string) $prefixEnv : null;
		$this->cacheExtraParams   = is_array($extraParams) ? $extraParams : [];

		parent::__construct($this->loadConfig());
	}

	/**
	 * Create an instance from a config array (no config file).
	 */
	public static function make(array $config = []): static {
		return new static(null, null, null, ['config' => $config]);
	}

	/**
	 * Path of the plugin's cache config file, or null.
	 */
	public function getConfigPath(): ?string {
		if (!empty($this->cacheExtraParams['config_path'])) {
			return (string) $this->cacheExtraParams['config_path'];
		}

		if ($this->cacheMainPath === null || $this->cacheMainPath === '') {
			return null;
		}

		return rtrim($this->cacheMainPath, '/\\') . '/config/cache.php';
	}

	/**
	 * Re-read the config file (e.g. after changing it at runtime in tests).
	 */
	public function reloadConfig(): static {
		$this->config = $this->mergeConfig($this->defaultConfig(), $this->loadConfig());

		return $this->setConfig([]);
	}

	public function getMainPath(): ?string {
		return $this->cacheMainPath;
	}

	public function getRootNamespace(): ?string {
		return $this->cacheRootNamespace;
	}

	public function getPrefixEnv(): ?string {
		return $this->cachePrefixEnv;
	}

	/*
	 *
	 */

	protected function loadConfig(): array {
		if (isset($this->cacheExtraParams['config']) && is_array($this->cacheExtraParams['config'])) {
			return $this->cacheExtraParams['config'];
		}

		$path = $this->getConfigPath();

		if ($path !== null && is_file($path)) {
			$config = require $path;

			if (is_array($config)) {
				return $config;
			}
		}

		return [];
	}

}

<?php

namespace WPSPCORELITE\App\Cache;

/**
 * Main cache class of WPSPCORELITE (the object behind the Cache facade, like Laravel's 'cache' binding).
 *
 * Constructor follows the WPSPCORELITE instance convention (mainPath, rootNamespace, prefixEnv, extraParams).
 * Config is read, in this order:
 *   1. $extraParams['config'] (array, e.g. config('cache') given by the Application binding)
 *   2. {mainPath}/config/cache.php (Laravel-style file returning an array)
 *   3. defaults (driver "transient")
 *
 * Prefix: 'prefix' from the config; when it is empty or missing, it is built from the plugin folder name
 * (plugins/wpsp-lite => "wpsp_lite_", plugins/plugin-cua-toi => "plugin_cua_toi_"). A WordPress site
 * cannot have two plugins in the same folder, so plugins never share cache keys, locks or folders,
 * even when every plugin was created from the same wpsp-lite template.
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
		$config = [];

		if (isset($this->cacheExtraParams['config']) && is_array($this->cacheExtraParams['config'])) {
			$config = $this->cacheExtraParams['config'];
		}
		else {
			$path = $this->getConfigPath();

			if ($path !== null && is_file($path)) {
				$loaded = require $path;
				$config = is_array($loaded) ? $loaded : [];
			}
		}

		if (!isset($config['prefix']) || trim((string) $config['prefix']) === '') {
			$config['prefix'] = $this->defaultPrefix();
		}

		return $config;
	}

	/**
	 * Prefix used when the config has none: the plugin folder name ("plugin-cua-toi" => "plugin_cua_toi_"),
	 * then the env prefix ("PLUGINA_" => "plugina_"), then "wpsp_lite_".
	 */
	public function defaultPrefix(): string {
		foreach ([$this->cacheMainPath !== null ? basename(rtrim($this->cacheMainPath, '/\\')) : null, $this->cachePrefixEnv] as $source) {
			$slug = trim(strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', (string) $source)), '_');

			if ($slug !== '') {
				return $slug . '_';
			}
		}

		return CachePrefix::DEFAULT_PREFIX;
	}

}

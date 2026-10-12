<?php

namespace WPSPCORELITE\App;

/**
 * Danh sách provider mặc định của core - tương đương Illuminate\Support\DefaultProviders.
 *
 * Dùng trong config (nếu có):
 *   'providers' => ServiceProvider::defaultProviders()->merge([...])->toArray(),
 */
class DefaultProviders {

	/** @var array */
	protected $providers;

	public function __construct(?array $providers = null) {
		$this->providers = $providers ?: [
			\WPSPCORELITE\App\Providers\AppServiceProvider::class,
		];
	}

	public function merge(array $providers) {
		return new static(array_merge($this->providers, $providers));
	}

	/**
	 * Thay provider: ->replace([Old::class => New::class])
	 */
	public function replace(array $replacements) {
		$current = $this->providers;

		foreach ($replacements as $from => $to) {
			$key = array_search($from, $current, true);

			$current = is_int($key) ? array_replace($current, [$key => $to]) : $current;
		}

		return new static(array_values($current));
	}

	public function except(array $providers) {
		return new static(array_values(array_filter($this->providers, function($p) use ($providers) {
			return !in_array($p, $providers, true);
		})));
	}

	public function toArray() {
		return $this->providers;
	}

}
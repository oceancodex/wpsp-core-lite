<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Lightweight event dispatcher for cache events (replaces Illuminate\Contracts\Events\Dispatcher).
 *
 * Listen without WordPress:
 *     $dispatcher->listen(CacheHit::class, fn(CacheHit $e) => ...);
 *
 * Listen with WordPress hooks (hook = "{prefix}cache", default "wpsp_lite_cache"):
 *     add_action('wpsp_lite_cache', fn(object $event) => ...);              // every event
 *     add_action('wpsp_lite_cache/cache_hit', fn(CacheHit $e) => ...);     // one event
 */
class Dispatcher {

	protected ?string $hook;

	/** @var array<string, callable[]> */
	protected array $listeners = [];

	public function __construct(?string $hook = null) {
		$this->hook = $hook;
	}

	/**
	 * Register a listener. $events: class name, short name (CacheHit), snake name (cache_hit) or '*'.
	 */
	public function listen(string|array $events, callable $listener): void {
		foreach ((array) $events as $event) {
			$this->listeners[static::normalize($event)][] = $listener;
		}
	}

	public function hasListeners(string $event): bool {
		return !empty($this->listeners[static::normalize($event)]) || !empty($this->listeners['*']);
	}

	public function forget(string $event): void {
		unset($this->listeners[static::normalize($event)]);
	}

	public function dispatch(object $event): void {
		$name = static::normalize(get_class($event));

		foreach (array_merge($this->listeners[$name] ?? [], $this->listeners['*'] ?? []) as $listener) {
			$listener($event);
		}

		if ($this->hook !== null && function_exists('do_action')) {
			do_action($this->hook, $event);
			do_action($this->hook . '/' . $name, $event);
		}
	}

	public function getHook(): ?string {
		return $this->hook;
	}

	/**
	 * WPSPCORELITE\App\Cache\Events\CacheHit | CacheHit | cache_hit => cache_hit
	 */
	public static function normalize(string $event): string {
		if ($event === '*') {
			return '*';
		}

		$pos   = strrpos($event, '\\');
		$event = $pos === false ? $event : substr($event, $pos + 1);

		return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $event));
	}

}

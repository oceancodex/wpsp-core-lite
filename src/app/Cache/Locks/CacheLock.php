<?php

namespace WPSPCORELITE\App\Cache\Locks;

use WPSPCORELITE\App\Cache\Contracts\Store;

/**
 * Mirror of Illuminate\Cache\CacheLock: a lock built on top of any Store.
 * Atomicity depends on the store's add() implementation.
 */
class CacheLock extends Lock {

	protected Store $store;

	public function __construct(Store $store, $name, $seconds, $owner = null) {
		parent::__construct($name, $seconds, $owner);
		$this->store = $store;
	}

	public function acquire(): bool {
		if (method_exists($this->store, 'add') && $this->seconds > 0) {
			return $this->store->add($this->name, $this->owner, $this->seconds);
		}

		if (!is_null($this->store->get($this->name))) {
			return false;
		}

		return $this->seconds > 0
			? $this->store->put($this->name, $this->owner, $this->seconds)
			: $this->store->forever($this->name, $this->owner);
	}

	public function release(): bool {
		if ($this->isOwnedByCurrentProcess()) {
			return $this->store->forget($this->name);
		}

		return false;
	}

	public function forceRelease(): void {
		$this->store->forget($this->name);
	}

	protected function getCurrentOwner(): ?string {
		$owner = $this->store->get($this->name);
		return $owner === null ? null : (string) $owner;
	}

}

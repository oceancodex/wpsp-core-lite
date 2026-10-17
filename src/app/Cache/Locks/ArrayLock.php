<?php

namespace WPSPCORELITE\App\Cache\Locks;

use WPSPCORELITE\App\Cache\Stores\ArrayStore;

/**
 * Mirror of Illuminate\Cache\ArrayLock.
 */
class ArrayLock extends Lock {

	protected ArrayStore $store;

	public function __construct(ArrayStore $store, $name, $seconds, $owner = null) {
		parent::__construct($name, $seconds, $owner);
		$this->store = $store;
	}

	public function acquire(): bool {
		if ($this->exists()) {
			return false;
		}

		$this->store->locks[$this->name] = [
			'owner'     => $this->owner,
			'expiresAt' => $this->seconds === 0 ? null : microtime(true) + $this->seconds,
		];

		return true;
	}

	protected function exists(): bool {
		if (!isset($this->store->locks[$this->name])) {
			return false;
		}

		$expiresAt = $this->store->locks[$this->name]['expiresAt'];

		return $expiresAt === null || $expiresAt > microtime(true);
	}

	public function release(): bool {
		if (!$this->exists() || !$this->isOwnedByCurrentProcess()) {
			return false;
		}

		$this->forceRelease();

		return true;
	}

	public function forceRelease(): void {
		unset($this->store->locks[$this->name]);
	}

	protected function getCurrentOwner(): ?string {
		return $this->exists() ? $this->store->locks[$this->name]['owner'] : null;
	}

}

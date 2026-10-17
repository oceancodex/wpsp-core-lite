<?php

namespace WPSPCORELITE\App\Cache\Locks;

/**
 * Mirror of Illuminate\Cache\NoLock (used by NullStore).
 */
class NoLock extends Lock {

	public function acquire(): bool {
		return true;
	}

	public function release(): bool {
		return true;
	}

	public function forceRelease(): void {
	}

	protected function getCurrentOwner(): ?string {
		return $this->owner;
	}

}

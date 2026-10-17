<?php

namespace WPSPCORELITE\App\Cache\Locks;

/**
 * Mirror of Illuminate\Cache\FileLock: relies on FileStore::add(), which is atomic (flock).
 */
class FileLock extends CacheLock {

	public function acquire(): bool {
		return $this->store->add($this->name, $this->owner, $this->seconds);
	}

}

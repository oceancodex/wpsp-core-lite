<?php

namespace WPSPCORELITE\App\Cache\Stores;

use WPSPCORELITE\App\Cache\Concerns\RetrievesMultipleKeys;
use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Locks\NoLock;

/**
 * Mirror of Illuminate\Cache\NullStore: caches nothing.
 */
class NullStore extends TaggableStore implements LockProvider {

	use RetrievesMultipleKeys;

	public function get($key): mixed {
		return null;
	}

	public function put($key, $value, $seconds): bool {
		return false;
	}

	public function increment($key, $value = 1): int|bool {
		return false;
	}

	public function decrement($key, $value = 1): int|bool {
		return false;
	}

	public function forever($key, $value): bool {
		return false;
	}

	public function forget($key): bool {
		return true;
	}

	public function flush(): bool {
		return true;
	}

	public function getPrefix(): string {
		return '';
	}

	public function lock($name, $seconds = 0, $owner = null): LockContract {
		return new NoLock($name, $seconds, $owner);
	}

	public function restoreLock($name, $owner): LockContract {
		return $this->lock($name, 0, $owner);
	}

}

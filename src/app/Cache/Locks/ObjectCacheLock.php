<?php

namespace WPSPCORELITE\App\Cache\Locks;

/**
 * Lock on the WordPress object cache (wp_cache_add is atomic on Redis / Memcached drop-ins,
 * like Laravel's RedisLock / MemcachedLock).
 *
 * Without a persistent object cache the lock only lives for the current request.
 */
class ObjectCacheLock extends Lock {

	protected string $group;

	public function __construct($name, $seconds, $owner = null, string $group = 'wpsp_lite_cache_locks') {
		parent::__construct($name, $seconds, $owner);
		$this->group = $group;
	}

	public function acquire(): bool {
		return (bool) wp_cache_add($this->name, $this->owner, $this->group, max(0, $this->seconds));
	}

	public function release(): bool {
		if (!$this->isOwnedByCurrentProcess()) {
			return false;
		}

		return (bool) wp_cache_delete($this->name, $this->group);
	}

	public function forceRelease(): void {
		wp_cache_delete($this->name, $this->group);
	}

	protected function getCurrentOwner(): ?string {
		$found = false;
		$owner = wp_cache_get($this->name, $this->group, false, $found);

		return ($found && $owner !== false) ? (string) $owner : null;
	}

	public function getGroup(): string {
		return $this->group;
	}

}

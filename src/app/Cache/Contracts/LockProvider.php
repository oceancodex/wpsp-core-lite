<?php

namespace WPSPCORELITE\App\Cache\Contracts;

/**
 * Mirror of Illuminate\Contracts\Cache\LockProvider.
 */
interface LockProvider {

	/**
	 * Get a lock instance.
	 */
	public function lock($name, $seconds = 0, $owner = null): Lock;

	/**
	 * Restore a lock instance using the owner identifier.
	 */
	public function restoreLock($name, $owner): Lock;

}

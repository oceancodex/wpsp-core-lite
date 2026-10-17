<?php

namespace WPSPCORELITE\App\Cache\Contracts;

/**
 * Mirror of Illuminate\Contracts\Cache\Lock.
 */
interface Lock {

	/**
	 * Attempt to acquire the lock. When a callback is given, it runs and the lock is released afterwards.
	 */
	public function get($callback = null): mixed;

	/**
	 * Attempt to acquire the lock for the given number of seconds, waiting if needed.
	 */
	public function block($seconds, $callback = null): mixed;

	/**
	 * Release the lock.
	 */
	public function release(): bool;

	/**
	 * Returns the current owner of the lock.
	 */
	public function owner(): string;

	/**
	 * Releases this lock in disregard of ownership.
	 */
	public function forceRelease(): void;

}

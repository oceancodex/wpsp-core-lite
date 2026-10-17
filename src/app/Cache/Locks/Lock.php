<?php

namespace WPSPCORELITE\App\Cache\Locks;

use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Exceptions\LockTimeoutException;

/**
 * Mirror of Illuminate\Cache\Lock.
 */
abstract class Lock implements LockContract {

	protected string $name;
	protected int    $seconds;
	protected string $owner;

	/**
	 * The number of milliseconds to wait before re-attempting to acquire a lock while blocking.
	 */
	protected int $sleepMilliseconds = 250;

	public function __construct($name, $seconds, $owner = null) {
		$this->name    = (string) $name;
		$this->seconds = (int) $seconds;
		$this->owner   = $owner === null ? bin2hex(random_bytes(8)) : (string) $owner;
	}

	/**
	 * Attempt to acquire the lock.
	 */
	abstract public function acquire(): bool;

	/**
	 * Release the lock.
	 */
	abstract public function release(): bool;

	/**
	 * Returns the owner value written into the driver for this lock.
	 */
	abstract protected function getCurrentOwner(): ?string;

	public function get($callback = null): mixed {
		$result = $this->acquire();

		if ($result && is_callable($callback)) {
			try {
				return $callback();
			}
			finally {
				$this->release();
			}
		}

		return $result;
	}

	public function block($seconds, $callback = null): mixed {
		$starttime = microtime(true);

		while (!$this->acquire()) {
			usleep($this->sleepMilliseconds * 1000);

			if (microtime(true) - $seconds >= $starttime) {
				throw new LockTimeoutException();
			}
		}

		if (is_callable($callback)) {
			try {
				return $callback();
			}
			finally {
				$this->release();
			}
		}

		return true;
	}

	public function owner(): string {
		return $this->owner;
	}

	public function isOwnedByCurrentProcess(): bool {
		return $this->isOwnedBy($this->owner);
	}

	public function isOwnedBy(?string $owner): bool {
		return $this->getCurrentOwner() === $owner;
	}

	public function betweenBlockedAttemptsSleepFor(int $milliseconds): static {
		$this->sleepMilliseconds = $milliseconds;
		return $this;
	}

}

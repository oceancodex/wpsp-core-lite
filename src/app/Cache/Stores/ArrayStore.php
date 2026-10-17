<?php

namespace WPSPCORELITE\App\Cache\Stores;

use WPSPCORELITE\App\Cache\Concerns\RetrievesMultipleKeys;
use WPSPCORELITE\App\Cache\Contracts\Lock as LockContract;
use WPSPCORELITE\App\Cache\Contracts\LockProvider;
use WPSPCORELITE\App\Cache\Locks\ArrayLock;

/**
 * Mirror of Illuminate\Cache\ArrayStore: in-memory, lives for one request. Useful for tests.
 */
class ArrayStore extends TaggableStore implements LockProvider {

	use RetrievesMultipleKeys;

	protected array $storage = [];
	public array    $locks   = [];
	protected bool  $serializesValues;

	public function __construct(bool $serializesValues = false) {
		$this->serializesValues = $serializesValues;
	}

	public function all(bool $unserialize = true): array {
		if (!$unserialize || !$this->serializesValues) {
			return $this->storage;
		}

		$storage = [];
		foreach ($this->storage as $key => $data) {
			$storage[$key] = ['value' => unserialize($data['value']), 'expiresAt' => $data['expiresAt']];
		}

		return $storage;
	}

	public function get($key): mixed {
		if (!isset($this->storage[$key])) {
			return null;
		}

		$item      = $this->storage[$key];
		$expiresAt = $item['expiresAt'] ?? 0;

		if ($expiresAt !== 0 && microtime(true) >= $expiresAt) {
			$this->forget($key);
			return null;
		}

		return $this->serializesValues ? unserialize($item['value']) : $item['value'];
	}

	public function put($key, $value, $seconds): bool {
		$this->storage[$key] = [
			'value'     => $this->serializesValues ? serialize($value) : $value,
			'expiresAt' => $this->calculateExpiration((int) $seconds),
		];

		return true;
	}

	public function increment($key, $value = 1): int|bool {
		if (!is_null($existing = $this->get($key))) {
			$incremented = ((int) $existing) + $value;

			$this->storage[$key]['value'] = $this->serializesValues ? serialize($incremented) : $incremented;

			return $incremented;
		}

		$this->forever($key, $value);

		return $value;
	}

	public function decrement($key, $value = 1): int|bool {
		return $this->increment($key, $value * -1);
	}

	public function forever($key, $value): bool {
		return $this->put($key, $value, 0);
	}

	public function touch($key, $seconds): bool {
		if ($this->get($key) === null) {
			return false;
		}

		$this->storage[$key]['expiresAt'] = $this->calculateExpiration((int) $seconds);

		return true;
	}

	public function forget($key): bool {
		if (array_key_exists($key, $this->storage)) {
			unset($this->storage[$key]);
			return true;
		}

		return false;
	}

	public function flush(): bool {
		$this->storage = [];
		return true;
	}

	public function getPrefix(): string {
		return '';
	}

	public function lock($name, $seconds = 0, $owner = null): LockContract {
		return new ArrayLock($this, $name, $seconds, $owner);
	}

	public function restoreLock($name, $owner): LockContract {
		return $this->lock($name, 0, $owner);
	}

	protected function calculateExpiration(int $seconds): float|int {
		return $seconds > 0 ? microtime(true) + $seconds : 0;
	}

}

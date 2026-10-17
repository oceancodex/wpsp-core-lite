<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\KeyWriteFailed.
 */
class KeyWriteFailed extends CacheEvent {

	public mixed $value;

	/**
	 * Number of seconds the key should be valid (null = forever).
	 */
	public ?int $seconds;

	public function __construct(?string $storeName, $key, mixed $value, ?int $seconds = null, array $tags = []) {
		parent::__construct($storeName, $key, $tags);
		$this->value   = $value;
		$this->seconds = $seconds;
	}

}

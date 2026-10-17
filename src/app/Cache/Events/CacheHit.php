<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\CacheHit.
 */
class CacheHit extends CacheEvent {

	public mixed $value;

	public function __construct(?string $storeName, $key, mixed $value, array $tags = []) {
		parent::__construct($storeName, $key, $tags);
		$this->value = $value;
	}

}

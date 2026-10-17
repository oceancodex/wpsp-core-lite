<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\RetrievingManyKeys.
 */
class RetrievingManyKeys extends CacheEvent {

	public array $keys;

	public function __construct(?string $storeName, array $keys, array $tags = []) {
		parent::__construct($storeName, $keys[0] ?? '', $tags);
		$this->keys = $keys;
	}

}

<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\WritingManyKeys.
 */
class WritingManyKeys extends CacheEvent {

	public array $keys;
	public array $values;
	public ?int  $seconds;

	public function __construct(?string $storeName, array $keys, array $values, ?int $seconds = null, array $tags = []) {
		parent::__construct($storeName, $keys[0] ?? '', $tags);
		$this->keys    = $keys;
		$this->values  = $values;
		$this->seconds = $seconds;
	}

}

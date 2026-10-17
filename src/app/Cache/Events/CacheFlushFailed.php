<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\CacheFlushFailed. Fired when flushing the cache failed.
 */
class CacheFlushFailed {

	public ?string $storeName;
	public array   $tags;

	public function __construct(?string $storeName, array $tags = []) {
		$this->storeName = $storeName;
		$this->tags      = $tags;
	}

	public function setTags(array $tags): static {
		$this->tags = $tags;
		return $this;
	}

}

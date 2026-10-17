<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\CacheFlushed. Fired after the cache was flushed.
 */
class CacheFlushed {

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

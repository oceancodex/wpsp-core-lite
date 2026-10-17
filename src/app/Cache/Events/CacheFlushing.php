<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\CacheFlushing. Fired before the cache is flushed.
 */
class CacheFlushing {

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

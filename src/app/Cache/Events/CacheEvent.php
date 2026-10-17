<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\CacheEvent.
 */
abstract class CacheEvent {

	/**
	 * The name of the cache store.
	 */
	public ?string $storeName;

	/**
	 * The key of the event.
	 */
	public string $key;

	/**
	 * The tags that were assigned to the key.
	 */
	public array $tags;

	public function __construct(?string $storeName, $key, array $tags = []) {
		$this->storeName = $storeName;
		$this->key       = (string) $key;
		$this->tags      = $tags;
	}

	/**
	 * Set the tags for the cache event.
	 */
	public function setTags(array $tags): static {
		$this->tags = $tags;
		return $this;
	}

}

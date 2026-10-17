<?php

namespace WPSPCORELITE\App\Cache;

use WPSPCORELITE\App\Cache\Contracts\Store;
use WPSPCORELITE\App\Cache\Events\CacheFlushed;
use WPSPCORELITE\App\Cache\Events\CacheFlushing;

/**
 * Mirror of Illuminate\Cache\TaggedCache.
 *
 * Cache::tags(['posts', 'user:1'])->put('list', $data, 600);
 * Cache::tags(['posts', 'user:1'])->get('list');
 * Cache::tags('posts')->flush();
 */
class TaggedCache extends Repository {

	protected TagSet $tags;

	public function __construct(Store $store, TagSet $tags) {
		parent::__construct($store);
		$this->tags = $tags;
	}

	/**
	 * Remove all items tagged with these tags.
	 */
	public function flush(): bool {
		$this->event(new CacheFlushing($this->getName()));

		$this->tags->reset();

		$this->event(new CacheFlushed($this->getName()));

		return true;
	}

	public function clear(): bool {
		return $this->flush();
	}

	/**
	 * Get a fully qualified key for a tagged item.
	 */
	public function taggedItemKey($key): string {
		return sha1($this->tags->getNamespace()) . ':' . $key;
	}

	public function getTags(): TagSet {
		return $this->tags;
	}

	protected function itemKey($key): string {
		return $this->taggedItemKey($key);
	}

	/**
	 * Fire an event with the tag names attached.
	 */
	protected function event(object $event): void {
		if (!$this->events) {
			return;
		}

		if (method_exists($event, 'setTags')) {
			$event->setTags($this->tags->getNames());
		}

		parent::event($event);
	}

}

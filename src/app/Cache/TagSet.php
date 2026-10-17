<?php

namespace WPSPCORELITE\App\Cache;

use WPSPCORELITE\App\Cache\Contracts\Store;

/**
 * Mirror of Illuminate\Cache\TagSet.
 * Every tag has a random id; flushing a tag just gives it a new id,
 * so all keys namespaced with the old id become unreachable.
 */
class TagSet {

	protected Store $store;
	protected array $names;

	public function __construct(Store $store, array $names = []) {
		$this->store = $store;
		$this->names = array_values($names);
	}

	/**
	 * Reset all tags in the set.
	 */
	public function reset(): void {
		array_walk($this->names, [$this, 'resetTag']);
	}

	/**
	 * Reset the tag and return the new tag identifier.
	 */
	public function resetTag($name): string {
		$this->store->forever($this->tagKey($name), $id = str_replace('.', '', uniqid('', true)));
		return $id;
	}

	/**
	 * Flush all the tags in the set (remove the tag ids).
	 */
	public function flush(): void {
		array_walk($this->names, [$this, 'flushTag']);
	}

	public function flushTag($name): void {
		$this->store->forget($this->tagKey($name));
	}

	/**
	 * Get a unique namespace that changes when any of the tags are flushed.
	 */
	public function getNamespace(): string {
		return implode('|', $this->tagIds());
	}

	protected function tagIds(): array {
		return array_map([$this, 'tagId'], $this->names);
	}

	public function tagId($name): string {
		$id = $this->store->get($this->tagKey($name));
		return is_string($id) && $id !== '' ? $id : $this->resetTag($name);
	}

	public function tagKey($name): string {
		return 'tag:' . $name . ':key';
	}

	public function getNames(): array {
		return $this->names;
	}

}

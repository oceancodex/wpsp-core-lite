<?php

namespace WPSPCORELITE\App\Cache\Stores;

use WPSPCORELITE\App\Cache\Contracts\Store;
use WPSPCORELITE\App\Cache\TagSet;
use WPSPCORELITE\App\Cache\TaggedCache;

/**
 * Mirror of Illuminate\Cache\TaggableStore.
 */
abstract class TaggableStore implements Store {

	/**
	 * Begin executing a new tags operation.
	 */
	public function tags($names): TaggedCache {
		return new TaggedCache($this, new TagSet($this, is_array($names) ? $names : func_get_args()));
	}

}

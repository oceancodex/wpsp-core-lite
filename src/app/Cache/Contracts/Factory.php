<?php

namespace WPSPCORELITE\App\Cache\Contracts;

/**
 * Mirror of Illuminate\Contracts\Cache\Factory.
 */
interface Factory {

	/**
	 * Get a cache store instance by name.
	 */
	public function store(?string $name = null): Repository;

}

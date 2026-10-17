<?php

namespace WPSPCORELITE\App\Cache\RateLimiting;

/**
 * Mirror of Illuminate\Cache\RateLimiting\Unlimited: Limit::none().
 */
class Unlimited extends GlobalLimit {

	public function __construct() {
		parent::__construct(PHP_INT_MAX);
	}

}

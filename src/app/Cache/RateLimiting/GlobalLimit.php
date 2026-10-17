<?php

namespace WPSPCORELITE\App\Cache\RateLimiting;

/**
 * Mirror of Illuminate\Cache\RateLimiting\GlobalLimit: one counter shared by every request.
 */
class GlobalLimit extends Limit {

	public function __construct(int $maxAttempts, int $decaySeconds = 60) {
		parent::__construct('', $maxAttempts, $decaySeconds);
	}

}

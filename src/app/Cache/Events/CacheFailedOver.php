<?php

namespace WPSPCORELITE\App\Cache\Events;

/**
 * Mirror of Illuminate\Cache\Events\CacheFailedOver (failover driver).
 */
class CacheFailedOver {

	public ?string    $storeName;
	public \Throwable $exception;

	public function __construct(?string $storeName, \Throwable $exception) {
		$this->storeName = $storeName;
		$this->exception = $exception;
	}

}

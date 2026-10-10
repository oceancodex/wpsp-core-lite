<?php

namespace WPSPCORELITE\App;

/**
 * Tương đương Illuminate\Contracts\Support\DeferrableProvider.
 *
 * Provider implement interface này sẽ chỉ được register() khi một service
 * trong provides() được make() lần đầu.
 */
interface DeferrableProvider {

	/**
	 * @return string[]
	 */
	public function provides();

}
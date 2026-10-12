<?php

namespace WPSPCORELITE\App\Routes\Apis;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait ApisRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->apis();
		$this->hooks();
	}

	/*
	 *
	 */

	abstract public function apis();

}
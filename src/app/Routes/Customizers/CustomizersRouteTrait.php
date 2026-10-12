<?php

namespace WPSPCORELITE\App\Routes\Customizers;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait CustomizersRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->customizers();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function customizers();

}
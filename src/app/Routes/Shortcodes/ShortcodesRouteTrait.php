<?php

namespace WPSPCORELITE\App\Routes\Shortcodes;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait ShortcodesRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->shortcodes();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function shortcodes();

}
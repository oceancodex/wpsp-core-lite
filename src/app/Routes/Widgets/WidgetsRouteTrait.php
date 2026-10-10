<?php

namespace WPSPCORELITE\App\Routes\Widgets;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait WidgetsRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->widgets();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function widgets();

}
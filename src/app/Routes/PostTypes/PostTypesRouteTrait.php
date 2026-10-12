<?php

namespace WPSPCORELITE\App\Routes\PostTypes;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait PostTypesRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->post_types();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function post_types();

}
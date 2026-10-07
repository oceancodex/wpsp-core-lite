<?php

namespace WPSPCORELITE\App\Routes\PostTypeColumns;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait PostTypeColumnsRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->post_type_columns();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function post_type_columns();

}
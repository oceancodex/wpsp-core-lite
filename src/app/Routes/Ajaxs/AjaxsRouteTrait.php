<?php

namespace WPSPCORELITE\App\Routes\Ajaxs;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait AjaxsRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->ajaxs();
		$this->hooks();
	}

	/*
	 *
	 */

	abstract public function ajaxs();

}
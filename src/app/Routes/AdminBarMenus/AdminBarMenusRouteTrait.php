<?php

namespace WPSPCORELITE\App\Routes\AdminBarMenus;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait AdminBarMenusRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->admin_bar_menus();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function admin_bar_menus();

}
<?php

namespace WPSPCORELITE\App\Routes\ThemeTemplates;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait ThemeTemplatesRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->theme_templates();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function theme_templates();

}
<?php

namespace WPSPCORELITE\App\Routes\PluginColumns;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait PluginColumnsRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->plugin_columns();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function plugin_columns();

}
<?php

namespace WPSPCORELITE\App\Routes\MediaColumns;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait MediaColumnsRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->media_columns();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function media_columns();

}
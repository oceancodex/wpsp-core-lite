<?php
namespace WPSPCORELITE\App\Routes\Blocks;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait BlocksRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->blocks();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function blocks();

}
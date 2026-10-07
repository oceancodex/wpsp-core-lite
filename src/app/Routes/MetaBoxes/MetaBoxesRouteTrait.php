<?php

namespace WPSPCORELITE\App\Routes\MetaBoxes;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait MetaBoxesRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->meta_boxes();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function meta_boxes();

}
<?php

namespace WPSPCORELITE\App\Routes\UserMetaBoxes;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait UserMetaBoxesRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->user_meta_boxes();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function user_meta_boxes();

}
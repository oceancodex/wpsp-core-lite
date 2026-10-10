<?php

namespace WPSPCORELITE\App\Routes\CommentColumns;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait CommentColumnsRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->comment_columns();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function comment_columns();

}
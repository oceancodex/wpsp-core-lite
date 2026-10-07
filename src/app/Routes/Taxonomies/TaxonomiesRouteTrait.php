<?php

namespace WPSPCORELITE\App\Routes\Taxonomies;

use WPSPCORELITE\App\Traits\HookRunnerTrait;

trait TaxonomiesRouteTrait {

	use HookRunnerTrait;

	public function register() {
		$this->taxonomies();
		$this->hooks();
	}

	/*
     *
     */

	abstract public function taxonomies();

}
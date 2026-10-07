<?php

namespace WPSPCORELITE\App\Integrations;

use WPSPCORELITE\BaseInstances;

class BaseIntegration extends BaseInstances {

	public $activate = true;

	/*
	 *
	 */

	public function getActivate() {
		return $this->activate;

	}

}
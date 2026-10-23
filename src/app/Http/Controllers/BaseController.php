<?php

namespace WPSPCORELITE\App\Http\Controllers;

use WPSPCORELITE\App\Traits\InstanceMiddlewareTrait;
use WPSPCORELITE\BaseInstances;

abstract class BaseController extends BaseInstances {

	use InstanceMiddlewareTrait;

}
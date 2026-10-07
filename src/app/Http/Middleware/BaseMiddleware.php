<?php

namespace WPSPCORELITE\App\Http\Middleware;

use WPSPCORELITE\BaseInstances;

abstract class BaseMiddleware extends BaseInstances {

	/**
	 * @var $request \Symfony\Component\HttpFoundation\Request|\WP_REST_Request
	 */
	abstract public function handle($request);

}
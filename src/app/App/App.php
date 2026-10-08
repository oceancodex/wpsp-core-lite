<?php

namespace WPSPCORELITE\App\Request;

use WPSPCORELITE\App\Facade;

class Request extends Facade {

	protected static function getFacadeAccessor() {
		return 'app';
	}

	/**
	 * Object request thật (Laravel: Request::instance()).
	 *
	 * @return \WPSPCORELITE\App\Http\Request
	 */
	public static function instance() {
		return static::getFacadeRoot();
	}

}
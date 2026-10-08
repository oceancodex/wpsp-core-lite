<?php

namespace WPSPCORELITE\App\App;

use WPSPCORELITE\App\Facade;

class App extends Facade {

	protected static function getFacadeAccessor() {
		return 'app';
	}

	public static function instance() {
		return static::getFacadeRoot();
	}

}
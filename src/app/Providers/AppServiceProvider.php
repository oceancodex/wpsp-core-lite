<?php

namespace WPSPCORELITE\App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider {

	/**
	 * Register any application services.
	 */
	public function register() {
		//
	}

	/**
	 * Bootstrap any application services.
	 */
	public function boot() {
		echo '<pre style="background: white; z-index: 9999; position: relative;">'; print_r('123'); echo '</pre>';
		//
	}

}
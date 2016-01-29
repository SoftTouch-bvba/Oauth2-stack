<?php namespace Cloudoki\OaStack;

use Illuminate\Support\ServiceProvider;

class OaStackServiceProvider extends ServiceProvider {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		# $this->package('cloudoki/oastack');
		
		# Oauth2 Routes
		if (! $this->app->routesAreCached ())
		{
			require __DIR__.'/../../routes.php';
		}
		
		# Oauth2 Views
		$this->loadViewsFrom (__DIR__.'/../../views', 'oastack');
		
		# Ouath2 simple filter
		# include __DIR__.'/../../filters.php';
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{

		$this->app['oastack'] = $this->app->share(function($app)
        {
            return new OaStack;
        });
	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides()
	{
		return array('oastack');
	}

}

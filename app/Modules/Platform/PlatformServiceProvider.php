<?php

namespace App\Modules\Platform;

use App\Modules\Platform\Access\PermissionChecker;
use App\Modules\Platform\Access\RequireFeature;
use App\Modules\Platform\Tenancy\CurrentCompany;
use App\Modules\Platform\Tenancy\SetCurrentCompany;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped = di-reset per request / per job (aman untuk Octane dan queue worker)
        $this->app->scoped(CurrentCompany::class);
        $this->app->scoped(PermissionChecker::class);
    }

    public function boot(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('company', SetCurrentCompany::class);
        $router->aliasMiddleware('feature', RequireFeature::class);

        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}

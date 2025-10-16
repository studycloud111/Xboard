<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        //
        if (admin_setting('force_https')) {
            resolve(\Illuminate\Routing\UrlGenerator::class)->forceScheme('https');
        }

        parent::boot();
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        Route::middleware('web')
            ->namespace($this->namespace)
            ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    /**
     * 获取隐藏的API路径
     * 
     * 优化策略：
     * - 在 Octane 环境下使用 60 秒短期缓存（保证性能的同时允许及时更新）
     * - 非 Octane 环境使用每次请求读取（传统 PHP-FPM 模式）
     */
    private function getHiddenApiPath()
    {
        try {
            // 检查是否是 Octane 环境
            $isOctane = class_exists(\Laravel\Octane\Octane::class);
            
            if ($isOctane) {
                // Octane 环境：使用 60 秒短期缓存（平衡性能和实时性）
                return Cache::remember('hidden_api_path_route', 60, function () {
                    return $this->fetchHiddenPathFromDatabase();
                });
            } else {
                // 非 Octane 环境：每次都从数据库读取（性能影响小）
                return $this->fetchHiddenPathFromDatabase();
            }
        } catch (\Exception $e) {
            // 如果出现任何错误（比如数据库未连接），使用默认值
            \Log::warning('Failed to get hidden API path: ' . $e->getMessage());
            return '/oxa/3vm';
        }
    }

    /**
     * 从数据库获取隐藏路径
     */
    private function fetchHiddenPathFromDatabase()
    {
        // 从数据库读取（只取第一条）
        $result = DB::table('v2_settings')
            ->where('name', 'hidden_api_path')
            ->orderBy('id', 'asc')
            ->first();
        
        // 如果有记录，返回值；否则返回默认值
        return $result ? $result->value : '/oxa/3vm';
    }

    protected function mapApiRoutes()
    {
        // 原始API路由
        Route::group([
            'prefix' => '/api/v1',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http/Routes/V1') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V1\\' . basename($file, '.php'))->map($router);
            }
        });

        // 获取隐藏路径
        $hiddenPath = $this->getHiddenApiPath();
        
        // 隐藏API路由 - 使用相同的控制器，只是路径不同
        Route::group([
            'prefix' => $hiddenPath,  // 动态隐藏路径
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http/Routes/V1') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V1\\' . basename($file, '.php'))->map($router);
            }
        });

        Route::group([
            'prefix' => '/api/v2',
            'middleware' => 'api',
            'namespace' => $this->namespace
        ], function ($router) {
            foreach (glob(app_path('Http/Routes/V2') . '/*.php') as $file) {
                $this->app->make('App\\Http\\Routes\\V2\\' . basename($file, '.php'))->map($router);
            }
        });
    }
}

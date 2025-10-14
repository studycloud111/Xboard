<?php

use App\Services\ThemeService;
use App\Services\UpdateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

/**
 * 获取或生成隐藏的API路径
 * 确保数据库只有一条记录
 */
function getHiddenApiPath() {
    return Cache::rememberForever('hidden_api_path', function () {
        // 获取所有记录
        $allPaths = DB::table('v2_settings')
            ->where('name', 'hidden_api_path')
            ->get();
        
        // 如果有多条记录，清理多余的
        if ($allPaths->count() > 1) {
            // 保留第一条（ID最小的）
            $keepId = $allPaths->min('id');
            $keepPath = $allPaths->where('id', $keepId)->first()->value;
            
            // 删除其他的
            DB::table('v2_settings')
                ->where('name', 'hidden_api_path')
                ->where('id', '!=', $keepId)
                ->delete();
            
            Log::info('Cleaned duplicate hidden API paths', [
                'kept' => $keepPath,
                'deleted_count' => $allPaths->count() - 1
            ]);
            
            return $keepPath;
        }
        
        // 如果只有一条记录，直接使用
        if ($allPaths->count() == 1) {
            return $allPaths->first()->value;
        }
        
        // 如果没有记录，生成新的
        $part1 = strtolower(Str::random(3));
        $part2 = strtolower(Str::random(3));
        $path = "/{$part1}/{$part2}";
        
        // 插入到数据库
        DB::table('v2_settings')->insert([
            'name' => 'hidden_api_path',
            'value' => $path,
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        Log::info('Generated new hidden API path', ['path' => $path]);
        
        return $path;
    });
}


Route::get('/', function (Request $request) {
    if (admin_setting('app_url') && admin_setting('safe_mode_enable', 0)) {
        if ($request->server('HTTP_HOST') !== parse_url(admin_setting('app_url'))['host']) {
            abort(403);
        }
    }

    $theme = admin_setting('frontend_theme', 'Xboard');
    $themeService = new ThemeService();

    try {
        if (!$themeService->exists($theme)) {
            if ($theme !== 'Xboard') {
                Log::warning('Theme not found, switching to default theme', ['theme' => $theme]);
                $theme = 'Xboard';
                admin_setting(['frontend_theme' => $theme]);
            }
            $themeService->switch($theme);
        }

        if (!$themeService->getThemeViewPath($theme)) {
            throw new Exception('主题视图文件不存在');
        }

        $publicThemePath = public_path('theme/' . $theme);
        if (!File::exists($publicThemePath)) {
            $themePath = $themeService->getThemePath($theme);
            if (!$themePath || !File::copyDirectory($themePath, $publicThemePath)) {
                throw new Exception('主题初始化失败');
            }
            Log::info('Theme initialized in public directory', ['theme' => $theme]);
        }

        // 自动注入隐藏API路径
        $hiddenApiPath = getHiddenApiPath();
        
        // 获取主题配置并自动设置隐藏路径
        $themeConfig = $themeService->getConfig($theme);
        $themeConfig['enable_api_path_hiding'] = 'true';  // 自动启用
        $themeConfig['custom_api_url'] = $hiddenApiPath;   // 自动设置路径
        
        $renderParams = [
            'title' => admin_setting('app_name', 'Xboard'),
            'theme' => $theme,
            'version' => app(UpdateService::class)->getCurrentVersion(),
            'description' => admin_setting('app_description', 'Xboard is best'),
            'logo' => admin_setting('logo'),
            'theme_config' => $themeConfig,
            'hidden_api_path' => $hiddenApiPath  // 备用：直接传递
        ];
        return view('theme::' . $theme . '.dashboard', $renderParams);
    } catch (Exception $e) {
        Log::error('Theme rendering failed', [
            'theme' => $theme,
            'error' => $e->getMessage()
        ]);
        abort(500, '主题加载失败');
    }
});

//TODO:: 兼容
Route::get('/' . admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => admin_setting('app_name', 'XBoard'),
        'theme_sidebar' => admin_setting('frontend_theme_sidebar', 'light'),
        'theme_header' => admin_setting('frontend_theme_header', 'dark'),
        'theme_color' => admin_setting('frontend_theme_color', 'default'),
        'background_url' => admin_setting('frontend_background_url'),
        'version' => app(UpdateService::class)->getCurrentVersion(),
        'logo' => admin_setting('logo'),
        'secure_path' => admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/' . (admin_setting('subscribe_path', 's')) . '/{token}', [\App\Http\Controllers\V1\Client\ClientController::class, 'subscribe'])
    ->middleware('client')
    ->name('client.subscribe');

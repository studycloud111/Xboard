<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;

class RefreshHiddenPath extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hidden-path:refresh';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '刷新隐藏 API 路径缓存并重启服务';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('🔄 正在刷新隐藏路径...');
        
        // 1. 清除隐藏路径缓存
        Cache::forget('hidden_api_path_route');
        $this->line('  ✅ 隐藏路径缓存已清除');
        
        // 2. 清除路由缓存
        try {
            Artisan::call('route:clear');
            $this->line('  ✅ 路由缓存已清除');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  路由缓存清除失败: ' . $e->getMessage());
        }
        
        // 3. 清除配置缓存
        try {
            Artisan::call('config:clear');
            $this->line('  ✅ 配置缓存已清除');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  配置缓存清除失败: ' . $e->getMessage());
        }
        
        // 4. 重新生成路由缓存
        try {
            Artisan::call('route:cache');
            $this->line('  ✅ 路由缓存已重建');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  路由缓存重建失败: ' . $e->getMessage());
        }
        
        // 5. 尝试重启 Octane（如果使用）
        if (class_exists(\Laravel\Octane\Octane::class)) {
            try {
                Artisan::call('octane:reload');
                $this->line('  ✅ Octane 服务已重启');
            } catch (\Exception $e) {
                $this->warn('  ⚠️  Octane 重启失败: ' . $e->getMessage());
                $this->warn('  ℹ️  请手动执行: php artisan octane:reload');
            }
        }
        
        $this->info('');
        $this->info('🎉 隐藏路径已刷新！');
        $this->info('');
        
        return 0;
    }
}


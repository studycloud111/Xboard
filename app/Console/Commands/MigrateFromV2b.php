<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class MigrateFromV2b extends Command
{
    protected $signature = 'migrateFromV2b {version?}';
    protected $description = '供不同版本V2b迁移到本项目的脚本';

    public function handle()
    {
        $version = $this->argument('version');
        if($version === 'config'){
            $this->MigrateV2ConfigToV2Settings();
            return;
        }

        // V2boardpro 特殊处理（需要迁移节点到统一表）
        if ($version === 'v2boardpro') {
            return $this->migrateFromV2boardpro();
        }

        // Define your SQL commands based on versions
        $sqlCommands = [
            'dev231027' => [
                // SQL commands for version Dev 2023/10/27
                'ALTER TABLE v2_order ADD COLUMN surplus_order_ids TEXT NULL;',
                'ALTER TABLE v2_plan DROP COLUMN daily_unit_price, DROP COLUMN transfer_unit_price;',
                'ALTER TABLE v2_server_hysteria DROP COLUMN ignore_client_bandwidth, DROP COLUMN obfs_type;'
            ],            
            '1.7.4' => [
                'CREATE TABLE `v2_server_vless` ( 
                    `id` INT AUTO_INCREMENT PRIMARY KEY, 
                    `group_id` TEXT NOT NULL, 
                    `route_id` TEXT NULL, 
                    `name` VARCHAR(255) NOT NULL,
                    `parent_id` INT NULL, 
                    `host` VARCHAR(255) NOT NULL, 
                    `port` INT NOT NULL, 
                    `server_port` INT NOT NULL, 
                    `tls` BOOLEAN NOT NULL, 
                    `tls_settings` TEXT NULL, 
                    `flow` VARCHAR(64) NULL, 
                    `network` VARCHAR(11) NOT NULL, 
                    `network_settings` TEXT NULL, 
                    `tags` TEXT NULL, 
                    `rate` VARCHAR(11) NOT NULL, 
                    `show` BOOLEAN DEFAULT 0, 
                    `sort` INT NULL, 
                    `created_at` INT NOT NULL, 
                    `updated_at` INT NOT NULL
                );'
            ],
            '1.7.3' => [
                'ALTER TABLE `v2_stat_order` RENAME TO `v2_stat`;',
                "ALTER TABLE `v2_stat` CHANGE COLUMN order_amount paid_total INT COMMENT '订单合计';",
                "ALTER TABLE `v2_stat` CHANGE COLUMN order_count paid_count INT COMMENT '邀请佣金';",
                "ALTER TABLE `v2_stat` CHANGE COLUMN commission_amount commission_total INT COMMENT '佣金合计';",
                "ALTER TABLE `v2_stat`
                    ADD COLUMN order_count INT NULL,
                    ADD COLUMN order_total INT NULL,
                    ADD COLUMN register_count INT NULL,
                    ADD COLUMN invite_count INT NULL,
                    ADD COLUMN transfer_used_total VARCHAR(32) NULL;
                ",  
                "CREATE TABLE `v2_log` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `title` TEXT NOT NULL,
                    `level` VARCHAR(11) NULL,
                    `host` VARCHAR(255) NULL,
                    `uri` VARCHAR(255) NOT NULL,
                    `method` VARCHAR(11) NOT NULL,
                    `data` TEXT NULL,
                    `ip` VARCHAR(128) NULL,
                    `context` TEXT NULL,
                    `created_at` INT NOT NULL,
                    `updated_at` INT NOT NULL
                );",
                'CREATE TABLE `v2_server_hysteria` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `group_id` VARCHAR(255) NOT NULL,
                    `route_id` VARCHAR(255) NULL,
                    `name` VARCHAR(255) NOT NULL,
                    `parent_id` INT NULL,
                    `host` VARCHAR(255) NOT NULL,
                    `port` VARCHAR(11) NOT NULL,
                    `server_port` INT NOT NULL,
                    `tags` VARCHAR(255) NULL,
                    `rate` VARCHAR(11) NOT NULL,
                    `show` BOOLEAN DEFAULT FALSE,
                    `sort` INT NULL,
                    `up_mbps` INT NOT NULL,
                    `down_mbps` INT NOT NULL,
                    `server_name` VARCHAR(64) NULL,
                    `insecure` BOOLEAN DEFAULT FALSE,
                    `created_at` INT NOT NULL,
                    `updated_at` INT NOT NULL
                );',
                "CREATE TABLE `v2_server_vless` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY, 
                    `group_id` TEXT NOT NULL, 
                    `route_id` TEXT NULL, 
                    `name` VARCHAR(255) NOT NULL, 
                    `parent_id` INT NULL, 
                    `host` VARCHAR(255) NOT NULL, 
                    `port` INT NOT NULL, 
                    `server_port` INT NOT NULL, 
                    `tls` BOOLEAN NOT NULL, 
                    `tls_settings` TEXT NULL, 
                    `flow` VARCHAR(64) NULL, 
                    `network` VARCHAR(11) NOT NULL, 
                    `network_settings` TEXT NULL, 
                    `tags` TEXT NULL, 
                    `rate` VARCHAR(11) NOT NULL, 
                    `show` BOOLEAN DEFAULT FALSE, 
                    `sort` INT NULL, 
                    `created_at` INT NOT NULL, 
                    `updated_at` INT NOT NULL
                );",
            ],
            'wyx2685' => [
                "ALTER TABLE `v2_plan` DROP COLUMN `device_limit`;",
                "ALTER TABLE `v2_server_hysteria` DROP COLUMN `version`, DROP COLUMN `obfs`, DROP COLUMN `obfs_password`;",
                "ALTER TABLE `v2_server_trojan` DROP COLUMN `network`, DROP COLUMN `network_settings`;",
                "ALTER TABLE `v2_user` DROP COLUMN `device_limit`;"
            ],
            'v2boardpro' => [] // V2boardpro 特殊处理，在上面已经调用了专门的方法
        ];

        if (!$version) {
            $version = $this->choice('请选择你迁移前的V2board版本:', array_keys($sqlCommands));
        }

        if (array_key_exists($version, $sqlCommands)) {
            
            try {
                foreach ($sqlCommands[$version] as $sqlCommand) {
                    // Execute SQL command
                    DB::statement($sqlCommand);
                }
                
                $this->info('1️⃣、数据库差异矫正成功');

                // 初始化数据库迁移
                $this->call('db:seed', ['--class' => 'OriginV2bMigrationsTableSeeder']);
                $this->info('2️⃣、数据库迁移记录初始化成功');

                $this->call('xboard:update');
                $this->info('3️⃣、更新成功');

                $this->info("🎉：成功从 $version 迁移到Xboard");
            } catch (\Exception $e) {
                // An error occurred, rollback the transaction
                $this->error('迁移失败'. $e->getMessage() );
            }


        } else {
            $this->error("你所输入的版本未找到");
        }
    }

    public function MigrateV2ConfigToV2Settings()
    {
        Artisan::call('config:clear');
        $configValue = config('v2board') ?? [];

        foreach ($configValue as $k => $v) {
            // 检查记录是否已存在
            $existingSetting = Setting::where('name', $k)->first();
            
            // 如果记录不存在，则插入
            if ($existingSetting) {
                $this->warn("配置 {$k} 在数据库已经存在， 忽略");
                continue;
            }
            Setting::create([
                'name' => $k,
                'value' => is_array($v)? json_encode($v) : $v,
            ]);
            $this->info("配置 {$k} 迁移成功");
        }
        Artisan::call('config:cache');

        $this->info('所有配置迁移完成');
    }

    /**
     * 从 V2boardpro 迁移节点数据
     */
    protected function migrateFromV2boardpro()
    {
        $this->info('');
        $this->info('========================================');
        $this->info('  V2boardpro → Xboardpro 节点迁移工具');
        $this->info('========================================');
        $this->info('');

        try {
            $this->info('1️⃣  开始迁移节点数据...');
            
            $totalMigrated = 0;
            $totalMigrated += $this->migrateServerData('trojan');
            $totalMigrated += $this->migrateServerData('vmess');
            $totalMigrated += $this->migrateServerData('vless');
            $totalMigrated += $this->migrateServerData('shadowsocks');
            $totalMigrated += $this->migrateServerData('hysteria');
            $totalMigrated += $this->migrateServerData('tuic');
            $totalMigrated += $this->migrateServerData('anytls');

            $this->info('');
            $this->info('2️⃣  更新父节点引用关系...');
            $this->updateServerParentIds();
            $this->info('✅ 父节点引用更新完成');

            $this->info('');
            $this->info('3️⃣  运行数据库迁移和更新...');
            $this->call('db:seed', ['--class' => 'OriginV2bMigrationsTableSeeder']);
            $this->call('xboard:update');

            $this->info('');
            $this->info('4️⃣  验证迁移结果...');
            $this->verifyServerMigration();

            $this->info('');
            $this->info('========================================');
            $this->info("🎉 迁移成功！共迁移 {$totalMigrated} 个节点");
            $this->info('========================================');
            $this->info('');
            
            return 0;

        } catch (\Exception $e) {
            $this->error('');
            $this->error('❌ 迁移失败：' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * 迁移指定类型的节点数据
     */
    protected function migrateServerData($type)
    {
        $tableName = 'v2_server_' . $type;
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            $this->line("  ⏭️  跳过 {$type}（表不存在）");
            return 0;
        }

        $servers = DB::table($tableName)->get();
        $count = 0;

        foreach ($servers as $server) {
            $data = [
                'type' => $type,
                'code' => (string) $server->id,
                'parent_id' => $server->parent_id,
                'group_ids' => $this->normalizeJson($server->group_id),
                'route_ids' => $this->normalizeJson($server->route_id ?? null),
                'name' => $server->name,
                'rate' => $server->rate,
                'tags' => $this->normalizeJson($server->tags ?? null),
                'host' => $server->host,
                'port' => $server->port,
                'server_port' => $server->server_port,
                'show' => $server->show ?? 0,
                'sort' => $server->sort ?? null,
                'created_at' => $server->created_at,
                'updated_at' => $server->updated_at,
            ];

            // 根据不同类型设置协议特定配置
            $data['protocol_settings'] = $this->getProtocolSettings($type, $server);

            DB::table('v2_server')->insert($data);
            $count++;
        }

        $this->line("  ✅ {$type}: {$count} 个节点");
        return $count;
    }

    /**
     * 获取协议特定配置
     */
    protected function getProtocolSettings($type, $server)
    {
        $settings = [];

        switch ($type) {
            case 'trojan':
                $settings = [
                    'allow_insecure' => $server->allow_insecure ?? 0,
                    'server_name' => $server->server_name ?? null,
                    'network' => $server->network ?? null,
                    'network_settings' => $this->parseJson($server->network_settings ?? null),
                ];
                break;

            case 'vmess':
                $settings = [
                    'tls' => $server->tls ?? 0,
                    'network' => $server->network ?? null,
                    'rules' => $this->parseJson($server->rules ?? null),
                    'network_settings' => $this->parseJson($server->networkSettings ?? null),
                    'tls_settings' => $this->parseJson($server->tlsSettings ?? null),
                ];
                break;

            case 'vless':
                $settings = [
                    'tls' => $server->tls ?? 0,
                    'tls_settings' => $this->parseJson($server->tls_settings ?? null),
                    'flow' => $server->flow ?? null,
                    'network' => $server->network ?? null,
                    'network_settings' => $this->parseJson($server->network_settings ?? null),
                ];
                break;

            case 'shadowsocks':
                $settings = [
                    'cipher' => $server->cipher ?? null,
                    'obfs' => $server->obfs ?? null,
                    'obfs_settings' => $this->parseJson($server->obfs_settings ?? null),
                ];
                break;

            case 'hysteria':
                $settings = [
                    'version' => $server->version ?? 2,
                    'bandwidth' => [
                        'up' => $server->up_mbps ?? 100,
                        'down' => $server->down_mbps ?? 100,
                    ],
                    'obfs' => [
                        'open' => $server->is_obfs ?? 0,
                        'type' => 'salamander',
                        'password' => $server->obfs_password ?? null,
                    ],
                    'tls' => [
                        'server_name' => $server->server_name ?? null,
                        'allow_insecure' => $server->insecure ?? 0,
                    ],
                ];
                break;

            case 'tuic':
                $settings = [
                    'version' => $server->version ?? 5,
                    'server_name' => $server->server_name ?? null,
                    'congestion_control' => $server->congestion_control ?? null,
                    'insecure' => $server->insecure ?? 0,
                    'disable_sni' => $server->disable_sni ?? 0,
                ];
                break;

            case 'anytls':
                $settings = [
                    'server_name' => $server->server_name ?? null,
                    'insecure' => $server->insecure ?? 0,
                    'padding_scheme' => $this->parseJson($server->padding_scheme ?? null),
                ];
                break;
        }

        return json_encode($settings);
    }

    /**
     * 更新节点父节点引用
     */
    protected function updateServerParentIds()
    {
        $servers = DB::table('v2_server')
            ->whereNotNull('parent_id')
            ->get();

        $updated = 0;
        foreach ($servers as $server) {
            $parentId = DB::table('v2_server')
                ->where('type', $server->type)
                ->where('code', $server->parent_id)
                ->value('id');

            if ($parentId) {
                DB::table('v2_server')
                    ->where('id', $server->id)
                    ->update(['parent_id' => $parentId]);
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->line("  更新了 {$updated} 个父节点引用");
        }
    }

    /**
     * 验证节点迁移结果
     */
    protected function verifyServerMigration()
    {
        $stats = DB::table('v2_server')
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->get();

        if ($stats->isEmpty()) {
            $this->warn('  ⚠️  未找到任何节点数据');
            return;
        }

        $this->table(
            ['节点类型', '数量'], 
            $stats->map(function ($item) {
                return [$item->type, $item->count];
            })->toArray()
        );
    }

    /**
     * 标准化 JSON 格式
     */
    protected function normalizeJson($value)
    {
        if (empty($value) || $value === null) {
            return '[]';
        }
        
        if (is_string($value) && json_decode($value) !== null) {
            return $value;
        }
        
        if (is_string($value)) {
            $array = array_filter(explode(',', $value));
            return json_encode($array);
        }
        
        return '[]';
    }

    /**
     * 解析 JSON 字段
     */
    protected function parseJson($value)
    {
        if (empty($value) || $value === null) {
            return null;
        }
        
        $decoded = json_decode($value, true);
        return $decoded !== null ? $decoded : null;
    }
}

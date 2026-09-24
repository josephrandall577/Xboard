<?php

namespace Plugin\PlanBonus;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // 路由与视图由 PluginManager 自动加载，无需额外引导
    }
}

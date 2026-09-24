# 套餐赠送时长管理（plan_bonus）

为订阅套餐提供「按周期赠送时长」的活动配置能力（如：买年付送 6 个月）。赠送月数在下单时快照到订单，支付开通时自动叠加到账期。

## 启用方式

1. 确保本插件目录位于 `plugins/PlanBonus/`（随仓库分发，已就位）
2. 登录管理面板 → 插件 → 找到「套餐赠送时长管理」→ 安装并启用
3. 浏览器直接访问 **`/plugins/plan-bonus`** 打开赠送管理页面

> 管理面板 SPA 无法注入菜单（官方 bundle 无扩展点），因此管理页是插件提供的独立页面，请收藏该地址。

## 页面使用

- 首次打开时，页面会自动从浏览器 localStorage 探测管理面板登录票据（同源）；探测失败时按提示粘贴一次管理员 Bearer 票据（`GET /api/v1/passport/auth/login` 返回的 `auth_data`），之后会记住
- 表格中为每个已定价的计费周期填写赠送月数（0~36 的整数），「保存」生效
- 「清空全部」= 活动下线（该套餐赠送配置置空）

## 行为规则

| 场景 | 行为 |
|---|---|
| 新购 / 续费 / 换购 | 均按下单时套餐配置快照赠送月数 |
| 修改/清空赠送配置 | 只影响之后的新订单，在途订单按快照照常发放 |
| 一次性套餐 / 重置流量包 | 不支持赠送 |
| 换购折抵 | 已计入历史订单的赠送月数（核心逻辑） |
| 原生面板保存套餐 | 请求不携带 `bonuses` 键时保留现有赠送配置，不会被清空 |

## 技术结构

```
plugins/PlanBonus/
├── config.json                     # feature 插件声明
├── Plugin.php
├── routes/
│   ├── web.php                     # GET /plugins/plan-bonus（页面壳，公开）
│   └── api.php                     # /api/plugin/plan-bonus/*（admin 中间件）
├── Controllers/PlanBonusController.php
└── resources/views/manage.blade.php
```

- 核心数据模型：`v2_plan.bonuses`（JSON，按周期赠送月数）、`v2_order.bonus_months`（下单快照）
- 清洗规则单一来源：`App\Models\Plan::cleanBonusesConfig()`
- 测试：`tests/Feature/Plugin/PlanBonusPluginTest.php`、`tests/Unit/Services/OrderBonusTest.php`、`tests/Unit/Requests/AdminPlanSaveTest.php`

## 本地运行测试

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=database/database.sqlite \
  vendor/bin/phpunit tests/Feature/Plugin tests/Unit/Services tests/Unit/Requests
```

> ⚠️ 必须带 `APP_ENV=testing`：仓库暂无 phpunit.xml，phpunit 默认以 production 环境运行，`RefreshDatabase` 触发的 `migrate:fresh` 会被 Laravel 生产保护静默拦截，导致表不存在。

> 已知框架行为：控制台内核（`Console\Kernel::commands()`）会提前执行插件初始化。功能测试需在插入启用记录后重置 `PluginManager` 的 `pluginsInitialized` 标记（本插件测试的 `reloadPluginRoutes()` 已示范）。

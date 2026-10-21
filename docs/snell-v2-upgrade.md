# xboard-snell v2 面板升级

本分支的 `snell` 类型专用于 xboard-snell v2：协议固定为 v6/default，
每个用户以 `user.uuid` 原始字符串作为独立 PSK。同一节点的两名用户
共享入口地址和端口，但不会共享 PSK。不生成 userkey/client_id。

## 部署与存量配置

1. 升级前备份数据库和节点 TOML，并导出存量配置：

   ```sql
   SELECT id, name, host, port, server_port, protocol_settings
   FROM v2_server WHERE type = 'snell';
   ```

2. 确认这些节点均迁移到 xboard-snell v2。如果还在使用其他 Snell 后端，
   应先拆分节点类型与能力，不能直接运行本次迁移。
3. 部署面板后运行 `php artisan xboard:update`（Docker 启动脚本已有该步骤）。
   新增迁移会将所有 Snell 节点的 version/mode 归一化为 `6/default`，
   保留旧 psk 和其他存储字段，不改其他协议。回滚迁移不会恢复旧模式；
   如需回退旧面板和节点，应恢复升级前备份。
4. 同步修改节点本地 TOML 为 `mode = "default"`，核对监听地址及端口映射，
   然后重启节点。面板配置接口和 `sync.config` 不能让 v2.0.0 节点自动热更新配置。
5. 用户刷新订阅。面板每次请求按当前用户生成凭据；缓存的模板不含用户凭据。
   若部署了插件或 CDN 的订阅响应缓存，需按用户隔离并在 UUID 重置时失效。

管理接口允许省略 version/mode，保存时补齐默认值。v5、unshaped、unsafe-raw
均返回 422。旧 psk 字段仅保留兼容存储，不作为用户凭据或节点配置输出。

## 管理端分发包

`public/assets/admin` 是上游分发子模块，本仓库没有对应 React 管理端源码，
目前也无法取得上游源码。本次沿用仓库已有的 `scripts/patch-admin-snell.php`
构建注入方式：修改脚本内表单定义，再从固定版本的原始分发包生成产物。
这与节点项目指南建议的“管理端完整源码构建”有差异；没有直接提交修改后的压缩 JS。

新表单移除共享 PSK 输入，显示 `PSK = user UUID`，版本和模式固定只读，
表单 schema 将提交值归一化为 v6/default 并移除旧 psk 字段。
构建后更新资源内容哈希及 HTML/manifest 引用，避免浏览器继续使用旧表单。
脚本重复运行不会再次修改产物；如果输入是未知或旧版 Snell 补丁产物则明确失败，
需从固定的子模块原始版本重新构建。Docker 构建已自动执行该脚本。

非 Docker 部署需要在拉取子模块后自行构建：

```sh
git submodule update --init --recursive
for bundle in public/assets/admin/assets/index-*.js; do
    php scripts/patch-admin-snell.php "$bundle"
done
```

如果已有旧版注入产物，应先备份本地修改并恢复子模块的固定原始版本，再执行构建。
取得完整管理端源码后，应将该表单迁入源码并构建分发包，随后移除历史注入方式。

## 节点接口与同步

推荐使用 `/api/v2/server/{handshake,config,user,report}`；已有 V1 部署可保持原接口。
GET 的 token/node_id 放在 query，POST 放在 JSON 请求体。

- config 返回 Snell 基础监听配置及 `version=6, mode=default`，不含共享 PSK。
- user 顶层返回 `{"users":[...]}`，含 id、原样 uuid、speed_limit、device_limit。
  保持分组、封禁、有效期及流量余额筛选；插件过滤后的稀疏键重新索引。
- 全量撤销返回 `{"users":[]}`。插件返回 null 等无效结果会报错，避免误撤销全体用户。
  仅在请求携带匹配的 If-None-Match 时返回 304。
- HTTP 和 WebSocket 使用同一个用户查询。Snell 用户变更、UUID 重置及旧分组撤销
  推送 `sync.users` 全量快照，因为节点 v2 不即时应用 `sync.user.delta`。
  需要运行 node_sync 队列；未连接 WebSocket 的节点在下一次轮询获取变化。
- 流量报告沿用 `processTraffic()` 与既有任务链路，按用户 ID 记录上传、下载增量并应用节点倍率。
  当前报告没有幂等 ID，不能用相同字节数去重重试。

## 验证与上线验收

```sh
composer install
php vendor/bin/phpunit
```

管理端构建回归需先运行 `git submodule update --init public/assets/admin`；未初始化时会跳过这两项。

测试使用 SQLite 内存数据库、array 缓存和 null 队列，不依赖生产数据库或 Redis。
需使用 composer.lock 支持的 PHP 版本；本次本地验证使用 PHP 8.4。

自动化覆盖保存校验及默认值、旧配置迁移、Surge/sing-box 两用户 PSK 隔离及 UUID 重置、
客户端版本筛选、节点配置接口、权限过滤、空数组与条件 ETag、插件过滤、WebSocket
全量快照、其他协议增量行为，以及报告的分用户方向和倍率。

这些是面板回归测试，不代表真实客户端联调。正式切换前仍需独立测试节点和两名用户
验证 TCP/UDP 载荷及计费，并用实际 Surge 版本检查首包和双向转发。
旧 PSK 撤销后的新连接拒绝行为属于节点验收；不能据此声称所有已建立转发流立即关闭。

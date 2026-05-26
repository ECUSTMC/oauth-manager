# OAuth Manager

Blessing Skin Server 插件 —— OAuth 授权管理与应用大厅。

## 功能

### OAuth 授权记录

- 查看当前账户授权的所有第三方 OAuth 应用
- 按客户端分组展示授权信息（应用名称、域名、授权时间、权限范围）
- 权限范围（Scope）自动翻译为中文，悬停显示原始标识
- 一键取消某个应用的授权（吊销 access token 及关联的 refresh token）

### OAuth 应用大厅

- 浏览站点所有可用的 OAuth 应用
- 显示应用名称、域名（可点击跳转）、创建者、使用人数
- 自动获取应用 favicon 图标，获取失败时显示默认图标
- 标记当前用户是否已授权

### 管理员配置

- 功能开关：独立控制「授权记录」和「应用大厅」的菜单显示
- 自动清理冗余令牌：同一客户端仅保留最新一条活跃令牌
- 自动删除已吊销令牌：查看授权记录时物理删除无用记录
- 一键清理全站已吊销记录
- 一键清理全站冗余令牌
- 显示全站已吊销令牌统计

## 依赖

- Blessing Skin Server `^6`
- PHP `^8.0`
- Laravel Passport（Blessing Skin 内置）

## 安装

将插件目录放入 Blessing Skin 的 `plugins/` 目录：

```bash
cp -r oauth-manager /path/to/blessing-skin/plugins/
```

然后在后台「插件管理」中启用 **OAuth 管理** 插件即可。

## 目录结构

```
oauth-manager/
├── package.json                          # 插件清单
├── bootstrap.php                         # 启动引导（注册路由、菜单）
├── callbacks.php                         # 生命周期回调（启用时初始化配置项）
├── src/
│   └── Controllers/
│       ├── OAuthRecordController.php     # 授权记录控制器
│       ├── AppHallController.php         # 应用大厅控制器
│       └── ConfigController.php          # 插件配置控制器
├── views/
│   ├── index.twig                        # 授权记录页面
│   ├── hall.twig                         # 应用大厅页面
│   └── config.twig                       # 配置页面
└── lang/
    ├── zh_CN/
    │   └── oauth-record.yml              # 中文翻译
    └── en/
        └── oauth-record.yml              # 英文翻译
```

## 路由

| 方法 | 路径 | 说明 |
|------|------|------|
| GET | `/user/oauth-record` | 授权记录页面 |
| POST | `/user/oauth-record/revoke/{tokenId}` | 吊销单条令牌 |
| POST | `/user/oauth-record/revoke-client/{clientId}` | 吊销某客户端的所有令牌 |
| GET | `/oauth-apps` | 应用大厅页面 |
| POST | `/admin/oauth-record/cleanup` | 清理全站已吊销记录 |
| POST | `/admin/oauth-record/cleanup-redundant` | 清理全站冗余令牌 |

授权记录路由需要登录且邮箱已验证，应用大厅仅需登录。

## 配置项

在后台「插件管理 → 插件配置 → OAuth 管理」中设置。

### 功能开关

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `oauth_record_enable_auth_record` | `true` | 启用 OAuth 授权记录页面（关闭后侧边栏菜单隐藏） |
| `oauth_record_enable_app_hall` | `true` | 启用 OAuth 应用大厅页面（关闭后侧边栏菜单隐藏） |

### 令牌清理

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `oauth_record_auto_cleanup` | `false` | 自动清理冗余令牌。开启后，查看授权记录时会自动吊销同一客户端多余的旧令牌，仅保留最新一条。 |
| `oauth_record_clean_revoked` | `false` | 自动删除已吊销令牌。开启后，查看授权记录时会从数据库中物理删除已吊销的令牌记录。 |

配置页还提供两个管理员操作按钮：

- **清理已吊销记录** — 一键删除全站所有 `revoked=true` 的 access token、refresh token、auth code
- **清理冗余令牌** — 一键吊销全站同一用户同一应用的多余令牌，仅保留最新一条

## 工作原理

插件直接读取 Laravel Passport 的 `oauth_access_tokens`、`oauth_clients` 等表，无需创建额外的数据库表。

查询时自动排除：

- 已吊销（`revoked = true`）的令牌
- 已过期（`expires_at < now`）的令牌
- 个人访问令牌（`personal_access_client = true`）
- 客户端本身已吊销的授权

取消授权时调用 Passport 的 `Token::revoke()` 将 `revoked` 置为 `true`，同时更新 `oauth_refresh_tokens` 表使刷新令牌一并失效。

## 许可证

MIT

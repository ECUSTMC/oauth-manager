# OAuth Record

Blessing Skin Server 插件 —— 查看 OAuth 授权记录并取消授权。

## 功能

- 查看当前账户授权的所有第三方 OAuth 应用
- 按客户端分组展示授权信息（应用名称、授权时间、权限范围、令牌数量）
- 一键取消某个应用的授权（吊销 access token 及关联的 refresh token）
- 侧边栏菜单入口，集成在用户中心
- 插件配置页，支持以下选项：
  - **自动清理冗余令牌** — 同一客户端仅保留最新一条活跃令牌，其余自动吊销
  - **自动删除已吊销令牌** — 查看授权记录时物理删除已吊销的令牌记录，释放数据库空间

## 依赖

- Blessing Skin Server `^6`
- PHP `^8.0`
- Laravel Passport（Blessing Skin 内置）

## 安装

将插件目录放入 Blessing Skin 的 `plugins/` 目录：

```bash
cp -r oauth-record /path/to/blessing-skin/plugins/
```

然后在后台「插件管理」中启用 **OAuth 授权记录** 插件即可。

## 目录结构

```
oauth-record/
├── package.json                          # 插件清单
├── bootstrap.php                         # 启动引导（注册路由、菜单）
├── callbacks.php                         # 生命周期回调（启用时初始化配置项）
├── src/
│   └── Controllers/
│       ├── OAuthRecordController.php     # 授权记录控制器
│       └── ConfigController.php          # 插件配置控制器
├── views/
│   ├── index.twig                        # 授权记录页面
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

所有路由需要登录且邮箱已验证。

## 配置项

在后台「插件管理 → 插件配置 → OAuth 授权记录」中设置。

| 配置项 | 默认值 | 说明 |
|--------|--------|------|
| `oauth_record_auto_cleanup` | `false` | 自动清理冗余令牌。开启后，查看授权记录时会自动吊销同一客户端多余的旧令牌，仅保留最新一条。 |
| `oauth_record_clean_revoked` | `false` | 自动删除已吊销令牌。开启后，查看授权记录时会从数据库中物理删除已吊销的令牌记录。 |

## 工作原理

插件直接读取 Laravel Passport 的 `oauth_access_tokens` 和 `oauth_clients` 表，无需创建额外的数据库表。

查询时自动排除：

- 已吊销（`revoked = true`）的令牌
- 已过期（`expires_at < now`）的令牌
- 个人访问令牌（`personal_access_client = true`）
- 客户端本身已吊销的授权

取消授权时调用 Passport 的 `Token::revoke()` 将 `revoked` 置为 `true`，同时更新 `oauth_refresh_tokens` 表使刷新令牌一并失效。

### 令牌清理机制

**冗余令牌清理**：同一客户端每次 OAuth 授权流程都会产生一条新的 access token，长期累积可能产生大量冗余令牌。开启自动清理后，查看授权记录时仅保留每个客户端最新的一条令牌，将其余旧令牌吊销。

**已吊销令牌删除**：Passport 吊销令牌只是将 `revoked` 字段设为 `true`，记录仍留在数据库中。开启自动删除后，查看授权记录时会将这些无用记录物理删除，同时清理关联的 refresh token。

## 许可证

MIT

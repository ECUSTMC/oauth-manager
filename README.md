# OAuth Record

Blessing Skin Server 插件 —— 查看 OAuth 授权记录并取消授权。

## 功能

- 查看当前账户授权的所有第三方 OAuth 应用
- 按客户端分组展示授权信息（应用名称、授权时间、权限范围、令牌数量）
- 一键取消某个应用的授权（吊销 access token 及关联的 refresh token）
- 侧边栏菜单入口，集成在用户中心

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
├── callbacks.php                         # 生命周期回调（启用/禁用）
├── src/
│   └── Controllers/
│       └── OAuthRecordController.php     # 控制器
├── views/
│   └── index.twig                        # 页面模板（继承 user.base）
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

## 工作原理

插件直接读取 Laravel Passport 的 `oauth_access_tokens` 和 `oauth_clients` 表，无需创建额外的数据库表。

查询时自动排除：

- 已吊销（`revoked = true`）的令牌
- 已过期（`expires_at < now`）的令牌
- 个人访问令牌（`personal_access_client = true`）
- 客户端本身已吊销的授权

取消授权时调用 Passport 的 `Token::revoke()` 将 `revoked` 置为 `true`，同时更新 `oauth_refresh_tokens` 表使刷新令牌一并失效。

## 许可证

MIT

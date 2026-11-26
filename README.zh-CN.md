# Paymos Easy Digital Downloads 稳定币支付插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Easy Digital Downloads 官方 Paymos 稳定币支付网关，面向出海数字商品站点提供 USDT/USDC 收款能力。买家在结账页选择 Paymos，跳转到 Paymos 托管收银台支付稳定币账单后返回待处理订单。只有当签名 Webhook 回传链上转账确认结果时，订单才会变为 `complete`——EDD 此时才会释放下载链接并发送购买收据。单纯从支付页跳回并不会解锁文件。

[![观看视频：12 款官方 CMS 稳定币支付插件 — 一键连接（Paymos）](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **视频演示（1:12，提供 6 种语言字幕）：** [在 YouTube 观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- WordPress 6.5 及以上版本，已测试兼容至 7.0——即 `paymos-easy-digital-downloads.php` 插件头声明的最低要求
- Easy Digital Downloads，已在插件头中声明为必需依赖；本网关写入的是 EDD 3.x 订单模型
- PHP 7.4 及以上版本且启用 OpenSSL——凭据采用 AES-256-GCM 加密封装
- 可从公网访问的 HTTPS 站点。Webhook 是您自有域名上的 REST 路由，通知绝不会发送到回环地址、内网地址或链路本地地址
- 拥有 Paymos 账号，且控制台已切换到当前店铺对应的收款项目

## 安装

从 [Releases](https://github.com/Paymos-labs/edd/releases/latest) 下载 `paymos-edd-<version>.zip`，或在 Paymos 控制台的 **CMS integration** 面板获取安装包。在 WordPress 后台进入 **Plugins → Add New Plugin → Upload Plugin**（旧版 WordPress 标注为 **Add New**）上传安装并启用。

压缩包内已打包 Paymos PHP SDK，无需执行 Composer 步骤。每位商户下载的安装包完全一致，内部不包含任何 API key、API secret、project id、webhook secret、OAuth token 或 device code。

## 一键连接

执行连接需要具备 EDD 的 `manage_shop_settings` 权限（店铺管理员或站点管理员）。

1. 先在 Paymos 控制台切换到该店铺实际收款的项目——后续授权将直接绑定该项目，无需再手动选择。
2. 打开 **Downloads → Settings → Payments → Paymos**，点击 **Connect Paymos**。
3. 在弹出的授权标签页中确认站点 URL 和项目。如果浏览器拦截了弹窗，按钮旁会直接显示授权链接和验证码。
4. 完成后，**Connection status** 会自动填入：当前生效模式、Sandbox 与 Live 是否已配置、脱敏后的 API key 以及 project id。

一次授权即可同时下发 Sandbox 和 Live 两套凭据。系统会复用您现有的唯一有效 Payment 密钥（若尚无则自动新建），并自动注册 Invoice Webhook：

```text
https://your-store.example/wp-json/paymos-edd/v1/webhook
```

只有当回调 URL、事件类别与项目三者完全匹配时，系统才会复用已有的 Webhook。

凭据以非自动加载（non-autoloaded）形式保存在 WordPress option 中，并使用站点 `auth` 和 `secure_auth` salt 派生的密钥进行 AES-256-GCM 加密。短时效授权令牌随后即被丢弃，后续所有 Merchant API 请求均通过 HMAC 签名认证。

## 配置项

| 配置项 | 默认值 |
|---|---|
| 结账页显示名称（Checkout label） | `Pay with stablecoins` |
| 运行模式（Mode） | Sandbox |
| Webhook URL | 只读；一键连接时自动注册 |
| 连接状态（Connection status） | 只读 |
| 调试日志（Debug logging） | 默认关闭；开启后会将 Paymos 日志写入 EDD 调试日志 |

界面中没有密钥输入框，也没有项目选择器；两者均在授权连接时自动下发。

## 下载权限何时解锁

| Paymos 事件 | EDD 订单状态 |
|---|---|
| 买家选择 Paymos、生成订单并清空购物车 | `pending`，并添加带有 invoice id 的订单备注 |
| `invoice.confirming` | `pending` —— 备注 `"Paymos payment is confirming."` |
| `invoice.underpaid_waiting` | `pending`，备注标明尚欠金额及对应代币 |
| `invoice.awaiting_payment` | `pending`，备注提示已确认的链上转账发生了回滚 |
| `invoice.paid`、`invoice.paid_over` | `complete` —— 释放下载链接并发送收据 |
| `invoice.underpaid` | `failed` |
| `invoice.expired`、`invoice.cancelled` | `abandoned`（EDD 中未完成待支付订单的标准状态） |

订单完成时，插件会将链上交易哈希写入 EDD 交易 ID，并保存 `_paymos_tx_hash` 和 `_paymos_explorer_url` 元数据。如果已完成的订单再次收到终态事件，插件会识别为重复完成通知而不追加重复备注；后续到来的 `confirming`、`underpaid`、`expired` 或 `cancelled` 事件则会被标记为忽略，不改动订单状态。

如果订单的金额或币种与账单不一致，订单根本不会被标记完成。插件会将 `_paymos_amount_mismatch` 元数据设为 `yes`，将差异详情写入订单备注，等待人工核对处理。在此期间，文件下载保持锁定。

## 买家支付后的展示

购买收据页面和订单收据邮件都会新增一行 **Payment confirmation**：若回调载荷包含区块浏览器链接，则显示可点击的浏览器链接；否则显示纯交易哈希。若当前没有交易哈希（例如所有 Sandbox 测试订单），则不渲染该行。

## 上线前测试

1. 保持 **Mode** 为 Sandbox，在前台完整购买一件数字商品。
2. 在 Paymos 控制台保持 Sandbox 环境，找到刚生成的账单，依次使用 **Pay Full**、**Pay 50%**、**Pay 150%** 或 **Cancel** 模拟状态流转。整个过程触发真实生命周期事件，不涉及任何真实资金。
3. 核对 EDD 订单状态、订单备注以及下载链接是否按预期解锁。
4. 将 **Mode** 切换为 Live。一次连接已同时下发两套环境凭据，无需重新授权配置。

## Webhook 机制

Webhook 路由注册在 WordPress REST API 上，仅接受 `POST` 请求。插件会同时使用两套环境的密钥校验 `X-Webhook-Signature`，再将载荷内的 `is_test` 标志与通过校验的环境交叉比对——Live 载荷无法关闭 Sandbox 订单，反之亦然，均会被严格拒绝。已处理过的 `event_id` 会直接返回确认并跳过。对于终态事件，插件还会反向调用 Merchant API 校验结果，确认项目、金额和币种与订单一致后才写入状态。

处理失败时，端点会返回发送方能明确识别的状态码，因为非 2xx 响应才会让事件继续进入重试阶梯：签名无效或时间戳过期返回 `401`，处理失败返回 `400`，未保存 Webhook 密钥返回 `500`。重试阶梯共 11 次，横跨约 16 小时。未支付账单依赖签名的 `invoice.paid` Webhook，本地没有账单轮询定时任务；如果某条账单事件超过重试周期仍未送达，可在 Paymos 控制台手动重放。待处理的退款则会由 `paymos_edd_refunds_reconcile` WP-Cron 任务每分钟本地轮询一次，直到进入终态。

## 退款

本网关通过 Paymos Merchant API（`/v1/refunds`）支持原生链上退款，包含两种模式：

- `full` —— 退还已支付账单的全额款项；
- `overpayment` —— 仅退还 `invoice.paid_over` 订单中多付的差额部分，同时保持订单为 `complete` 状态。

资金只会原路退回买家支付账单时由 Paymos 记录并验证的 `first_sender` 钱包地址。无需也无法手动输入收款钱包地址，且不支持任意自定义部分退款金额。

在 **Downloads → Orders** 中打开已完成的 Paymos 订单，您可以直接通过 EDD 原生的 **Initiate a Refund** 弹窗（勾选订单内全部商品并全额退款）发起 `full` 退款，也可以在订单详情页的 **Paymos refunds** 面板统一管理 `full` 与 `overpayment` 退款并查看进度。只有当 Paymos 通过 `/wp-json/paymos-edd/v1/webhook` 或每分钟运行一次的 `paymos_edd_refunds_reconcile` WP-Cron 任务确认状态为 `completed` 后，EDD 才会生成本地退款单并发送退款收据。在 Sandbox 模式下，可以直接模拟终态退款结果（`completed`、`failed`、`cancelled`），不会动用任何真实资金。

## 常见问题排查

**买家已付款，但文件仍处于锁定状态。** 订单仍在等待尚未送达的 Webhook。请确认该端点可从外部公网正常访问——安全插件、HTTP Basic Auth 或“禁用 REST API”开关都会悄无声息地拦截回调，而且 Webhook 投递不会跟随重定向到其他地址。

**端点返回 `not_configured`。** 本地尚未保存任何凭据，因此没有可用于验签的密钥。请前往设置页点击 **Connect Paymos** 并完成授权。

**修改 `wp-config.php` 后整个网关失效。** 凭据密文包由站点的 `auth` 和 `secure_auth` salt 派生密钥加密。轮换 salt 或将数据库恢复到使用不同 salt 的站点会导致密文无法解密；重新执行一次一键连接即可重新下发密文包。

**更换站点域名后一切停止工作。** 此前授权绑定的站点 URL 以及注册的 Webhook 仍指向旧域名。请在新域名下重新执行连接。

## 相关链接

- 文档：[paymos.io/zh-hans/docs/cms-easy-digital-downloads](https://paymos.io/zh-hans/docs/cms-easy-digital-downloads)
- 源码与发布：[Paymos-labs/edd](https://github.com/Paymos-labs/edd)
- 技术支持：[support@paymos.io](mailto:support@paymos.io)

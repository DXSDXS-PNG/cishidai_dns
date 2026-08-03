# DNS 二级域名分发系统

一套简洁的 PHP + MySQL 二级域名分发/租赁系统，适合用于域名池管理、用户自助选择域名、创建 DNS 解析、积分充值和订单开通。

本项目聚焦普通 DNS 解析和域名分发所需能力，适合作为轻量系统或二次开发起点。

## 功能特性

- 管理后台：管理员登录、账号管理、系统设置、审计日志
- 平台账号：支持阿里云 DNS、腾讯云/DNSPod
- 域名管理：添加域名、同步域名、设置价格和状态
- DNS 解析：支持 A、AAAA、CNAME、MX、TXT、NS、SRV、CAA
- 用户前台：注册登录、积分余额、域名选择、弹窗式解析下单
- 订单支付：积分消费、充值订单、易支付回调、手动补单
- 开源友好：提供 `install.sql`、部署教程和 Nginx 示例配置

## 项目截图


<img width="1910" height="915" alt="image" src="https://github.com/user-attachments/assets/7b5468c2-b0f5-4f5e-8545-ac0d3de5f31b" />

```
## 运行环境

- PHP 7.4 或以上
- MySQL 5.7 / MariaDB 10.3 或以上
- Nginx 或 Apache
- PHP PDO MySQL 扩展

## 快速部署

1. 解压源码到网站目录。
2. 创建 MySQL 数据库。
3. 导入 `install.sql`。
4. 修改 `db_config.php` 数据库连接信息。
5. 确保 `data/` 目录可写。
6. 访问 `/admin` 登录后台。

默认后台账号：

```text
admin / admin123
```

首次登录后请立刻修改默认密码。

更详细的步骤请查看：

```text
部署教程.txt
```

## Nginx 伪静态参考

```nginx
location /data/ {
    deny all;
}

location /api/ {
    try_files $uri $uri/ /index.php?$query_string;
}

location /admin {
    try_files $uri $uri/ /index.php?$query_string;
}

location /user {
    try_files $uri $uri/ /index.php?$query_string;
}

location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

## 后台初始化建议

1. 修改默认管理员密码。
2. 在系统设置中配置站点名称、支付参数。
3. 添加阿里云或腾讯云/DNSPod 平台账号。
4. 同步或手动添加可分发域名。
5. 使用用户前台测试注册、充值、选择域名和创建解析。

## 商业服务

如果你基于本项目上线业务，可以在这里留下服务入口：

- 代部署上线
- 私有化部署
- 功能二次开发
- UI/交互定制
- 支付接口对接
- DNS 平台适配
- 服务器运维维护

联系方式：

```text
作者D先生微信（二次开发/开通域名违规检测功能/程序定制）：A1565182
```

## 安全提醒

开源或发布前，请不要提交自己的：

- 真实 `db_config.php` 数据库密码
- 平台 AccessKey / SecretKey
- 支付接口密钥
- 线上用户、订单、日志、数据库备份

## 贡献

欢迎提交 Issue 和 Pull Request。贡献前请阅读：

```text
CONTRIBUTING.md
```

## 许可证

本项目基于 MIT License 开源，详见：

```text
LICENSE
```

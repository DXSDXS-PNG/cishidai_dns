# API接口说明

所有业务接口除 `/api/login` 和 `/api/payment/notify` 外，都需要请求头：

```text
Authorization: Bearer <token>
```

## 认证

- `POST /api/login` 登录
- `GET /api/bootstrap` 获取前端初始化数据
- `GET /api/dashboard` 获取统计指标

## 平台账号

- `POST /api/accounts` 新增平台账号
- `PATCH /api/accounts/:id` 更新平台账号
- `POST /api/accounts/:id/sync` 同步平台域名

## 域名

- `POST /api/domains` 新增域名
- `POST /api/domains/batch` 批量导入域名
- `PATCH /api/domains/:id` 更新域名

## 解析记录

- `POST /api/records` 新增解析记录
- `POST /api/records/batch` 批量新增解析记录
- `PATCH /api/records/:id` 更新解析记录
- `DELETE /api/records/:id` 删除解析记录

## 分发策略

- `POST /api/policies` 新增策略
- `PATCH /api/policies/:id` 更新策略

## 监控告警

- `POST /api/monitors/check` 执行健康检查
- `POST /api/alerts/:id/close` 关闭告警

## 租户公开接口

- `GET /api/public/catalog` 获取可租域名池和套餐
- `POST /api/public/check` 检查二级前缀是否可租
- `POST /api/public/orders` 租户创建支付订单

## 订单支付

- `POST /api/orders` 创建易支付订单
- `POST /api/orders/:id/mark-paid` 手动补单
- `POST /api/payment/notify` 易支付异步回调

## 用户与日志

- `POST /api/users` 新增用户
- `GET /api/export/logs` 导出审计日志 CSV
- `PATCH /api/settings` 更新系统设置


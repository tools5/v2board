-- 第三方登录绑定表（可扩展多平台）
-- 推荐使用 php artisan migrate 建表，此文件仅用于手动导入的兜底场景。
CREATE TABLE IF NOT EXISTS `v2_user_oauth` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '本站用户ID',
  `provider` varchar(32) NOT NULL COMMENT '平台标识，如 linuxdo',
  `provider_user_id` varchar(128) NOT NULL COMMENT '第三方用户唯一ID',
  `provider_username` varchar(128) DEFAULT NULL COMMENT '第三方用户名',
  `provider_email` varchar(128) DEFAULT NULL COMMENT '第三方邮箱',
  `provider_avatar` varchar(512) DEFAULT NULL COMMENT '第三方头像',
  `access_token` text COMMENT '访问令牌（加密存储）',
  `refresh_token` text COMMENT '刷新令牌（加密存储）',
  `raw` mediumtext COMMENT '原始用户信息JSON',
  `password_never_set` tinyint(4) NOT NULL DEFAULT '0' COMMENT 'OAuth自动注册且用户从未设置真实密码时为1',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_provider_user` (`provider`, `provider_user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_provider` (`provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户第三方登录绑定';

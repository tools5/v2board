-- OAuth 独立用户表（与用户管理分离）
-- 若已执行 php artisan migrate 可忽略本文件

CREATE TABLE IF NOT EXISTS `v2_oauth_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT '关联系统运行账号 ID（v2_user），仅供订阅/鉴权/订单使用，不在用户管理展示',
  `email` varchar(64) NOT NULL COMMENT '本站邮箱（可占位）',
  `primary_provider` varchar(32) NOT NULL COMMENT '首次注册平台',
  `primary_provider_user_id` varchar(128) NOT NULL COMMENT '平台用户唯一ID（LinuxDo=论坛ID，Telegram=TGID）',
  `primary_provider_username` varchar(128) DEFAULT NULL,
  `primary_provider_email` varchar(128) DEFAULT NULL,
  `primary_provider_avatar` varchar(512) DEFAULT NULL,
  `password_never_set` tinyint(4) NOT NULL DEFAULT '1' COMMENT '是否尚未设置真实登录密码',
  `remarks` text COMMENT '管理员备注（OAuth侧）',
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  UNIQUE KEY `uniq_oauth_user_provider` (`primary_provider`,`primary_provider_user_id`),
  KEY `idx_email` (`email`),
  KEY `idx_primary_provider` (`primary_provider`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

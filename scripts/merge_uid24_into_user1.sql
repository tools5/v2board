-- 截图场景精简版：保留 UID 1，处理 UID 24（linuxdo_448961@oauth.local）
-- 说明：
--   UID 1 已有 LinuxDo 论坛ID 13966
--   UID 24 是另一 LinuxDo 账号 448961（占位邮箱）
--   策略：丢弃 UID 24 的绑定并删除 UID 24，不覆盖 UID 1 的 LinuxDo

START TRANSACTION;

-- 备份查看
SELECT id, user_id, provider, provider_user_id, provider_username, provider_email
FROM v2_user_oauth
WHERE user_id IN (1, 24)
ORDER BY user_id, id;

SELECT id, email FROM v2_user WHERE id IN (1, 24);

-- 删除 UID 24 的全部 OAuth 绑定
DELETE FROM v2_user_oauth WHERE user_id = 24;

-- 删除 OAuth 独立用户标记
DELETE FROM v2_oauth_user WHERE user_id = 24;

-- 邀请关系改挂 UID 1（如有）
UPDATE v2_user SET invite_user_id = 1 WHERE invite_user_id = 24;
UPDATE v2_order SET user_id = 1 WHERE user_id = 24;

-- 删除 UID 24 账号
DELETE FROM v2_invite_code WHERE user_id = 24;
DELETE FROM v2_ticket_message WHERE ticket_id IN (SELECT id FROM v2_ticket WHERE user_id = 24);
DELETE FROM v2_ticket WHERE user_id = 24;
DELETE FROM v2_user WHERE id = 24;

-- 邮箱主账号不应再带 oauth 独立标记
DELETE FROM v2_oauth_user WHERE user_id = 1;

-- 核对：应只剩 UID 1 的绑定
SELECT ub.id, ub.user_id, u.email, ub.provider, ub.provider_user_id, ub.provider_username, ub.provider_email
FROM v2_user_oauth ub
LEFT JOIN v2_user u ON u.id = ub.user_id
ORDER BY ub.user_id, ub.id;

-- 确认后执行：
COMMIT;
-- 有问题则：
-- ROLLBACK;

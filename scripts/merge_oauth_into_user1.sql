-- 可选：把「源账号上、keep 还没有的平台绑定」迁到 UID 1。
-- 默认不删除任何用户、不删除冲突绑定。
-- 使用前请先 SELECT 核对，并备份数据库。

START TRANSACTION;

SELECT ub.id, ub.user_id, u.email, ub.provider, ub.provider_user_id, ub.provider_username, ub.provider_email
FROM v2_user_oauth ub
LEFT JOIN v2_user u ON u.id = ub.user_id
ORDER BY ub.user_id, ub.id;

-- 仅迁移：源用户有、UID 1 尚无该 provider 的绑定
UPDATE v2_user_oauth AS src
LEFT JOIN v2_user_oauth AS keep_same_provider
  ON keep_same_provider.user_id = 1
 AND keep_same_provider.provider = src.provider
SET src.user_id = 1,
    src.password_never_set = 0
WHERE src.user_id <> 1
  AND keep_same_provider.id IS NULL
  AND src.user_id IN (
      SELECT user_id FROM v2_oauth_user WHERE user_id <> 1
      UNION
      SELECT id FROM v2_user WHERE id <> 1 AND email LIKE '%@oauth.local'
  );

-- 核对（源账号若仍有绑定会继续保留）
SELECT ub.id, ub.user_id, u.email, ub.provider, ub.provider_user_id, ub.provider_username, ub.provider_email
FROM v2_user_oauth ub
LEFT JOIN v2_user u ON u.id = ub.user_id
ORDER BY ub.user_id, ub.id;

-- 确认后：
COMMIT;
-- 有问题：
-- ROLLBACK;

-- 注意：不要 DELETE v2_user。UID 24 等账号默认保留。

-- 保留 UID 1，清理重复 OAuth 自动注册账号（按截图场景：删除 UID 24 等占位账号）
-- 使用前请先 SELECT 核对，并备份数据库。
-- 建议在维护窗口执行。

START TRANSACTION;

-- 1) 查看当前绑定
SELECT ub.id, ub.user_id, u.email, ub.provider, ub.provider_user_id, ub.provider_username, ub.provider_email
FROM v2_user_oauth ub
LEFT JOIN v2_user u ON u.id = ub.user_id
ORDER BY ub.user_id, ub.id;

-- 2) 把「可安全迁入」的绑定改到 UID 1：
--    keep 上还没有该 provider 的绑定才迁移
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

-- 3) 删除源用户上与 UID1 冲突的同平台绑定（保留 UID1）
DELETE src FROM v2_user_oauth AS src
INNER JOIN v2_user_oauth AS keep_binding
  ON keep_binding.user_id = 1
 AND keep_binding.provider = src.provider
WHERE src.user_id <> 1
  AND src.user_id IN (
      SELECT user_id FROM (
          SELECT user_id FROM v2_oauth_user WHERE user_id <> 1
          UNION
          SELECT id AS user_id FROM v2_user WHERE id <> 1 AND email LIKE '%@oauth.local'
      ) AS merge_ids
  );

-- 4) 订单 / 邀请关系迁到 UID1（可选）
UPDATE v2_order SET user_id = 1
WHERE user_id IN (
    SELECT user_id FROM (
        SELECT user_id FROM v2_oauth_user WHERE user_id <> 1
        UNION
        SELECT id AS user_id FROM v2_user WHERE id <> 1 AND email LIKE '%@oauth.local'
    ) AS merge_ids
)
AND user_id NOT IN (
    SELECT user_id FROM v2_user_oauth WHERE user_id <> 1
);

-- 5) 删除已无剩余绑定的 OAuth 占位账号
DELETE FROM v2_oauth_user
WHERE user_id <> 1
  AND user_id NOT IN (SELECT user_id FROM v2_user_oauth);

DELETE FROM v2_user
WHERE id <> 1
  AND (
      email LIKE '%@oauth.local'
      OR id IN (SELECT user_id FROM v2_oauth_user)
  )
  AND id NOT IN (SELECT user_id FROM v2_user_oauth);

-- 6) 邮箱主账号去掉 oauth 独立标记（若存在）
DELETE FROM v2_oauth_user WHERE user_id = 1;

-- 核对
SELECT ub.id, ub.user_id, u.email, ub.provider, ub.provider_user_id, ub.provider_username, ub.provider_email
FROM v2_user_oauth ub
LEFT JOIN v2_user u ON u.id = ub.user_id
ORDER BY ub.user_id, ub.id;

-- 确认无误后：
-- COMMIT;
-- 若有问题：
-- ROLLBACK;

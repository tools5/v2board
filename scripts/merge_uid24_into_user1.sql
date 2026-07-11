-- 仅查看 / 说明：UID 1 的两个平台绑定无需合并账号，列表聚合即可。
-- UID 24 独立保留，不要执行删除。

-- 查看 UID 1 与 UID 24
SELECT ub.id, ub.user_id, u.email, ub.provider, ub.provider_user_id, ub.provider_username, ub.provider_email
FROM v2_user_oauth ub
LEFT JOIN v2_user u ON u.id = ub.user_id
WHERE ub.user_id IN (1, 24)
ORDER BY ub.user_id, ub.id;

SELECT id, email, banned, plan_id FROM v2_user WHERE id IN (1, 24);

-- 不需要做任何 DELETE。
-- 若后台仍显示 UID 1 两行，请 git pull 后强刷，依赖按用户聚合的列表接口。

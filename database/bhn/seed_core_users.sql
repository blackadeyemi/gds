-- Migrate BHN's legacy users into core.user (company 4), so BHN staff can log in.
--
-- gds auth lives in core.user (bil/bpl/bhn.user are compat views over it);
-- operational tables reference a user by USERNAME string, and username is
-- globally UNIQUE. BHN's legacy `user` table (company 2 of the shared Abuja DB)
-- has 40 accounts. 10 usernames already exist in core.user (admin, jad,
-- lecrosoft, and the malek/mansour/wael/remi/tayo/kola/gbemi management set,
-- mostly company_id NULL = cross-company) — those SAME accounts already cover
-- BHN, so we do NOT touch them (never overwrite an existing credential). The
-- remaining ~30 are BHN-specific staff; they come in scoped to company 4.
--
-- Passwords are bcrypt ($2y$) — Laravel-compatible as-is. userlevel is copied
-- for reference; gds access is governed by its own page-access RBAC.
-- Idempotent: the NOT IN guard skips anyone already present.

INSERT INTO `core`.`user`
    (`username`, `fullname`, `email`, `password`, `userlevel`, `company_id`, `redirection_id`, `created_at`)
SELECT s.`username`, s.`fullname`, s.`email`, s.`password`, s.`userlevel`, 4, s.`redirection_id`, s.`created_at`
FROM `bhn_stage`.`user` s
WHERE s.`username` NOT IN (SELECT `username` FROM `core`.`user`);

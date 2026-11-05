-- =====================================================================
-- St. Austin Conference — Vincentian Resource Center
-- PostgreSQL schema
-- Run as the application database owner, e.g.:
--   psql -U svdp -d svdp -f schema.sql
-- =====================================================================

-- A single, shared content document stored as JSONB.
-- Two people edit asynchronously; the "version" column powers optimistic
-- concurrency so neither editor can silently overwrite the other's work.
CREATE TABLE IF NOT EXISTS content (
    id          integer      PRIMARY KEY DEFAULT 1,
    data        jsonb        NOT NULL DEFAULT '{}'::jsonb,
    version     integer      NOT NULL DEFAULT 0,
    updated_at  timestamptz  NOT NULL DEFAULT now(),
    updated_by  text,
    -- enforce that there is only ever one content row
    CONSTRAINT content_singleton CHECK (id = 1)
);

-- Optional audit trail: every save appends a snapshot here so you can
-- see who changed what and roll back if needed.
CREATE TABLE IF NOT EXISTS content_history (
    hist_id     bigserial    PRIMARY KEY,
    version     integer      NOT NULL,
    data        jsonb        NOT NULL,
    updated_at  timestamptz  NOT NULL DEFAULT now(),
    updated_by  text
);

-- Helpful index if the history table grows large.
CREATE INDEX IF NOT EXISTS idx_content_history_version
    ON content_history (version DESC);

-- Website usage counts (one row per day per section) powering the Activity tab.
-- The app also creates this automatically on first use, so this is optional.
CREATE TABLE IF NOT EXISTS usage_stats (
    day      date     NOT NULL,
    section  text     NOT NULL,   -- 'visit' for a page load, else a tab id
    hits     integer  NOT NULL DEFAULT 0,
    PRIMARY KEY (day, section)
);

-- Per-day visitor IP counts, powering the "Top visitors" list on the Activity
-- tab. Also created automatically on first use, so this is optional.
CREATE TABLE IF NOT EXISTS usage_ips (
    day   date     NOT NULL,
    ip    text     NOT NULL,
    hits  integer  NOT NULL DEFAULT 0,
    PRIMARY KEY (day, ip)
);

-- Member survey responses. Survey definitions live in the JSON content blob
-- (edited like the rest of the site); each submitted response is one row here.
-- The app also creates this automatically on first use, so this is optional.
CREATE TABLE IF NOT EXISTS survey_responses (
    id           bigserial    PRIMARY KEY,
    survey_id    text         NOT NULL,
    answers      jsonb        NOT NULL,   -- { questionId: value, ... }
    submitted_at timestamptz  NOT NULL DEFAULT now(),
    ip           text
);
CREATE INDEX IF NOT EXISTS idx_survey_responses_survey
    ON survey_responses (survey_id);

-- NOTE: We intentionally do NOT seed a content row here.
-- On first load the app serves its built-in default content (the resource
-- categories, limits, spirituality text, and event placeholders). The first
-- time an editor saves, that content is written to this table as version 1.

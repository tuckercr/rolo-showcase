-- Slack bot, phase 1 (docs/SLACK_BOT_SPEC.md): the evening debrief DM and
-- approval notifications.

-- One row per debrief actually sent, so the double-fired cron (DST trick,
-- same as the daily email) and retries can't DM twice. Mirrors
-- daily_summary_log.
CREATE TABLE slack_debrief_log (
    debrief_date DATE NOT NULL,
    slack_user VARCHAR(20) NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (debrief_date, slack_user)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Set once the pending proposal has been DM'd, so each one is announced
-- exactly once however many cron ticks see it.
ALTER TABLE api_proposals
    ADD COLUMN slack_notified_at DATETIME NULL AFTER reviewed_at;

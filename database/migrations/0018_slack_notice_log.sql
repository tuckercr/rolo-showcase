-- Dedupe for scheduled Slack notices beyond the debrief (going-cold alerts
-- now, whatever comes next later): one row per notice kind, date and
-- recipient, so double-fired crons can't DM twice.
CREATE TABLE slack_notice_log (
    kind VARCHAR(20) NOT NULL,
    notice_date DATE NOT NULL,
    slack_user VARCHAR(20) NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (kind, notice_date, slack_user)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

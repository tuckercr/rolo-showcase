-- One row per calendar day the daily summary email went out. The cron
-- endpoint fires twice (13:00 + 14:00 UTC, covering EST/EDT); the unique
-- key makes the second firing a no-op.

CREATE TABLE daily_summary_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    summary_date DATE NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recipients VARCHAR(500) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_summary_date (summary_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

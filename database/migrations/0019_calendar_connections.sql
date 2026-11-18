-- Google Calendar, read-only (per-user opt-in from /settings): the evening
-- Slack debrief suggests logging the meetings you actually had with people
-- who are in Rolo. One row per user; disconnecting deletes the row.
--
-- The refresh token is stored as-is: anyone who can read this table can
-- already read every contact and the .env beside it, so encrypting it here
-- would only be a lock whose key hangs on the same hook.
CREATE TABLE calendar_connections (
    user_id INT UNSIGNED NOT NULL,
    -- The Google account the calendar belongs to (not necessarily the
    -- sign-in account); also used to skip "yourself" among event attendees.
    google_email VARCHAR(255) NOT NULL,
    refresh_token TEXT NOT NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_calendar_connections_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

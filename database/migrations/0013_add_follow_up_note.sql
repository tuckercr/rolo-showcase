-- Jessica's request (2026-09-23): when "Follow-up needed" is checked on a
-- logged interaction, a short note describes WHAT needs doing. Shown on the
-- activity entry, the dashboard queue, and the daily summary email while
-- that interaction is the contact's latest touch.

ALTER TABLE activities
    ADD COLUMN follow_up_note VARCHAR(255) NULL AFTER follow_up_date;

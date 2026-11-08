-- Snoozes now leave a history entry on the contact ("Modified follow up
-- date from: X, to: Y"). 'snoozed' is system-generated only: not offered in
-- the log form and not editable.

ALTER TABLE activities
    MODIFY COLUMN activity_type ENUM (
        'event_meeting', 'email', 'call', 'coffee', 'linkedin_message',
        'intro_made', 'other', 'snoozed'
    ) NOT NULL;

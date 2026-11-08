-- Data repair: before 2026-07-10 the app ignored an activity's explicit
-- "follow up by" date and always rescheduled next_touch_date from the stage
-- cadence rule. Retroactively apply the explicit date wherever a contact's
-- MOST RECENT activity requested one (e.g. a contact snoozed for Saturday
-- with follow-up needed by 2026-07-11 but next_touch showing 2026-09-07).

UPDATE contacts c
JOIN (
    SELECT a.contact_id, a.follow_up_date
    FROM activities a
    JOIN (
        SELECT contact_id, MAX(id) AS max_id
        FROM activities
        GROUP BY contact_id
    ) latest ON latest.max_id = a.id
    WHERE a.follow_up_needed = 1
      AND a.follow_up_date IS NOT NULL
) f ON f.contact_id = c.id
SET c.next_touch_date = f.follow_up_date;

-- Closed / Not a Fit is hidden from the dashboard and daily email by
-- default (2026-10-23). It never surfaced on its own (no reminder rule,
-- excluded from going-cold by name), but an explicit follow-up date logged
-- on a closed contact could still appear; checking the box makes the
-- default behavior explicit. It stays a normal checkbox at /settings, so
-- unchecking it restores the old explicit-date exception.

UPDATE stages SET hidden_from_follow_ups = 1 WHERE name = 'Closed / Not a Fit';

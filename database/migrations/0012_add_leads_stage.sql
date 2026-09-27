-- Jessica's request (2026-09-23): a "Leads" stage at the top of the
-- pipeline with a same-day follow-up cadence (fixed_days 0 = the follow-up
-- lands on the day the contact enters the stage). sort_order 5 places it
-- before New/Captured (10).

INSERT INTO stages (name, sort_order) VALUES ('Leads', 5);

INSERT INTO reminder_rules (relationship_status, rule_type, value_days, is_active)
VALUES ('Leads', 'fixed_days', 0, 1);

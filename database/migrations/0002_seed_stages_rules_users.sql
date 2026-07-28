-- Seed data from Jessica's actual SOP and cadence numbers (docs/SCHEMA.md),
-- not invented placeholders.

INSERT INTO stages (name, sort_order) VALUES
    ('New/Captured', 10),
    ('Researching', 20),
    ('Intro Sent', 30),
    ('Conversation Started', 40),
    ('Relationship Building', 50),
    ('Opportunity Identified', 60),
    ('Proposal / Pitch', 70),
    ('Active client', 80),
    ('Dormant', 90),
    ('Closed / Not a Fit', 100);

-- 'Closed / Not a Fit' intentionally has no rule: no reminder is ever generated.
INSERT INTO reminder_rules (relationship_status, rule_type, value_days) VALUES
    ('New/Captured', 'fixed_days', 3),
    ('Researching', 'fixed_days', 7),
    ('Intro Sent', 'fixed_days', 7),
    ('Conversation Started', 'fixed_days', 14),
    ('Relationship Building', 'cadence_from_last_touch', 30),
    ('Opportunity Identified', 'fixed_days', 5),
    ('Proposal / Pitch', 'business_days', 5),
    ('Active client', 'cadence_from_last_touch', 30),
    ('Dormant', 'fixed_days', 180);

-- The two demo users (replace with your own allowlisted people). google_sub is filled in on each person's
-- first Google Sign-In (matched by email — see docs/SCHEMA.md Authentication).
INSERT INTO users (name, email, role) VALUES
    ('Colin', 'colin@example.com', 'admin'),
    ('Jessica', 'jessica@example.com', 'admin');

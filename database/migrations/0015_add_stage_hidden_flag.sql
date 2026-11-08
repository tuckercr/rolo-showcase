-- Settings-controlled exclusion from the dashboard and daily email
-- (2026-10-23). Generalizes the hardcoded Leads exclusion: each stage can
-- be hidden from the follow-up surfaces; contacts in hidden stages stay on
-- the Contacts list and keep scheduling normally.

ALTER TABLE stages
    ADD COLUMN hidden_from_follow_ups TINYINT(1) NOT NULL DEFAULT 0;

UPDATE stages SET hidden_from_follow_ups = 1 WHERE name = 'Leads';

-- Per-user agent identities (API_USER_TOKENS in .env): which PERSON's
-- API/MCP connection made a request or proposed a change. NULL on both
-- columns means the legacy shared read/write token — anonymous, kept
-- working for the transition. A matched per-user token also defaults
-- proposed contacts' owner_id to that user (see ProposalService).
ALTER TABLE api_audit
    ADD COLUMN user_id INT UNSIGNED NULL AFTER token_name;

ALTER TABLE api_proposals
    ADD COLUMN proposed_by INT UNSIGNED NULL AFTER token_name,
    ADD CONSTRAINT fk_proposals_proposed_by FOREIGN KEY (proposed_by) REFERENCES users (id);

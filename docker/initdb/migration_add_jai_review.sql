ALTER TABLE changelog
    ADD COLUMN jai_verdict ENUM('pending','approved','flagged','revoked') NOT NULL DEFAULT 'pending',
    ADD COLUMN jai_reasoning TEXT NULL,
    ADD COLUMN jai_reviewed_at TIMESTAMP NULL,
    ADD COLUMN jai_attempts INT NOT NULL DEFAULT 0,
    ADD COLUMN manual_review_requested_at TIMESTAMP NULL,
    ADD COLUMN admin_reviewed_at TIMESTAMP NULL,
    ADD COLUMN admin_reviewed_by VARCHAR(50) NULL;

ALTER TABLE usersnew
    ADD COLUMN jai_ban_review_requested_at TIMESTAMP NULL,
    ADD COLUMN jai_ban_reasoning TEXT NULL;

-- Grandfather in all pre-existing scores as already-approved so the ~32k-row
-- historical backlog doesn't vanish behind a serially-paced AI review queue.
-- Only new submissions from this point on go through JAI.
UPDATE changelog SET jai_verdict = 'approved' WHERE jai_verdict = 'pending';

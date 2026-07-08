CREATE TABLE score_votes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    changelog_id INT NOT NULL,
    profile_number VARCHAR(50) NOT NULL,
    vote TINYINT(1) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_voter (changelog_id, profile_number),
    FOREIGN KEY (changelog_id) REFERENCES changelog(id) ON DELETE CASCADE
);

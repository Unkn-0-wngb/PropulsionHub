CREATE TABLE forum_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    position INT NOT NULL DEFAULT 0,
    admin_only_post TINYINT(1) NOT NULL DEFAULT 0
);

CREATE TABLE forum_threads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    profile_number VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked TINYINT(1) NOT NULL DEFAULT 0,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    INDEX (category_id),
    FOREIGN KEY (category_id) REFERENCES forum_categories(id) ON DELETE CASCADE
);

CREATE TABLE forum_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    thread_id INT NOT NULL,
    profile_number VARCHAR(50) NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at TIMESTAMP NULL DEFAULT NULL,
    INDEX (thread_id),
    FOREIGN KEY (thread_id) REFERENCES forum_threads(id) ON DELETE CASCADE
);

INSERT INTO forum_categories (name, description, position, admin_only_post) VALUES
    ('Announcements', 'Site, plugin, and config release notes.', 1, 1),
    ('General', 'General Portal 2 speedrunning discussion.', 2, 0),
    ('Support', 'Ask for help with the site, plugin, or configs.', 3, 0),
    ('Suggestions', 'Suggest features or changes.', 4, 0);

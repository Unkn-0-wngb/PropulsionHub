<?php
class Forum {
    public static function getCategories() {
        $result = Database::unsafe_raw(
            "SELECT c.*
                  , (SELECT COUNT(*) FROM forum_threads WHERE category_id = c.id) AS thread_count
             FROM forum_categories c
             ORDER BY position ASC"
        );
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public static function getCategory($id) {
        return Database::findOne(
            "SELECT * FROM forum_categories WHERE id = ?",
            "i",
            [$id]
        );
    }

    public static function getThreads($categoryId) {
        return Database::findMany(
            "SELECT t.*
                  , IFNULL(u.boardname, u.steamname) AS authorName
                  , (SELECT COUNT(*) FROM forum_posts WHERE thread_id = t.id) AS post_count
                  , (SELECT MAX(created_at) FROM forum_posts WHERE thread_id = t.id) AS last_post_at
             FROM forum_threads t
             LEFT JOIN usersnew u ON u.profile_number = t.profile_number
             WHERE t.category_id = ?
             ORDER BY t.pinned DESC, last_post_at DESC, t.created_at DESC",
            "i",
            [$categoryId]
        );
    }

    public static function getThread($id) {
        return Database::findOne(
            "SELECT t.*
                  , IFNULL(u.boardname, u.steamname) AS authorName
                  , c.name AS categoryName
                  , c.admin_only_post
             FROM forum_threads t
             LEFT JOIN usersnew u ON u.profile_number = t.profile_number
             JOIN forum_categories c ON c.id = t.category_id
             WHERE t.id = ?",
            "i",
            [$id]
        );
    }

    public static function getPosts($threadId) {
        return Database::findMany(
            "SELECT p.*
                  , IFNULL(u.boardname, u.steamname) AS authorName
                  , u.avatar AS authorAvatar
                  , u.admin AS authorIsAdmin
             FROM forum_posts p
             LEFT JOIN usersnew u ON u.profile_number = p.profile_number
             WHERE p.thread_id = ?
             ORDER BY p.created_at ASC",
            "i",
            [$threadId]
        );
    }

    public static function createThread($categoryId, $profileNumber, $title, $body) {
        Database::query(
            "INSERT INTO forum_threads (category_id, profile_number, title) VALUES (?, ?, ?)",
            "iss",
            [$categoryId, $profileNumber, $title]
        );
        $threadId = Database::getMysqli()->insert_id;

        Database::query(
            "INSERT INTO forum_posts (thread_id, profile_number, body) VALUES (?, ?, ?)",
            "iss",
            [$threadId, $profileNumber, $body]
        );

        return $threadId;
    }

    public static function createPost($threadId, $profileNumber, $body) {
        Database::query(
            "INSERT INTO forum_posts (thread_id, profile_number, body) VALUES (?, ?, ?)",
            "iss",
            [$threadId, $profileNumber, $body]
        );
        return Database::getMysqli()->insert_id;
    }

    public static function setThreadLocked($threadId, $locked) {
        Database::query(
            "UPDATE forum_threads SET locked = ? WHERE id = ?",
            "ii",
            [$locked ? 1 : 0, $threadId]
        );
    }

    public static function deleteThread($threadId) {
        Database::query(
            "DELETE FROM forum_threads WHERE id = ?",
            "i",
            [$threadId]
        );
    }

    public static function deletePost($postId) {
        Database::query(
            "DELETE FROM forum_posts WHERE id = ?",
            "i",
            [$postId]
        );
    }

    public static function getPost($postId) {
        return Database::findOne(
            "SELECT * FROM forum_posts WHERE id = ?",
            "i",
            [$postId]
        );
    }
}

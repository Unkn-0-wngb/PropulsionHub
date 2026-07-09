<?php

/**
 * JAI is the main reviewer for every submitted run. It can approve a run,
 * flag it for admin-only sign-off, or revoke (hide) a specific run outright,
 * and can warn a player. It can NEVER ban a user account itself -- that
 * always goes through requestAdminBanReview() for a human admin to decide.
 */
final class JaiReviewer {

    const OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions";
    const MAX_ATTEMPTS = 5;

    // Processes exactly one pending run per call -- the worker loop
    // (util/jai_review_loop.sh) is responsible for the 30s pacing between calls.
    public static function reviewNext(): bool {
        $result = Database::unsafe_raw(
            "SELECT ch.id
                  , ch.profile_number
                  , ch.score
                  , ch.map_id
                  , ch.has_demo
                  , ch.youtube_id
                  , ch.submission
                  , ch.jai_attempts
                  , maps.name as chamberName
                  , IFNULL(usersnew.boardname, usersnew.steamname) as playerName
                  , usersnew.cheat_warning_at
             FROM changelog ch
             INNER JOIN maps ON ch.map_id = maps.steam_id
             INNER JOIN usersnew ON ch.profile_number = usersnew.profile_number
             WHERE ch.jai_verdict = 'pending'
             AND usersnew.banned = 0
             ORDER BY ch.jai_attempts ASC, ch.time_gained ASC
             LIMIT 1"
        );

        $change = $result ? $result->fetch_assoc() : null;
        if (!$change) {
            return false;
        }

        Debug::log("JAI reviewing changelog id: " . $change["id"]);

        $context = self::buildContext($change);
        $verdict = self::callOpenRouter($context);

        if ($verdict === null) {
            self::recordFailedAttempt(intval($change["id"]), intval($change["jai_attempts"]));
            return true;
        }

        self::applyVerdict($change, $verdict);
        return true;
    }

    private static function buildContext(array $change): string {
        $mapStats = Database::findOne(
            "SELECT MIN(score) as wr, COUNT(*) as sampleSize
             FROM changelog
             WHERE map_id = ? AND banned = 0 AND pending = 0",
            "s",
            [$change["map_id"]]
        );

        $fastestData = Database::query(
            "SELECT score FROM changelog
             WHERE map_id = ? AND banned = 0 AND pending = 0
             ORDER BY score ASC LIMIT 10",
            "s",
            [$change["map_id"]]
        );
        $fastest = [];
        while ($row = $fastestData->fetch_assoc()) {
            $fastest[] = Leaderboard::convertToTime(intval($row["score"]));
        }

        $historyData = Database::query(
            "SELECT ch.score, ch.jai_verdict, maps.name as mapName
             FROM changelog ch
             INNER JOIN maps ON ch.map_id = maps.steam_id
             WHERE ch.profile_number = ?
             ORDER BY ch.time_gained DESC
             LIMIT 15",
            "s",
            [$change["profile_number"]]
        );
        $history = [];
        while ($row = $historyData->fetch_assoc()) {
            $history[] = Leaderboard::convertToTime(intval($row["score"])) . " on " . $row["mapName"] . " (" . $row["jai_verdict"] . ")";
        }

        $lines = [];
        $lines[] = "Chamber: " . $change["chamberName"];
        $lines[] = "Current world record on this chamber: " . ($mapStats && $mapStats["wr"] !== null ? Leaderboard::convertToTime(intval($mapStats["wr"])) : "no verified times yet");
        $lines[] = "Sample size of verified times on this chamber: " . ($mapStats ? $mapStats["sampleSize"] : 0);
        $lines[] = "10 fastest verified times on this chamber: " . (count($fastest) ? implode(", ", $fastest) : "none yet");
        $lines[] = "";
        $lines[] = "Run under review: " . Leaderboard::convertToTime(intval($change["score"])) . " by " . $change["playerName"];
        $lines[] = "Has demo attached: " . ($change["has_demo"] ? "yes" : "no");
        $lines[] = "Has YouTube link attached: " . ($change["youtube_id"] ? "yes" : "no");
        $lines[] = "Submission type: " . (intval($change["submission"]) == 2 ? "automatic Steam leaderboard sync" : "manual/plugin submission");
        $lines[] = "Player already has a prior cheat warning: " . ($change["cheat_warning_at"] ? "yes" : "no");
        $lines[] = "";
        $lines[] = "This player's other recent submitted times (most recent first): " . (count($history) ? implode("; ", $history) : "none");

        return implode("\n", $lines);
    }

    private static function callOpenRouter(string $context): ?array {
        $apiKey = Config::get()->openrouter_api_key;
        $model = Config::get()->jai_reviewer_model;

        if (empty($apiKey)) {
            Debug::log("JAI: no OpenRouter API key configured, skipping review");
            return null;
        }

        $systemPrompt = <<<PROMPT
You are JAI, the anti-cheat reviewer for a small personal Portal 2 Challenge Mode speedrun leaderboard. You review one submitted run at a time and decide how it should be handled.

You have exactly three possible verdicts for the RUN itself:
- "approve": this time is plausible for a skilled human speedrunner on this chamber. The run goes live on the public leaderboard immediately, labeled "Approved by JAI".
- "flag": you are not confident either way -- accept the submission but hold it back for a human admin to manually sign off. Labeled "Flagged by JAI". Use this when a time is unusually fast but not obviously impossible, or when you lack enough context to be sure.
- "revoke": this time is not humanly possible or is otherwise clearly fake (e.g. the exact same time repeating across unrelated chambers, which is the signature of a cheat tool producing a fixed output rather than a real run). This hides only this one run, labeled "Revoked by JAI". It does NOT ban the player's account.

You can ALSO independently decide:
- "warn_player": true if this specific run looks like cheating and the player should get a warning banner on their profile. Only meaningful the first time -- if they already have a prior warning, the system escalates to admin ban review automatically regardless of what you set here.
- "request_admin_ban_review": true if you believe this player's ACCOUNT (not just this one run) should be considered for banning by a human admin -- e.g. you see a clear pattern of fabricated times in their recent history. You are NEVER able to ban an account yourself, only an admin can.

Respond with ONLY a single JSON object, no other text, no markdown code fences:
{"verdict": "approve|flag|revoke", "warn_player": true|false, "request_admin_ban_review": true|false, "reasoning": "one or two short sentences explaining your call"}
PROMPT;

        $payload = json_encode([
            "model" => $model,
            "messages" => [
                ["role" => "system", "content" => $systemPrompt],
                ["role" => "user", "content" => $context],
            ],
            "temperature" => 0.2,
        ]);

        $ch = curl_init(self::OPENROUTER_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json",
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            Debug::log("JAI: OpenRouter call failed, http code " . $httpCode . " response: " . substr(strval($response), 0, 500));
            return null;
        }

        $decoded = json_decode($response, true);
        $content = $decoded["choices"][0]["message"]["content"] ?? null;
        if (!$content) {
            Debug::log("JAI: no content in OpenRouter response: " . substr($response, 0, 500));
            return null;
        }

        $jsonStart = strpos($content, "{");
        $jsonEnd = strrpos($content, "}");
        if ($jsonStart === false || $jsonEnd === false) {
            Debug::log("JAI: couldn't find JSON in model response: " . substr($content, 0, 500));
            return null;
        }

        $verdict = json_decode(substr($content, $jsonStart, $jsonEnd - $jsonStart + 1), true);
        if (!$verdict || !isset($verdict["verdict"]) || !in_array($verdict["verdict"], ["approve", "flag", "revoke"])) {
            Debug::log("JAI: invalid verdict JSON: " . substr($content, 0, 500));
            return null;
        }

        return $verdict;
    }

    private static function applyVerdict(array $change, array $verdict): void {
        $id = intval($change["id"]);
        $profileNumber = strval($change["profile_number"]);
        $reasoning = strval($verdict["reasoning"] ?? "");

        Debug::log("JAI verdict for changelog id " . $id . ": " . $verdict["verdict"] . " -- " . $reasoning);

        switch ($verdict["verdict"]) {
            case "approve":
                Leaderboard::verifyRun($id);
                break;
            case "flag":
                Database::query(
                    "UPDATE changelog SET pending = 1, admin_review_required = 1 WHERE id = ?",
                    "i",
                    [$id]
                );
                break;
            case "revoke":
                Leaderboard::setScoreBanStatus($id, 1);
                break;
        }

        $verdictState = [
            "approve" => "approved",
            "flag" => "flagged",
            "revoke" => "revoked",
        ][$verdict["verdict"]];
        Leaderboard::setJaiVerdict($id, $verdictState, $reasoning);

        if (!empty($verdict["warn_player"])) {
            Leaderboard::jaiWarnPlayer($profileNumber);
        }

        if (!empty($verdict["request_admin_ban_review"])) {
            Leaderboard::requestAdminBanReview($profileNumber, $reasoning);
        }
    }

    private static function recordFailedAttempt(int $changelogId, int $priorAttempts): void {
        $attempts = $priorAttempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            Debug::log("JAI: giving up on changelog id " . $changelogId . " after " . $attempts . " failed attempts, flagging for admin");
            Database::query(
                "UPDATE changelog SET pending = 1, admin_review_required = 1, jai_attempts = ? WHERE id = ?",
                "ii",
                [$attempts, $changelogId]
            );
            Leaderboard::setJaiVerdict($changelogId, "flagged", "JAI review failed repeatedly (API errors) -- needs manual admin attention.");
            return;
        }

        Database::query(
            "UPDATE changelog SET jai_attempts = ? WHERE id = ?",
            "ii",
            [$attempts, $changelogId]
        );
    }
}

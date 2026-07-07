<?php
class DiscordSignIn
{
    const AUTHORIZE_URL = 'https://discord.com/api/v10/oauth2/authorize';
    const TOKEN_URL = 'https://discord.com/api/v10/oauth2/token';
    const API = 'https://discord.com/api/v10';

    public static function genUrl($host)
    {
        $redirect = 'https://' . $host . '/discord/callback';
        $params = [
            'client_id' => Config::get()->discord_client_id,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => 'identify',
        ];
        return self::AUTHORIZE_URL . '?' . http_build_query($params);
    }

    /**
     * Exchanges an OAuth2 code for the Discord user id/username, links it
     * to the given profile number, and (best-effort) assigns the
     * Speedrunner role in the configured guild. Returns true on success.
     */
    public static function linkAccount($code, $host, $profileNumber)
    {
        $redirect = 'https://' . $host . '/discord/callback';

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => Config::get()->discord_client_id,
            'client_secret' => Config::get()->discord_client_secret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) {
            Debug::log("Discord token exchange failed: $status $resp");
            return false;
        }

        $token = json_decode($resp, true)['access_token'] ?? null;
        if (!$token) {
            return false;
        }

        $ch = curl_init(self::API . '/users/@me');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
        $userResp = curl_exec($ch);
        $userStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($userStatus !== 200) {
            Debug::log("Discord user fetch failed: $userStatus $userResp");
            return false;
        }

        $discordUser = json_decode($userResp, true);
        $discordId = $discordUser['id'];
        $discordUsername = $discordUser['username'];

        Database::query(
            "UPDATE usersnew SET discord_id = ?, discord_username = ? WHERE profile_number = ?",
            "sss",
            [$discordId, $discordUsername, $profileNumber]
        );

        self::assignSpeedrunnerRole($discordId);

        return true;
    }

    public static function unlinkAccount($profileNumber)
    {
        Database::query(
            "UPDATE usersnew SET discord_id = NULL, discord_username = NULL WHERE profile_number = ?",
            "s",
            [$profileNumber]
        );
    }

    private static function assignSpeedrunnerRole($discordId)
    {
        $guild = Config::get()->discord_guild_id;
        $role = Config::get()->discord_speedrunner_role_id;
        if (!$guild || !$role) {
            return;
        }
        try {
            $ch = curl_init(self::API . "/guilds/$guild/members/$discordId/roles/$role");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bot ' . Config::get()->discord_bot_token]);
            curl_exec($ch);
            // Deliberately not checking status - the user may not have
            // joined the Discord server yet, which is fine; linking the
            // account itself should still succeed.
            curl_close($ch);
        } catch (\Throwable $th) {
            Debug::log($th->__toString());
        }
    }
}

<?php

class Discord {
    private static $username = 'PropulsionHub';
    private static $avatar = 'https://p2sr.laveryjonez.uk/images/brand/unkn-logo.png';
    private static $embed_icon = 'https://p2sr.laveryjonez.uk/favicon.ico';

    public static function sendMdpWebhook($data, $demoName, $text, $err = null) {
        try {
            //Debug::log("Trying to sending Webhook for mdp");
            $payload = [
                'username' => 'Demo Parse Bot',
                'avatar_url' => self::$avatar,
                'content' => 'Link to change log: [Click Here](<https://board.portal2.sr/changelog?id='.$data['id'].'>)'
            ];
            $tempFile = self::CreateTempFile($text);
            $tempErrFile = self::CreateTempFile($err);
            $post = [
                'files[0]' => curl_file_create($tempFile, 'text/plain', $demoName.'.txt'),
                'payload_json' => json_encode($payload)
            ];
    
            if ($err != null) {
                $post['files[1]'] = curl_file_create($tempErrFile, 'text/plain', $demoName.'_err.txt');
            }
            //Debug::log(json_encode($payload));
            $ch = curl_init(Config::get()->discord_webhook_mdp);
            curl_setopt($ch, CURLOPT_USERAGENT, 'board.portal2.sr (https://github.com/p2sr/Portal2Boards)');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
            $response = curl_exec($ch);
            curl_close($ch);
            //Debug::log($response);

            self::DeleteTempFile($tempErrFile);
            self::DeleteTempFile($tempFile);
            //Debug::log("Finished sending");
            
        } catch (\Throwable $th) {
            Debug::log($th->__toString());
        }
        
    }

    public static function sendWebhook($data) {
        Debug::log("Sending Webhook - Building embed");
        $embed = self::buildEmbed($data);
        $payload = [
            'username' => self::$username,
            'avatar_url' => self::$avatar,
            'embeds' => [ $embed ]
        ];
        $body = json_encode($payload);
        Debug::log($body);
        // wait=true is required - without it Discord returns 204 but silently
        // drops the embed from the created message (reproducible; not
        // documented, but confirmed empirically against the live API).
        $ch = curl_init(Config::get()->discord_webhook_wr . '?wait=true');
        curl_setopt($ch, CURLOPT_USERAGENT, 'PropulsionHub (https://github.com/Unkn-0-wngb/PropulsionHub)');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
        Debug::log("Sending Webhook - Finished");
    }

    public static function buildEmbed($data) {
        $embed = [
            'title' => $data['map'],
            'url' => 'https://p2sr.laveryjonez.uk/chamber/'.$data['map_id'],
            'color' => 15959074,
            'thumbnail' => [
                'url' => 'https://p2sr.laveryjonez.uk/images/chambers/'.$data['map_id'].'.jpg',
            ],
            'fields' => [
                [
                    'name' => 'WR',
                    'value' => $data['score'].' (-'.$data['wr_diff'].')',
                    'inline' => true
                ],
                [
                    'name' => 'By',
                    'value' => '['.self::sanitiseText($data['player']).'](<https://p2sr.laveryjonez.uk/profile/'.$data['player_id'].'>)',
                    'inline' => true
                ],
            ]
        ];
        return (object)$embed;
    }

    public static function sanitiseText($text) {
        return preg_replace('/(\\*|_|`|~)/miu', '\\\\$1', $text);
    }

    private static function CreateTempFile($text) {
        $file = tempnam(sys_get_temp_dir(), 'POST');
        file_put_contents($file, $text);
        return $file;
    }

    private static function DeleteTempFile($file) {
        unlink($file);
    }
}

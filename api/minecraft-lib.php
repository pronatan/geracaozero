<?php
/**
 * Lookup Minecraft nick via Mojang + Ely.by (TLauncher)
 */

function gz_mc_ssl_verify(): bool
{
    $v = getenv('GZ_CURL_INSECURE');
    if ($v === false || $v === '') {
        $v = $_ENV['GZ_CURL_INSECURE'] ?? $_SERVER['GZ_CURL_INSECURE'] ?? '';
    }
    return !in_array(strtolower((string) $v), ['1', 'true', 'yes'], true);
}

function gz_mc_http_get_json(string $url, int $timeout = 8): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: GeracaoZero/1.0 (minecraft-lookup)',
        ],
        CURLOPT_SSL_VERIFYPEER => gz_mc_ssl_verify(),
        CURLOPT_SSL_VERIFYHOST => gz_mc_ssl_verify() ? 2 : 0,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => $err, 'transportError' => true];
    }
    $body = null;
    if ($raw !== '' && $status !== 204) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $body,
        'raw' => (string) $raw,
        'transportError' => false,
    ];
}

function gz_mc_format_uuid(string $id): string
{
    $id = strtolower(preg_replace('/[^a-f0-9]/i', '', $id) ?? '');
    if (strlen($id) !== 32) {
        return $id;
    }
    return substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-' . substr($id, 12, 4) . '-' . substr($id, 16, 4) . '-' . substr($id, 20, 12);
}

/**
 * @return array{found:bool,source:?string,sourceLabel:?string,nick:string,uuid:?string,uuidDashed:?string,avatar:?string,message?:string,error?:bool}
 */
function gz_mc_lookup_nick(string $nick): array
{
    $nick = trim($nick);
    $enc = rawurlencode($nick);
    $sawTransportError = false;
    $sawDefinitiveMiss = false;

    $mojangUrls = [
        'https://api.minecraftservices.com/minecraft/profile/lookup/name/' . $enc,
        'https://api.mojang.com/users/profiles/minecraft/' . $enc,
    ];
    foreach ($mojangUrls as $url) {
        $res = gz_mc_http_get_json($url);
        if (!empty($res['transportError'])) {
            $sawTransportError = true;
            continue;
        }
        if ($res['status'] === 200 && !empty($res['body']['id']) && !empty($res['body']['name'])) {
            $uuid = (string) $res['body']['id'];
            $name = (string) $res['body']['name'];
            return [
                'found' => true,
                'source' => 'mojang',
                'sourceLabel' => 'Mojang',
                'nick' => $name,
                'uuid' => $uuid,
                'uuidDashed' => gz_mc_format_uuid($uuid),
                'avatar' => 'https://mc-heads.net/avatar/' . rawurlencode($uuid) . '/64',
            ];
        }
        if (in_array($res['status'], [204, 404], true)) {
            $sawDefinitiveMiss = true;
        }
    }

    $elyUrl = 'https://authserver.ely.by/api/users/profiles/minecraft/' . $enc;
    $ely = gz_mc_http_get_json($elyUrl);
    if (!empty($ely['transportError'])) {
        $sawTransportError = true;
    } elseif ($ely['status'] === 200 && !empty($ely['body']['id']) && !empty($ely['body']['name'])) {
        $uuid = (string) $ely['body']['id'];
        $name = (string) $ely['body']['name'];
        return [
            'found' => true,
            'source' => 'ely',
            'sourceLabel' => 'TLauncher',
            'nick' => $name,
            'uuid' => $uuid,
            'uuidDashed' => gz_mc_format_uuid($uuid),
            'avatar' => 'https://mc-heads.net/avatar/' . rawurlencode($name) . '/64',
        ];
    } elseif (in_array($ely['status'], [204, 404], true)) {
        $sawDefinitiveMiss = true;
    }

    if ($sawTransportError && !$sawDefinitiveMiss) {
        return [
            'found' => false,
            'error' => true,
            'source' => null,
            'sourceLabel' => null,
            'nick' => $nick,
            'uuid' => null,
            'uuidDashed' => null,
            'avatar' => null,
            'message' => 'Não foi possível consultar o nick agora. Tente de novo.',
        ];
    }

    return [
        'found' => false,
        'error' => false,
        'source' => null,
        'sourceLabel' => null,
        'nick' => $nick,
        'uuid' => null,
        'uuidDashed' => null,
        'avatar' => null,
        'message' => 'Nick não encontrado',
    ];
}

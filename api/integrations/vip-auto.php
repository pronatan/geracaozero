<?php
/**
 * Liberação VIP automática via RCON (Minecraft).
 * Ative com MC_VIP_AUTO=true + MC_RCON_* no ambiente.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

function gz_vip_auto_enabled(): bool
{
    return strtolower(gz_env('MC_VIP_AUTO', 'false')) === 'true'
        && gz_env('MC_RCON_HOST', '') !== ''
        && gz_env('MC_RCON_PASSWORD', '') !== '';
}

function gz_rcon_request($fp, int $id, int $type, string $body): ?array
{
    $payload = pack('V', $id) . pack('V', $type) . $body . "\x00\x00";
    $packet = pack('V', strlen($payload)) . $payload;
    if (fwrite($fp, $packet) === false) {
        return null;
    }
    $sizeData = fread($fp, 4);
    if ($sizeData === false || strlen($sizeData) < 4) {
        return null;
    }
    $size = unpack('V', $sizeData)[1];
    $data = '';
    while (strlen($data) < $size) {
        $chunk = fread($fp, $size - strlen($data));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    if (strlen($data) < 8) {
        return null;
    }
    $idOut = unpack('V', substr($data, 0, 4))[1];
    $typeOut = unpack('V', substr($data, 4, 4))[1];
    $bodyOut = substr($data, 8, -2);
    return ['id' => $idOut, 'type' => $typeOut, 'body' => $bodyOut];
}

function gz_rcon_command(string $command): array
{
    $host = gz_env('MC_RCON_HOST', '');
    $port = (int) gz_env('MC_RCON_PORT', '25575');
    $password = gz_env('MC_RCON_PASSWORD', '');
    $fp = @fsockopen($host, $port, $errno, $errstr, 5);
    if (!$fp) {
        return ['ok' => false, 'message' => "RCON connect fail: $errstr ($errno)"];
    }
    stream_set_timeout($fp, 5);
    $auth = gz_rcon_request($fp, 1, 3, $password); // SERVERDATA_AUTH
    if (!$auth || (int) $auth['id'] === -1) {
        fclose($fp);
        return ['ok' => false, 'message' => 'RCON auth failed'];
    }
    $res = gz_rcon_request($fp, 2, 2, $command); // SERVERDATA_EXECCOMMAND
    fclose($fp);
    if (!$res) {
        return ['ok' => false, 'message' => 'RCON command failed'];
    }
    return ['ok' => true, 'message' => 'ok', 'response' => (string) ($res['body'] ?? '')];
}

/**
 * @return array{ok:bool,message:string,command?:string}
 */
function gz_vip_auto_fulfill(string $nick, string $vip): array
{
    if (!gz_vip_auto_enabled()) {
        return ['ok' => false, 'message' => 'VIP automático desligado (MC_VIP_AUTO + RCON)'];
    }

    $nick = preg_replace('/[^a-zA-Z0-9_]/', '', $nick);
    $vip = preg_replace('/[^a-zA-Z0-9_-]/', '', $vip);
    if ($nick === '' || $vip === '') {
        return ['ok' => false, 'message' => 'Nick/VIP inválido'];
    }

    $tpl = gz_env('MC_VIP_COMMAND', 'lp user {nick} parent set {vip}');
    $cmd = str_replace(['{nick}', '{vip}'], [$nick, $vip], $tpl);

    $res = gz_rcon_command($cmd);
    gz_log('vip-auto.log', [
        'nick' => $nick,
        'vip' => $vip,
        'command' => $cmd,
        'ok' => $res['ok'],
        'message' => $res['message'] ?? '',
        'response' => $res['response'] ?? '',
    ]);

    return [
        'ok' => !empty($res['ok']),
        'message' => (string) ($res['message'] ?? ''),
        'command' => $cmd,
    ];
}

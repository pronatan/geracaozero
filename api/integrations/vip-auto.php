<?php
/**
 * Esqueleto Fase B: liberação VIP automática via RCON.
 * Chamado pelo webhook quando o pagamento for aprovado (ligar depois).
 */
require_once dirname(__DIR__) . '/bootstrap.php';

function gz_vip_auto_enabled(): bool
{
    return strtolower(gz_env('MC_VIP_AUTO', 'false')) === 'true'
        && gz_env('MC_RCON_HOST', '') !== ''
        && gz_env('MC_RCON_PASSWORD', '') !== '';
}

/**
 * @return array{ok:bool,message:string,command?:string}
 */
function gz_vip_auto_fulfill(string $nick, string $vip): array
{
    if (!gz_vip_auto_enabled()) {
        return ['ok' => false, 'message' => 'VIP automático desligado (configure MC_VIP_AUTO + RCON)'];
    }

    $tpl = gz_env('MC_VIP_COMMAND', 'lp user {nick} parent set {vip}');
    $cmd = str_replace(
        ['{nick}', '{vip}'],
        [preg_replace('/[^a-zA-Z0-9_]/', '', $nick), preg_replace('/[^a-zA-Z0-9_-]/', '', $vip)],
        $tpl
    );

    // Placeholder: implementar socket RCON na Fase C
    gz_log('vip-auto.log', [
        'nick' => $nick,
        'vip' => $vip,
        'command' => $cmd,
        'note' => 'stub — RCON ainda não conectado',
    ]);

    return [
        'ok' => false,
        'message' => 'RCON ainda não implementado — comando preparado',
        'command' => $cmd,
    ];
}

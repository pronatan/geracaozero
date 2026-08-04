# Integrações (Fase B / C)

Esqueleto para conectar depois com credenciais reais.

## VIP automático (`vip-auto.php`)
Após pagamento aprovado (webhook MP), chamar RCON/plugin para liberar VIP no Minecraft.

Env previstos:
- `MC_RCON_HOST`
- `MC_RCON_PORT`
- `MC_RCON_PASSWORD`
- `MC_VIP_COMMAND=lp user {nick} parent set {vip}`

## Discord OAuth / bot
Env previstos:
- `DISCORD_CLIENT_ID`
- `DISCORD_CLIENT_SECRET`
- `DISCORD_BOT_TOKEN`
- `DISCORD_GUILD_ID`
- `DISCORD_VIP_ROLE_ID`

## Ranking
Fonte de dados do plugin (placeholder em `/ranking`).

## Mapa
URL Dynmap/BlueMap em `GZ_MAP_URL` (página `/mapa`).

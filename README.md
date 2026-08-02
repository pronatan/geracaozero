# Geração Zero — site + checkout Mercado Pago

## Dados do servidor

| Campo | Valor |
|-------|--------|
| IP | `geracaozero.bedrock.net.br` |
| Versão | `26.2` |
| Discord | https://discord.gg/pAtKdPHBk2 |

## Checkout (Mercado Pago Orders)

Formas ativas no site:
- **Pix**
- **Cartão** (Checkout Transparente)

Credenciais ficam em `.env` (nunca no front):
- `MP_ACCESS_TOKEN`
- `MP_PUBLIC_KEY`
- `MP_NOTIFICATION_URL` (opcional, URL pública do webhook)

Endpoints:
- `GET  api/public-key.php`
- `POST api/create-order.php`
- `GET  api/order-status.php?id=ORDER_ID`
- `POST/GET api/webhook.php`

## Conta (login / criar conta)

- `register.html` — criar conta (nick, e-mail, senha)
- `login.html` — entrar
- API: `api/auth/register.php`, `login.php`, `logout.php`, `me.php`
- Contas em `data/users.json` (protegido)

Na navbar aparecem **Entrar** e **Criar conta**. Depois do login: nick + **Sair**.

## Produção (AWS)

- Conta AWS: `adm-geracaozero` (profile CLI `geracaozero`)
- Elastic Beanstalk app: `geracaozero` / env: `geracaozero-prod`
- URL: http://geracaozero-prod.eba-xbechmnn.us-east-1.elasticbeanstalk.com
- Stack: PHP 8.4 / Amazon Linux 2023 / SingleInstance `t3.micro`
- Repo GitHub: https://github.com/pronatan/geracaozero

## Abrir local

O checkout precisa de **PHP** (Live Server/`npx serve` não executa `api/*.php`).

```bash
cd geracaozero
php -S localhost:8080
```

Abra: `http://localhost:8080/produto.html?vip=lacoste`

### Cartões de teste (sandbox)

| Resultado | Número | CVV | Validade | Nome | CPF |
|-----------|--------|-----|----------|------|-----|
| Aprovado (Visa) | `4235 6477 2802 5682` | `123` | `11/30` | `APRO` | `12345678909` |
| Aprovado (Master*) | `5031 4332 1540 6351` | `123` | `11/30` | `APRO` | `12345678909` |

\* Em alguns apps Master de teste pode falhar; use Visa.  
E-mail de teste: `algo@testuser.com`

### Pix de teste

Gera QR normalmente (`waiting_transfer`). Não precisa pagar de verdade no sandbox.

## Webhook em produção

1. No painel do Mercado Pago → Sua integração → Webhooks
2. URL: `https://SEU-DOMINIO/api/webhook.php`
3. Cole a mesma URL em `MP_NOTIFICATION_URL` no `.env`

## Segurança

- Não commit o `.env`
- Se a Access Token vazou (chat/print), **regenere** no painel do MP
- Comece testando valores baixos / ambiente de teste quando possível

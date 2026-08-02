# Geração Zero — site + checkout Mercado Pago

## Dados do servidor

| Campo | Valor |
|-------|--------|
| IP | `geracaozero.bedrock.net.br` |
| Versão | `26.2` |
| Discord | https://discord.gg/pAtKdPHBk2 |

## Domínios (Dynu — free)

| Hostname | Tipo | Destino |
|----------|------|---------|
| `geracaozero.ddnsfree.com` | A (Dynu free) | Elastic Beanstalk |
| `geracaozero.freeddns.org` | A (Dynu free) | Elastic Beanstalk |
| `www.geracaozero.ddnsfree.com` | (CloudFront / ACM) | em configuração |

Credenciais Dynu ficam só no `.env` (`DYNU_API_KEY`, OAuth). **Não commitar.**

## Produção (AWS)

| Recurso | Valor |
|---------|--------|
| Conta / user IAM | `983902695861` / `adm-geracaozero` |
| CLI profile | `geracaozero` |
| Elastic Beanstalk app | `geracaozero` |
| Environment | `geracaozero-prod` (Ready/Green) |
| Platform | PHP 8.4 · Amazon Linux 2023 · `t3.micro` SingleInstance |
| URL EB | http://geracaozero-prod.eba-xbechmnn.us-east-1.elasticbeanstalk.com |
| URL Dynu | http://geracaozero.ddnsfree.com |
| S3 (EB versions) | `elasticbeanstalk-us-east-1-983902695861` |
| ACM | certificado DNS emitido para `geracaozero.ddnsfree.com` |
| CloudFront | **pendente** — conta AWS precisa verificação em Support (`AccessDenied` ao criar distribuição) |
| IAM roles | `aws-elasticbeanstalk-service-role`, `aws-elasticbeanstalk-ec2-role` |

## GitHub

- Conta: **pronatan**
- Repo: https://github.com/pronatan/geracaozero

## Checkout (Mercado Pago Orders)

Formas ativas no site:
- **Pix**
- **Cartão** (Checkout Transparente)

Credenciais em `.env` (nunca no front):
- `MP_ACCESS_TOKEN`
- `MP_PUBLIC_KEY`
- `MP_NOTIFICATION_URL` (webhook público)

> Status atual das chaves: **ambiente de teste** (`test_user_...@testuser.com`). Para cobrança real, trocar pelas chaves de **Produção** no painel do MP.

Endpoints:
- `GET  api/public-key.php`
- `POST api/create-order.php`
- `GET  api/order-status.php?id=ORDER_ID`
- `POST/GET api/webhook.php`

Webhook sugerido:
`http://geracaozero.ddnsfree.com/api/webhook.php`  
(depois do HTTPS/CloudFront: `https://geracaozero.ddnsfree.com/api/webhook.php`)

## Conta (login / criar conta)

- `register.html` — criar conta (nick, e-mail, senha)
- `login.html` — entrar
- API: `api/auth/register.php`, `login.php`, `logout.php`, `me.php`
- Contas em `data/users.json` (protegido)

Na navbar: **Entrar** / **Criar conta** → após login: nick + **Sair**.

## Abrir local

O checkout/login precisa de **PHP** (Live Server/`npx serve` não executa `api/*.php`).

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

Gera QR normalmente (`waiting_transfer`).

## Segurança

- Não commit o `.env`
- Chaves Dynu/MP que vazaram em print/chat: **rotacionar** nos painéis
- Em produção: `MP_SSL_VERIFY=true` e chaves MP de Produção
- CloudFront HTTPS: abrir chamado AWS Support para verificar a conta e liberar criação de distribuições

## Recursos usados (inventário)

| Serviço | Recurso |
|---------|---------|
| GitHub | `pronatan/geracaozero` |
| AWS IAM | user `adm-geracaozero` + roles EB |
| AWS EB | app/env `geracaozero` / `geracaozero-prod` |
| AWS S3 | `elasticbeanstalk-us-east-1-983902695861` |
| AWS ACM | cert `geracaozero.ddnsfree.com` (ISSUED) |
| AWS CloudFront | bloqueado até verificação da conta |
| Dynu | API Key + OAuth2 (no `.env`) |
| Dynu DNS | `geracaozero.ddnsfree.com`, `geracaozero.freeddns.org` |
| Mercado Pago | Orders API (chaves de teste no momento) |
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
| DynamoDB | `gz_users`, `gz_orders`, `gz_products` (on-demand) · `us-east-1` |
| API pública | `http://geracaozero.ddnsfree.com/api/...` |
| Painel admin | `http://geracaozero.ddnsfree.com/admin.html` |

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

> Status das chaves no servidor: **produção** aplicada em `geracaozero-secure`
> (`MP_ACCESS_TOKEN` + `MP_PUBLIC_KEY` + webhook HTTPS).

### Ativar / manter produção (Mercado Pago)

1. Acesse https://www.mercadopago.com.br/developers/panel/app  
2. Abra a aplicação do Geração Zero  
3. Em **Credenciais de produção**, copie:
   - **Public Key** → `MP_PUBLIC_KEY`
   - **Access Token** → `MP_ACCESS_TOKEN`
4. Em **Webhooks** / notificações, cadastre:  
   `https://geracaozero.ddnsfree.com/api/webhook.php`  
   (eventos de Orders / pagamentos)
5. Atualize as variáveis no EB (`geracaozero-secure`) e aguarde o ambiente Ready  
6. Faça um pagamento real pequeno (Pix) para validar

**Importante:** chaves de teste (`TEST-...` ou conta `test_user_...`) **não** cobram dinheiro de verdade.

Endpoints:
- `GET  api/public-key.php`
- `POST api/create-order.php`
- `GET  api/order-status.php?id=ORDER_ID`
- `POST/GET api/webhook.php`

Webhook sugerido:
`https://geracaozero.ddnsfree.com/api/webhook.php`

## Conta (login / criar conta)

- `register.html` — criar conta (nick, e-mail, senha)
- `login.html` — entrar
- API: `api/auth/register.php`, `login.php`, `logout.php`, `me.php`
- Contas, pedidos e produtos no **DynamoDB** (`gz_users`, `gz_orders`, `gz_products`)
- Auth via token (`Authorization: Bearer`) no `localStorage` (`gz_token`)

Na navbar: **Entrar** / **Criar conta** → após login: nick + **Sair** (+ **Admin** se role=admin).

## Painel administrativo

URL: `http://geracaozero.ddnsfree.com/admin.html`

- Produtos VIP (criar/editar/ativar/excluir) → tabela `gz_products`
- Pedidos (listar + marcar VIP liberado)
- Usuários (listar, promover admin, criar user/admin, excluir)

Admin inicial (troque a senha depois):
- nick: `admin`
- e-mail: `admin@geracaozero.com`
- senha: `Admin@GZ2026`

APIs admin (Bearer admin):
- `GET /api/admin/stats.php`
- `GET|POST|PUT|DELETE /api/admin/users.php`
- `GET|POST|PUT|DELETE /api/admin/products.php`
- `GET|PUT /api/admin/orders.php`

Catálogo público: `GET /api/catalog.php`

## Front → API AWS

`assets/js/config.js` define `GZ_API_BASE`:
- no domínio AWS/Dynu → API relativa (`/api/...`)
- em localhost / Live Server → `http://geracaozero.ddnsfree.com`

Assim o HTML local já fala com a API publicada na AWS (não precisa PHP local).

## Abrir local (só front)

```bash
cd geracaozero
npx serve .
# ou Live Server — a API vai para geracaozero.ddnsfree.com
```

Para debugar a API PHP localmente (com DynamoDB), use role/credenciais AWS no `.env`
(`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION=us-east-1`):

```bash
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
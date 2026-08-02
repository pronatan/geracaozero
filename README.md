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

## Recursos usados (inventário completo)

### 1. Hospedagem e nuvem (AWS)

| Recurso | Uso no projeto |
|---------|----------------|
| **Elastic Beanstalk** | App `geracaozero`, env **`geracaozero-secure`** (HTTPS) — PHP 8.x · AL2023 · Apache (`ProxyServer: apache`) |
| **EC2 (via EB)** | Instância que roda o site + API PHP |
| **ALB** | Load balancer do EB; redirect HTTP→HTTPS |
| **S3 (deploy)** | Bucket `elasticbeanstalk-us-east-1-983902695861` — zips de versão do EB |
| **S3 assets** | Bucket `geracaozero-assets-983902695861` — imagens de produtos (`products/`) e avatares (`avatars/`); URLs públicas no DynamoDB |
| **IAM** | User `adm-geracaozero` (CLI profile `geracaozero`); roles EB + policy DynamoDB/S3 |
| **DynamoDB** | `gz_users`, `gz_orders`, `gz_products` (on-demand, `us-east-1`) |
| **ACM** | Certificado TLS para `geracaozero.ddnsfree.com` |
| **CloudWatch Logs** | Stream de logs do EB (`StreamLogs=true`) + health logs |
| **CloudWatch Alarm** | `geracaozero-secure-health` — alerta se saúde do ambiente piorar |
| **CloudFront** | Ainda bloqueado até verificação da conta AWS Support |
| **IMDS** | Credenciais IAM da instância para DynamoDB/S3 |

Config EB no repo: `.ebextensions/01-php.config`, `.ebextensions/03-apache-rewrite.config`, `.htaccess` (URLs limpas + HTTPS via `X-Forwarded-Proto`).

### 2. Domínio / DNS

| Recurso | Uso |
|---------|-----|
| **Dynu** (free) | DNS dinâmico / A records |
| `geracaozero.ddnsfree.com` | Domínio principal público (HTTPS) |
| `geracaozero.freeddns.org` | Domínio secundário |
| Credenciais Dynu | `DYNU_API_KEY`, OAuth no `.env` (não commitado) |

### 3. Pagamentos (Mercado Pago)

| Recurso | Uso |
|---------|-----|
| **Mercado Pago Orders API** | Criar pedido Pix/cartão (`api.mercadopago.com`) |
| **Checkout Transparente SDK JS** | `https://sdk.mercadopago.com/js/v2` no checkout |
| **Webhook** | `POST /api/webhook.php` (HMAC com `MP_WEBHOOK_SECRET`) |
| Env vars | `MP_ACCESS_TOKEN`, `MP_PUBLIC_KEY`, `MP_NOTIFICATION_URL`, `MP_SSL_VERIFY` |

Formas ativas: **Pix** e **cartão**. Status sync: webhook + polling `order-status` + refresh no perfil.

### 4. Minecraft / identidade do jogador

| Recurso | Uso |
|---------|-----|
| **Mojang API** | `api.minecraftservices.com` + `api.mojang.com` — lookup de nick oficial |
| **Ely.by / TLauncher** | `authserver.ely.by` — lookup de nick cracked/TLauncher (interno; UI não cita Ely.by) |
| **mc-heads.net** | Avatar/skin do nick ou UUID (`/avatar/{id}/64`) |
| Front | `assets/js/mc-lookup.js` no cadastro e checkout |
| API | `GET /api/minecraft-lookup.php` · validação em `register.php` e `create-order.php` |

Campos salvos no user: `nick`, `mcUuid`, `mcSource`.

### 5. Front-end (libs CDN + locais)

| Recurso | Uso |
|---------|-----|
| **Bulma** `0.8` | Grid/navbar/forms (`assets/css/bulma.min.css`) |
| **Google Fonts** | `Press Start 2P` (estilo Minecraft) |
| **Font Awesome** | Ícones (v5 `use.fontawesome.com` em páginas antigas; v6.5.2 `cdnjs` em login/register/checkout/conta/admin) |
| **jQuery** `3.4.1` | Google Ajax CDN |
| **CSS próprio** | `assets/css/gz.css`, `admin.css` |
| **JS próprio** | `config.js`, `gz.js`, `auth.js`, `checkout.js`, `conta.js`, `admin.js`, `packs.js`, `avatar.js`, `mc-lookup.js`, `password-toggle.js`, `scripts.js` |

Fundo visual: imagem Pinimg referenciada em `gz.css`.

### 6. Back-end (PHP)

| Área | Arquivos / função |
|------|-------------------|
| Bootstrap | `api/bootstrap.php` (env, CORS, MP HTTP, respond JSON) |
| DynamoDB client | `api/dynamodb.php` (SigV4) |
| Auth | `register`, `login`, `logout`, `me`, `profile`, `common` |
| Loja/pagamentos | `catalog`, `create-order`, `order-status`, `webhook`, `mp-orders`, `public-key` |
| Admin | `stats`, `users`, `products`, `orders` |
| Minecraft | `minecraft-lib.php`, `minecraft-lookup.php` |

Auth: token Bearer em `localStorage` (`gz_token`) + sessão PHP.

### 7. Páginas do site

| Rota limpa | Arquivo | Função |
|------------|---------|--------|
| `/` | `index.html` | Home |
| `/loja` | `loja.html` | Catálogo VIP |
| `/produto` | `produto.html` | Detalhe do pack |
| `/checkout` | `checkout.html` | Pagamento Pix/cartão |
| `/login` · `/register` | login/register | Conta |
| `/conta` | `conta.html` | Pedidos, avatar, senha |
| `/admin` | `admin.html` | Painel admin |
| `/regras` · `/termos` · `/votar` | páginas institucionais | |

Servidor Minecraft (info no site): IP `geracaozero.bedrock.net.br` · Discord `discord.gg/pAtKdPHBk2`.

### 8. Código e deploy

| Recurso | Uso |
|---------|-----|
| **GitHub** | `https://github.com/pronatan/geracaozero` |
| Deploy | Zip → S3 → Application Version → Update Environment `geracaozero-secure` |
| Segredos | `.env` / env vars EB (gitignored) |
| Admin seed (trocar senha) | nick histórico pode ter mudado; senha seed antiga `Admin@GZ2026` |

### 9. Fluxo resumido

```
Visitante → Dynu DNS → ALB HTTPS → Elastic Beanstalk (Apache + PHP)
                                      ├─ HTML/CSS/JS estáticos
                                      ├─ API PHP ──► DynamoDB
                                      ├─ API PHP ──► Mercado Pago
                                      └─ Lookup nick ──► Mojang / Ely.by → avatar mc-heads
```

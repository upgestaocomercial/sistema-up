# Sistema UP de Gestão Comercial

Sistema próprio da UP Gestão Comercial: pedidos, clientes, metas, visitas e financeiro da representação. Construído para substituir o Mercos; roda em paralelo até estar completo e conferido.

- `replica/recon.md` — mapa do sistema (telas, fluxos, modelo de dados, o que a UP usa hoje)
- `replica/architecture.md` — arquitetura, rotas e ordem de construção
- `replica/features.csv` — lista de funcionalidades e andamento

## Estrutura

```
config/          config.example.php (o config.php real não vai para o Git)
database/        schema.sql, seed.sql
src/             servidor PHP (API)
public/          o que vai para o ar: app compilado + api/index.php + .htaccess
frontend/        código do app (Vue); `npm run build` gera os arquivos em public/
bin/             scripts: criar usuário, servidor local
storage/         anexos, extratos, backups (fora do public_html)
tests/           testes
```

## Publicar na HostGator (cPanel)

1. **Subdomínio:** cPanel → Domínios → criar `sistema.upgestaocomercial.com.br`, com a raiz do documento apontando para `sistema-up/public`.
2. **HTTPS:** cPanel → SSL/TLS Status → rodar AutoSSL para o subdomínio.
3. **PHP:** cPanel → Selecionar versão do PHP → 8.1 ou mais nova, com `pdo_mysql`, `mbstring`, `zip`, `gd`, `intl`.
4. **Banco:** cPanel → Bancos de dados MySQL → criar banco e usuário, dar todas as permissões. No phpMyAdmin, importar `database/schema.sql` e depois `database/seed.sql`.
5. **Código:** cPanel → Git Version Control → clonar o repositório privado em `~/sistema-up` (ou enviar a pasta pelo Gerenciador de Arquivos).
6. **Configuração:** copiar `config/config.example.php` para `config/config.php` e preencher banco e SMTP.
7. **Dependências:** no Terminal do cPanel, dentro de `~/sistema-up`: `composer install --no-dev`.
8. **Primeiro usuário:** `php bin/criar-usuario.php "Cristiano Barbosa" marketing@upgestaocomercial.com.br gestao` (a senha é pedida na hora). Os demais usuários são criados pela tela Configurações → Usuários.
9. **Rotinas automáticas** (entram nas próximas fases): cPanel → Cron Jobs.

## Rodar localmente

```bash
composer install
cp config/config.example.php config/config.php   # ajustar banco, debug=true, cookie_secure=false
mysql up_sistema < database/schema.sql && mysql up_sistema < database/seed.sql
cd frontend && npm install && npm run build && cd ..
php -S 127.0.0.1:8080 -t public bin/dev-router.php
tests/api-smoke.sh
```

## Regras do projeto

- Desenho próprio da UP. O Mercos é usado como referência do que a empresa precisa, sem copiar telas, textos, estrutura de banco ou código.
- Nada com histórico é apagado: cadastros são desativados.
- O financeiro só aparece para quem tem "Acessa o financeiro" marcado no usuário, e é bloqueado também no servidor.

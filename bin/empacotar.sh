#!/usr/bin/env bash
# Gera o pacote para publicar na HostGator.
# Estrutura do .zip (extrair na pasta inicial da conta, a "home"):
#   sistema-up/          código, banco, configuração (fora do public_html)
#   public_html/app/     o que o navegador acessa (app.upgestaocomercial.com.br)
# Uso: bin/empacotar.sh [primeira-instalacao|atualizacao]
set -euo pipefail
MODO="${1:-primeira-instalacao}"
RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
SAIDA="${SAIDA:-$RAIZ/dist}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

rm -rf "$RAIZ/public/assets"
cd "$RAIZ/frontend" && npm run build >/dev/null
cd "$RAIZ"
composer dump-autoload -q --optimize --no-dev

mkdir -p "$TMP/sistema-up/config" "$TMP/sistema-up/storage" "$TMP/public_html/app"
cp -r src vendor database bin "$TMP/sistema-up/"
rm -f "$TMP/sistema-up/bin/dev-router.php"
cp config/config.example.php "$TMP/sistema-up/config/"
cp composer.json README.md "$TMP/sistema-up/"
printf 'Require all denied\nDeny from all\n' > "$TMP/sistema-up/.htaccess"
printf 'Require all denied\nDeny from all\n' > "$TMP/sistema-up/storage/.htaccess"

# pasta pública
cp -r public/. "$TMP/public_html/app/"
if [ "$MODO" = "atualizacao" ]; then
  rm -f "$TMP/public_html/app/instalar.php"   # atualização não reinstala
fi

VERSAO="$(git rev-parse --short HEAD 2>/dev/null || date +%Y%m%d%H%M)"
echo "$VERSAO" > "$TMP/sistema-up/VERSAO"
mkdir -p "$SAIDA"
NOME="sistema-up-$MODO-$VERSAO.zip"
rm -f "$SAIDA/$NOME"
(cd "$TMP" && zip -qr -X "$SAIDA/$NOME" sistema-up public_html)
composer dump-autoload -q   # volta o autoload de desenvolvimento
echo "$SAIDA/$NOME"

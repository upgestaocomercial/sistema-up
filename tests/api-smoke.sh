#!/usr/bin/env bash
# Teste rápido da API contra um servidor rodando (padrão: http://127.0.0.1:8080).
# Precisa dos usuários de teste criados (ver README, "Rodar localmente").
set -u
URL="${1:-http://127.0.0.1:8080}"
H=(-H 'Content-Type: application/json' -H 'X-Requested-With: sistema-up')
T=$(mktemp -d); falhas=0

checa() { # nome, esperado, obtido
  if [[ "$3" == *"$2"* ]]; then echo "ok   - $1"; else echo "FALHA - $1 (esperado '$2', veio '$3')"; falhas=$((falhas+1)); fi
}
login() { curl -s -c "$T/$1" "${H[@]}" -d "{\"email\":\"$2\",\"senha\":\"$3\"}" "$URL/api/auth/login"; }

checa "saúde"                  '"ok":true'                 "$(curl -s "$URL/api/saude")"
checa "sem login bloqueia"     'Faça login'                "$(curl -s "$URL/api/representadas")"
checa "senha errada"           'incorretos'                "$(login x marketing@upgestaocomercial.com.br errada)"
checa "login gestão"           '"perfil":"gestao"'         "$(login g marketing@upgestaocomercial.com.br gestao123)"
checa "login financeiro"       '"acesso_financeiro":true'  "$(login f leonardo@upgestaocomercial.com.br financeiro1)"
checa "login vendedor"         '"perfil":"vendedor"'       "$(login v vendedora.teste@exemplo.com vendedora1)"
checa "5 representadas"        'Bognar'                    "$(curl -s -b "$T/g" "$URL/api/representadas")"
checa "gestão sem financeiro"  'restrito ao financeiro'    "$(curl -s -b "$T/g" "$URL/api/financeiro/ping")"
checa "financeiro entra"       '"ok":true'                 "$(curl -s -b "$T/f" "$URL/api/financeiro/ping")"
checa "vendedor sem config"    'Sem permissão'             "$(curl -s -b "$T/v" "$URL/api/config/usuarios")"
checa "CSRF sem header"        'recusada'                  "$(curl -s -b "$T/g" -H 'Content-Type: application/json' -X PUT -d '{}' "$URL/api/config/representadas/5")"
checa "validação de usuário"   'E-mail inválido'           "$(curl -s -b "$T/g" "${H[@]}" -d '{"email":"ruim"}' "$URL/api/config/usuarios")"
checa "vírgula decimal"        '"comissao_pct":7.0'        "$(curl -s -b "$T/g" "${H[@]}" -X PUT -d '{"comissao_pct":"7,0"}' "$URL/api/config/representadas/5")"
checa "rota inexistente"       'não encontrada'            "$(curl -s -b "$T/g" "$URL/api/nada")"
checa "logout"                 '"ok":true'                 "$(curl -s -b "$T/g" -c "$T/g" "${H[@]}" -X POST "$URL/api/auth/logout")"
checa "sessão encerrada"       'Faça login'                "$(curl -s -b "$T/g" "$URL/api/auth/eu")"

rm -rf "$T"
echo; [[ $falhas -eq 0 ]] && echo "Tudo certo." || { echo "$falhas falha(s)."; exit 1; }

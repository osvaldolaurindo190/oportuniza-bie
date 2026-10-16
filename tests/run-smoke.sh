#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${SMOKE_PORT:-18927}"
LOG="$(mktemp)"
cd "$ROOT"
php tests/unit.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null
echo "PASS: sintaxe PHP"
php -S "127.0.0.1:${PORT}" -t "$ROOT" >"$LOG" 2>&1 &
SERVER_PID=$!
cleanup() { kill "$SERVER_PID" >/dev/null 2>&1 || true; rm -f "$LOG"; }
trap cleanup EXIT
for _ in $(seq 1 30); do
  if curl -fsS "http://127.0.0.1:${PORT}/" >/dev/null 2>&1; then break; fi
  sleep 0.2
done
check_page() {
  local path="$1" expected="$2" body
  body="$(curl -fsS "http://127.0.0.1:${PORT}${path}")"
  if ! grep -Fq "$expected" <<<"$body"; then
    echo "FAIL: $path não contém texto esperado: $expected" >&2
    exit 1
  fi
  echo "PASS: $path"
}
check_page / 'Oportuniza Bié'
check_page /auth/registar.php 'Criar conta'
check_page /auth/entrar.php 'Entrar na conta'
check_page /modules/emprego/index.php 'Emprego e recrutamento'
check_page /modules/microtarefas/index.php 'Microtarefas'
check_page /modules/servicos/index.php 'Prestação de serviços'
check_page /modules/mercado/index.php 'Mercado digital'

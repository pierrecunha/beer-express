#!/usr/bin/env bash
# Sintaxe de todo o código: PHP (php -l) e <script> dentro dos .html (node).
set -uo pipefail
cd "$(dirname "$0")/.."
erros=0
for f in $(git ls-files 'server/*.php' 'tests/*.php' 2>/dev/null || find server tests -name '*.php'); do
  out=$(php -l "$f" 2>&1) || { echo "$out"; erros=$((erros+1)); }
done
for f in $(git ls-files 'server/*.html' 2>/dev/null || find server -name '*.html'); do
  node -e '
    const s=require("fs").readFileSync(process.argv[1],"utf8"),re=/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi;let m,i=0,bad=0;
    while((m=re.exec(s))){i++;try{new Function(m[1])}catch(e){bad++;console.log(`${process.argv[1]}: <script> #${i}: ${e.message}`)}}
    process.exit(bad?1:0)' "$f" || erros=$((erros+1))
done
[ "$erros" -eq 0 ] && echo "lint: ok" || { echo "lint: $erros arquivo(s) com erro"; exit 1; }

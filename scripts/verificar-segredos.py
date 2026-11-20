#!/usr/bin/env python3
"""Barra segredo no Git. Roda no pre-commit, no `make gate` e no GitHub Actions.

  scripts/verificar-segredos.py            -> confere todos os arquivos versionados (git ls-files)
  scripts/verificar-segredos.py --staged   -> confere só o que está no próximo commit
  scripts/verificar-segredos.py ARQ...     -> confere os arquivos indicados

Acha: arquivo .env/certificado versionado, chave privada, token do Mercado Pago, token/segredo do PagBank,
JWT_SECRET/senha/token com valor real atribuído em código ou .env, chave da Evolution, URL com senha.
Valor de exemplo ("troque", "cole_", "SEU_", vazio, ${VAR}) é ignorado.
Falso positivo: acrescente o comentário  segredo-ok  na mesma linha.
"""
import re, subprocess, sys, os

PROIBIDOS = re.compile(r'(^|/)(\.env(\..+)?|.*\.(pem|key|p12|pfx)|env_.*\.txt)$')
LIBERADOS = {'.env.example'}
PADROES = [
    ('chave privada', re.compile(r'-----BEGIN (RSA |EC |OPENSSH |)PRIVATE KEY-----')),
    ('token Mercado Pago', re.compile(r'APP_USR-\d{6,}-\d{6}-[0-9a-f]{20,}')),
    ('token PagBank', re.compile(r'\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}[0-9a-f]{40,}', re.I)),
    ('token GitHub', re.compile(r'\bgh[pousr]_[A-Za-z0-9]{30,}')),
    ('token Telegram', re.compile(r'\b\d{8,10}:AA[A-Za-z0-9_-]{30,}')),
    ('segredo em .env', re.compile(
        r'^\s*(JWT_SECRET|DB_PASS|DB_ROOT_PASS|EVO_TOKEN|PAGBANK_TOKEN|PAGBANK_CLIENT_SECRET|PIX_CLIENT_SECRET|MP_ACCESS_TOKEN|'
        r'SUPERADMIN_SETUP_KEY|OTA_PASSWORD)\s*=\s*["\']?([^"\'\s#]{6,})')),
    ('URL com senha', re.compile(r'\b[a-z]+://[^\s/:@]+:[^\s/@]{6,}@[\w.-]+')),  # segredo-ok
    ('segredo atribuído', re.compile(
        r'''(?ix)\b(JWT_SECRET|DB_PASS(WORD)?|DB_ROOT_PASS|EVO_TOKEN|PAGBANK_TOKEN|PAGBANK_CLIENT_SECRET|PIX_CLIENT_SECRET|
             MP_ACCESS_TOKEN|AGENT_KEY|API_KEY|SECRET|PASSWORD|SENHA|OTA_PASS(WORD)?|SUPERADMIN_SETUP_KEY)\b
             ["']?\s*(=>|=|:|,)\s*["']?([^"'\s,;)#]{12,})''')),
]
EXEMPLO = re.compile(r'(?i)(troque|cole|seu_|sua_|exemplo|example|changeme|xxx|placeholder|aqui|\$\{|getenv|env\(|\$_|test_secret|we_test|\.\.\.|<|%s|\{\$)')

def arquivos(argv):
    if '--staged' in argv:
        out = subprocess.run(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR'], capture_output=True, text=True).stdout
        return [f for f in out.splitlines() if f]
    if argv: return argv
    return subprocess.run(['git', 'ls-files'], capture_output=True, text=True).stdout.splitlines()

def main(argv):
    achados = []
    for f in arquivos(argv):
        nome = f.replace('\\', '/')
        if PROIBIDOS.search(nome) and os.path.basename(nome) not in LIBERADOS:
            achados.append((f, 0, 'arquivo de segredo não pode ir para o Git', '')); continue
        if not os.path.isfile(f) or os.path.getsize(f) > 3_000_000: continue
        try: txt = open(f, encoding='utf-8', errors='ignore').read()
        except OSError: continue
        if '\0' in txt[:2000]: continue
        for n, linha in enumerate(txt.splitlines(), 1):
            if 'segredo-ok' in linha: continue
            for nomep, rx in PADROES:
                m = rx.search(linha)
                if not m: continue
                if nomep == 'segredo em .env' and EXEMPLO.search(m.group(2)): continue
                if nomep == 'segredo atribuído':
                    v = m.group(5)
                    # valor literal de verdade: tem dígito E letra minúscula, e não é expressão/constante de código
                    if EXEMPLO.search(m.group(0)) or re.search(r'[()$\[\]{}]', v) or re.fullmatch(r'[A-Z][A-Z0-9_]*', v) \
                       or not re.search(r'[A-Za-z]', v) or not (re.search(r'\d', v) or len(v) >= 16):
                        continue
                achados.append((f, n, nomep, linha.strip()[:90]))
    if achados:
        print('SEGREDO ENCONTRADO — commit/deploy bloqueado:\n')
        for f, n, t, l in achados:
            dica = re.split(r'''[=:'"]''', l, maxsplit=1)[0][:40] + '=…' if l else ''   # nunca imprime o valor
            print(f'  {f}:{n}  [{t}]  {dica}')
        print('\nTire o valor do arquivo (vá para o .env) ou marque o falso positivo com  # segredo-ok')
        return 1
    print('segredos: ok')
    return 0

if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))

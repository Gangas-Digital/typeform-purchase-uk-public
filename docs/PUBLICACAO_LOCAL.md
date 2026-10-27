# Publicação local (sem GitHub Actions)

## Testar

```bash
docker compose up -d          # WordPress em http://localhost:8088 (OrbStack)
tests/run-all.sh              # tem de terminar com "0 falharam"
```

## Gerar o pacote do plugin

A partir de um checkout limpo, no SHA aprovado:

```bash
git status --short            # tem de estar vazio
rm -rf dist && mkdir dist
(cd plugin && zip -qr ../dist/typeform-purchase-uk.zip typeform-purchase-uk)
git rev-parse HEAD            # anotar o SHA publicado
```

## Instalar no dronline.uk

Só com aprovação explícita do Arménio para aquela versão (SHA).

1. WordPress → Plugins → Adicionar → Carregar o `dist/typeform-purchase-uk.zip` (substitui a versão anterior).
2. Confirmar as definições no menu **Typeform UK**: token do Typeform ("Testar o token"), URL do sGTM, GA4 Measurement ID e moeda.
3. Validar com uma compra de teste (modo de teste do Stripe no Typeform), com o GTM Preview e o GA4 DebugView abertos.
4. Registar no PR ou na issue: SHA, data, e o que foi validado (compra no registo do plugin, evento no Preview do sGTM, GA4 e Google Ads).

Não há deploy automático, nem staging do dronline.uk neste repositório.

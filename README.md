# Typeform Purchase UK

> **Public snapshot of v0.5.1** (single commit, no history). Development happens in a private repository. Installation guide in English: [docs/README-EN.md](docs/README-EN.md).

> **English:** see [docs/README-EN.md](docs/README-EN.md) (how it works, with diagrams, installation and validation).

Desenvolvido por **Arménio Ganga - Dronline**. Versão atual: **0.5.1**. A versão aparece no admin, no registo e em cada evento enviado ao sGTM (`plugin_version` e header `X-TPUK-Version`).

Plugin WordPress para o dronline.uk que encontra as compras pagas nos Typeforms e as envia ao **sGTM** (`sgtm.dronline.uk`) como evento `purchase` do GA4, **no mesmo formato que o GTM do site usa** (`/g/collect`). O client GA4 que já existe no sGTM recebe-as e as tags que já existem tratam delas: GA4 (todas as compras, pagas e orgânicas), Google Ads "Purchase (Google Ads)" e Meta CAPI (com o consentimento). Não é preciso mudar nada no GTM, no sGTM nem nos formulários do Typeform.

## Problema

- Nenhuma venda dos Typeforms do UK chega ao Google Ads nem ao GA4 como compra. A maioria acontece em Typeforms que abrem em typeform.com (depois do questionário principal do site), onde o GTM da página não chega.

## Como funciona

```
Site (tpuk.js)  ── Typeform incorporado submetido: id da resposta + gclid, msclkid, fbc/fbp, _ga, UTMs ──▶ WordPress
                                                                                                              │
Cron (5 min)  1. lê essas respostas na API do Typeform e liga os cliques ao email (hash)                      │
              2. lê as respostas das últimas 2 h de todos os formulários com pagamento                         │
              3. cada compra paga nova ──▶ sGTM /g/collect (evento purchase do GA4, como o site) ──▶ client GA4 existente
                                            └─▶ GA4 · Google Ads (Purchase) · Meta CAPI (com consentimento)
```

- **Regra de compra.** Só conta uma resposta com o bloco de pagamento confirmado: `completed: true` na API de respostas (`success: true` no webhook), sempre o booleano `true`. O valor é o montante cobrado (`"208.00"`, `"1,234.50"`, `"208,50"`…). Pagamentos falhados e respostas sem pagamento são ignorados.
- **Cliques ligados pelo email.** Quando a pessoa submete um Typeform incorporado no site (ex.: o questionário principal), o `tpuk.js` envia o id da resposta e os cliques da visita. O plugin lê essa resposta na API e guarda os cliques pelo hash do email (30 dias). A compra paga depois, mesmo noutro Typeform, leva-os. Se o próprio formulário de pagamento estiver no site, liga-se pelo id da resposta.
- **Consentimento (Complianz, opt-in).** Todas as compras pagas são enviadas ao sGTM (o GA4 regista todas). Os identificadores dependem da escolha da pessoa no banner: cliques (gclid, gbraid, wbraid, msclkid, fbc, fbp) e email em hash só com o **marketing** aceite; o `_ga` só com as **estatísticas** aceites. Sem registo da visita ao site conta como não aceite. O `tpuk.js` só guarda o cookie com os cliques depois de o marketing ser aceite (também quando a pessoa aceita o banner depois de chegar). O estado vai sempre no pedido: `gcs` (G1 + ad_storage + analytics_storage, como os pedidos do GA4 do site), `npa` e `ep.consent_marketing`/`ep.consent_statistics` (`granted`, `denied`, `unknown`). No sGTM, as tags respeitam este estado como fazem com os pedidos do site.
- **Espera e repetição.** Uma compra sem registo da visita ao site espera até 45 min depois de submetida (a API do Typeform pode demorar ~30 min a devolver a resposta do site). Se o sGTM falhar, volta a tentar de 5 em 5 min até 71 h (limite do GA4).
- **Formulários novos.** A lista de formulários com pagamento é revista de 6 em 6 horas. Num formulário que aparece pela primeira vez, a leitura começa na revisão anterior da lista.
- **Sem duplicados.** `transaction_id = tf_<response_id>`. Cada compra é enviada uma vez.
- **Só leitura.** O plugin só faz `GET` à API do Typeform; nunca altera formulários. O token nunca é mostrado nem registado; o registo não guarda emails nem hashes.

## O que é enviado ao sGTM

Um pedido por compra, igual aos do GA4 do site:

```
POST https://sgtm.dronline.uk/g/collect?v=2&tid=G-KD0WK0RF22&cid=<_ga ou id estável>&sid=…&en=purchase
     &dl=https://dronline.uk/?gclid=…&utm_source=…     (cliques só com o marketing aceite)
     &gcs=G1<ad_storage><analytics_storage>&npa=0|1    (consentimento do Complianz)
     &cu=GBP&epn.value=59&ep.transaction_id=tf_<response_id>&pr1=id<form_id>~nm<título>~pr59~qt1
     &ep.form_id=…&ep.source=typeform&ep.plugin_version=0.5.1&ep.consent_marketing=granted|denied|unknown
     &ep.msclkid=…&ep.fbc=…&ep.fbp=…&em=tv.1~em.<sha256 do email>   (só com o marketing aceite)
```

No sGTM publicado, sem alterações: a tag "GA4 - reencaminhar para Google" regista a compra no GA4; a tag "Google Ads - Purchase Google Ads (server)" dispara no `purchase` (conversão "Purchase (Google Ads)", deduplicada pelo `transaction_id`); a "CAPI - complete_payment" só dispara com ad_storage aceite. O campo "Preview do sGTM" do plugin envia o pedido para o Preview, para testar sem afetar dados reais.

## Instalação no dronline.uk

Seguir [docs/PUBLICACAO_LOCAL.md](docs/PUBLICACAO_LOCAL.md). Resumo:

1. Acesso da conta do token a **todos os espaços** do Typeform com formulários de pagamento, com permissão para ver respostas.
2. Token: Typeform → Settings → Personal tokens, com `forms:read` e `responses:read`.
3. Instalar `dist/typeform-purchase-uk.zip` e ativar. Menu **Typeform UK** (ícone Dronline): token ("Testar o token"), URL do sGTM (`https://sgtm.dronline.uk`), GA4 Measurement ID (`G-KD0WK0RF22`) e moeda (GBP). O campo "Preview do sGTM" só se usa em testes e fica vazio em produção.
4. Validar no registo do plugin, no Preview do sGTM, no GA4 e, 24 a 48 h depois, no Google Ads.
5. Depois: `TypeformSubmit` a secundária no Google Ads; pausar as tags GA4 antigas `purchase_weightloss` e `purchase_flow` no GTM web.

## Desenvolvimento e testes (OrbStack, sem GitHub Actions)

```bash
docker compose up -d     # WordPress em http://localhost:8088 (admin em tests/dev-credentials.env)
tests/run-all.sh         # 95 testes: regra de compra, leitura, cliques pelo email, consentimento, envio e repetição, formulários novos, segurança, front-end
```

- A API do Typeform é simulada por `tests/mu-typeform-mock.php` (token `tf-test-token`); para testar com o Typeform real, remover esse mu-plugin do contentor.
- O contentor `echo` faz de sGTM (`http://echo:8080`). Para ver o que foi enviado: `docker compose logs echo | grep 'POST /g/collect'`.
- Os testes do front-end (`tests/browser.mjs`) usam o Google Chrome em modo headless (Node 22 ou mais recente). Sem Chrome são ignorados.

## Agentes e fixes

- `.claude/agents/qa.md`: testa e abre uma issue `fix` por cada defeito no GitHub Project de fixes.
- `.claude/agents/fix.md`: corrige uma issue por PR, com teste.

Ver [AGENTS.md](AGENTS.md).

## Em aberto

- [ ] Acesso ao espaço dos formulários "Follow up Weight Loss Consultation" (onde estão as vendas atuais).
- [ ] Validar no Preview do sGTM publicado: GA4, Google Ads (gclid no page_location, email em hash) e Meta (consentimento).
- [ ] Confirmar com o Typeform real que o iframe envia o `form-submit` com o `responseId`.
- [ ] Medir quanto tempo a API do Typeform demora a devolver uma resposta nova.
- [ ] Vendas passadas: só compras das últimas 2 h na 1.ª leitura (até 71 h podem ser enviadas, se for preciso recuperar).

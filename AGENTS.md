# Instruções para agentes

## Política atual: zero GitHub Actions

Este repositório segue o processo Gangas Digital de 25/10/2026: GitHub guarda o código, as issues e as revisões; os testes, o build e a publicação correm no computador. Não criar `.github/workflows`, não usar Run workflow nem `gh workflow run`. Ver [docs/PUBLICACAO_LOCAL.md](docs/PUBLICACAO_LOCAL.md).

## O projeto

Plugin WordPress para o dronline.uk que encontra as compras pagas nos Typeforms (API do Typeform, só leitura) e as envia ao **sGTM** como evento `purchase` do GA4, no mesmo formato que o site (`/g/collect`), para as tags que já existem (GA4, Google Ads, Meta), com os cliques da visita ao site ligados pelo email e o consentimento do Complianz. Não muda nada no GTM nem no sGTM, não usa dataLayer nem altera os formulários.

Regra de compra: só conta uma resposta com o bloco de pagamento confirmado (`completed: true` na API, `success: true` no webhook), sempre o booleano `true`. Os outros fluxos do formulário nunca são compra.

## Agentes

| Agente | Ficheiro | Papel |
|---|---|---|
| QA | `.claude/agents/qa.md` | Corre os testes, procura defeitos e abre uma issue `fix` por defeito no Project. Não altera código. |
| Fix | `.claude/agents/fix.md` | Pega numa issue `fix`, reproduz com um teste, corrige num ramo próprio e abre o PR. Não faz merge nem deploy. |

**Project de fixes:** GitHub Project "Typeform Purchase UK — Fixes", da org Gangas-Digital (número no fim deste ficheiro). Estados: Todo → In Progress → In Review → Done.

## Regras

- Uma worktree e um ramo por tarefa, sempre dentro do projeto em `.worktrees/<tarefa>` (fora do Git); nunca ao lado da pasta do projeto. Depois de o PR ser integrado, remover a worktree (`git worktree remove`). Preservar o trabalho de outros agentes.
- Testar sempre com `tests/run-all.sh` (WordPress local no OrbStack, `docker compose up -d`).
- Nunca usar dados reais de clientes nem credenciais reais nos testes, nas issues ou nos logs. As credenciais locais de teste estão em `tests/dev-credentials.env` (fora do Git).
- Instalar no dronline.uk, fazer release ou mudar as definições de produção exige aprovação explícita do Arménio.
- Distinguir: teste local passou ≠ instalado em produção ≠ compra validada no GA4/Ads.

## Referências

- Project de fixes: https://github.com/orgs/Gangas-Digital/projects/12 (número 12, privado)
- Versão: cabeçalho `Version` e `const VERSION` em `typeform-purchase-uk.php` têm de ser iguais (o teste verifica). Subir a versão em cada alteração que vá para produção.

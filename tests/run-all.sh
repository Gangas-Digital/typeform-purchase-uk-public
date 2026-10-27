#!/usr/bin/env bash
# Testes locais (sem GitHub Actions). Precisa do ambiente do docker-compose a correr.
# Uso: tests/run-all.sh   -> sai com código 1 se algum teste falhar
# A API do Typeform é simulada (tests/mu-typeform-mock.php); o contentor "echo" faz de sGTM.
set -uo pipefail
cd "$(dirname "$0")/.."

PASS=0; FAIL=0
check() { # nome, esperado (substring), comando...
  local name="$1" expected="$2"; shift 2
  local out; out="$("$@" 2>&1)"
  if [[ "$out" == *"$expected"* ]]; then PASS=$((PASS+1)); echo "ok   $name";
  else FAIL=$((FAIL+1)); echo "FAIL $name"; echo "     esperado: $expected"; echo "     obtido:   ${out:0:400}"; fi
}
check_eq() { # nome, esperado (valor exato), comando...
  local name="$1" expected="$2"; shift 2
  local out; out="$("$@" 2>&1)"
  if [[ "$out" == "$expected" ]]; then PASS=$((PASS+1)); echo "ok   $name";
  else FAIL=$((FAIL+1)); echo "FAIL $name"; echo "     esperado: $expected"; echo "     obtido:   ${out:0:400}"; fi
}
wp() { docker compose run --rm -T --no-deps cli wp "$@" 2>/dev/null; }
B=http://localhost:8088/wp-json/typeform-purchase-uk/v1
visit() { curl -s -X POST "$B/visit" -H 'Content-Type: application/json' -d "{\"form_id\":\"$1\",\"response_id\":\"$2\",\"attr\":$3}"; }
# Pedidos /g/collect que o sGTM (echo) recebeu desde T0 com o texto $1 (e $2, se indicado), no URL do pedido.
sgtm() {
  docker compose logs echo --no-log-prefix --since "$T0" 2>/dev/null | grep '"POST /g/collect?' | grep -F "$1" | grep -cF -- "${2:-$1}"
}
txv() { wp eval "\$r = get_transient('tpuk_tx_' . md5('$1')); echo \$r ? \$r['p']['value'] : 'sem registo';"; }
queue() { wp eval 'echo implode(",", array_keys(get_option("tpuk_queue", [])));'; }
cron() { wp eval "echo Typeform_Purchase_UK::run_cron(${1:-time()});"; }
# Respostas da API simulada: tpuk_add(id, formulário, há quantos segundos, respostas).
ADD='function pay($v, $ok = true) { return ["type" => "payment", "field" => ["id" => "p1", "type" => "payment"], "payment" => ["amount" => $v, "last4" => "4242", "name" => "Teste", "completed" => $ok]]; }
function em($e) { return ["type" => "email", "field" => ["id" => "e1", "type" => "email"], "email" => $e]; }
function tpuk_add($rid, $form, $ago, array $answers) { $r = get_option("tpuk_mock_responses", []); $r[$rid] = ["form_id" => $form, "response_id" => $rid, "token" => $rid, "submitted_at" => gmdate("Y-m-d\\TH:i:s\\Z", time() - $ago), "hidden" => [], "answers" => $answers]; update_option("tpuk_mock_responses", $r, false); }
function sgtm_url($u) { $s = get_option("tpuk_settings"); $s["sgtm_url"] = $u; update_option("tpuk_settings", $s); }'
add() { wp eval "$ADD $1"; }

echo "# preparar"
docker compose up -d >/dev/null 2>&1
docker compose exec -T wordpress sh -c 'mkdir -p /var/www/html/wp-content/mu-plugins && rm -f /var/www/html/wp-content/mu-plugins/tpuk-echo.php'
docker compose cp tests/mu-typeform-mock.php wordpress:/var/www/html/wp-content/mu-plugins/tpuk-typeform-mock.php >/dev/null 2>&1
wp plugin activate typeform-purchase-uk >/dev/null
wp option update tpuk_settings --format=json "$(cat tests/settings.json)" >/dev/null
wp transient delete --all >/dev/null
for o in tpuk_log tpuk_scan tpuk_queue tpuk_resp_wait tpuk_mock_calls tpuk_mock_responses; do wp option delete $o >/dev/null; done
wp eval 'update_option("tpuk_mock_forms", [
  "mockpay01"  => ["title" => "Mock pagamento", "fields" => [["type" => "group", "properties" => ["fields" => [["type" => "payment"]]]]]],
  "mockmain01" => ["title" => "Mock questionário principal", "fields" => [["type" => "email"], ["type" => "short_text"]]],
], false);' >/dev/null
add 'tpuk_add("rpaid0000000001", "mockpay01", 600, [pay("189.00"), em("Paid@Example.com")]);
tpuk_add("rfree0000000002", "mockpay01", 600, [["type" => "text", "text" => "sem pagamento"]]);
tpuk_add("rfail0000000003", "mockpay01", 600, [pay("189.00", false)]);
tpuk_add("rstr00000000004", "mockpay01", 600, [pay("189.00", "true")]);
tpuk_add("rold00000000005", "mockpay01", 5 * 3600, [pay("99.00")]);
tpuk_add("rmain0000000006", "mockmain01", 1200, [em("Lead@Example.com"), ["type" => "text", "text" => "x"]]);' >/dev/null
T0="$(date -u +%Y-%m-%dT%H:%M:%SZ)"; sleep 1
NOW="$(date +%s)"

echo "# php"
check "php lint" "No syntax errors" docker compose exec -T wordpress php -l /var/www/html/wp-content/plugins/typeform-purchase-uk/typeform-purchase-uk.php
F=plugin/typeform-purchase-uk/typeform-purchase-uk.php
HV="$(sed -n 's/^ \* Version: *//p' $F)"
check "versão do cabeçalho = VERSION ($HV)" "const VERSION      = '$HV'" grep -o "const VERSION      = '[^']*'" $F
check "autor Arménio Ganga - Dronline" 'Arménio Ganga - Dronline' grep -o 'Author: .*' $F

echo "# definições"
check_eq "testar o token"               'Token OK: it can access 2 forms.' wp eval 'echo Typeform_Purchase_UK::check_token();'
check_eq "só 5 opções (sem dataLayer, webhook nem reserva)" 'typeform_token,sgtm_url,ga4_measurement_id,sgtm_preview,currency' wp eval 'echo implode(",", array_keys(Typeform_Purchase_UK::sanitize(["datalayer" => 1, "webhook_secret" => "x", "server_mode" => "both"])));'
check_eq "guardar com o token vazio mantém o token" 'tf-test-token' wp eval 'echo Typeform_Purchase_UK::sanitize(["typeform_token" => ""])["typeform_token"];'
check_eq "Apagar remove o token"        ''                     wp eval 'echo Typeform_Purchase_UK::sanitize(["typeform_token" => "", "typeform_token_clear" => "1"])["typeform_token"];'
check_eq "Measurement ID inválido -> vazio" ''                 wp eval 'echo Typeform_Purchase_UK::sanitize(["ga4_measurement_id" => "UA-1"])["ga4_measurement_id"];'
check_eq "admin não mostra o token"     'escondido'            wp eval 'wp_set_current_user(1); ob_start(); Typeform_Purchase_UK::render_admin(); echo strpos(ob_get_clean(), "tf-test-token") === false ? "escondido" : "VISIVEL";'
check "rotas antigas (dataLayer) removidas" 'rest_no_route'    curl -s "$B/purchase?sid=x"
check_eq "montantes: 208.00, 208,50, 1,234.50, 1.234,50, 99.5, sem número" '208|208.5|1234.5|1234.5|99.5|null' wp eval '$f = function ($v) { $n = Typeform_Purchase_UK::parse_amount($v); return $n === null ? "null" : $n; }; echo implode("|", array_map($f, ["208.00", "208,50", "1,234.50", "1.234,50", 99.5, "abc"]));'

echo "# visita ao site: cliques guardados para a resposta"
check "id de resposta inválido -> 400"  'HTTP 400'             curl -s -o /dev/null -w 'HTTP %{http_code}' -X POST "$B/visit" -H 'Content-Type: application/json' -d '{"form_id":"mockmain01","response_id":"../x","attr":{"gclid":"x"}}'
check_eq "sem cliques -> ignored"       '{"status":"ignored"}' visit mockmain01 rmain0000000006 '{}'
check_eq "com gclid, msclkid e fbc -> ok" '{"status":"ok"}'    visit mockmain01 rmain0000000006 '{"gclid":"GCLID-LEAD","msclkid":"MSCLK-LEAD","fbc":"fb.1.1700000000000.FBLEAD","fbp":"fb.1.1700000000000.222","ga_client_id":"111.222","consent_marketing":"granted","consent_statistics":"granted","outra":"x"}'
check_eq "  só as chaves conhecidas"    'gclid,msclkid,fbc,fbp,ga_client_id,consent_marketing,consent_statistics' wp eval 'echo implode(",", array_keys(get_option("tpuk_resp_wait")["rmain0000000006"][2]));'

echo "# leitura dos formulários e envio ao sGTM"
check_eq "1.ª execução: nada enviado (compra sem cliques espera 45 min)" '0' cron
check_eq "encontrou só a compra paga"   'tf_rpaid0000000001'   queue
check_eq "  valor cobrado (189)"        '189'                  txv tf_rpaid0000000001
check_eq "completed \"true\" (texto) não é compra" 'sem registo' txv tf_rstr00000000004
check_eq "pagamento falhado não é compra" 'sem registo'        txv tf_rfail0000000003
check_eq "resposta sem pagamento não é compra" 'sem registo'   txv tf_rfree0000000002
check_eq "fora da janela (5 h atrás) não é relida" 'sem registo' txv tf_rold00000000005
check_eq "resposta do site ligada ao email" 'GCLID-LEAD'       wp eval 'echo get_transient("tpuk_em_" . md5(hash("sha256", "lead@example.com")))["gclid"] ?? "";'
check_eq "  e sai da lista de espera"   '0'                    wp eval 'echo count(get_option("tpuk_resp_wait", []));'
check_eq "  sGTM ainda sem a compra"    '0'                    sgtm tf_rpaid0000000001
add 'tpuk_add("rlead0000000007", "mockpay01", 120, [pay("249.50"), em(" LEAD@example.com ")]);' >/dev/null
check_eq "quem passou pelo site paga noutro Typeform: enviada logo" '1' cron
check_eq "  sGTM recebeu 1 vez"         '1'                    sgtm tf_rlead0000000007
check_eq "  evento purchase (en=purchase)" '1'                 sgtm tf_rlead0000000007 'en=purchase'
check_eq "  para o stream GA4 (tid)"    '1'                    sgtm tf_rlead0000000007 'tid=G-TEST123'
check_eq "  valor 249.5 GBP"            '1'                    sgtm tf_rlead0000000007 'epn.value=249.5&'
check_eq "  moeda GBP"                  '1'                    sgtm tf_rlead0000000007 'cu=GBP'
check_eq "  produto (formulário e valor)" '1'                  sgtm tf_rlead0000000007 'pr1=idmockpay01~nmMock%20pagamento~pr249.5~qt1'
check_eq "  gclid no page_location (Google Ads)" '1'           sgtm tf_rlead0000000007 'gclid%3DGCLID-LEAD'
check_eq "  msclkid (Microsoft Ads)"    '1'                    sgtm tf_rlead0000000007 'ep.msclkid=MSCLK-LEAD'
check_eq "  fbc (Meta)"                 '1'                    sgtm tf_rlead0000000007 'ep.fbc=fb.1.1700000000000.FBLEAD'
check_eq "  client_id do _ga"           '1'                    sgtm tf_rlead0000000007 'cid=111.222'
check_eq "  email só em hash (em)"      '1'                    sgtm tf_rlead0000000007 "em=tv.1~em.$(printf 'lead@example.com' | shasum -a 256 | cut -d' ' -f1)"
check_eq "  sem email em claro"         '0'                    sgtm tf_rlead0000000007 'example.com'
check_eq "  sem header de Preview (campo vazio)" '0'           bash -c 'docker compose logs echo --no-log-prefix --since "$0" 2>/dev/null | grep -c "x-gtm-server-preview"' "$T0"
check_eq "  consentimento: gcs=G111, npa=0" '1'                sgtm tf_rlead0000000007 'gcs=G111&npa=0'
check_eq "45 min depois: a compra sem cliques é enviada" '1'   cron "$((NOW + 3600))"
check_eq "  sGTM recebeu 1 vez"         '1'                    sgtm tf_rpaid0000000001
check "  registo: marketing unknown, sem cliques" 'marketing unknown · no clicks' wp eval 'echo get_option("tpuk_log")[0]["message"];'
check_eq "  sem visita ao site: sem email em hash" '0'         sgtm tf_rpaid0000000001 'em=tv.1'
check_eq "  consentimento: gcs=G100, npa=1" '1'                sgtm tf_rpaid0000000001 'gcs=G100&npa=1'
check_eq "  client_id estável (sem _ga)" '1'                   sgtm tf_rpaid0000000001 'cid='
check_eq "  page_location sem cliques"  '0'                    sgtm tf_rpaid0000000001 'gclid'
check_eq "execução seguinte: nada repetido" '0'                cron "$((NOW + 3700))"
check_eq "  tf_rlead continua com 1 envio" '1'                 sgtm tf_rlead0000000007
check_eq "  fila vazia"                 ''                     queue

echo "# formulário de pagamento incorporado no site (mesmo id de resposta)"
check_eq "visita com gclid -> ok"       '{"status":"ok"}'      visit mockpay01 rembed000000008 '{"gclid":"GCLID-EMBED","consent_marketing":"granted","consent_statistics":"granted"}'
add 'tpuk_add("rembed000000008", "mockpay01", 30, [pay("59.00")]);' >/dev/null
check_eq "enviada com o gclid da visita" '1'                   cron "$((NOW + 3800))"
check_eq "  sGTM recebeu-a com GCLID-EMBED" '1'                sgtm tf_rembed000000008 'gclid%3DGCLID-EMBED'

echo "# consentimento recusado: a compra é registada, sem identificadores"
check_eq "visita com marketing recusado -> ok" '{"status":"ok"}' visit mockpay01 rdeny000000012 '{"gclid":"GCLID-DENY","fbc":"fb.1.1.FBDENY","ga_client_id":"333.444","consent_marketing":"denied","consent_statistics":"granted"}'
add 'tpuk_add("rdeny000000012", "mockpay01", 30, [pay("99.00"), em("deny@example.com")]);' >/dev/null
check_eq "enviada logo (a visita ao site já chegou)" '1'       cron "$((NOW + 3850))"
check_eq "  sGTM recebeu a compra (99)" '1'                    sgtm tf_rdeny000000012 'epn.value=99&'
check_eq "  sem gclid"                  '0'                    sgtm tf_rdeny000000012 'GCLID-DENY'
check_eq "  sem fbc"                    '0'                    sgtm tf_rdeny000000012 'FBDENY'
check_eq "  sem email em hash"          '0'                    sgtm tf_rdeny000000012 'em=tv.1'
check_eq "  com o _ga (estatísticas aceites)" '1'              sgtm tf_rdeny000000012 'cid=333.444'
check_eq "  consentimento denied"       '1'                    sgtm tf_rdeny000000012 'ep.consent_marketing=denied'
check_eq "  gcs=G101, npa=1"            '1'                    sgtm tf_rdeny000000012 'gcs=G101&npa=1'
check_eq "consentimento inválido -> unknown" 'unknown'         wp eval 'echo Typeform_Purchase_UK::clean_attr(["consent_marketing" => "sim"])["consent_marketing"];'

echo "# Preview do sGTM (testes)"
add '$s = get_option("tpuk_settings"); $s["sgtm_preview"] = "ZW52LTV8dGVzdGU="; update_option("tpuk_settings", $s); tpuk_add("rprev000000013", "mockpay01", 30, [pay("10.00"), em("lead@example.com")]);' >/dev/null
check_eq "compra de teste enviada"      '1'                    cron "$((NOW + 3870))"
check "  com o header X-Gtm-Server-Preview" '"x-gtm-server-preview": "ZW52LTV8dGVzdGU="' bash -c 'docker compose logs echo --no-log-prefix --since "$0" 2>/dev/null' "$T0"
add '$s = get_option("tpuk_settings"); $s["sgtm_preview"] = ""; update_option("tpuk_settings", $s);' >/dev/null

echo "# sGTM em baixo: volta a tentar, sem repetir"
add 'sgtm_url("http://127.0.0.1:9");' >/dev/null
add 'tpuk_add("rdown0000000009", "mockpay01", 30, [pay("75.00"), em("lead@example.com")]);' >/dev/null
check_eq "sGTM em baixo: 0 enviadas"    '0'                    cron "$((NOW + 3900))"
check_eq "  fica na fila"               'tf_rdown0000000009'   queue
check_eq "2.ª tentativa falhada"        '0'                    cron "$((NOW + 4200))"
check_eq "  1 só erro no registo"       '1'                    wp eval 'echo count(array_filter(get_option("tpuk_log"), function ($e) { return strpos($e["message"], "the sGTM did not accept it") === 0; }));'
add 'sgtm_url("http://echo:8080");' >/dev/null
check_eq "sGTM de volta: enviada"       '1'                    cron "$((NOW + 4500))"
check_eq "  sGTM recebeu 1 vez"         '1'                    sgtm tf_rdown0000000009
add 'sgtm_url("http://127.0.0.1:9"); tpuk_add("rlate0000000010", "mockpay01", 30, [pay("80.00"), em("lead@example.com")]);' >/dev/null
check_eq "outra compra com o sGTM em baixo" '0'                cron "$((NOW + 4600))"
add 'sgtm_url("http://echo:8080");' >/dev/null
check_eq "72 h depois: já não é enviada (limite do GA4)" '0'   cron "$((NOW + 72 * 3600))"
check_eq "  sGTM nunca a recebeu"       '0'                    sgtm tf_rlate0000000010
check "  registo explica"               'not sent: the sGTM did not accept it within 71 h' wp eval 'echo implode("|", array_column(get_option("tpuk_log"), "message"));'
check_eq "  fila vazia"                 ''                     queue

echo "# formulário novo: lê desde a revisão anterior da lista"
wp eval '$x = time(); update_option("tpuk_scan", ["last" => $x, "forms_at" => $x - 6 * 3600 - 60, "forms" => ["mockpay01" => "Mock pagamento"]], false);
$f = get_option("tpuk_mock_forms"); $f["mockpay02"] = ["title" => "Mock pagamento novo", "fields" => [["type" => "payment"]]]; update_option("tpuk_mock_forms", $f, false);' >/dev/null
add 'tpuk_add("rnew0000000011", "mockpay02", 5 * 3600, [pay("120.00")]);' >/dev/null
check_eq "formulário novo: compra de há 5 h encontrada" '1'    wp eval 'echo Typeform_Purchase_UK::scan_typeform(time());'
check_eq "  valor 120"                  '120'                  txv tf_rnew0000000011
check_eq "  formulários antigos mantêm a janela de 2 h" 'sem registo' txv tf_rold00000000005
check_eq "nunca lê respostas de formulários sem pagamento" '0' wp eval 'echo count(array_filter(get_option("tpuk_mock_calls", []), function ($c) { return strpos($c, "mockmain01/responses?page_size") !== false; }));'
wp option delete tpuk_queue >/dev/null

echo "# segurança"
check_eq "o plugin só lê a API do Typeform (só GET)" '0'      wp eval 'echo count(array_filter(get_option("tpuk_mock_calls", []), function ($c) { return strpos($c, "GET ") !== 0; }));'
check_eq "registo sem o token"          'limpo'                wp eval 'echo strpos(wp_json_encode(get_option("tpuk_log")), "tf-test-token") === false ? "limpo" : "VISIVEL";'
check_eq "registo sem email nem hash"   'limpo'                wp eval '$l = wp_json_encode(get_option("tpuk_log")); echo (strpos($l, "example.com") === false && strpos($l, hash("sha256", "lead@example.com")) === false) ? "limpo" : "VISIVEL";'
check "front-end recebe o visitUrl"     'typeform-purchase-uk/v1/visit' bash -c 'curl -s http://localhost:8088/teste-typeform/ | grep -o "typeform-purchase-uk[^\"]*visit" | tr -d "\\\\" | head -1'
check_eq "front-end sem dataLayer"      '0'                    bash -c 'grep -cE "window\.dataLayer|dataLayer\.push" plugin/typeform-purchase-uk/assets/tpuk.js'
check_eq "nenhum warning/notice PHP do plugin" '0'             bash -c 'docker compose logs wordpress --no-log-prefix --since "$0" 2>&1 | grep -c "PHP .*typeform-purchase-uk.php"' "$T0"

echo "# front-end (Chrome headless, tests/browser.mjs)"
if [[ -x "${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}" ]] && command -v node >/dev/null; then
  attr() { wp eval "echo wp_json_encode(get_option('tpuk_resp_wait', [])['$1'][2] ?? 'sem visita');"; }
  RID="$(node tests/browser.mjs landing)"
  check "marketing aceite: landing com gclid, msclkid e fbclid -> visita guardada" '"gclid":"GBROWSER"' attr "$RID"
  check "  msclkid"                     '"msclkid":"MSBROWSER"' attr "$RID"
  check "  fbc montado a partir do fbclid" '.FBBROWSER"'       attr "$RID"
  check "  consentimento granted"       '"consent_marketing":"granted"' attr "$RID"
  RID="$(node tests/browser.mjs navega-antes)"
  check "gclid guardado ao mudar de página" '"gclid":"GNAV"'   attr "$RID"
  RID="$(node tests/browser.mjs aceita-depois)"
  check "aceita o banner depois de chegar: gclid guardado" '"gclid":"GLATE"' attr "$RID"
  RID="$(node tests/browser.mjs recusou)"
  check_eq "marketing recusado: sem cliques, com o consentimento" '{"consent_marketing":"denied","consent_statistics":"denied"}' attr "$RID"
  check_eq "  nenhum cookie de cliques guardado" 'sem cookie'  node tests/browser.mjs recusou-cookie
  RID="$(node tests/browser.mjs sem-banner)"
  check_eq "banner por responder: unknown, sem cliques" '{"consent_marketing":"unknown","consent_statistics":"unknown"}' attr "$RID"
else
  echo "skip front-end: falta o Google Chrome ou o node"
fi

echo
echo "resultado: $PASS ok, $FAIL falharam"
[[ $FAIL -eq 0 ]]

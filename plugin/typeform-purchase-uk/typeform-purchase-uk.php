<?php
/**
 * Plugin Name: Typeform Purchase UK
 * Description: Finds paid purchases in the Typeforms (Typeform API) and sends them to the sGTM as a GA4 purchase event, in the same format as the website, for the tags that already exist (GA4, Google Ads, Meta), with the site visit's clicks matched by email and the Complianz consent.
 * Version:     0.5.1
 * Author:      Arménio Ganga - Dronline
 * Author URI:  https://dronline.uk
 * Plugin URI:  https://dronline.uk
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Typeform_Purchase_UK
{
    const VERSION      = '0.5.1'; // manter igual ao cabeçalho "Version" (tests/run-all.sh verifica)
    const OPT          = 'tpuk_settings';
    const OPT_LOG      = 'tpuk_log';
    const OPT_SCAN     = 'tpuk_scan';      // leitura periódica: última janela e formulários com pagamento
    const OPT_QUEUE    = 'tpuk_queue';     // compras registadas e ainda não aceites pelo sGTM: transaction_id => desde
    const OPT_WAIT     = 'tpuk_resp_wait'; // respostas submetidas no site, à espera da API: response_id => [form_id, desde, atribuição]
    const LOG_MAX      = 50;
    const TX_PREFIX    = 'tpuk_tx_';       // compra registada: ['p' => compra, 'sent' => 0|1]
    const EMAIL_PREFIX = 'tpuk_em_';       // atribuição da visita ao site, por email (hash)
    const RESP_PREFIX  = 'tpuk_resp_';     // resposta do site lida há pouco na API (não voltar a pedir logo)
    const TX_TTL       = 30 * DAY_IN_SECONDS;
    const ATTR_TTL     = 30 * DAY_IN_SECONDS;
    const HOLD         = 45 * MINUTE_IN_SECONDS; // compra sem atribuição espera pela resposta do site (a API pode demorar)
    const SEND_MAX     = 71 * HOUR_IN_SECONDS;   // o GA4 só aceita eventos até 72 h para trás
    const WAIT_MAX     = 2 * HOUR_IN_SECONDS;
    const WAIT_CAP     = 500;
    const API_PER_MIN  = 60;               // pedidos por minuto à API a partir das visitas do site
    const SCAN_OVERLAP = 2 * HOUR_IN_SECONDS; // a API pode devolver uma resposta até ~30 min depois: relê as últimas 2 h
    const FORMS_EVERY  = 6 * HOUR_IN_SECONDS; // revê que formulários têm pagamento (formulários novos)
    const REST_NS      = 'typeform-purchase-uk/v1';
    const CRON_HOOK    = 'tpuk_typeform_scan';

    // Atribuição da visita: cliques do Google Ads (gclid/gbraid/wbraid), Microsoft Ads (msclkid), Meta (fbc/fbp),
    // GA4 (_ga), UTMs e a escolha da pessoa no banner do Complianz (granted | denied | unknown).
    const ATTR_KEYS = [
        'gclid', 'gbraid', 'wbraid', 'msclkid', 'fbc', 'fbp', 'ga_client_id', 'ga_session_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'consent_marketing', 'consent_statistics',
    ];
    const MARKETING_KEYS  = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'fbc', 'fbp']; // só com o marketing aceite
    const STATISTICS_KEYS = ['ga_client_id', 'ga_session_id'];                     // só com as estatísticas aceites

    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'action_links']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_tpuk_check_token', [__CLASS__, 'handle_check_token']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend']);
        add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
        add_action(self::CRON_HOOK, [__CLASS__, 'run_cron']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('init', [__CLASS__, 'activate']); // repõe o cron se tiver sido apagado
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    public static function defaults()
    {
        return [
            'typeform_token'     => '',     // token pessoal da API do Typeform (só leitura); nunca é mostrado
            'sgtm_url'           => '',     // ex.: https://sgtm.dronline.uk (o client GA4 que o site já usa)
            'ga4_measurement_id' => '',     // stream GA4 do UK, vai em cada pedido ao sGTM
            'sgtm_preview'       => '',     // só para testes: valor do header X-Gtm-Server-Preview do Preview do sGTM
            'currency'           => 'GBP',
        ];
    }

    public static function settings()
    {
        return wp_parse_args((array) get_option(self::OPT, []), self::defaults());
    }

    /* ------------------------------------------------------------------ */
    /* REST: visita ao site                                                */
    /* ------------------------------------------------------------------ */

    public static function register_routes()
    {
        $id = function ($min) {
            return function ($v) use ($min) { return is_string($v) && preg_match('/^[A-Za-z0-9]{' . $min . ',64}$/', $v); };
        };
        // Um Typeform incorporado no site foi submetido: guarda os cliques desta visita para essa resposta.
        register_rest_route(self::REST_NS, '/visit', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_visit'],
            'permission_callback' => '__return_true',
            'args'                => [
                'form_id'     => ['required' => true, 'validate_callback' => $id(4)],
                'response_id' => ['required' => true, 'validate_callback' => $id(8)],
                'attr'        => ['required' => false, 'default' => [], 'sanitize_callback' => [__CLASS__, 'clean_attr']],
            ],
        ]);
    }

    /** Atribuição enviada pelo browser: só as chaves conhecidas, texto curto. */
    public static function clean_attr($attr)
    {
        $out = [];
        foreach (self::ATTR_KEYS as $k) {
            if (isset($attr[$k]) && is_scalar($attr[$k]) && (string) $attr[$k] !== '' && (string) $attr[$k] !== 'xxxxx') {
                $out[$k] = substr(sanitize_text_field((string) $attr[$k]), 0, 250);
            }
        }
        foreach (['consent_marketing', 'consent_statistics'] as $k) {
            if (isset($out[$k]) && !in_array($out[$k], ['granted', 'denied', 'unknown'], true)) {
                $out[$k] = 'unknown';
            }
        }
        return $out;
    }

    public static function handle_visit(WP_REST_Request $request)
    {
        $attr = (array) $request['attr'];
        if (!$attr) {
            return self::no_store(['status' => 'ignored']);
        }
        $rid = (string) $request['response_id'];
        self::wait_add($rid, (string) $request['form_id'], $attr);
        self::add_attribution('tf_' . $rid, $attr); // o formulário de pagamento estava no site: a compra já pode estar registada
        return self::no_store(['status' => 'ok']);
    }

    private static function no_store(array $body)
    {
        $res = new WP_REST_Response($body, 200);
        $res->header('Cache-Control', 'no-store');
        return $res;
    }

    /* ------------------------------------------------------------------ */
    /* Cron: respostas do site, leitura dos formulários, envio ao sGTM     */
    /* ------------------------------------------------------------------ */

    /**
     * De 5 em 5 minutos. A ordem conta: primeiro liga a atribuição do site aos emails, depois procura compras novas,
     * por fim envia ao sGTM as que estão prontas.
     * @param int|null $now para testes
     * @return int compras enviadas ao sGTM
     */
    public static function run_cron($now = null)
    {
        $now = $now ?: time();
        if (!self::lock('cron', 0)) {
            return 0; // o WP-Cron pode disparar duas execuções em paralelo
        }
        try {
            self::check_waiting($now);
            self::scan_typeform($now);
            return self::send_due($now);
        } finally {
            self::unlock('cron');
        }
    }

    /** Lê na API as respostas submetidas no site e guarda a atribuição pelo email (hash) de cada uma. */
    public static function check_waiting($now)
    {
        $s = self::settings();
        if ($s['typeform_token'] === '') {
            return;
        }
        $drop = [];
        foreach (self::fresh_option(self::OPT_WAIT) as $rid => $w) {
            list($form_id, $since, $attr) = (array) $w + ['', 0, []];
            $rid = (string) $rid;
            if ($now - (int) $since > self::WAIT_MAX) {
                $drop[] = $rid;
                continue;
            }
            if (get_transient(self::RESP_PREFIX . md5($rid))) {
                continue;
            }
            set_transient(self::RESP_PREFIX . md5($rid), 1, 4 * MINUTE_IN_SECONDS);
            $fr = self::fetch_response((string) $form_id, $rid, $s);
            if (!is_array($fr)) {
                continue; // a API ainda não a devolve: tenta na próxima execução
            }
            $email = self::email_hash($fr);
            if ($email !== '') {
                set_transient(self::EMAIL_PREFIX . md5($email), (array) $attr, self::ATTR_TTL);
            }
            self::record_from_fr($fr, $s, (array) $attr); // se esta resposta já for o pagamento
            $drop[] = $rid;
        }
        if ($drop) {
            self::update_locked(self::OPT_WAIT, function ($wait) use ($drop) {
                return array_diff_key($wait, array_flip($drop));
            });
        }
    }

    /**
     * Lê as respostas recentes de todos os formulários com pagamento e regista as compras novas.
     * @return int compras novas registadas
     */
    public static function scan_typeform($now = null)
    {
        $s   = self::settings();
        $now = $now ?: time();
        if ($s['typeform_token'] === '') {
            return 0;
        }
        $state  = (array) get_option(self::OPT_SCAN, []);
        $last   = (int) ($state['last'] ?? $now);
        $known  = array_keys((array) ($state['forms'] ?? []));
        $listed = (int) ($state['forms_at'] ?? 0);
        $forms  = self::payment_forms($s, $state, $now);
        $found  = 0;
        $errors = 0;
        foreach ($forms as $id => $title) {
            $since = $last - self::SCAN_OVERLAP;
            if ($listed && !in_array((string) $id, $known, true)) {
                $since = min($since, $listed - self::SCAN_OVERLAP); // formulário novo: lê desde a revisão anterior da lista
            }
            $before = '';
            $pages  = 0;
            do {
                $q = '/forms/' . rawurlencode($id) . '/responses?page_size=200&response_type=completed&since=' . gmdate('Y-m-d\TH:i:s', $since) . ($before !== '' ? '&before=' . rawurlencode($before) : '');
                $r = self::typeform_api($q, $s);
                if (!$r['ok']) {
                    self::api_error($id, $r);
                    $errors++;
                    break;
                }
                $items = (array) ($r['json']['items'] ?? []);
                foreach ($items as $item) {
                    $rid = (string) ($item['response_id'] ?? $item['token'] ?? '');
                    if (preg_match('/^[A-Za-z0-9]{8,64}$/', $rid) && self::record_from_fr(self::api_item_to_fr($id, $rid, (array) $item, $title), $s)) {
                        $found++;
                    }
                }
                $tail   = end($items);
                $before = count($items) === 200 && is_array($tail) ? (string) ($tail['token'] ?? '') : '';
            } while ($before !== '' && ++$pages < 10);
        }
        $state['last']   = $errors && $forms ? $last : $now; // com erros, relê a mesma janela na próxima vez
        $state['time']   = current_time('mysql');
        $state['found']  = $found;
        $state['errors'] = $errors;
        update_option(self::OPT_SCAN, $state, false);
        return $found;
    }

    /** Formulários com bloco de pagamento (id => título), revistos de 6 em 6 horas. */
    private static function payment_forms(array $s, array &$state, $now)
    {
        if (isset($state['forms']) && $now - (int) ($state['forms_at'] ?? 0) < self::FORMS_EVERY) {
            return (array) $state['forms'];
        }
        $forms = [];
        $page  = 1;
        do {
            $r = self::typeform_api('/forms?page_size=200&page=' . $page, $s);
            if (!$r['ok']) {
                self::api_error('form list', $r);
                return (array) ($state['forms'] ?? []);
            }
            foreach ((array) ($r['json']['items'] ?? []) as $f) {
                $id = (string) ($f['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $d = self::typeform_api('/forms/' . rawurlencode($id), $s);
                if ($d['ok'] && self::has_payment((array) ($d['json']['fields'] ?? []))) {
                    $forms[$id] = (string) ($d['json']['title'] ?? $id);
                }
            }
        } while (++$page <= (int) ($r['json']['page_count'] ?? 1) && $page <= 20);
        $state['forms']    = $forms;
        $state['forms_at'] = $now;
        return $forms;
    }

    private static function has_payment(array $fields)
    {
        foreach ($fields as $f) {
            if (($f['type'] ?? '') === 'payment' || self::has_payment((array) ($f['properties']['fields'] ?? []))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Regista a compra (se a resposta tem o pagamento confirmado e ainda não está registada) e põe-na na fila do sGTM.
     * @return bool true se registou uma compra nova
     */
    private static function record_from_fr(array $fr, array $s, array $attr = [])
    {
        $tx  = 'tf_' . $fr['token'];
        $key = self::TX_PREFIX . md5($tx);
        if (self::fresh_transient($key)) {
            return false;
        }
        $purchase = self::build_purchase($fr, $s);
        if ($purchase === null) {
            return false;
        }
        $wait = self::fresh_option(self::OPT_WAIT);
        $purchase['attr'] += $attr + (array) ($wait[$fr['token']][2] ?? []); // o mesmo id de resposta submetido no site
        $lock = 'tx_' . md5($tx);
        if (!self::lock($lock, 10)) {
            return false;
        }
        try {
            if (self::fresh_transient($key)) {
                return false; // registada entretanto por outra execução
            }
            set_transient($key, ['p' => $purchase, 'sent' => 0], self::TX_TTL);
        } finally {
            self::unlock($lock);
        }
        self::update_locked(self::OPT_QUEUE, function ($q) use ($tx) {
            $q[$tx] = time();
            return $q;
        });
        self::log('found', 'paid purchase found in Typeform', self::log_data($purchase));
        return true;
    }

    /** Junta atribuição a uma compra ainda não enviada (sem substituir o que já tem). */
    private static function add_attribution($tx, array $attr)
    {
        $key = self::TX_PREFIX . md5($tx);
        if (!self::fresh_transient($key)) {
            return;
        }
        $lock = 'tx_' . md5($tx);
        if (!self::lock($lock, 5)) {
            return;
        }
        $record = self::fresh_transient($key);
        if ($record && !$record['sent']) {
            $record['p']['attr'] = (array) $record['p']['attr'] + $attr;
            set_transient($key, $record, self::TX_TTL);
        }
        self::unlock($lock);
    }

    /**
     * Envia ao sGTM as compras da fila. Uma compra sem clique nem _ga espera até 45 min depois de submetida, para
     * dar tempo a que a resposta do site (com a atribuição) seja lida; o que falhar volta a ser tentado até 71 h.
     * @return int compras aceites pelo sGTM
     */
    public static function send_due($now)
    {
        $s = self::settings();
        if ($s['sgtm_url'] === '' || $s['ga4_measurement_id'] === '') {
            return 0;
        }
        $sent = 0;
        $drop = [];
        foreach (self::fresh_option(self::OPT_QUEUE) as $tx => $since) {
            $tx   = (string) $tx;
            $lock = 'tx_' . md5($tx);
            if (!self::lock($lock, 0)) {
                continue;
            }
            $key    = self::TX_PREFIX . md5($tx);
            $record = self::fresh_transient($key);
            if (!$record || $record['sent']) {
                $drop[] = $tx;
                self::unlock($lock);
                continue;
            }
            $p = $record['p'];
            if ($p['email_sha256'] !== '') {
                $p['attr'] += (array) get_transient(self::EMAIL_PREFIX . md5($p['email_sha256'])); // visita ao site com o mesmo email
            }
            $age = $now - (strtotime($p['submitted_at']) ?: (int) $since);
            if ($age > self::SEND_MAX) {
                $drop[] = $tx;
                self::unlock($lock);
                self::log('error', 'not sent: the sGTM did not accept it within 71 h', self::log_data($p));
                continue;
            }
            if (!isset($p['attr']['consent_marketing']) && $age < self::HOLD) {
                self::unlock($lock);
                continue; // ainda pode chegar a visita ao site (cliques e consentimento)
            }
            $hit = self::ga4_hit($p, $s);
            $r   = self::send_sgtm($hit, $s);
            if ($r['ok']) {
                $record['p']    = $p;
                $record['sent'] = 1;
                set_transient($key, $record, self::TX_TTL);
                $drop[] = $tx;
                $sent++;
                $clicks = array_values(array_filter(['gclid', 'gbraid', 'wbraid', 'msclkid', 'fbc'], function ($k) use ($hit) {
                    return isset($hit['ep.' . $k]) || strpos($hit['dl'], $k . '=') !== false;
                }));
                self::log('sent', 'sent to sGTM · marketing ' . $hit['ep.consent_marketing'] . ($clicks ? ' · with ' . implode(', ', $clicks) : ' · no clicks'), self::log_data($p) + ['sgtm' => $r]);
            } elseif (empty($record['failed'])) {
                $record['failed'] = 1; // regista a 1.ª falha; as repetições não enchem o registo
                set_transient($key, $record, self::TX_TTL);
                self::log('error', 'the sGTM did not accept it (retrying every 5 min)', self::log_data($p) + ['sgtm' => $r]);
            }
            self::unlock($lock);
        }
        if ($drop) {
            self::update_locked(self::OPT_QUEUE, function ($q) use ($drop) {
                return array_diff_key($q, array_flip($drop));
            });
        }
        return $sent;
    }

    /**
     * Evento purchase no mesmo formato que o GTM do site usa para enviar o GA4 ao sGTM (/g/collect): o client GA4
     * que já existe no sGTM recebe-o e as tags que já existem tratam dele (GA4, Google Ads, Meta), sem mudar nada
     * no sGTM.
     * Consentimento (Complianz, opt-in): sem o marketing aceite não vão cliques nem o email; sem as estatísticas
     * aceites não vai o _ga. Sem registo da visita ao site conta como não aceite. O estado vai sempre em gcs
     * (G1 + ad_storage + analytics_storage, como os pedidos do site), npa e ep.consent_*, para as tags decidirem.
     */
    public static function ga4_hit(array $p, array $s)
    {
        $a  = (array) $p['attr'];
        $mk = ($a['consent_marketing'] ?? '') === 'granted';
        $st = ($a['consent_statistics'] ?? '') === 'granted';
        if (!$mk) {
            $a = array_diff_key($a, array_flip(self::MARKETING_KEYS));
        }
        if (!$st) {
            $a = array_diff_key($a, array_flip(self::STATISTICS_KEYS));
        }
        // page_location com os cliques do Google Ads e as UTMs, como a página de onde a pessoa veio.
        $dl = add_query_arg(array_map('rawurlencode', array_intersect_key($a, array_flip(['gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']))), home_url('/'));
        $hit = [
            'v'                     => '2',
            'tid'                   => $s['ga4_measurement_id'],
            '_p'                    => (string) wp_rand(100000000, 2147483647),
            'cid'                   => $a['ga_client_id'] ?? self::fallback_client_id($p['transaction_id']),
            'sid'                   => $a['ga_session_id'] ?? (string) (strtotime($p['submitted_at']) ?: time()),
            'sct'                   => '1',
            'seg'                   => '1',
            'ul'                    => 'en-gb',
            'dl'                    => $dl,
            'dt'                    => 'Typeform: ' . $p['form_title'],
            'en'                    => 'purchase',
            'gcs'                   => 'G1' . ($mk ? '1' : '0') . ($st ? '1' : '0'),
            'npa'                   => $mk ? '0' : '1',
            'cu'                    => $p['currency'],
            'pr1'                   => 'id' . self::pr_value($p['form_id']) . '~nm' . self::pr_value($p['form_title']) . '~pr' . $p['value'] . '~qt1',
            'ep.transaction_id'     => $p['transaction_id'],
            'epn.value'             => (string) $p['value'],
            'ep.form_id'            => $p['form_id'],
            'ep.form_title'         => $p['form_title'],
            'ep.source'             => 'typeform',
            'ep.plugin_version'     => self::VERSION,
            'ep.consent_marketing'  => $mk ? 'granted' : (($a['consent_marketing'] ?? '') === 'denied' ? 'denied' : 'unknown'),
            'ep.consent_statistics' => $st ? 'granted' : (($a['consent_statistics'] ?? '') === 'denied' ? 'denied' : 'unknown'),
        ];
        foreach (['msclkid', 'fbc', 'fbp'] as $k) {
            if (!empty($a[$k])) {
                $hit['ep.' . $k] = $a[$k];
            }
        }
        if ($mk && $p['email_sha256'] !== '') {
            $hit['em'] = 'tv.1~em.' . $p['email_sha256']; // email em hash (dados fornecidos pelo utilizador)
        }
        return $hit;
    }

    /** Valor de um campo de produto (pr1): sem os separadores "~". */
    private static function pr_value($v)
    {
        return str_replace('~', ' ', (string) $v);
    }

    public static function send_sgtm(array $hit, array $s)
    {
        $url     = untrailingslashit($s['sgtm_url']) . '/g/collect?' . http_build_query($hit, '', '&', PHP_QUERY_RFC3986);
        $headers = ['X-TPUK-Version' => self::VERSION, 'User-Agent' => 'Mozilla/5.0 (compatible; TypeformPurchaseUK/' . self::VERSION . '; +https://dronline.uk)'];
        if ($s['sgtm_preview'] !== '') {
            $headers['X-Gtm-Server-Preview'] = $s['sgtm_preview']; // o pedido aparece no Preview em vez de ir para o contentor publicado
        }
        $res = wp_remote_post($url, ['timeout' => 10, 'headers' => $headers, 'body' => '']);
        if (is_wp_error($res)) {
            return ['ok' => false, 'error' => $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        return ['ok' => $code >= 200 && $code < 300, 'code' => $code];
    }

    /** client_id estável no formato GA4 quando a compra não tem o _ga da visita. */
    private static function fallback_client_id($seed)
    {
        return sprintf('%u.%u', crc32($seed), crc32(strrev($seed)));
    }

    /* ------------------------------------------------------------------ */
    /* Regra de compra                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Só é compra uma resposta com o bloco de pagamento confirmado: "completed" na API de respostas
     * ("success" no webhook), sempre o booleano true. O valor é o montante cobrado.
     */
    public static function build_purchase(array $fr, array $s)
    {
        $value = null;
        foreach ((array) ($fr['answers'] ?? []) as $a) {
            $pay = (array) ($a['payment'] ?? []);
            if (($a['type'] ?? '') === 'payment' && (($pay['completed'] ?? null) === true || ($pay['success'] ?? null) === true)) {
                $value = self::parse_amount($pay['amount'] ?? null);
            }
        }
        if ($value === null || $value <= 0) {
            return null;
        }
        return [
            'transaction_id' => 'tf_' . $fr['token'],
            'form_id'        => (string) ($fr['form_id'] ?? ''),
            'form_title'     => (string) ($fr['definition']['title'] ?? $fr['form_id'] ?? ''),
            'value'          => round($value, 2),
            'currency'       => $s['currency'],
            'submitted_at'   => (string) ($fr['submitted_at'] ?? gmdate('c')),
            'email_sha256'   => self::email_hash($fr),
            'attr'           => self::clean_attr((array) ($fr['hidden'] ?? [])), // hidden fields, se o formulário os tiver
        ];
    }

    /** sha256 do primeiro email da resposta, normalizado (minúsculas, sem espaços). */
    private static function email_hash(array $fr)
    {
        foreach ((array) ($fr['answers'] ?? []) as $a) {
            if (($a['type'] ?? '') === 'email' && !empty($a['email'])) {
                return hash('sha256', strtolower(trim((string) $a['email'])));
            }
        }
        return '';
    }

    /**
     * Montante como número: "208.00", "208,50", "1,234.50", "1.234,50", "£1 234" ou 99.5.
     * Com "," e "." o último é o separador decimal; só com "," é decimal, exceto em grupos de 3 ("1,234").
     * @return float|null null se não for um montante
     */
    public static function parse_amount($raw)
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        if (!is_string($raw)) {
            return null;
        }
        $n = preg_replace('/[^0-9.,]/', '', $raw);
        if (!preg_match('/[0-9]/', $n)) {
            return null;
        }
        $dec = strrpos($n, ',') > strrpos($n, '.') ? ',' : '.'; // strrpos devolve false (0) se não houver
        if (strpos($n, '.') === false && preg_match('/^\d{1,3}(,\d{3})+$/', $n)) {
            $dec = '.'; // "1,234": milhares
        } elseif (strpos($n, ',') === false && preg_match('/^\d{1,3}(\.\d{3}){2,}$/', $n)) {
            $dec = ','; // "1.234.567": milhares
        }
        $thousands = $dec === '.' ? ',' : '.';
        $n = str_replace($thousands, '', $n);
        $n = str_replace(',', '.', $n);
        return is_numeric($n) ? (float) $n : null;
    }

    /* ------------------------------------------------------------------ */
    /* API do Typeform (só leitura)                                        */
    /* ------------------------------------------------------------------ */

    /** A resposta no formato do form_response (para o build_purchase). null se a API ainda não a devolve. */
    public static function fetch_response($form_id, $rid, array $s)
    {
        if (!self::api_budget()) {
            return null;
        }
        $r = self::typeform_api('/forms/' . rawurlencode($form_id) . '/responses?included_response_ids=' . rawurlencode($rid) . '&page_size=1', $s);
        if (!$r['ok']) {
            self::api_error($form_id, $r);
            return null;
        }
        foreach ((array) ($r['json']['items'] ?? []) as $item) {
            if (in_array($rid, [(string) ($item['response_id'] ?? ''), (string) ($item['token'] ?? '')], true)) {
                return self::api_item_to_fr($form_id, $rid, (array) $item, self::form_title($form_id, $s));
            }
        }
        return null;
    }

    private static function api_item_to_fr($form_id, $rid, array $item, $title)
    {
        return [
            'form_id'      => $form_id,
            'token'        => $rid,
            'submitted_at' => (string) ($item['submitted_at'] ?? ''),
            'hidden'       => (array) ($item['hidden'] ?? []),
            'answers'      => (array) ($item['answers'] ?? []),
            'definition'   => ['title' => $title],
        ];
    }

    /** Título do formulário (para o item_name). */
    private static function form_title($form_id, array $s)
    {
        $forms = (array) (get_option(self::OPT_SCAN, [])['forms'] ?? []);
        if (isset($forms[$form_id])) {
            return $forms[$form_id];
        }
        $key   = 'tpuk_form_' . md5($form_id);
        $title = get_transient($key);
        if ($title === false) {
            $r     = self::typeform_api('/forms/' . rawurlencode($form_id), $s);
            $title = $r['ok'] ? (string) ($r['json']['title'] ?? '') : '';
            if ($title !== '') {
                set_transient($key, $title, DAY_IN_SECONDS);
            }
        }
        return $title !== '' ? $title : $form_id;
    }

    /** GET à API do Typeform. O plugin nunca altera formulários. */
    private static function typeform_api($path, array $s)
    {
        $base = apply_filters('tpuk_typeform_api', 'https://api.typeform.com');
        $res  = wp_remote_get($base . $path, [
            'timeout' => 15,
            'headers' => ['Authorization' => 'Bearer ' . $s['typeform_token']],
        ]);
        if (is_wp_error($res)) {
            return ['ok' => false, 'code' => 0, 'error' => $res->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        return ['ok' => $code === 200, 'code' => $code, 'json' => json_decode(wp_remote_retrieve_body($res), true)];
    }

    /** Limite de pedidos à API por minuto (ids inventados enviados ao /visit não esgotam a quota do Typeform). */
    private static function api_budget()
    {
        $key = 'tpuk_api_' . (int) floor(time() / 60);
        $n   = (int) get_transient($key);
        if ($n >= self::API_PER_MIN) {
            return false;
        }
        set_transient($key, $n + 1, 2 * MINUTE_IN_SECONDS);
        return true;
    }

    /** Regista um erro da API uma vez por formulário e código, por hora. */
    private static function api_error($form_id, array $r)
    {
        $key = 'tpuk_apierr_' . md5($form_id . '|' . $r['code']);
        if (get_transient($key)) {
            return;
        }
        set_transient($key, 1, HOUR_IN_SECONDS);
        $why = [401 => 'invalid token, or no access to this form\'s workspace', 403 => 'the token has no access to this form', 404 => 'form not found', 429 => 'API rate limit'][$r['code']] ?? ($r['error'] ?? '');
        self::log('error', 'Typeform API: HTTP ' . $r['code'] . ($why !== '' ? ' (' . $why . ')' : ''), ['form_id' => $form_id]);
    }

    /** Resposta submetida no site, à espera de ser lida na API. */
    private static function wait_add($rid, $form_id, array $attr)
    {
        self::update_locked(self::OPT_WAIT, function ($wait) use ($rid, $form_id, $attr) {
            if (!isset($wait[$rid]) && count($wait) < self::WAIT_CAP) {
                $wait[$rid] = [$form_id, time(), $attr];
            }
            return $wait;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Cron e concorrência                                                 */
    /* ------------------------------------------------------------------ */

    public static function cron_schedules($schedules)
    {
        $schedules['tpuk_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (Typeform Purchase UK)'];
        return $schedules;
    }

    public static function activate()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'tpuk_5min', self::CRON_HOOK);
        }
        if (wp_next_scheduled('tpuk_server_fallback')) {
            wp_clear_scheduled_hook('tpuk_server_fallback'); // cron das versões <= 0.4.0
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /** Nome do lock no MySQL (até 64 caracteres, único por site). */
    public static function lock_name($name)
    {
        global $wpdb;
        return 'tpuk_' . md5(DB_NAME . '|' . $wpdb->prefix . '|' . $name);
    }

    /**
     * Lock partilhado entre pedidos (GET_LOCK do MySQL/MariaDB), libertado com unlock() ou no fim da ligação.
     * @return bool false se outro pedido o tem há mais de $timeout s; true se o obteve ou se a BD não suporta locks
     */
    public static function lock($name, $timeout = 10)
    {
        global $wpdb;
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name($name), $timeout));
        return $got === null || (int) $got === 1;
    }

    public static function unlock($name)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($name)));
    }

    /** Lê o transient da BD, não da cache deste pedido (outro pedido pode tê-lo alterado). */
    private static function fresh_transient($key)
    {
        if (!wp_using_ext_object_cache()) {
            wp_cache_delete('_transient_' . $key, 'options');
            wp_cache_delete('_transient_timeout_' . $key, 'options');
        }
        return get_transient($key);
    }

    /** Opção lida da BD, não da cache deste pedido (filas partilhadas entre pedidos). */
    private static function fresh_option($name)
    {
        wp_cache_delete($name, 'options');
        $notoptions = wp_cache_get('notoptions', 'options');
        if (is_array($notoptions) && isset($notoptions[$name])) {
            unset($notoptions[$name]);
            wp_cache_set('notoptions', $notoptions, 'options');
        }
        return (array) get_option($name, []);
    }

    /** Altera uma fila com lock, sobre a versão atual da BD: nunca escreve uma cópia antiga. */
    private static function update_locked($name, callable $change)
    {
        self::lock($name);
        $before = self::fresh_option($name);
        $after  = $change($before);
        if ($after !== $before) {
            update_option($name, $after, false);
        }
        self::unlock($name);
    }

    /* ------------------------------------------------------------------ */
    /* Registo                                                             */
    /* ------------------------------------------------------------------ */

    public static function log($status, $message, array $data = [])
    {
        $log = get_option(self::OPT_LOG, []);
        array_unshift($log, ['time' => current_time('mysql'), 'version' => self::VERSION, 'status' => $status, 'message' => $message, 'data' => $data]);
        update_option(self::OPT_LOG, array_slice($log, 0, self::LOG_MAX), false);
    }

    /** O que vai para o registo: sem email (nem o hash) e só os nomes da atribuição. */
    private static function log_data(array $p)
    {
        return [
            'transaction_id' => $p['transaction_id'],
            'form_id'        => $p['form_id'],
            'form_title'     => $p['form_title'],
            'value'          => $p['value'],
            'currency'       => $p['currency'],
            'submitted_at'   => $p['submitted_at'],
            'attribution'    => array_keys(array_diff_key((array) $p['attr'], ['consent_marketing' => 1, 'consent_statistics' => 1])),
            'consent'        => ['marketing' => $p['attr']['consent_marketing'] ?? 'no record', 'statistics' => $p['attr']['consent_statistics'] ?? 'no record'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Front-end                                                           */
    /* ------------------------------------------------------------------ */

    public static function enqueue_frontend()
    {
        wp_enqueue_script('tpuk', plugins_url('assets/tpuk.js', __FILE__), [], self::VERSION, true);
        wp_localize_script('tpuk', 'TPUK', [
            'version'       => self::VERSION,
            'measurementId' => self::settings()['ga4_measurement_id'],
            'visitUrl'      => rest_url(self::REST_NS . '/visit'),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Admin                                                               */
    /* ------------------------------------------------------------------ */

    public static function admin_menu()
    {
        add_menu_page(
            'Typeform Purchase UK',
            'Typeform UK',
            'manage_options',
            'tpuk',
            [__CLASS__, 'render_admin'],
            plugins_url('assets/dronline-icon-40.png', __FILE__),
            81
        );
    }

    public static function action_links($links)
    {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=tpuk')) . '">Settings</a>');
        return $links;
    }

    public static function register_settings()
    {
        register_setting('tpuk', self::OPT, ['sanitize_callback' => [__CLASS__, 'sanitize']]);
    }

    public static function sanitize($in)
    {
        $old = self::settings();
        $tok = preg_replace('/\s+/', '', (string) ($in['typeform_token'] ?? ''));
        $mid = strtoupper(trim((string) ($in['ga4_measurement_id'] ?? '')));
        $cur = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($in['currency'] ?? '')));
        return [
            // Campo vazio = manter o token guardado (nunca é enviado de volta para o formulário).
            'typeform_token'     => !empty($in['typeform_token_clear']) ? '' : ($tok !== '' ? $tok : $old['typeform_token']),
            'sgtm_url'           => untrailingslashit(esc_url_raw(trim((string) ($in['sgtm_url'] ?? '')))),
            'ga4_measurement_id' => preg_match('/^G-[A-Z0-9]+$/', $mid) ? $mid : '',
            'sgtm_preview'       => preg_replace('/[^A-Za-z0-9+\/=_-]/', '', (string) ($in['sgtm_preview'] ?? '')),
            'currency'           => strlen($cur) === 3 ? $cur : 'GBP',
        ];
    }

    /** Botão "Testar o token": uma leitura na API do Typeform, sem mostrar o token. */
    public static function handle_check_token()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('tpuk_check_token');
        set_transient('tpuk_token_check_' . get_current_user_id(), self::check_token(), MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=tpuk'));
        exit;
    }

    public static function check_token()
    {
        $s = self::settings();
        if ($s['typeform_token'] === '') {
            return 'No token saved.';
        }
        $r = self::typeform_api('/forms?page_size=1', $s);
        if ($r['ok']) {
            return 'Token OK: it can access ' . (int) ($r['json']['total_items'] ?? 0) . ' forms.';
        }
        return 'The token did not work (HTTP ' . $r['code'] . ($r['code'] === 401 || $r['code'] === 403 ? ': invalid, or missing the forms:read permission' : '') . ').';
    }

    public static function render_admin()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s     = self::settings();
        $o     = self::OPT;
        $log   = get_option(self::OPT_LOG, []);
        $scan  = (array) get_option(self::OPT_SCAN, []);
        $queue = count(self::fresh_option(self::OPT_QUEUE));
        $check = get_transient('tpuk_token_check_' . get_current_user_id());
        if ($check) {
            delete_transient('tpuk_token_check_' . get_current_user_id());
        }
        $text = function ($key, $label, $type, $help, $placeholder = '') use ($s, $o) {
            printf(
                '<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" class="regular-text" id="%1$s" name="%4$s[%1$s]" value="%5$s" placeholder="%6$s"><p class="description">%7$s</p></td></tr>',
                esc_attr($key), esc_html($label), esc_attr($type), esc_attr($o), esc_attr($s[$key]), esc_attr($placeholder), wp_kses_post($help)
            );
        };
        ?>
        <style>
            #adminmenu .toplevel_page_tpuk .wp-menu-image img { width: 20px; height: 20px; padding-top: 7px; opacity: 1; }
            .tpuk-head { display: flex; align-items: center; gap: 12px; margin: 12px 0 4px; }
            .tpuk-head img { width: 40px; height: 40px; }
            .tpuk-head h1 { padding: 0; margin: 0; }
            .tpuk-version { background: #1d1747; color: #fff; border-radius: 10px; padding: 2px 8px; font-size: 12px; font-weight: 600; }
            .tpuk-credit { color: #646970; margin: 0 0 16px 52px; }
        </style>
        <div class="wrap">
            <div class="tpuk-head">
                <img src="<?php echo esc_url(plugins_url('assets/dronline-icon.png', __FILE__)); ?>" alt="Dronline">
                <h1>Typeform Purchase UK</h1>
                <span class="tpuk-version">v<?php echo esc_html(self::VERSION); ?></span>
            </div>
            <p class="tpuk-credit">Developed by Arménio Ganga - Dronline</p>
            <p>Every 5 minutes, the plugin reads the recent responses of every Typeform with a payment block (Typeform API) and sends each paid purchase to the sGTM as a GA4 <code>purchase</code> event, in the same format as the website (GA4, Google Ads, Meta). When the person went through a Typeform embedded on the site, the purchase carries that visit's clicks (gclid, msclkid, fbc), matched by email. Nothing needs to change in the forms.</p>
            <p><strong>Consent (Complianz):</strong> clicks and the hashed email are only sent when marketing was accepted; the <code>_ga</code> only when statistics were accepted. No record of a site visit counts as not accepted. The consent state is always sent with the event, so the sGTM tags can decide.</p>

            <form method="post" action="options.php">
                <?php settings_fields('tpuk'); ?>
                <h2>Typeform</h2>
                <table class="form-table">
                    <tr><th><label for="typeform_token">Typeform token</label></th><td>
                        <input type="password" class="regular-text" id="typeform_token" name="<?php echo esc_attr($o); ?>[typeform_token]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($s['typeform_token'] !== '' ? 'saved (type to replace)' : 'paste the token here'); ?>">
                        <?php if ($s['typeform_token'] !== '') : ?>
                            <label><input type="checkbox" name="<?php echo esc_attr($o); ?>[typeform_token_clear]" value="1"> Delete</label>
                        <?php endif; ?>
                        <p class="description">Typeform → Settings → Personal tokens, with <code>forms:read</code> and <code>responses:read</code>, from an account that can access every workspace with payment forms. Once saved, it is never shown again.</p>
                        <?php if ($check) : ?>
                            <p><strong><?php echo esc_html($check); ?></strong></p>
                        <?php endif; ?>
                    </td></tr>
                    <tr><th>Form scan</th><td>
                        <?php
                        echo esc_html($scan
                            ? sprintf('Last run: %s · %d forms with payment · %d new purchases · %d waiting to be sent to the sGTM%s', $scan['time'] ?? '', count((array) ($scan['forms'] ?? [])), (int) ($scan['found'] ?? 0), $queue, !empty($scan['errors']) ? ' · ' . (int) $scan['errors'] . ' errors (see the log)' : '')
                            : 'Not run yet.');
                        ?>
                    </td></tr>
                </table>

                <h2>sGTM</h2>
                <table class="form-table">
                    <?php
                    $text('sgtm_url', 'sGTM URL', 'url', 'The plugin sends to <code>&lt;URL&gt;/g/collect</code>, the same format the website GTM uses: the sGTM GA4 client receives the purchase and the existing tags (GA4, Google Ads, Meta) handle it. Nothing needs to change in the sGTM.', 'https://sgtm.dronline.uk');
                    $text('ga4_measurement_id', 'GA4 Measurement ID', 'text', 'UK GA4 stream. Also used to read the <code>session_id</code> from the <code>_ga</code> cookie on the site.', 'G-XXXXXXX');
                    ?>
                    <?php $text('sgtm_preview', 'sGTM Preview (testing only)', 'text', 'Testing only: in the sGTM, Preview → menu (⋮) → "Send requests manually" → copy the <code>X-Gtm-Server-Preview</code> value. While this field is filled in, purchases go to the Preview instead of the published container. Leave it empty in production.'); ?>
                </table>

                <h2>Purchase</h2>
                <table class="form-table">
                    <?php $text('currency', 'Currency', 'text', 'Purchase currency (the UK Typeforms charge in GBP).'); ?>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if ($s['typeform_token'] !== '') : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tpuk_check_token">
                    <?php wp_nonce_field('tpuk_check_token'); ?>
                    <?php submit_button('Test the token', 'secondary', 'submit', false); ?>
                </form>
            <?php endif; ?>

            <h2>Latest events</h2>
            <table class="widefat striped">
                <thead><tr><th>Date</th><th>Version</th><th>Status</th><th>Message</th><th>Details</th></tr></thead>
                <tbody>
                <?php if (!$log) : ?>
                    <tr><td colspan="5">No events yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($log as $row) : ?>
                    <tr>
                        <td><?php echo esc_html($row['time']); ?></td>
                        <td><?php echo esc_html($row['version'] ?? ''); ?></td>
                        <td><?php echo esc_html($row['status']); ?></td>
                        <td><?php echo esc_html($row['message']); ?></td>
                        <td><details><summary><?php echo esc_html(($row['data']['form_title'] ?? $row['data']['form_id'] ?? '') . (isset($row['data']['value']) ? ' · ' . $row['data']['value'] . ' ' . ($row['data']['currency'] ?? '') : '')); ?></summary><pre style="white-space:pre-wrap"><?php echo esc_html(wp_json_encode($row['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre></details></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}

Typeform_Purchase_UK::init();

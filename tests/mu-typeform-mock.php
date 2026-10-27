<?php
// Só para o WordPress local: API do Typeform simulada, nunca chama a real.
// Estado em opções: tpuk_mock_forms (id => [title, fields]), tpuk_mock_responses (response_id => item com form_id),
// e cada pedido fica em tpuk_mock_calls ("GET /forms/...").
add_filter('tpuk_typeform_api', function () { return 'http://typeform.mock'; });

add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'http://typeform.mock/') !== 0) {
        return $pre;
    }
    $method = strtoupper($args['method'] ?? 'GET');
    $path   = substr($url, strlen('http://typeform.mock'));
    $calls  = get_option('tpuk_mock_calls', []);
    $calls[] = $method . ' ' . $path;
    update_option('tpuk_mock_calls', $calls, false);

    $reply = function ($code, $body) {
        return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
    };
    if (($args['headers']['Authorization'] ?? '') !== 'Bearer tf-test-token') {
        return $reply(401, ['code' => 'AUTHENTICATION_FAILED']);
    }
    if ($method !== 'GET') {
        return $reply(405, ['code' => 'METHOD_NOT_ALLOWED']);
    }
    $p = wp_parse_url($path);
    parse_str($p['query'] ?? '', $q);
    $seg   = explode('/', trim($p['path'], '/'));
    $forms = get_option('tpuk_mock_forms', []);

    if ($seg === ['forms']) {
        $items = [];
        foreach ($forms as $id => $f) {
            $items[] = ['id' => $id, 'title' => $f['title']];
        }
        return $reply(200, ['total_items' => count($forms), 'page_count' => 1, 'items' => $items]);
    }
    if (($seg[0] ?? '') !== 'forms' || !isset($forms[$seg[1] ?? ''])) {
        return $reply(404, ['code' => 'FORM_NOT_FOUND']);
    }
    $id = $seg[1];
    if (count($seg) === 2) {
        return $reply(200, ['id' => $id, 'title' => $forms[$id]['title'], 'fields' => $forms[$id]['fields']]);
    }
    if (count($seg) === 3 && $seg[2] === 'responses') {
        $want  = explode(',', (string) ($q['included_response_ids'] ?? ''));
        $items = [];
        foreach (get_option('tpuk_mock_responses', []) as $rid => $item) {
            $after = !isset($q['since']) || strtotime((string) ($item['submitted_at'] ?? '')) >= strtotime($q['since'] . 'Z');
            if (($item['form_id'] ?? '') === $id && $after && (!isset($q['included_response_ids']) || in_array((string) $rid, $want, true))) {
                unset($item['form_id']);
                $items[] = $item;
            }
        }
        return $reply(200, ['total_items' => count($items), 'page_count' => 1, 'items' => $items]);
    }
    return $reply(404, ['code' => 'NOT_FOUND']);
}, 10, 3);

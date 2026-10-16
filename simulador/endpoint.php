<?php
// Responde em /oficial/... (via .htaccess) com os arquivos simulados no formato do TSE.
// Qualquer outro arquivo é redirecionado para o TSE. Para testar sem rewrite: endpoint.php?f=br|sp|ab
require __DIR__ . '/lib/nucleo.php';

$origem = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Origin: ' . ($origem !== '' ? $origem : '*'));
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Max-Age: 600');

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$chave = null;
$f = $_GET['f'] ?? null;
if (is_string($f) && isset(arquivos()[$f])) {
    $chave = $f;
} else {
    $caminho = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    $pos = strpos($caminho, '/' . cfg('ambiente') . '/');
    $rel = $pos === false ? '' : substr($caminho, $pos + 1);
    foreach (arquivos() as $k => $_) {
        if ($rel === caminho_relativo($k)) $chave = $k;
    }
    if ($chave === null) {
        if ($rel === '' || !preg_match('#^[A-Za-z0-9/._-]+$#', $rel) || strpos($rel, '..') !== false) {
            http_response_code(404);
            exit;
        }
        $qs = $_SERVER['QUERY_STRING'] ?? '';
        header('Cache-Control: no-store');
        header('Location: ' . rtrim((string) cfg('tse_base'), '/') . '/' . $rel . ($qs !== '' ? '?' . $qs : ''), true, 302);
        exit;
    }
}

try {
    $corpo = gerar_arquivo($chave);
} catch (Throwable $e) {
    error_log('simulador: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

$etag = '"' . md5($corpo) . '"';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . strlen($corpo));
if ($metodo !== 'HEAD') echo $corpo;

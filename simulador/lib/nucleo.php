<?php
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));

const UFS = ['br' => 'Brasil', 'sp' => 'São Paulo'];
const CURVAS = ['linear', 'realista'];

class ErroUsuario extends RuntimeException {}

if (!function_exists('array_is_list')) {
    function array_is_list(array $a): bool
    {
        $i = 0;
        foreach ($a as $k => $_) {
            if ($k !== $i++) return false;
        }
        return true;
    }
}

if (!function_exists('array_key_last')) {
    function array_key_last(array $a) { return $a ? array_keys($a)[count($a) - 1] : null; }
}

function cfg(string $k)
{
    static $c = null;
    if ($c === null) {
        $c = require RAIZ . '/config.php';
        date_default_timezone_set($c['fuso'] ?? 'America/Sao_Paulo');
    }
    return $c[$k] ?? null;
}
cfg('fuso');

/* ------------------------------------------------------------------ caminhos */

// chave => caminho dentro de /dados/
function arquivos(): array
{
    $f = str_pad((string) cfg('eleicao'), 6, '0', STR_PAD_LEFT);
    return [
        'br' => "br/br-c0001-e{$f}-u.json",
        'sp' => "sp/sp-c0001-e{$f}-u.json",
        'ab' => "br/br-e{$f}-ab.json",
    ];
}

// "oficial/ele2026/6258/dados/br/br-c0001-e006258-u.json"
function caminho_relativo(string $chave): string
{
    return cfg('ambiente') . '/' . cfg('ciclo') . '/' . cfg('eleicao') . '/dados/' . arquivos()[$chave];
}

function url_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* ------------------------------------------------------------------ banco */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $dir = RAIZ . '/dados';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . $dir . '/simulador.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS modelo (chave TEXT PRIMARY KEY, json TEXT NOT NULL, atualizado INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS estado (uf TEXT PRIMARY KEY, json TEXT NOT NULL, atualizado INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS simulacao (uf TEXT PRIMARY KEY, ativa INTEGER NOT NULL, inicio INTEGER NOT NULL, fim INTEGER NOT NULL,
        intervalo INTEGER NOT NULL, curva TEXT NOT NULL, origem TEXT NOT NULL, destino TEXT NOT NULL, criado_por TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS trava (uf TEXT PRIMARY KEY, token TEXT NOT NULL, nome TEXT NOT NULL, desde INTEGER NOT NULL, expira INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS historico (id INTEGER PRIMARY KEY AUTOINCREMENT, uf TEXT NOT NULL, quando INTEGER NOT NULL, quem TEXT NOT NULL, acao TEXT NOT NULL)');
    return $pdo;
}

function linha(string $sql, array $p = []): ?array
{
    $q = db()->prepare($sql);
    $q->execute($p);
    $r = $q->fetch();
    return $r === false ? null : $r;
}

function executar(string $sql, array $p = []): void
{
    db()->prepare($sql)->execute($p);
}

// Executa $fn com o banco travado para escrita (evita duas gravações simultâneas).
function transacao(callable $fn)
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $r = $fn();
        $pdo->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function registrar(string $uf, string $quem, string $acao): void
{
    executar('INSERT INTO historico (uf, quando, quem, acao) VALUES (?, ?, ?, ?)', [$uf, time(), $quem, $acao]);
}

function historico(string $uf, int $n = 15): array
{
    $q = db()->prepare('SELECT quando, quem, acao FROM historico WHERE uf = ? ORDER BY id DESC LIMIT ' . $n);
    $q->execute([$uf]);
    return $q->fetchAll();
}

/* ------------------------------------------------------------------ modelos (JSON original do TSE) */

function modelo(string $chave): array
{
    $cache = &$GLOBALS['__modelos'];
    if (isset($cache[$chave])) return $cache[$chave];
    $r = linha('SELECT json FROM modelo WHERE chave = ?', [$chave]);
    if ($r) {
        $json = $r['json'];
    } else {
        $json = file_get_contents(RAIZ . '/modelos/' . basename(arquivos()[$chave]));
        executar('INSERT OR IGNORE INTO modelo (chave, json, atualizado) VALUES (?, ?, ?)', [$chave, $json, time()]);
    }
    return $cache[$chave] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

// Baixa o arquivo real do TSE e passa a usá-lo como modelo (os valores simulados são mantidos).
function atualizar_modelo_do_tse(string $chave): void
{
    $url = cfg('tse_base') . '/' . caminho_relativo($chave);
    $corpo = baixar($url);
    $j = json_decode($corpo, true);
    $campo = $chave === 'ab' ? 'abr' : 'carg';
    if (!is_array($j) || empty($j[$campo])) {
        throw new ErroUsuario("O TSE devolveu um conteúdo inesperado para $url.");
    }
    executar('INSERT OR REPLACE INTO modelo (chave, json, atualizado) VALUES (?, ?, ?)', [$chave, $corpo, time()]);
    unset($GLOBALS['__modelos'][$chave]);
}

function baixar(string $url): string
{
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (simulador)']);
        $r = curl_exec($c);
        $cod = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if ($r === false || $cod !== 200) throw new ErroUsuario("Não foi possível baixar $url (HTTP $cod).");
        return $r;
    }
    $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0']]));
    if ($r === false) throw new ErroUsuario("Não foi possível baixar $url.");
    return $r;
}

function candidatos(array $m): array
{
    $out = [];
    foreach ($m['carg'][0]['agr'] ?? [] as $agr) {
        foreach ($agr['par'] ?? [] as $par) {
            foreach ($par['cand'] ?? [] as $c) {
                $out[] = [
                    'sqcand' => (string) $c['sqcand'],
                    'n' => (string) $c['n'],
                    'nm' => (string) $c['nm'],
                    'nmu' => (string) ($c['nmu'] ?? $c['nm']),
                    'partido' => (string) $par['sg'],
                    'vice' => (string) ($c['vs'][0]['nmu'] ?? ''),
                    'seq' => (int) ($c['seq'] ?? 0),
                ];
            }
        }
    }
    usort($out, function ($a, $b) { return $a['seq'] <=> $b['seq']; });
    return $out;
}

/* ------------------------------------------------------------------ serialização no formato exato do TSE */

function tse_json($v): string
{
    if (is_array($v)) {
        if (array_is_list($v)) {
            return '[ ' . implode(', ', array_map('tse_json', $v)) . ' ]';
        }
        $p = [];
        foreach ($v as $k => $x) $p[] = tse_str((string) $k) . ' : ' . tse_json($x);
        return "\n{ " . implode(', ', $p) . ' }';
    }
    if ($v === null) return 'null';
    if (is_bool($v)) return $v ? 'true' : 'false';
    if (is_int($v) || is_float($v)) return (string) $v;
    return tse_str((string) $v);
}

function tse_str(string $s): string
{
    return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/* ------------------------------------------------------------------ formatação de percentuais */

// "47,03". Como o TSE: um valor > 0 nunca aparece como 0,00 e um valor < 100% nunca aparece como 100,00.
function pct2($num, $den): string
{
    if ($den <= 0) return '0,00';
    $x = round($num / $den * 100, 2);
    if ($num > 0 && $x < 0.01) $x = 0.01;
    if ($num < $den && $x > 99.99) $x = 99.99;
    return number_format($x, 2, ',', '');
}

// "47,027772356" (até 9 casas, sem zeros à direita). $zero é o texto quando o denominador é zero.
function pctn($num, $den, string $zero = '0'): string
{
    if ($den <= 0) return $zero;
    $s = number_format($num / $den * 100, 9, ',', '');
    return rtrim(rtrim($s, '0'), ',');
}

/* ------------------------------------------------------------------ cálculo dos blocos s / e / v */

function calc_s(int $ts, int $st, int $sni): array
{
    $si = $st - $sni;
    $sa = $si;
    $sna = 0;
    return [
        'ts' => $ts, 'st' => $st, 'pst' => pct2($st, $ts), 'pstn' => pctn($st, $ts),
        'snt' => $ts - $st, 'psnt' => pct2($ts - $st, $ts), 'psntn' => pctn($ts - $st, $ts),
        'si' => $si, 'psi' => pct2($si, $st), 'psin' => pctn($si, $st),
        'sni' => $sni, 'psni' => pct2($sni, $st), 'psnin' => pctn($sni, $st),
        'sa' => $sa, 'psa' => pct2($sa, $si), 'psan' => pctn($sa, $si),
        'sna' => $sna, 'psna' => pct2($sna, $si), 'psnan' => pctn($sna, $si),
    ];
}

// Eleitores das seções totalizadas (proporcional às seções).
function eleitores_totalizados(int $te, int $ts, int $st): int
{
    if ($ts <= 0) return 0;
    return $st >= $ts ? $te : (int) round($te * $st / $ts);
}

function calc_e(int $te, int $ts, int $st, int $esni, int $c): array
{
    $est = eleitores_totalizados($te, $ts, $st);
    $esni = max(0, min($esni, $est));
    $esi = $est - $esni;
    $esa = $esi;
    $esna = 0;
    $a = max(0, $esi - $c);
    return [
        'te' => $te, 'est' => $est, 'pest' => pct2($est, $te), 'pestn' => pctn($est, $te),
        'esnt' => $te - $est, 'pesnt' => pct2($te - $est, $te), 'pesntn' => pctn($te - $est, $te),
        'esi' => $esi, 'pesi' => pct2($esi, $est), 'pesin' => pctn($esi, $est),
        'esni' => $esni, 'pesni' => pct2($esni, $est), 'pesnin' => pctn($esni, $est),
        'esa' => $esa, 'pesa' => pct2($esa, $esi), 'pesan' => pctn($esa, $esi),
        'esna' => $esna, 'pesna' => pct2($esna, $esi), 'pesnan' => pctn($esna, $esi),
        'c' => $c, 'pc' => pct2($c, $esi), 'pcn' => pctn($c, $esi),
        'a' => $a, 'pa' => pct2($a, $esi), 'pan' => pctn($a, $esi),
    ];
}

function calc_v(int $vnom, int $vb, int $vn, int $vnt): array
{
    $vv = $vnom;
    $vvc = $vv;
    $tvn = $vn + $vnt;
    $tv = $vv + $vb + $tvn;
    $z = '0,00';
    return [
        'tv' => $tv, 'vvc' => $vvc, 'pvvc' => pct2($vvc, $tv), 'pvvcn' => pctn($vvc, $tv, $z),
        'vv' => $vv, 'pvv' => pct2($vv, $vvc), 'pvvn' => pctn($vv, $vvc, $z),
        'vnom' => $vnom, 'pvnom' => pct2($vnom, $vv), 'pvnomn' => pctn($vnom, $vv, $z),
        'van' => 0, 'pvan' => pct2(0, $tv), 'pvann' => pctn(0, $tv, $z),
        'vansj' => 0, 'pvansj' => pct2(0, $tv), 'pvansjn' => pctn(0, $tv, $z),
        'vb' => $vb, 'pvb' => pct2($vb, $tv), 'pvbn' => pctn($vb, $tv, $z),
        'tvn' => $tvn, 'ptvn' => pct2($tvn, $tv), 'ptvnn' => pctn($tvn, $tv, $z),
        'vn' => $vn, 'pvn' => pct2($vn, $tvn), 'pvnn' => pctn($vn, $tvn, $z),
        'vnt' => $vnt, 'pvnt' => pct2($vnt, $tvn), 'pvntn' => pctn($vnt, $tvn, $z),
        'vsan' => 0, 'vscv' => 0,
    ];
}

/* ------------------------------------------------------------------ estado editável */

// Formato do estado: números brutos que a pessoa edita; todo o resto é derivado.
function estado_zero(string $uf): array
{
    $m = modelo($uf);
    $vap = [];
    foreach (candidatos($m) as $c) $vap[$c['sqcand']] = 0;
    return [
        'st' => 0, 'sni' => 0, 'esni' => 0, 'vb' => 0, 'vn' => 0, 'vnt' => 0, 'vap' => $vap,
        'eleito' => [], 'matematico' => false, 'and' => 'auto', 'tf' => 'auto',
        'idg' => (int) $m['idg'], 'quando' => null,
    ];
}

function estado_salvo(string $uf): array
{
    $r = linha('SELECT json FROM estado WHERE uf = ?', [$uf]);
    $base = estado_zero($uf);
    if (!$r) return $base;
    $e = array_replace($base, json_decode($r['json'], true) ?: []);
    $e['vap'] = array_replace($base['vap'], array_intersect_key($e['vap'], $base['vap']));
    return $e;
}

function gravar_estado(string $uf, array $e): void
{
    executar('INSERT OR REPLACE INTO estado (uf, json, atualizado) VALUES (?, ?, ?)', [$uf, json_encode($e), time()]);
}

// Limpa e valida o que veio do formulário. Lança ErroUsuario se os números não fecharem.
function sanitizar_estado(string $uf, $entrada): array
{
    if (!is_array($entrada)) throw new ErroUsuario('Dados inválidos.');
    $m = modelo($uf);
    $ts = (int) $m['s']['ts'];
    $int = function ($v) { return max(0, (int) round((float) $v)); };
    $e = estado_zero($uf);
    $e['st'] = min($ts, $int($entrada['st'] ?? 0));
    $e['sni'] = min($e['st'], $int($entrada['sni'] ?? 0));
    $e['esni'] = $e['sni'] > 0 ? $int($entrada['esni'] ?? 0) : 0;
    foreach (['vb', 'vn', 'vnt'] as $k) $e[$k] = $int($entrada[$k] ?? 0);
    foreach ($e['vap'] as $sq => $_) $e['vap'][$sq] = $int($entrada['vap'][$sq] ?? 0);
    foreach ($e['vap'] as $sq => $_) {
        $x = $entrada['eleito'][$sq] ?? 'auto';
        if ($x === 's' || $x === 'm' || $x === 'n') $e['eleito'][$sq] = $x;
    }
    $e['matematico'] = ($entrada['matematico'] ?? false) === true;
    $and = $entrada['and'] ?? 'auto';
    $e['and'] = in_array($and, ['auto', 'n', 'p', 'f'], true) ? $and : 'auto';
    $tf = $entrada['tf'] ?? 'auto';
    $e['tf'] = in_array($tf, ['auto', 's', 'n'], true) ? $tf : 'auto';

    $r = calcular($uf, $e);
    if ($r['v']['tv'] > $r['e']['esi']) {
        throw new ErroUsuario(sprintf(
            'O total de votos (%s) é maior que o número de eleitores das seções apuradas (%s). Aumente o %% de apuração ou reduza os votos.',
            number_format($r['v']['tv'], 0, ',', '.'), number_format($r['e']['esi'], 0, ',', '.')));
    }
    return $e;
}

function calcular(string $uf, array $est): array
{
    $m = modelo($uf);
    $ts = (int) $m['s']['ts'];
    $te = (int) $m['e']['te'];
    $cands = candidatos($m);
    $st = max(0, min((int) $est['st'], $ts));
    $sni = max(0, min((int) $est['sni'], $st));

    $vap = [];
    foreach ($cands as $c) $vap[$c['sqcand']] = max(0, (int) ($est['vap'][$c['sqcand']] ?? 0));
    $v = calc_v(array_sum($vap), (int) $est['vb'], (int) $est['vn'], (int) $est['vnt']);
    $e = calc_e($te, $ts, $st, $sni > 0 ? (int) $est['esni'] : 0, $v['tv']);
    $s = calc_s($ts, $st, $sni);

    // Ordem por votos (empate: ordem original do TSE).
    $ordem = $cands;
    usort($ordem, function ($a, $b) use ($vap) {
        return [$vap[$b['sqcand']], $a['seq']] <=> [$vap[$a['sqcand']], $b['seq']];
    });
    // Eleito automático: quando o líder já não pode ser alcançado nem com todos os eleitores restantes.
    $restante = $te - $e['est'];
    $lider = $ordem[0]['sqcand'] ?? null;
    $segundo = isset($ordem[1]) ? $vap[$ordem[1]['sqcand']] : 0;
    $decidido = $lider !== null && $vap[$lider] > 0 && $vap[$lider] > $segundo + $restante;
    $algumVoto = array_sum($vap) > 0;

    $and = $est['and'] !== 'auto' ? $est['and'] : ($st === 0 ? 'n' : ($st < $ts ? 'p' : 'f'));
    $tf = $est['tf'] !== 'auto' ? $est['tf'] : ($st >= $ts ? 's' : 'n');
    // Com a opção ligada, o líder inalcançável aparece como "Matematicamente eleito" até a totalização final.
    $rotuloAuto = !empty($est['matematico']) && $tf !== 's' ? 'Matematicamente eleito' : 'Eleito';

    $res = [];
    foreach ($ordem as $i => $c) {
        $sq = $c['sqcand'];
        $manual = $est['eleito'][$sq] ?? 'auto';
        if ($manual === 's' || $manual === 'm' || $manual === 'n') {
            $eleito = $manual === 'n' ? 'n' : 's';
            $situacao = ['s' => 'Eleito', 'm' => 'Matematicamente eleito', 'n' => 'Não eleito'][$manual];
        } elseif ($decidido) {
            $eleito = $sq === $lider ? 's' : 'n';
            $situacao = $sq === $lider ? $rotuloAuto : 'Não eleito';
        } else {
            $eleito = 'n';
            $situacao = '';
        }
        $res[$sq] = [
            'vap' => $vap[$sq], 'pvap' => pct2($vap[$sq], $v['vv']), 'pvapn' => pctn($vap[$sq], $v['vv']),
            'e' => $eleito, 'st' => $situacao, 'seq' => $algumVoto ? $i + 1 : $c['seq'],
        ];
    }

    return ['ts' => $ts, 'te' => $te, 'st' => $st, 's' => $s, 'e' => $e, 'v' => $v, 'cand' => $res,
        'and' => $and, 'tf' => $tf, 'decidido' => $decidido];
}

/* ------------------------------------------------------------------ simulação automática */

const MODOS = ['virada', 'lidera', 'manual'];
// amplitude da oscilação dos percentuais (fração dos válidos) e variação do tamanho de cada lote
const OSCILACAO = ['nenhuma' => [0.0, 0.0], 'leve' => [0.006, 0.35], 'forte' => [0.015, 0.7]];

function simulacao(string $uf): ?array
{
    $r = linha('SELECT * FROM simulacao WHERE uf = ? AND ativa = 1', [$uf]);
    if (!$r) return null;
    $r['origem'] = json_decode($r['origem'], true);
    $r['destino'] = json_decode($r['destino'], true);
    $r['passos'] = max(1, (int) ceil(($r['fim'] - $r['inicio']) / $r['intervalo']));
    return $r;
}

function curva(string $tipo, float $x): float
{
    // "realista": apuração rápida no começo e mais lenta no fim, como no dia da eleição.
    return $tipo === 'realista' ? 1 - (1 - $x) ** 2 : $x;
}

// Fração dos votos válidos de cada candidato num estado.
function fatias(array $vap): array
{
    $vv = array_sum($vap);
    $r = [];
    foreach ($vap as $sq => $v) $r[$sq] = $vv > 0 ? $v / $vv : 1 / max(1, count($vap));
    return $r;
}

// Gerador pseudoaleatório com semente: a mesma semente sempre gera o mesmo caminho,
// então todas as requisições do mesmo passo devolvem o mesmo JSON e a prévia bate com a execução.
function rng_novo(int $semente): array
{
    return ['s' => ($semente % 2147483646) + 1];
}

function rng(array &$r): float
{
    $r['s'] = ($r['s'] * 48271) % 2147483647;
    return $r['s'] / 2147483647;
}

function gauss(array &$r): float
{
    return (rng($r) + rng($r) + rng($r) + rng($r) - 2) * 1.7320508;
}

// Valida os parâmetros e calcula como o % de cada candidato vai andar.
function preparar_simulacao(string $uf, array $origem, $destino, $minutos, $intervalo, $curva, array $op): array
{
    $minutos = (float) $minutos;
    $intervalo = (int) $intervalo;
    if ($minutos < 1 || $minutos > 720) throw new ErroUsuario('A duração deve ficar entre 1 e 720 minutos.');
    if ($intervalo < 5 || $intervalo > 900) throw new ErroUsuario('O intervalo entre atualizações deve ficar entre 5 e 900 segundos.');
    if ($intervalo > $minutos * 60) throw new ErroUsuario('O intervalo não pode ser maior que a duração.');
    $curva = in_array($curva, CURVAS, true) ? $curva : 'realista';

    $dest = sanitizar_estado($uf, $destino);
    $dest['and'] = 'auto';
    $dest['tf'] = 'auto';
    $dest['eleito'] = [];
    $dest['matematico'] = !empty($origem['matematico']);
    $m = modelo($uf);
    $nomes = array_column(candidatos($m), 'nmu', 'sqcand');
    if ($dest['st'] <= $origem['st']) throw new ErroUsuario('O % apurado do destino precisa ser maior que o que está no ar agora.');
    foreach (['vb' => 'brancos', 'vn' => 'nulos', 'vnt' => 'nulos técnicos'] as $k => $rot) {
        if ($dest[$k] < $origem[$k]) throw new ErroUsuario("O destino não pode ter menos $rot do que já está no ar.");
    }
    foreach ($dest['vap'] as $sq => $v) {
        if ($v < ($origem['vap'][$sq] ?? 0)) {
            throw new ErroUsuario('O destino não pode ter menos votos de ' . ($nomes[$sq] ?? $sq) . ' do que já está no ar.');
        }
    }

    $modo = in_array($op['modo'] ?? '', MODOS, true) ? $op['modo'] : 'virada';
    $oscNome = isset(OSCILACAO[$op['oscilacao'] ?? '']) ? $op['oscilacao'] : 'leve';
    $semente = (int) ($op['semente'] ?? 0);
    if ($semente <= 0) $semente = random_int(1, 2147483000);

    $fim = fatias($dest['vap']);
    $ovv = array_sum($origem['vap']);
    $sqs = array_keys($dest['vap']);
    $ordem = $sqs;
    usort($ordem, function ($a, $b) use ($fim) { return $fim[$b] <=> $fim[$a]; });
    $dois = count($sqs) === 2;
    [$w, $l] = $dois ? $ordem : [null, null];

    if ($ovv > 0) {
        $ini = fatias($origem['vap']);
    } elseif ($modo === 'manual') {
        $ini = [];
        foreach ($sqs as $sq) $ini[$sq] = max(0.0, (float) ($op['inicio_pct'][$sq] ?? 0));
        $t = array_sum($ini);
        if ($t <= 0) throw new ErroUsuario('Informe o % inicial dos candidatos.');
        foreach ($ini as $sq => $x) $ini[$sq] = $x / $t;
    } elseif ($modo === 'virada' && $dois) {
        // quem perde no fim começa na frente, com uma vantagem proporcional à margem final
        $ini = [$w => max(0.30, min(0.49, 0.5 - 1.5 * ($fim[$w] - 0.5)))];
        $ini[$l] = 1 - $ini[$w];
    } elseif ($modo === 'lidera' && $dois) {
        $ini = [$w => min(0.70, $fim[$w] + 0.02)];
        $ini[$l] = 1 - $ini[$w];
    } else {
        $ini = $fim;
    }

    // Formato da curva do %: h(q) = q^gama, com q = progresso da simulação (0 a 1).
    $gama = 1.0;
    $viradaPct = null;
    if ($modo === 'virada') {
        if (!$dois) throw new ErroUsuario('A virada só funciona com dois candidatos.');
        if (abs($fim[$w] - 0.5) < 1e-9) throw new ErroUsuario('Os votos finais estão empatados; para ter virada, alguém precisa vencer.');
        if ($ini[$w] >= 0.5) {
            throw new ErroUsuario(sprintf('%s já está na frente agora e também vence no final, então não há virada. Mude o destino ou zere os valores.', $nomes[$w]));
        }
        $ts = (int) $m['s']['ts'];
        $viradaPct = max(0.0, min(100.0, (float) ($op['virada_pct'] ?? 60)));
        $q = ($viradaPct / 100 * $ts - $origem['st']) / ($dest['st'] - $origem['st']);
        $min = ($origem['st'] + 0.03 * ($dest['st'] - $origem['st'])) / $ts * 100;
        $max = ($origem['st'] + 0.97 * ($dest['st'] - $origem['st'])) / $ts * 100;
        if ($q < 0.03 || $q > 0.97) {
            throw new ErroUsuario(sprintf('A virada precisa ficar entre %s%% e %s%% apurado.',
                number_format(ceil($min * 10) / 10, 1, ',', ''), number_format(floor($max * 10) / 10, 1, ',', '')));
        }
        $h = (0.5 - $ini[$w]) / ($fim[$w] - $ini[$w]);
        $gama = log($h) / log($q);
    }

    return [
        'destino' => $dest,
        'minutos' => $minutos,
        'intervalo' => $intervalo,
        'passos' => max(1, (int) ceil($minutos * 60 / $intervalo)),
        'param' => [
            'modo' => $modo, 'virada_pct' => $viradaPct, 'oscilacao' => $oscNome, 'semente' => $semente,
            'curva' => $curva, 'ini' => $ini, 'gama' => $gama,
            'osc' => OSCILACAO[$oscNome][0], 'variacao' => OSCILACAO[$oscNome][1],
        ],
    ];
}

// Divide os votos novos deste passo entre os candidatos: ninguém perde voto e ninguém passa do total final.
function distribuir(array $antes, array $alvo, array $teto, int $vv): array
{
    $delta = $vv - array_sum($antes);
    if ($delta <= 0) return $antes;
    $def = [];
    foreach ($antes as $sq => $a) $def[$sq] = max(0.0, min($alvo[$sq], $teto[$sq]) - $a);
    $somaDef = array_sum($def);
    $add = [];
    if ($somaDef >= $delta) {
        foreach ($def as $sq => $x) $add[$sq] = $delta * $x / $somaDef;
    } else {
        $folga = [];
        foreach ($antes as $sq => $a) $folga[$sq] = max(0.0, $teto[$sq] - $a - $def[$sq]);
        $somaFolga = array_sum($folga);
        foreach ($def as $sq => $x) {
            $add[$sq] = $x + ($somaFolga > 0 ? ($delta - $somaDef) * $folga[$sq] / $somaFolga : ($delta - $somaDef) / count($def));
        }
    }
    $novo = [];
    $frac = [];
    foreach ($add as $sq => $x) {
        $novo[$sq] = $antes[$sq] + (int) floor($x);
        $frac[$sq] = $x - floor($x);
    }
    $falta = $vv - array_sum($novo);
    arsort($frac);
    while ($falta > 0) {
        $deu = false;
        foreach ($frac as $sq => $_) {
            if ($falta <= 0) break;
            if ($novo[$sq] < $teto[$sq]) { $novo[$sq]++; $falta--; $deu = true; }
        }
        if (!$deu) break;
    }
    return $novo;
}

// Calcula todos os passos da simulação, de 0 até $ate (por padrão, até o fim).
function serie_simulacao(array $o, array $d, array $p, int $passos, ?int $ate = null): array
{
    $ate = $ate === null ? $passos : min($ate, $passos);
    $r = rng_novo((int) $p['semente']);

    // ritmo: cada lote tem um tamanho um pouco diferente
    $acum = [];
    $soma = 0.0;
    $ant = 0.0;
    for ($k = 1; $k <= $passos; $k++) {
        $c = curva($p['curva'], $k / $passos);
        $soma += ($c - $ant) * max(0.05, 1 + $p['variacao'] * (2 * rng($r) - 1));
        $ant = $c;
        $acum[$k] = $soma;
    }

    $sqs = array_keys($d['vap']);
    $fim = fatias($d['vap']);
    $ini = $p['ini'];
    $ovv = array_sum($o['vap']);
    $dvv = array_sum($d['vap']);
    $ruido = array_fill_keys($sqs, 0.0);
    $serie = [0 => $o];
    $antes = $o['vap'];
    for ($k = 1; $k <= $ate; $k++) {
        $e = $o;
        if ($k === $passos) {
            foreach (['st', 'sni', 'esni', 'vb', 'vn', 'vnt', 'vap'] as $c) $e[$c] = $d[$c];
            $serie[$k] = $e;
            break;
        }
        $q = $acum[$k] / $soma;
        foreach (['st', 'sni', 'esni', 'vb', 'vn', 'vnt'] as $c) $e[$c] = (int) round($o[$c] + ($d[$c] - $o[$c]) * $q);
        $vv = (int) round($ovv + ($dvv - $ovv) * $q);
        $h = $q ** $p['gama'];
        // oscilação forte nas primeiras parciais (poucos votos) e que some no fim
        $amp = $p['osc'] * (1 - $q) ** 1.5 * (1 + 2 * exp(-$q / 0.05));
        $media = 0.0;
        foreach ($sqs as $sq) {
            $ruido[$sq] = 0.8 * $ruido[$sq] + 0.6 * gauss($r);
            $media += $ruido[$sq];
        }
        $media /= count($sqs);
        $alvo = [];
        foreach ($sqs as $sq) {
            $f = ($ini[$sq] ?? 0) + (($fim[$sq] ?? 0) - ($ini[$sq] ?? 0)) * $h + $amp * ($ruido[$sq] - $media);
            $alvo[$sq] = $vv * max(0.0, min(1.0, $f));
        }
        $e['vap'] = distribuir($antes, $alvo, $d['vap'], $vv);
        $antes = $e['vap'];
        $serie[$k] = $e;
    }
    return $serie;
}

// Resumo da série para o gráfico: % apurado x % de cada candidato, viradas e momento do "Eleito".
function resumir_serie(string $uf, array $serie): array
{
    $m = modelo($uf);
    $ts = (int) $m['s']['ts'];
    $te = (int) $m['e']['te'];
    $total = count($serie);
    $pulo = max(1, (int) ceil($total / 300));
    $pontos = [];
    $viradas = [];
    $eleito = null;
    $lider = null;
    foreach ($serie as $k => $e) {
        $vap = $e['vap'];
        $vv = array_sum($vap);
        $pst = $ts ? $e['st'] / $ts * 100 : 0;
        $pct = null;
        if ($vv > 0) {
            arsort($vap);
            $top = array_keys($vap);
            $l = $top[0];
            $segundo = isset($top[1]) ? $vap[$top[1]] : 0;
            if ($vap[$l] > $segundo) {
                if ($lider !== null && $l !== $lider) $viradas[] = ['k' => $k, 'pst' => round($pst, 2), 'sq' => $l];
                $lider = $l;
            }
            if ($eleito === null && $vap[$l] > $segundo + ($te - eleitores_totalizados($te, $ts, (int) $e['st']))) {
                $eleito = ['k' => $k, 'pst' => round($pst, 2), 'sq' => $l];
            }
            $pct = [];
            foreach ($e['vap'] as $sq => $v) $pct[$sq] = round($v / $vv * 100, 3);
        }
        if ($k % $pulo === 0 || $k === $total - 1) $pontos[] = ['k' => $k, 'pst' => round($pst, 3), 'pct' => $pct];
    }
    return ['pontos' => $pontos, 'viradas' => $viradas, 'eleito' => $eleito, 'passos' => $total - 1];
}

function previa_simulacao(string $uf, $destino, $minutos, $intervalo, $curva, array $op): array
{
    $origem = estado_salvo($uf);
    $prep = preparar_simulacao($uf, $origem, $destino, $minutos, $intervalo, $curva, $op);
    $serie = serie_simulacao($origem, $prep['destino'], $prep['param'], $prep['passos']);
    return resumir_serie($uf, $serie) + ['semente' => $prep['param']['semente']];
}

// Estado que vale agora. Se houver simulação rodando, calcula o passo atual a partir do horário.
function estado_atual(string $uf): array
{
    $sim = simulacao($uf);
    if (!$sim) return estado_salvo($uf);

    $k = intdiv(max(0, time() - (int) $sim['inicio']), (int) $sim['intervalo']);
    if ($k >= $sim['passos']) {
        return transacao(function () use ($uf) {
            $sim = simulacao($uf); // outra requisição pode ter finalizado antes
            if (!$sim) return estado_salvo($uf);
            $final = $sim['destino'];
            unset($final['param']);
            $final['idg'] = (int) $sim['origem']['idg'] + $sim['passos'];
            $final['quando'] = min(time(), (int) $sim['inicio'] + $sim['passos'] * (int) $sim['intervalo']);
            gravar_estado($uf, $final);
            executar('UPDATE simulacao SET ativa = 0 WHERE uf = ?', [$uf]);
            registrar($uf, $sim['criado_por'], 'Simulação concluída');
            return $final;
        });
    }
    if ($k === 0) return $sim['origem'];

    $e = serie_simulacao($sim['origem'], $sim['destino'], $sim['destino']['param'], $sim['passos'], $k)[$k];
    $e['eleito'] = [];
    $e['and'] = 'auto';
    $e['tf'] = 'auto';
    $e['idg'] = (int) $sim['origem']['idg'] + $k;
    $e['quando'] = (int) $sim['inicio'] + $k * (int) $sim['intervalo'];
    return $e;
}

function iniciar_simulacao(string $uf, $destino, $minutos, $intervalo, $curva, array $op, string $quem): void
{
    transacao(function () use ($uf, $destino, $minutos, $intervalo, $curva, $op, $quem) {
        if (simulacao($uf)) throw new ErroUsuario('Já existe uma simulação em andamento.');
        $origem = estado_salvo($uf);
        $prep = preparar_simulacao($uf, $origem, $destino, $minutos, $intervalo, $curva, $op);
        $dest = $prep['destino'];
        $dest['param'] = $prep['param'];
        $inicio = time();
        executar('INSERT OR REPLACE INTO simulacao (uf, ativa, inicio, fim, intervalo, curva, origem, destino, criado_por)
                  VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?)',
            [$uf, $inicio, $inicio + (int) round($prep['minutos'] * 60), $prep['intervalo'], $prep['param']['curva'],
                json_encode($origem), json_encode($dest), $quem]);
        $p = $prep['param'];
        $modo = $p['modo'] === 'virada' ? 'virada em ~' . str_replace('.', ',', (string) $p['virada_pct']) . '%'
            : ($p['modo'] === 'lidera' ? 'vencedor lidera' : 'manual');
        registrar($uf, $quem, sprintf('Iniciou simulação: %s min, a cada %d s, até %s%% apurado, %s, oscilação %s',
            str_replace('.', ',', (string) $prep['minutos']), $prep['intervalo'], calcular($uf, $dest)['s']['pst'], $modo, $p['oscilacao']));
    });
}

// Para a simulação e congela os valores do passo atual.
function parar_simulacao(string $uf, string $quem): void
{
    $atual = estado_atual($uf);
    transacao(function () use ($uf, $atual, $quem) {
        if (!simulacao($uf)) return;
        gravar_estado($uf, $atual);
        executar('UPDATE simulacao SET ativa = 0 WHERE uf = ?', [$uf]);
        registrar($uf, $quem, 'Parou a simulação em ' . calcular($uf, $atual)['s']['pst'] . '% apurado');
    });
}

/* ------------------------------------------------------------------ trava da tela */

function trava_obter(string $uf, string $token, string $nome): array
{
    return transacao(function () use ($uf, $token, $nome) {
        $t = linha('SELECT * FROM trava WHERE uf = ?', [$uf]);
        $agora = time();
        $ttl = (int) cfg('trava_ttl');
        if ($t && $t['expira'] > $agora && !($token !== '' && hash_equals($t['token'], $token))) {
            return ['ok' => false];
        }
        if ($t && $t['expira'] > $agora) {
            executar('UPDATE trava SET expira = ?, nome = ? WHERE uf = ?', [$agora + $ttl, $nome, $uf]);
            return ['ok' => true, 'token' => $token];
        }
        $novo = bin2hex(random_bytes(16));
        executar('INSERT OR REPLACE INTO trava (uf, token, nome, desde, expira) VALUES (?, ?, ?, ?, ?)', [$uf, $novo, $nome, $agora, $agora + $ttl]);
        registrar($uf, $nome, 'Assumiu o controle da tela');
        return ['ok' => true, 'token' => $novo];
    });
}

// Informa quem está com a tela e renova a trava se o token for do dono.
function trava_status(string $uf, string $token): array
{
    $t = linha('SELECT * FROM trava WHERE uf = ?', [$uf]);
    $agora = time();
    if (!$t || $t['expira'] <= $agora) return ['livre' => true, 'minha' => false];
    $minha = $token !== '' && hash_equals($t['token'], $token);
    if ($minha) executar('UPDATE trava SET expira = ? WHERE uf = ?', [$agora + (int) cfg('trava_ttl'), $uf]);
    return ['livre' => false, 'minha' => $minha, 'nome' => $t['nome'], 'desde' => (int) $t['desde'],
        'expira' => $minha ? $agora + (int) cfg('trava_ttl') : (int) $t['expira']];
}

function trava_exigir(string $uf, string $token): void
{
    $t = linha('SELECT * FROM trava WHERE uf = ?', [$uf]);
    if (!$t || $t['expira'] <= time() || $token === '' || !hash_equals($t['token'], $token)) {
        throw new ErroUsuario('Você não está com o controle desta tela. Recarregue a página.');
    }
}

function trava_liberar(string $uf, string $token): void
{
    $t = linha('SELECT * FROM trava WHERE uf = ?', [$uf]);
    if ($t && $token !== '' && hash_equals($t['token'], $token)) {
        executar('DELETE FROM trava WHERE uf = ?', [$uf]);
        registrar($uf, $t['nome'], 'Liberou a tela');
    }
}

/* ------------------------------------------------------------------ montagem dos arquivos */

function definir(array &$a, string $k, $v): void
{
    if (array_key_exists($k, $a)) $a[$k] = (string) $v;
}

// Grava $k logo depois de $ref, mantendo a ordem dos campos. Se $k já existir, só troca o valor.
function inserir_depois(array $a, string $ref, string $k, $v): array
{
    if (array_key_exists($k, $a) || !array_key_exists($ref, $a)) {
        $a[$k] = (string) $v;
        return $a;
    }
    $novo = [];
    foreach ($a as $chave => $x) {
        $novo[$chave] = $x;
        if ($chave === $ref) $novo[$k] = (string) $v;
    }
    return $novo;
}

function preencher(array $alvo, array $valores): array
{
    foreach ($valores as $k => $v) definir($alvo, $k, $v);
    return $alvo;
}

function datas(array $m, array $est, bool $temApuracao): array
{
    if ($est['quando'] === null) return [$m['dg'], $m['hg'], $m['dt'] ?? '', $m['ht'] ?? ''];
    $q = (int) $est['quando'];
    return [date('d/m/Y', $q), date('H:i:s', $q), $temApuracao ? date('d/m/Y', $q) : '', $temApuracao ? date('H:i:s', $q) : ''];
}

function montar_uf(string $uf, array $est): array
{
    $m = modelo($uf);
    $r = calcular($uf, $est);
    [$dg, $hg, $dt, $ht] = datas($m, $est, $r['st'] > 0);
    definir($m, 'dg', $dg);
    definir($m, 'hg', $hg);
    definir($m, 'idg', $est['idg']);
    definir($m, 'dt', $dt);
    definir($m, 'ht', $ht);
    definir($m, 'tf', $r['tf']);
    definir($m, 'and', $r['and']);
    // "md" = "e": eleição matematicamente definida (é o campo que o site lê para mostrar o aviso).
    // Os modelos do TSE não trazem o campo, então ele só entra no arquivo enquanto houver alguém "Matematicamente eleito".
    if (in_array('Matematicamente eleito', array_column($r['cand'], 'st'), true)) {
        $m = inserir_depois($m, 'and', 'md', 'e');
    }

    foreach ($m['carg'][0]['agr'] as &$agr) {
        $somaAgr = 0;
        foreach ($agr['par'] as &$par) {
            $somaPar = 0;
            foreach ($par['cand'] as &$c) {
                $x = $r['cand'][(string) $c['sqcand']];
                foreach (['vap', 'pvap', 'pvapn', 'e', 'st', 'seq'] as $k) definir($c, $k, $x[$k]);
                $somaPar += $x['vap'];
            }
            unset($c);
            definir($par, 'tvtn', $somaPar);
            definir($par, 'tvan', $somaPar);
            $somaAgr += $somaPar;
        }
        unset($par);
        definir($agr, 'tvtn', $somaAgr);
        definir($agr, 'tvan', $somaAgr);
    }
    unset($agr);

    $m['s'] = preencher($m['s'], $r['s']);
    $m['e'] = preencher($m['e'], $r['e']);
    $m['v'] = preencher($m['v'], $r['v']);
    return $m;
}

// Arquivo de acompanhamento por UF (mapa). BR e SP saem dos valores editados;
// as demais UFs acompanham o % de apuração e o comparecimento do Brasil.
function montar_ab(): array
{
    $m = modelo('ab');
    $eb = estado_atual('br');
    $es = estado_atual('sp');
    $rb = calcular('br', $eb);
    $rs = calcular('sp', $es);
    $p = $rb['ts'] > 0 ? $rb['st'] / $rb['ts'] : 0;
    $taxa = $rb['e']['esi'] > 0 ? $rb['v']['tv'] / $rb['e']['esi'] : 0;

    foreach ($m['abr'] as &$a) {
        $ts = (int) $a['s']['ts'];
        $te = (int) $a['e']['te'];
        $cd = $a['cdabr'];
        if ($cd === 'br' || $cd === 'sp') {
            $r = $cd === 'br' ? $rb : $rs;
            $est = $cd === 'br' ? $eb : $es;
            $st = min($ts, $r['st']);
            $sni = min($st, (int) $r['s']['sni']);
            $esni = (int) $r['e']['esni'];
            $c = $r['v']['tv'];
            $and = $r['and'];
        } else {
            $st = $p >= 1 ? $ts : (int) round($ts * $p);
            $sni = 0;
            $esni = 0;
            $c = (int) round(eleitores_totalizados($te, $ts, $st) * $taxa);
            $and = $st === 0 ? 'n' : ($st < $ts ? 'p' : 'f');
            $est = $eb;
        }
        $a['s'] = preencher($a['s'], calc_s($ts, $st, $sni));
        $a['e'] = preencher($a['e'], calc_e($te, $ts, $st, $esni, $c));
        definir($a, 'and', $and);
        [, , $dt, $ht] = datas($m, $est, $st > 0);
        definir($a, 'dt', $dt);
        definir($a, 'ht', $ht);

        if (isset($a['munnr'], $a['munpt'], $a['munf'])) {
            $tot = (int) $a['munnr'] + (int) $a['munpt'] + (int) $a['munf'];
            $q = $ts > 0 ? $st / $ts : 0;
            $f = $q >= 1 ? $tot : (int) floor($tot * $q * $q);
            $nr = $q <= 0 ? $tot : min($tot - $f, (int) round($tot * (1 - $q) ** 2));
            $pt = $tot - $f - $nr;
            foreach (['munnr' => $nr, 'munpt' => $pt, 'munf' => $f] as $k => $n) {
                definir($a, $k, $n);
                definir($a, 'p' . $k, pct2($n, $tot));
                definir($a, 'p' . $k . 'n', pctn($n, $tot));
            }
        }
    }
    unset($a);

    $quando = max((int) $eb['quando'], (int) $es['quando']);
    $ref = ['quando' => $quando ?: null];
    [$dg, $hg] = datas($m, $ref, true);
    definir($m, 'dg', $dg);
    definir($m, 'hg', $hg);
    definir($m, 'idg', (int) $m['idg'] + ($eb['idg'] - (int) modelo('br')['idg']) + ($es['idg'] - (int) modelo('sp')['idg']));
    return $m;
}

function gerar_arquivo(string $chave): string
{
    if ($chave === 'ab') return tse_json(montar_ab());
    return tse_json(montar_uf($chave, estado_atual($chave)));
}

/* ------------------------------------------------------------------ sessão */

function sessao_iniciar(): void
{
    session_name('simtse');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => dirname($_SERVER['SCRIPT_NAME'] ?? '/') ?: '/',
        'secure' => strpos(url_base(), 'https://') === 0,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function senha_configurada(): bool
{
    $s = (string) cfg('senha');
    return $s !== '' && $s !== 'troque-esta-senha';
}

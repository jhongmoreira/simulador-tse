<?php
// API JSON usada pela tela de edição.
require __DIR__ . '/lib/nucleo.php';
sessao_iniciar();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(array $dados, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($_SESSION['ok'])) responder(['erro' => 'Sessão expirada. Faça login novamente.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    responder(['erro' => 'Requisição inválida.'], 400);
}
session_write_close();

$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$acao = (string) ($in['acao'] ?? '');
$uf = (string) ($in['uf'] ?? '');
$token = (string) ($in['token'] ?? '');
$nome = (string) ($in['nome'] ?? '');
$nome = trim(function_exists('mb_substr') ? mb_substr($nome, 0, 40) : substr($nome, 0, 40)) ?: 'Sem nome';
if (!isset(UFS[$uf])) responder(['erro' => 'Abrangência inválida.'], 400);

function opcoes_sim(array $in): array
{
    return [
        'modo' => (string) ($in['modo'] ?? ''), 'virada_pct' => $in['virada_pct'] ?? 60, 'oscilacao' => (string) ($in['oscilacao'] ?? ''),
        'semente' => (int) ($in['semente'] ?? 0), 'inicio_pct' => is_array($in['inicio_pct'] ?? null) ? $in['inicio_pct'] : [],
    ];
}

function painel(string $uf, string $token): array
{
    $m = modelo($uf);
    $est = estado_atual($uf);
    $r = calcular($uf, $est);
    $sim = simulacao($uf);
    $cands = candidatos($m);
    [$dg, $hg] = datas($m, $est, $r['st'] > 0);

    $resumoCands = [];
    foreach ($cands as $c) $resumoCands[] = $c + $r['cand'][$c['sqcand']];
    usort($resumoCands, function ($a, $b) { return $b['vap'] <=> $a['vap'] ?: $a['seq'] <=> $b['seq']; });

    $simInfo = null;
    if ($sim) {
        $k = min($sim['passos'], intdiv(max(0, time() - (int) $sim['inicio']), (int) $sim['intervalo']));
        $simInfo = [
            'inicio' => (int) $sim['inicio'], 'fim' => (int) $sim['fim'], 'intervalo' => (int) $sim['intervalo'],
            'curva' => $sim['curva'], 'passo' => $k, 'passos' => $sim['passos'], 'criado_por' => $sim['criado_por'],
            'proxima' => (int) $sim['inicio'] + ($k + 1) * (int) $sim['intervalo'],
            'destino_pst' => calcular($uf, $sim['destino'])['s']['pst'],
            'modo' => $sim['destino']['param']['modo'], 'virada_pct' => $sim['destino']['param']['virada_pct'],
            'oscilacao' => $sim['destino']['param']['oscilacao'],
            'serie' => resumir_serie($uf, serie_simulacao($sim['origem'], $sim['destino'], $sim['destino']['param'], $sim['passos'])),
        ];
    }

    return [
        'uf' => $uf,
        'agora' => time(),
        'urls' => [
            'publica' => url_base() . '/' . caminho_relativo($uf),
            'tse' => rtrim((string) cfg('tse_base'), '/') . '/' . caminho_relativo($uf),
            'ab' => url_base() . '/' . caminho_relativo('ab'),
        ],
        'meta' => ['ts' => $r['ts'], 'te' => $r['te'], 'candidatos' => $cands,
            'modelo_atualizado' => (int) (linha('SELECT atualizado FROM modelo WHERE chave = ?', [$uf])['atualizado'] ?? 0)],
        'estado' => $est,
        'resumo' => [
            'st' => $r['st'], 'ts' => $r['ts'], 'pst' => $r['s']['pst'],
            'est' => $r['e']['est'], 'esi' => $r['e']['esi'], 'c' => $r['e']['c'], 'pc' => $r['e']['pc'],
            'a' => $r['e']['a'], 'pa' => $r['e']['pa'],
            'vv' => $r['v']['vv'], 'vb' => $r['v']['vb'], 'pvb' => $r['v']['pvb'],
            'tvn' => $r['v']['tvn'], 'ptvn' => $r['v']['ptvn'],
            'and' => $r['and'], 'tf' => $r['tf'], 'dg' => $dg, 'hg' => $hg, 'idg' => $est['idg'],
            'candidatos' => $resumoCands,
        ],
        'sim' => $simInfo,
        'trava' => trava_status($uf, $token),
        'historico' => historico($uf),
        'json' => gerar_arquivo($uf),
    ];
}

try {
    switch ($acao) {
        case 'status':
            responder(painel($uf, $token));

        case 'travar':
            $r = trava_obter($uf, $token, $nome);
            responder(['token' => $r['token'] ?? ''] + painel($uf, $r['token'] ?? ''));

        case 'liberar':
            trava_liberar($uf, $token);
            responder(['ok' => true]);

        case 'salvar':
            trava_exigir($uf, $token);
            if (simulacao($uf)) throw new ErroUsuario('Pare a simulação antes de editar os valores.');
            $e = sanitizar_estado($uf, $in['estado'] ?? null);
            transacao(function () use ($uf, $e, $nome) {
                $e['idg'] = estado_salvo($uf)['idg'] + 1;
                $e['quando'] = time();
                gravar_estado($uf, $e);
                registrar($uf, $nome, 'Publicou valores: ' . calcular($uf, $e)['s']['pst'] . '% apurado');
            });
            responder(painel($uf, $token));

        case 'zerar':
            trava_exigir($uf, $token);
            if (simulacao($uf)) throw new ErroUsuario('Pare a simulação antes de zerar.');
            transacao(function () use ($uf, $nome) {
                $e = estado_zero($uf);
                $e['idg'] = estado_salvo($uf)['idg'] + 1;
                $e['quando'] = time();
                gravar_estado($uf, $e);
                registrar($uf, $nome, 'Zerou todos os valores');
            });
            responder(painel($uf, $token));

        case 'sim_iniciar':
            trava_exigir($uf, $token);
            iniciar_simulacao($uf, $in['destino'] ?? null, $in['minutos'] ?? 0, $in['intervalo'] ?? 0, (string) ($in['curva'] ?? ''),
                opcoes_sim($in), $nome);
            responder(painel($uf, $token));

        case 'sim_previa':
            if (simulacao($uf)) responder(['previa' => null]);
            responder(['previa' => previa_simulacao($uf, $in['destino'] ?? null, $in['minutos'] ?? 0, $in['intervalo'] ?? 0,
                (string) ($in['curva'] ?? ''), opcoes_sim($in))]);

        case 'sim_parar':
            trava_exigir($uf, $token);
            parar_simulacao($uf, $nome);
            responder(painel($uf, $token));

        case 'modelo_tse':
            trava_exigir($uf, $token);
            atualizar_modelo_do_tse($uf);
            if ($uf === 'br') atualizar_modelo_do_tse('ab');
            registrar($uf, $nome, 'Atualizou o modelo a partir do TSE');
            responder(painel($uf, $token));
    }
    responder(['erro' => 'Ação desconhecida.'], 400);
} catch (ErroUsuario $e) {
    responder(['erro' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('simulador: ' . $e);
    responder(['erro' => 'Erro interno: ' . $e->getMessage()], 500);
}

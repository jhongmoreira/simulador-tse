<?php
require __DIR__ . '/lib/nucleo.php';
sessao_iniciar();

$uf = isset(UFS[$_GET['uf'] ?? '']) ? $_GET['uf'] : 'br';

if (isset($_GET['sair'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

$erro = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['senha']) && senha_configurada()) {
    if (hash_equals((string) cfg('senha'), (string) $_POST['senha'])) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        header('Location: ./?uf=' . $uf);
        exit;
    }
    sleep(1);
    $erro = 'Senha incorreta.';
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Simulador TSE · <?= h(UFS[$uf]) ?></title>
<link rel="stylesheet" href="assets/app.css?v=1">
</head>
<?php if (empty($_SESSION['ok'])): ?>
<body class="login">
  <form method="post" class="cartao login__caixa">
    <h1>Simulador de resultados</h1>
    <p class="mudo">2º turno · formato TSE</p>
    <?php if (!senha_configurada()): ?>
      <p class="aviso aviso--erro">Defina uma senha em <code>simulador/config.php</code> antes de usar.</p>
    <?php else: ?>
      <label>Senha <input type="password" name="senha" autofocus required></label>
      <?php if ($erro): ?><p class="aviso aviso--erro"><?= h($erro) ?></p><?php endif; ?>
      <button class="btn btn--primario">Entrar</button>
    <?php endif; ?>
  </form>
</body>
<?php else: ?>
<body data-uf="<?= h($uf) ?>">
<header class="topo">
  <div class="topo__marca">Simulador TSE <span class="mudo">2º turno</span></div>
  <nav class="abas">
    <?php foreach (UFS as $k => $nome): ?>
      <a href="?uf=<?= $k ?>" class="aba<?= $k === $uf ? ' aba--ativa' : '' ?>"><?= h($nome) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="topo__usuario">
    <button class="link" id="btn-nome" title="Alterar seu nome"></button>
    <a class="link" href="?sair=1">Sair</a>
  </div>
</header>

<div id="faixa-trava" class="faixa" hidden></div>

<main class="grade">
  <section class="cartao" id="publicado">
    <div class="cartao__cab">
      <h2>No ar agora</h2>
      <span class="mudo" id="pub-geracao"></span>
    </div>
    <div class="apuracao">
      <div class="apuracao__num"><span id="pub-pst">–</span><small>% apurado</small></div>
      <div class="barra"><div class="barra__fill" id="pub-barra"></div></div>
      <div class="mudo" id="pub-secoes"></div>
    </div>
    <div id="pub-cands" class="pub-cands"></div>
    <dl class="numeros" id="pub-numeros"></dl>
    <div class="links">
      <a id="link-publica" target="_blank" rel="noopener">Abrir JSON publicado</a>
      <a id="link-tse" target="_blank" rel="noopener">Original do TSE</a>
      <a id="link-ab" target="_blank" rel="noopener">Acompanhamento (mapa)</a>
    </div>
  </section>

  <section class="cartao" id="editar">
    <div class="cartao__cab">
      <h2>Editar valores</h2>
      <button class="btn btn--leve" data-assistente="atual">Preencher por %</button>
    </div>
    <p class="aviso" id="aviso-sim-edicao" hidden>Há uma simulação em andamento. Pare a simulação para editar manualmente.</p>
    <form id="form-atual" class="formulario" autocomplete="off"></form>
    <div class="acoes">
      <button class="btn btn--primario" id="btn-salvar">Publicar alterações</button>
      <button class="btn" id="btn-descartar">Descartar</button>
      <span class="espaco"></span>
      <button class="btn btn--perigo" id="btn-zerar">Zerar tudo</button>
    </div>
  </section>

  <section class="cartao" id="simulacao">
    <div class="cartao__cab">
      <h2>Simulação automática</h2>
      <button class="btn btn--leve" data-assistente="destino" id="btn-assist-destino">Preencher por %</button>
    </div>
    <div id="sim-andamento" hidden>
      <div class="sim-progresso">
        <div class="barra barra--grossa"><div class="barra__fill" id="sim-barra"></div></div>
        <div class="sim-progresso__info" id="sim-info"></div>
      </div>
      <div class="grafico" id="grafico-sim"></div>
      <div class="acoes"><button class="btn btn--perigo" id="btn-parar">Parar simulação</button></div>
    </div>
    <div id="sim-config">
      <p class="mudo">Os valores saem de onde estão agora e chegam a estes números no fim do período, mudando a cada intervalo. Os percentuais oscilam como numa apuração real e terminam exatamente nos votos definidos. A simulação roda no servidor e continua mesmo se você fechar esta tela.</p>
      <form id="form-destino" class="formulario" autocomplete="off"></form>
      <fieldset id="sim-inicio" class="sim-inicio"></fieldset>
      <div class="linha-campos">
        <label>Duração (min)<input type="number" id="sim-minutos" min="1" max="720" step="1" value="30"></label>
        <label>Atualizar a cada (s)<input type="number" id="sim-intervalo" min="5" max="900" step="5" value="30"></label>
        <label>Ritmo
          <select id="sim-curva">
            <option value="realista">Realista (rápido no início)</option>
            <option value="linear">Constante</option>
          </select>
        </label>
      </div>
      <p class="mudo" id="sim-previa"></p>
      <div class="acoes">
        <button class="btn btn--primario" id="btn-iniciar">Iniciar simulação</button>
        <button class="btn" id="btn-projetar">Projetar a partir dos valores atuais</button>
      </div>
    </div>
  </section>

  <section class="cartao" id="extras">
    <details>
      <summary>JSON publicado</summary>
      <pre id="json-previa" class="json"></pre>
    </details>
    <details>
      <summary>Histórico</summary>
      <ul id="historico" class="historico"></ul>
    </details>
    <details>
      <summary>Modelo do TSE</summary>
      <p class="mudo">A estrutura do JSON (campos, nomes, candidatos) vem de uma cópia do arquivo real do TSE. Se o TSE mudar algo, atualize o modelo; os valores simulados são mantidos.</p>
      <p class="mudo" id="modelo-data"></p>
      <button class="btn" id="btn-modelo">Atualizar modelo a partir do TSE</button>
    </details>
  </section>
</main>

<dialog id="dlg-nome" class="dialogo">
  <form method="dialog">
    <h3>Como você se chama?</h3>
    <p class="mudo">Aparece para os outros quando você estiver com o controle da tela.</p>
    <input id="inp-nome" maxlength="40" required>
    <div class="acoes"><button class="btn btn--primario">Continuar</button></div>
  </form>
</dialog>

<dialog id="dlg-assistente" class="dialogo">
  <form method="dialog" id="form-assistente">
    <h3>Preencher por percentuais</h3>
    <div class="linha-campos">
      <label>% apurado<input type="number" name="apur" min="0" max="100" step="0.01"></label>
      <label>% comparecimento<input type="number" name="comp" min="0" max="100" step="0.01"></label>
    </div>
    <div class="linha-campos">
      <label><span>% brancos <small>(do total de votos)</small></span><input type="number" name="brancos" min="0" max="100" step="0.01"></label>
      <label><span>% nulos <small>(do total de votos)</small></span><input type="number" name="nulos" min="0" max="100" step="0.01"></label>
    </div>
    <div id="assist-cands"></div>
    <p class="mudo" id="assist-previa"></p>
    <div class="acoes">
      <button class="btn btn--primario" value="aplicar">Aplicar</button>
      <button class="btn" value="cancelar" formnovalidate>Cancelar</button>
    </div>
  </form>
</dialog>

<div id="toast" class="toast" hidden></div>
<script src="assets/app.js?v=1"></script>
</body>
<?php endif; ?>
</html>

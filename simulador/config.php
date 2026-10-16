<?php
// Configuração do simulador. Edite antes de subir para o servidor.
return [
    // Senha de acesso à tela de edição. Enquanto estiver com o valor padrão, o login fica bloqueado.
    'senha' => 'troque-esta-senha',

    'fuso' => 'America/Sao_Paulo',

    // Partes do caminho: /{ambiente}/{ciclo}/{eleicao}/dados/...
    'ambiente' => 'oficial',
    'ciclo'    => 'ele2026',
    'eleicao'  => '6258',

    // Para onde redirecionar os arquivos que o simulador não gera (1º turno, fotos, municípios...).
    'tse_base' => 'https://resultados.tse.jus.br',

    // Segundos sem sinal do navegador até a trava da tela expirar sozinha.
    'trava_ttl' => 120,
];

# Simulador de resultados TSE (2º turno)

Gera, em `jgmoreira.com.br`, os mesmos arquivos JSON do TSE (mesmos campos, mesma ordem e mesma formatação), com valores que você edita numa tela ou que uma simulação automática vai alterando ao longo do tempo.

## O que sobe para o servidor

Copie as duas pastas para a raiz do site (normalmente `public_html/`):

```
public_html/
  oficial/.htaccess      -> manda /oficial/... para o simulador
  simulador/             -> tela, API e banco SQLite
```

Requisitos: PHP 7.4 ou mais novo com `pdo_sqlite`, e Apache ou LiteSpeed com `mod_rewrite` (o padrão de quase toda hospedagem PHP).

1. Edite `simulador/config.php` e troque a `senha`. Enquanto ela estiver com o valor padrão, o login fica bloqueado.
2. Garanta que o PHP pode escrever em `simulador/dados/`, porque é ali que o banco `simulador.sqlite` é criado sozinho. Se der erro, use `chmod 775 simulador/dados`.
3. Abra `https://jgmoreira.com.br/oficial/ele2026/6258/dados/br/br-c0001-e006258-u.json`. Enquanto nada for editado, o conteúdo é idêntico ao do TSE.

## Endereços

| O quê | URL |
|---|---|
| Tela de edição | `https://jgmoreira.com.br/simulador/` |
| Presidente, Brasil | `/oficial/ele2026/6258/dados/br/br-c0001-e006258-u.json` |
| Presidente, São Paulo | `/oficial/ele2026/6258/dados/sp/sp-c0001-e006258-u.json` |
| Acompanhamento por UF (mapa) | `/oficial/ele2026/6258/dados/br/br-e006258-ab.json` |

Qualquer outro arquivo pedido em `/oficial/...` (1º turno, fotos etc.) é redirecionado para o TSE. Assim o site funciona inteiro trocando só a `baseUrl`.

O parâmetro `?_t=...` que o site adiciona é aceito normalmente. As respostas levam `Access-Control-Allow-Origin`, para o `eleicoesaovivo.com` conseguir ler.

## Apontar o site para o simulador

No `data/config.json` do site:

```json
"baseUrl": "https://jgmoreira.com.br",
...
"turno2": {
  "codigo": "6258",
  "codigos": { "presidente": "6258", "governador": "6259" },
```

**Atenção:** hoje o `turno2` está com `6257`, que é a eleição do **1º turno**. Mesmo sem o simulador, a página de 2º turno está buscando os dados do 1º turno. O 2º turno de presidente é `6258`. Depois do teste, volte a `baseUrl` para `https://resultados.tse.jus.br` e mantenha o `6258`.

## Como a tela funciona

- **Editar valores:** você informa o % apurado (ou o número de seções), os votos de cada candidato, os brancos e os nulos. Todo o resto é calculado com as mesmas regras do TSE: percentuais, comparecimento, abstenção, válidos, ordem (`seq`), `and`, `tf`, `dg`/`hg` e `idg`. "Publicar" coloca no ar na hora.
- **Preencher por %:** você digita percentuais (apuração, comparecimento, brancos, nulos e % de cada candidato) e a tela converte em votos.
- **Situação do candidato:** em "Automático", o líder vira *Eleito* quando já não pode mais ser alcançado, como o TSE faz antes de 100%. Também dá para forçar Eleito, Matematicamente eleito ou Não eleito.
- **Matematicamente eleito:** logo abaixo dos candidatos, escolha como o modo "Automático" marca quem já não pode ser alcançado. Com "Matematicamente eleito", o JSON sai com `"e" : "s"` e `"st" : "Matematicamente eleito"` no candidato, mais `"md" : "e"` na raiz (é esse campo que faz o site mostrar "Eleição matematicamente definida"), até a totalização final (100% ou `tf = s`), quando passa a `"Eleito"`. A escolha vale para a tela publicada e também para as simulações iniciadas depois. Para mudar durante uma simulação, pare a simulação, troque a opção e publique.
- **Simulação automática:** você define onde quer chegar (por exemplo 100% apurado, com tantos votos), a duração (por exemplo 30 min) e o intervalo entre atualizações (por exemplo 30 s). Os números partem do que está no ar e chegam ao destino no fim do período. O % de cada candidato também muda ao longo da apuração: partindo do zero, você define o % com que cada um começa. O padrão inverte as posições, o que gera uma virada. Se já houver votos publicados, a simulação parte do % que está no ar. O % inicial pode ser no máximo o dobro do final, senão os votos do candidato teriam que diminuir. A simulação roda no servidor, calculada pelo horário, e **continua mesmo com a tela fechada**. "Parar" congela os valores onde estiverem.
- **Trava:** só uma pessoa por vez controla cada tela (Brasil e São Paulo têm travas separadas). Quem abrir depois vê tudo ao vivo em modo leitura, com o nome de quem está usando. A trava é liberada ao fechar a aba, no botão "Liberar tela" ou, se o navegador sumir, depois de 2 minutos sem sinal (`trava_ttl` no config).
- **Modelo do TSE:** a estrutura do JSON vem de uma cópia do arquivo real, que fica em `simulador/modelos/`. Se o TSE mudar algo até o dia, use "Atualizar modelo a partir do TSE". Os valores simulados são mantidos.

## Observações

- No mapa (`-ab.json`), BR e SP usam os valores editados. As demais UFs seguem o % de apuração e o comparecimento do Brasil.
- O código de andamento parcial usado é `and = "p"`. Os valores `n` e `f` foram confirmados nos arquivos do TSE, mas `p` não deu para confirmar, porque não há apuração parcial publicada agora. Seu site não usa esse campo. Se precisar, ele pode ser trocado em "Avançado".
- Votos anulados (`van`, `vansj`) ficam em 0.
- Servidor nginx (sem `.htaccess`): use `location ^~ /oficial/ { try_files $uri /simulador/endpoint.php?$args; }` e bloqueie `/simulador/(lib|dados|modelos)/`. Sem rewrite, dá para testar com `/simulador/endpoint.php?f=br` (ou `sp`, `ab`).

'use strict';

const UF = document.body.dataset.uf;
const CHAVE_TOKEN = 'simtse-token-' + UF;
const POLL_MS = 3000;

const S = {
  token: lerStorage(sessionStorage, CHAVE_TOKEN) || '',
  nome: lerStorage(localStorage, 'simtse-nome') || '',
  dados: null,
  montado: false,
  sujo: { atual: false, destino: false, inicio: false },
  liberouManual: false,
  ocupado: false,
  assistenteAlvo: null,
};

const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];
const fmt = (n) => Number(n || 0).toLocaleString('pt-BR');
const fmtPct = (x) => Number(x || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const hora = (t) => new Date(t * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const horaCurta = (t) => new Date(t * 1000).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function lerStorage(st, k) { try { return st.getItem(k); } catch { return null; } }
function gravarStorage(st, k, v) { try { st.setItem(k, v); } catch { /* sem storage */ } }

/* ------------------------------------------------------------ API */

async function api(acao, extra = {}, opcoes = {}) {
  const r = await fetch('acao.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ acao, uf: UF, token: S.token, nome: S.nome, ...extra }),
    keepalive: !!opcoes.keepalive,
  });
  if (r.status === 401) { location.reload(); throw new Error('Sessão expirada'); }
  const j = await r.json().catch(() => ({ erro: `Resposta inválida do servidor (HTTP ${r.status}).` }));
  if (j.erro) throw new Error(j.erro);
  return j;
}

async function executar(acao, extra, msgOk) {
  if (S.ocupado) return;
  S.ocupado = true;
  try {
    const d = await api(acao, extra);
    if (msgOk) toast(msgOk);
    return d;
  } catch (e) {
    toast(e.message, true);
  } finally {
    S.ocupado = false;
  }
}

/* ------------------------------------------------------------ cálculos (espelham o servidor) */

function totais(e) {
  const { ts, te } = S.dados.meta;
  const st = Math.max(0, Math.min(e.st, ts));
  const sni = Math.max(0, Math.min(e.sni, st));
  const est = ts <= 0 ? 0 : st >= ts ? te : Math.round(te * st / ts);
  const esni = sni > 0 ? Math.max(0, Math.min(e.esni || 0, est)) : 0;
  const esi = est - esni;
  const vv = Object.values(e.vap).reduce((a, b) => a + b, 0);
  const tvn = e.vn + e.vnt;
  const tv = vv + e.vb + tvn;
  return { st, est, esi, vv, tvn, tv };
}

// Gera votos a partir de percentuais.
function porPercentuais(p, base) {
  const { ts, te, candidatos } = S.dados.meta;
  const st = Math.round(ts * Math.min(100, Math.max(0, p.apur)) / 100);
  const e = { ...base, st, vap: {} };
  const { esi } = totais({ ...e, vap: {}, vb: 0, vn: 0, vnt: 0 });
  const c = Math.round(esi * p.comp / 100);
  e.vb = Math.round(c * p.brancos / 100);
  e.vn = Math.max(0, Math.round(c * p.nulos / 100) - (base.vnt || 0));
  const vv = Math.max(0, c - e.vb - e.vn - (base.vnt || 0));
  let soma = 0;
  candidatos.forEach((cand, i) => {
    const v = i === candidatos.length - 1 ? vv - soma : Math.round(vv * (p.cands[cand.sqcand] || 0) / 100);
    e.vap[cand.sqcand] = Math.max(0, v);
    soma += e.vap[cand.sqcand];
  });
  return e;
}

// Percentuais que descrevem um estado (para pré-preencher o assistente).
function percentuaisDe(e) {
  const t = totais(e);
  const { ts, candidatos } = S.dados.meta;
  const p = {
    apur: ts ? e.st / ts * 100 : 0,
    comp: t.tv && t.esi ? t.tv / t.esi * 100 : 79,
    brancos: t.tv ? e.vb / t.tv * 100 : 1.8,
    nulos: t.tv ? t.tvn / t.tv * 100 : 3.2,
    cands: {},
  };
  // sem votos ainda: padrão 52% x 48% (um empate impediria a virada)
  candidatos.forEach((c, i) => { p.cands[c.sqcand] = t.vv ? e.vap[c.sqcand] / t.vv * 100 : candidatos.length === 2 ? (i === 0 ? 52 : 48) : 100 / candidatos.length; });
  return p;
}

/* ------------------------------------------------------------ formulários */

function montarFormulario(form, tipo) {
  const { candidatos, ts } = S.dados.meta;
  const atual = tipo === 'atual';
  form.innerHTML = `
    <fieldset>
      <div class="linha-campos">
        <label>% apurado<input type="number" data-c="pct" min="0" max="100" step="0.01"></label>
        <label><span>Seções totalizadas <small>de ${fmt(ts)}</small></span><input type="number" data-c="st" min="0" max="${ts}" step="1"></label>
      </div>
      <input type="range" data-c="faixa" min="0" max="10000" step="1" class="faixa-pct" aria-label="% apurado">
      <div class="cands">
        ${candidatos.map((c) => `
          <div class="cand">
            <div class="cand__nome"><strong>${esc(c.nmu)}</strong><span class="mudo">${esc(c.n)} · ${esc(c.partido)}${c.vice ? ' · vice ' + esc(c.vice) : ''}</span></div>
            <label class="cand__votos">Votos<input type="number" min="0" step="1" data-c="vap" data-sq="${c.sqcand}"></label>
            <output class="cand__pct" data-pct="${c.sqcand}">–</output>
            ${atual ? `<label class="cand__sit">Situação
              <select data-c="eleito" data-sq="${c.sqcand}">
                <option value="auto">Automático</option><option value="s">Eleito</option><option value="m">Matematicamente eleito</option><option value="n">Não eleito</option>
              </select></label>` : ''}
          </div>`).join('')}
      </div>
      ${atual ? `
      <label class="cand__sit">Em "Automático", quem já não pode ser alcançado aparece como
        <select data-c="matematico">
          <option value="n">Eleito</option><option value="s">Matematicamente eleito (vira Eleito na totalização final)</option>
        </select></label>` : ''}
      <div class="linha-campos">
        <label>Brancos<input type="number" min="0" step="1" data-c="vb"></label>
        <label>Nulos<input type="number" min="0" step="1" data-c="vn"></label>
      </div>
      ${atual ? `
      <details class="avancado">
        <summary>Avançado</summary>
        <div class="linha-campos">
          <label>Nulos técnicos<input type="number" min="0" step="1" data-c="vnt"></label>
          <label>Seções não instaladas<input type="number" min="0" step="1" data-c="sni"></label>
          <label>Eleitores nessas seções<input type="number" min="0" step="1" data-c="esni"></label>
        </div>
        <div class="linha-campos">
          <label>Andamento (<code>and</code>)
            <select data-c="and"><option value="auto">Automático</option><option value="n">n · não iniciada</option><option value="p">p · em andamento</option><option value="f">f · finalizada</option></select>
          </label>
          <label>Totalização final (<code>tf</code>)
            <select data-c="tf"><option value="auto">Automático</option><option value="n">n · não</option><option value="s">s · sim</option></select>
          </label>
        </div>
        <p class="mudo">Em "Automático", o candidato aparece como eleito quando já não pode mais ser alcançado, e a totalização fecha em 100% das seções.</p>
      </details>` : ''}
      <p class="calculo" data-calculo></p>
    </fieldset>`;

  const campo = (c) => $(`[data-c="${c}"]`, form);
  campo('pct').addEventListener('input', () => {
    const p = parseFloat(campo('pct').value) || 0;
    campo('st').value = Math.round(ts * Math.min(100, Math.max(0, p)) / 100);
    campo('faixa').value = Math.round(p * 100);
  });
  campo('st').addEventListener('input', () => {
    const st = parseInt(campo('st').value, 10) || 0;
    campo('pct').value = (ts ? st / ts * 100 : 0).toFixed(2);
    campo('faixa').value = Math.round(ts ? st / ts * 10000 : 0);
  });
  campo('faixa').addEventListener('input', () => {
    const p = campo('faixa').value / 100;
    campo('pct').value = p.toFixed(2);
    campo('st').value = Math.round(ts * p / 100);
  });
  form.addEventListener('input', () => { S.sujo[tipo] = true; calcularFormulario(form, tipo); });
  form.addEventListener('submit', (ev) => ev.preventDefault());
}

function lerFormulario(form, tipo) {
  const int = (el) => Math.max(0, parseInt(el?.value, 10) || 0);
  const base = S.dados.estado;
  const e = {
    st: int($('[data-c="st"]', form)), vb: int($('[data-c="vb"]', form)), vn: int($('[data-c="vn"]', form)),
    vnt: tipo === 'atual' ? int($('[data-c="vnt"]', form)) : base.vnt,
    sni: tipo === 'atual' ? int($('[data-c="sni"]', form)) : base.sni,
    esni: tipo === 'atual' ? int($('[data-c="esni"]', form)) : base.esni,
    vap: {}, eleito: {}, matematico: !!base.matematico, and: 'auto', tf: 'auto',
  };
  $$('[data-c="vap"]', form).forEach((el) => { e.vap[el.dataset.sq] = int(el); });
  if (tipo === 'atual') {
    $$('[data-c="eleito"]', form).forEach((el) => { if (el.value !== 'auto') e.eleito[el.dataset.sq] = el.value; });
    e.matematico = $('[data-c="matematico"]', form).value === 's';
    e.and = $('[data-c="and"]', form).value;
    e.tf = $('[data-c="tf"]', form).value;
  }
  return e;
}

function preencherFormulario(form, tipo, e) {
  const { ts } = S.dados.meta;
  const set = (c, v) => { const el = $(`[data-c="${c}"]`, form); if (el) el.value = v; };
  set('st', e.st);
  set('pct', (ts ? e.st / ts * 100 : 0).toFixed(2));
  set('faixa', Math.round(ts ? e.st / ts * 10000 : 0));
  set('vb', e.vb); set('vn', e.vn); set('vnt', e.vnt); set('sni', e.sni); set('esni', e.esni || 0);
  set('and', e.and || 'auto'); set('tf', e.tf || 'auto'); set('matematico', e.matematico ? 's' : 'n');
  $$('[data-c="vap"]', form).forEach((el) => { el.value = e.vap[el.dataset.sq] || 0; });
  $$('[data-c="eleito"]', form).forEach((el) => { el.value = (e.eleito && e.eleito[el.dataset.sq]) || 'auto'; });
  S.sujo[tipo] = false;
  calcularFormulario(form, tipo);
}

function calcularFormulario(form, tipo) {
  const e = lerFormulario(form, tipo);
  const t = totais(e);
  $$('[data-pct]', form).forEach((el) => {
    el.textContent = t.vv ? fmtPct(e.vap[el.dataset.pct] / t.vv * 100) + '%' : '–';
  });
  const out = $('[data-calculo]', form);
  const comp = t.esi ? t.tv / t.esi * 100 : 0;
  const excede = t.tv > t.esi;
  out.classList.toggle('calculo--erro', excede);
  out.innerHTML = excede
    ? `Total de votos (${fmt(t.tv)}) passa do número de eleitores das seções apuradas (${fmt(t.esi)}). Aumente o % apurado ou reduza os votos.`
    : `Total de votos <strong>${fmt(t.tv)}</strong> · válidos ${fmt(t.vv)} · comparecimento ${fmtPct(comp)}% de ${fmt(t.esi)} eleitores das seções apuradas`;
  if (tipo === 'destino') { previaSimulacao(); atualizarInicio(); }
  return !excede;
}

/* ------------------------------------------------------------ assistente por % */

function abrirAssistente(alvo) {
  S.assistenteAlvo = alvo;
  const form = $('#form-' + alvo);
  const p = percentuaisDe(lerFormulario(form, alvo));
  if (alvo === 'destino' && p.apur === 0) p.apur = 100;
  const f = $('#form-assistente');
  f.apur.value = p.apur.toFixed(2);
  f.comp.value = p.comp.toFixed(2);
  f.brancos.value = p.brancos.toFixed(2);
  f.nulos.value = p.nulos.toFixed(2);
  const cands = S.dados.meta.candidatos;
  $('#assist-cands').innerHTML = `<div class="linha-campos">${cands.map((c, i) => `
    <label><span>% ${esc(c.nmu)} <small>(dos válidos)</small></span>
      <input type="number" min="0" max="100" step="0.01" data-sq="${c.sqcand}" value="${p.cands[c.sqcand].toFixed(2)}" ${i === cands.length - 1 ? 'readonly tabindex="-1"' : ''}>
    </label>`).join('')}</div>`;
  previaAssistente();
  $('#dlg-assistente').showModal();
}

function lerAssistente() {
  const f = $('#form-assistente');
  const n = (v) => Math.max(0, parseFloat(v) || 0);
  const p = { apur: n(f.apur.value), comp: n(f.comp.value), brancos: n(f.brancos.value), nulos: n(f.nulos.value), cands: {} };
  const inputs = $$('#assist-cands input');
  let soma = 0;
  inputs.forEach((el, i) => {
    if (i === inputs.length - 1) {
      el.value = Math.max(0, 100 - soma).toFixed(2);
    }
    p.cands[el.dataset.sq] = n(el.value);
    soma += n(el.value);
  });
  return p;
}

function previaAssistente() {
  const p = lerAssistente();
  const base = lerFormulario($('#form-' + S.assistenteAlvo), S.assistenteAlvo);
  const e = porPercentuais(p, base);
  const t = totais(e);
  const nomes = S.dados.meta.candidatos.map((c) => `${esc(c.nmu)}: ${fmt(e.vap[c.sqcand])}`).join(' · ');
  $('#assist-previa').innerHTML = `${fmt(e.st)} seções · ${fmt(t.tv)} votos · ${nomes} · brancos ${fmt(e.vb)} · nulos ${fmt(e.vn)}`;
}

/* ------------------------------------------------------------ renderização */

function render(d) {
  S.dados = d;
  if (!S.montado) {
    montarFormulario($('#form-atual'), 'atual');
    montarFormulario($('#form-destino'), 'destino');
    montarInicio();
    S.montado = true;
    preencherFormulario($('#form-atual'), 'atual', d.estado);
    projetarDestino();
  }
  const minha = d.trava.minha;
  const simAtiva = !!d.sim;

  renderTrava(d.trava);
  renderPublicado(d);

  if (!S.sujo.atual || simAtiva) preencherFormulario($('#form-atual'), 'atual', d.estado);

  $('#form-atual fieldset').disabled = !minha || simAtiva;
  $('#aviso-sim-edicao').hidden = !simAtiva;
  ['#btn-salvar', '#btn-descartar', '#btn-zerar', '#btn-modelo'].forEach((s) => { $(s).disabled = !minha || simAtiva; });
  $$('[data-assistente="atual"]').forEach((b) => { b.disabled = !minha || simAtiva; });

  $('#sim-andamento').hidden = !simAtiva;
  $('#sim-config').hidden = simAtiva;
  $('#btn-assist-destino').hidden = simAtiva;
  $('#form-destino fieldset').disabled = !minha;
  ['#btn-iniciar', '#btn-projetar', '#btn-assist-destino', '#sim-minutos', '#sim-intervalo', '#sim-curva'].forEach((s) => { $(s).disabled = !minha; });
  $('#btn-parar').disabled = !minha;
  $('#sim-inicio').disabled = !minha;
  atualizarInicio(false);
  if (!simAtiva && S.idgPrevia !== d.estado.idg) agendarPrevia(0);
  if (simAtiva) renderSimulacao(d);

  $('#json-previa').textContent = d.json;
  $('#historico').innerHTML = d.historico.map((h) =>
    `<li><time>${new Date(h.quando * 1000).toLocaleString('pt-BR')}</time> <strong>${esc(h.quem)}</strong> ${esc(h.acao)}</li>`).join('') || '<li class="mudo">Nada ainda.</li>';
  $('#modelo-data').textContent = d.meta.modelo_atualizado
    ? 'Modelo carregado em ' + new Date(d.meta.modelo_atualizado * 1000).toLocaleString('pt-BR') + '.' : '';
}

function renderTrava(t) {
  const el = $('#faixa-trava');
  el.hidden = false;
  el.className = 'faixa ' + (t.minha ? 'faixa--ok' : t.livre ? 'faixa--livre' : 'faixa--bloqueio');
  const ttlMin = Math.round(((t.expira || 0) - (S.dados.agora || 0)) / 60);
  if (t.minha) {
    el.innerHTML = `<span>Você está com o controle desta tela. Quem abrir agora vê tudo em modo leitura.</span>
      <button class="btn btn--leve" id="btn-liberar">Liberar tela</button>`;
    $('#btn-liberar').onclick = liberar;
  } else if (t.livre) {
    el.innerHTML = `<span>A tela está livre.</span><button class="btn btn--primario" id="btn-assumir">Assumir controle</button>`;
    $('#btn-assumir').onclick = () => { S.liberouManual = false; travar(); };
  } else {
    el.innerHTML = `<span><strong>${esc(t.nome)}</strong> está usando esta tela desde ${horaCurta(t.desde)}. Você está em modo leitura.
      A tela fica livre quando ${esc(t.nome)} sair${ttlMin > 0 ? ` ou ficar inativo (no máximo ~${ttlMin} min)` : ''}.</span>`;
  }
}

function renderPublicado(d) {
  const r = d.resumo;
  const pst = parseFloat(r.pst.replace(',', '.'));
  $('#pub-pst').textContent = r.pst;
  $('#pub-barra').style.width = pst + '%';
  $('#pub-secoes').textContent = `${fmt(r.st)} de ${fmt(r.ts)} seções totalizadas`;
  $('#pub-geracao').textContent = `gerado ${r.dg} ${r.hg} · idg ${r.idg}`;
  $('#pub-cands').innerHTML = r.candidatos.map((c, i) => {
    const p = parseFloat(c.pvap.replace(',', '.'));
    return `<div class="pub-cand${i === 0 && c.vap > 0 ? ' pub-cand--lider' : ''}">
      <div class="pub-cand__linha">
        <span class="pub-cand__nome">${esc(c.nmu)} <span class="mudo">${esc(c.partido)}</span>
          ${c.e === 's' ? `<span class="selo selo--eleito">${esc(c.st || 'Eleito')}</span>` : c.st ? `<span class="selo">${esc(c.st)}</span>` : ''}</span>
        <span class="pub-cand__pct">${c.pvap}%</span>
      </div>
      <div class="barra"><div class="barra__fill" style="width:${p}%"></div></div>
      <div class="mudo">${fmt(c.vap)} votos</div>
    </div>`;
  }).join('');
  const andamento = { n: 'não iniciada', p: 'em andamento', f: 'finalizada' }[r.and] || r.and;
  $('#pub-numeros').innerHTML = [
    ['Comparecimento', `${fmt(r.c)} <small>${r.pc}%</small>`],
    ['Abstenção', `${fmt(r.a)} <small>${r.pa}%</small>`],
    ['Válidos', fmt(r.vv)],
    ['Brancos', `${fmt(r.vb)} <small>${r.pvb}%</small>`],
    ['Nulos', `${fmt(r.tvn)} <small>${r.ptvn}%</small>`],
    ['Andamento', `${esc(r.and)} <small>${esc(andamento)}</small>`],
    ['Totalização final', r.tf === 's' ? 'sim' : 'não'],
  ].map(([k, v]) => `<div><dt>${k}</dt><dd>${v}</dd></div>`).join('');
  $('#link-publica').href = d.urls.publica;
  $('#link-tse').href = d.urls.tse;
  $('#link-ab').href = d.urls.ab;
}

function renderSimulacao(d) {
  const s = d.sim;
  const frac = s.passos ? s.passo / s.passos : 0;
  $('#sim-barra').style.width = (frac * 100) + '%';
  const falta = Math.max(0, s.proxima - d.agora);
  $('#sim-info').innerHTML = `
    <div><strong>${fmt(s.passo)} de ${fmt(s.passos)}</strong> atualizações · próxima em ${falta}s</div>
    <div class="mudo">Iniciada por ${esc(s.criado_por)} às ${horaCurta(s.inicio)} · termina às ${hora(s.fim)} · destino ${s.destino_pst}% apurado ·
      a cada ${s.intervalo}s · ritmo ${s.curva === 'realista' ? 'realista' : 'constante'} ·
      ${s.modo === 'virada' ? `virada em ~${fmtPct(s.virada_pct)}%` : s.modo === 'lidera' ? 'vencedor lidera' : '% inicial manual'} · oscilação ${esc(s.oscilacao)}</div>`;
  desenharGrafico($('#grafico-sim'), s.serie, { passo: s.passo });
}

function previaSimulacao() {
  if (!S.dados) return;
  const min = parseFloat($('#sim-minutos').value) || 0;
  const int = parseInt($('#sim-intervalo').value, 10) || 0;
  if (min <= 0 || int <= 0) { $('#sim-previa').textContent = ''; return; }
  const passos = Math.ceil(min * 60 / int);
  const fim = Date.now() / 1000 + min * 60;
  $('#sim-previa').textContent = `${fmt(passos)} atualizações, uma a cada ${int}s, terminando por volta de ${horaCurta(fim)}.`;
}

/* ------------------------------------------------------------ comportamento da simulação + prévia */

const partindoDoZero = () => S.dados.resumo.vv === 0;
S.semente = 1 + Math.floor(Math.random() * 2147483000);

function fatiasPct(vap) {
  const cands = S.dados.meta.candidatos;
  const vv = cands.reduce((a, c) => a + (vap[c.sqcand] || 0), 0);
  const r = {};
  cands.forEach((c) => { r[c.sqcand] = vv ? (vap[c.sqcand] || 0) / vv * 100 : 100 / cands.length; });
  return r;
}

function montarInicio() {
  const cands = S.dados.meta.candidatos;
  const dois = cands.length === 2;
  const el = $('#sim-inicio');
  el.innerHTML = `
    <p class="sim-inicio__titulo">Como os percentuais se comportam</p>
    <div class="linha-campos">
      <label>Comportamento
        <select data-op="modo">
          ${dois ? '<option value="virada">Com virada</option><option value="lidera">Vencedor lidera o tempo todo</option>' : ''}
          <option value="manual">Definir % inicial manualmente</option>
        </select>
      </label>
      <label data-so="virada"><span>Virada em torno de <small>(% apurado)</small></span>
        <input type="number" data-op="virada_pct" min="1" max="99" step="1" value="60"></label>
      <label>Oscilação
        <select data-op="oscilacao">
          <option value="leve">Leve</option><option value="forte">Forte</option><option value="nenhuma">Nenhuma (curva lisa)</option>
        </select>
      </label>
    </div>
    <div data-so="manual">
      <p class="mudo">% de cada candidato nas primeiras parciais (dos válidos). Antes da primeira atualização, todos ficam com 0 votos.</p>
      <div class="linha-campos">${cands.map((c, i) => `
        <label><span>${esc(c.nmu)}</span>
          <input type="number" min="0" max="100" step="0.01" data-ini data-sq="${c.sqcand}" ${i === cands.length - 1 ? 'readonly tabindex="-1"' : ''}>
        </label>`).join('')}</div>
    </div>
    <p class="mudo" data-op-info></p>
    <div class="grafico" id="grafico-previa"></div>
    <div class="acoes acoes--compacto">
      <button type="button" class="btn btn--leve" id="btn-sortear">Sortear outro caminho</button>
      <span class="mudo">Mesmos votos finais, oscilação diferente.</span>
    </div>`;
  el.oninput = (ev) => { if (ev.target.matches('[data-ini]')) S.sujo.inicio = true; atualizarInicio(); };
  el.onchange = el.oninput;
  $('#btn-sortear').onclick = () => { S.semente = 1 + Math.floor(Math.random() * 2147483000); agendarPrevia(0); };
}

function opcoesSim() {
  const r = { modo: $('[data-op="modo"]').value, virada_pct: parseFloat($('[data-op="virada_pct"]').value) || 0,
    oscilacao: $('[data-op="oscilacao"]').value, semente: S.semente, inicio_pct: {} };
  $$('[data-ini]').forEach((el) => { r.inicio_pct[el.dataset.sq] = Math.max(0, parseFloat(el.value) || 0); });
  return r;
}

function atualizarInicio(previa = true) {
  if (!$('[data-op="modo"]') || !S.dados) return;
  const cands = S.dados.meta.candidatos;
  const zero = partindoDoZero();
  const modoSel = $('[data-op="modo"]');
  const optManual = $('option[value="manual"]', modoSel);
  optManual.disabled = !zero && cands.length === 2;
  if (optManual.disabled && modoSel.value === 'manual') modoSel.value = 'virada';
  const modo = modoSel.value;
  $$('[data-so]').forEach((el) => { el.hidden = el.dataset.so !== modo || (modo === 'manual' && !zero); });

  const fim = fatiasPct(lerFormulario($('#form-destino'), 'destino').vap);
  const ins = $$('[data-ini]');
  if (!S.sujo.inicio) ins.forEach((el) => { el.value = (cands.length === 2 ? 100 - fim[el.dataset.sq] : fim[el.dataset.sq]).toFixed(2); });
  let soma = 0;
  ins.forEach((el, i) => {
    if (i === ins.length - 1) el.value = Math.max(0, 100 - soma).toFixed(2);
    soma += parseFloat(el.value) || 0;
  });

  const info = $('[data-op-info]');
  if (zero) {
    info.textContent = modo === 'virada' ? 'Quem perde no final começa na frente e é ultrapassado perto do ponto escolhido.'
      : modo === 'lidera' ? 'Quem vence no final já sai na frente e a vantagem vai se ajustando até o resultado.' : '';
  } else {
    const atual = fatiasPct(S.dados.estado.vap);
    info.textContent = 'Parte do que está no ar agora: ' + cands.map((c) => `${c.nmu} ${fmtPct(atual[c.sqcand])}%`).join(' × ') + '.';
  }
  if (previa) agendarPrevia();
}

function agendarPrevia(ms = 450) {
  clearTimeout(agendarPrevia.t);
  agendarPrevia.t = setTimeout(carregarPrevia, ms);
}

async function carregarPrevia() {
  if (!S.dados || S.dados.sim) return;
  const pedido = ++carregarPrevia.n;
  try {
    const d = await api('sim_previa', {
      destino: lerFormulario($('#form-destino'), 'destino'),
      minutos: parseFloat($('#sim-minutos').value), intervalo: parseInt($('#sim-intervalo').value, 10),
      curva: $('#sim-curva').value, ...opcoesSim(),
    });
    if (pedido !== carregarPrevia.n || !d.previa) return;
    S.previaErro = null;
    S.idgPrevia = S.dados.estado.idg;
    desenharGrafico($('#grafico-previa'), d.previa);
  } catch (e) {
    if (pedido !== carregarPrevia.n) return;
    S.previaErro = e.message;
    $('#grafico-previa').innerHTML = `<p class="calculo calculo--erro">${esc(e.message)}</p>`;
  }
}
carregarPrevia.n = 0;

/* ------------------------------------------------------------ gráfico: % dos válidos x % apurado */

const NS = 'http://www.w3.org/2000/svg';
function svg(tag, attrs, pai) {
  const el = document.createElementNS(NS, tag);
  Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
  if (pai) pai.appendChild(el);
  return el;
}

function desenharGrafico(cont, serie, opcoes = {}) {
  cont._serie = serie;
  cont._opcoes = opcoes;
  const cands = S.dados.meta.candidatos;
  const pts = serie.pontos.filter((p) => p.pct);
  cont.textContent = '';
  if (!pts.length) return;

  const legenda = document.createElement('div');
  legenda.className = 'grafico__legenda';
  cands.forEach((c, i) => {
    const item = document.createElement('span');
    const amostra = document.createElement('i');
    amostra.style.background = `var(--serie-${i + 1})`;
    item.append(amostra, document.createTextNode(c.nmu));
    legenda.appendChild(item);
  });
  cont.appendChild(legenda);

  const W = Math.max(280, cont.clientWidth || 600);
  const H = 230;
  const m = { l: 38, r: W < 480 ? 64 : 120, t: 18, b: 26 };
  const iw = W - m.l - m.r;
  const ih = H - m.t - m.b;
  let min = 50, max = 50;
  pts.forEach((p) => cands.forEach((c) => { min = Math.min(min, p.pct[c.sqcand]); max = Math.max(max, p.pct[c.sqcand]); }));
  min = Math.max(0, Math.floor(min - 1));
  max = Math.min(100, Math.ceil(max + 1));
  const x = (v) => m.l + v / 100 * iw;
  const y = (v) => m.t + (max - v) / (max - min) * ih;

  const s = svg('svg', { width: W, height: H, viewBox: `0 0 ${W} ${H}`, class: 'grafico__svg', role: 'img',
    'aria-label': 'Percentual dos votos válidos de cada candidato ao longo da apuração' }, cont);
  const passoY = (max - min) > 12 ? 5 : (max - min) > 6 ? 2 : 1;
  for (let v = Math.ceil(min / passoY) * passoY; v <= max; v += passoY) {
    svg('line', { x1: m.l, x2: m.l + iw, y1: y(v), y2: y(v), class: v === 50 ? 'g-meio' : 'g-grade' }, s);
    svg('text', { x: m.l - 6, y: y(v) + 4, class: 'g-eixo', 'text-anchor': 'end' }, s).textContent = v + '%';
  }
  [0, 25, 50, 75, 100].forEach((v) => {
    svg('text', { x: x(v), y: H - 8, class: 'g-eixo', 'text-anchor': v === 0 ? 'start' : v === 100 ? 'end' : 'middle' }, s)
      .textContent = v + '%' + (v === 100 ? ' apurado' : '');
  });

  const marcar = (pst, rotulo, classe) => {
    svg('line', { x1: x(pst), x2: x(pst), y1: m.t, y2: m.t + ih, class: classe }, s);
    const t = svg('text', { x: x(pst) + (pst > 85 ? -4 : 4), y: m.t - 5, class: 'g-nota', 'text-anchor': pst > 85 ? 'end' : 'start' }, s);
    t.textContent = rotulo;
  };
  const nome = (sq) => (cands.find((c) => c.sqcand === sq) || {}).nmu || '';
  if (serie.viradas.length) {
    const v = serie.viradas[0];
    marcar(v.pst, `virada ${fmtPct(v.pst)}%` + (serie.viradas.length > 1 ? ` (+${serie.viradas.length - 1} trocas)` : ''), 'g-marca');
  }
  if (serie.eleito) marcar(serie.eleito.pst, `eleito ${fmtPct(serie.eleito.pst)}%`, 'g-marca');

  const atual = opcoes.passo;
  cands.forEach((c, i) => {
    const caminho = (lista) => lista.map((p) => `${x(p.pst).toFixed(1)},${y(p.pct[c.sqcand]).toFixed(1)}`).join(' ');
    if (atual === undefined) {
      svg('polyline', { points: caminho(pts), class: 'g-linha', stroke: `var(--serie-${i + 1})` }, s);
    } else {
      const feito = pts.filter((p) => p.k <= atual);
      const falta = pts.filter((p) => p.k >= (feito.length ? feito[feito.length - 1].k : 0));
      svg('polyline', { points: caminho(falta), class: 'g-linha g-linha--futuro', stroke: `var(--serie-${i + 1})` }, s);
      if (feito.length) svg('polyline', { points: caminho(feito), class: 'g-linha', stroke: `var(--serie-${i + 1})` }, s);
    }
  });
  // rótulos diretos no fim das linhas (texto em tinta neutra; a cor fica no marcador)
  const ult = pts[pts.length - 1];
  const ys = cands.map((c) => ({ c, y: y(ult.pct[c.sqcand]) })).sort((a, b) => a.y - b.y);
  for (let i = 1; i < ys.length; i++) if (ys[i].y - ys[i - 1].y < 26) ys[i].y = ys[i - 1].y + 26;
  ys.forEach(({ c, y: yy }) => {
    const i = cands.indexOf(c);
    svg('circle', { cx: x(ult.pst), cy: y(ult.pct[c.sqcand]), r: 4, fill: `var(--serie-${i + 1})`, class: 'g-ponto' }, s);
    svg('text', { x: m.l + iw + 10, y: yy - 2, class: 'g-rotulo' }, s).textContent = W < 480 ? c.nmu.split(' ')[0] : c.nmu;
    svg('text', { x: m.l + iw + 10, y: yy + 12, class: 'g-valor' }, s).textContent = fmtPct(ult.pct[c.sqcand]) + '%';
  });
  if (atual !== undefined) {
    const agora = pts.filter((p) => p.k <= atual).pop();
    if (agora) svg('line', { x1: x(agora.pst), x2: x(agora.pst), y1: m.t, y2: m.t + ih, class: 'g-agora' }, s);
  }

  // crosshair + tooltip
  const mira = svg('line', { y1: m.t, y2: m.t + ih, class: 'g-mira', visibility: 'hidden' }, s);
  const dica = document.createElement('div');
  dica.className = 'grafico__dica';
  dica.hidden = true;
  cont.appendChild(dica);
  const area = svg('rect', { x: m.l, y: m.t, width: iw, height: ih, fill: 'transparent' }, s);
  area.addEventListener('pointermove', (ev) => {
    const r = s.getBoundingClientRect();
    const pst = (ev.clientX - r.left - m.l) / iw * 100;
    let p = pts[0];
    pts.forEach((q) => { if (Math.abs(q.pst - pst) < Math.abs(p.pst - pst)) p = q; });
    mira.setAttribute('x1', x(p.pst)); mira.setAttribute('x2', x(p.pst)); mira.setAttribute('visibility', 'visible');
    dica.textContent = '';
    const tit = document.createElement('div');
    tit.className = 'grafico__dica-tit';
    tit.textContent = `${fmtPct(p.pst)}% apurado`;
    dica.appendChild(tit);
    cands.map((c, i) => ({ c, i, v: p.pct[c.sqcand] })).sort((a, b) => b.v - a.v).forEach(({ c, i, v }) => {
      const l = document.createElement('div');
      const sw = document.createElement('i');
      sw.style.background = `var(--serie-${i + 1})`;
      const val = document.createElement('strong');
      val.textContent = fmtPct(v) + '%';
      l.append(sw, val, document.createTextNode(' ' + c.nmu));
      dica.appendChild(l);
    });
    dica.hidden = false;
    const px = x(p.pst);
    dica.style.left = Math.min(px + 12, W - 170) + 'px';
    dica.style.top = (m.t + legenda.offsetHeight + 8) + 'px';
  });
  area.addEventListener('pointerleave', () => { mira.setAttribute('visibility', 'hidden'); dica.hidden = true; });

  // visão em tabela
  const det = document.createElement('details');
  det.className = 'grafico__tabela';
  const sum = document.createElement('summary');
  sum.textContent = 'Ver em tabela';
  const tab = document.createElement('table');
  const cab = tab.createTHead().insertRow();
  ['% apurado', ...cands.map((c) => c.nmu)].forEach((t) => { const th = document.createElement('th'); th.textContent = t; cab.appendChild(th); });
  const corpo = tab.createTBody();
  [1, 5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 95, 99, 100].forEach((alvo) => {
    const p = pts.find((q) => q.pst >= alvo);
    if (!p || corpo.querySelector(`[data-k="${p.k}"]`)) return;
    const tr = corpo.insertRow();
    tr.dataset.k = p.k;
    tr.insertCell().textContent = fmtPct(p.pst) + '%';
    cands.forEach((c) => { tr.insertCell().textContent = fmtPct(p.pct[c.sqcand]) + '%'; });
  });
  det.append(sum, tab);
  cont.appendChild(det);
}

window.addEventListener('resize', () => {
  clearTimeout(desenharGrafico.t);
  desenharGrafico.t = setTimeout(() => {
    ['#grafico-previa', '#grafico-sim'].forEach((sel) => {
      const c = $(sel);
      if (c && c._serie && c.offsetParent) desenharGrafico(c, c._serie, c._opcoes);
    });
  }, 150);
});

// Destino padrão: mesmos percentuais de agora, com 100% apurado.
function projetarDestino() {
  const p = percentuaisDe(S.dados.estado);
  p.apur = 100;
  preencherFormulario($('#form-destino'), 'destino', porPercentuais(p, S.dados.estado));
}

/* ------------------------------------------------------------ trava e ciclo */

async function travar() {
  try {
    const d = await api('travar');
    if (d.token) { S.token = d.token; gravarStorage(sessionStorage, CHAVE_TOKEN, d.token); }
    render(d);
  } catch (e) { toast(e.message, true); }
}

async function liberar() {
  S.liberouManual = true;
  await api('liberar').catch(() => {});
  S.token = '';
  gravarStorage(sessionStorage, CHAVE_TOKEN, '');
  atualizar();
}

async function atualizar() {
  try {
    const d = await api('status');
    if (d.trava.livre && !S.liberouManual) return travar();
    render(d);
  } catch (e) { console.warn(e); }
}

function toast(msg, erro = false) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'toast' + (erro ? ' toast--erro' : '');
  t.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => { t.hidden = true; }, erro ? 7000 : 3500);
}

function pedirNome() {
  return new Promise((resolve) => {
    const dlg = $('#dlg-nome');
    $('#inp-nome').value = S.nome;
    dlg.onclose = () => {
      const n = $('#inp-nome').value.trim();
      if (n) { S.nome = n; gravarStorage(localStorage, 'simtse-nome', n); }
      $('#btn-nome').textContent = 'Você: ' + (S.nome || 'sem nome');
      resolve();
    };
    dlg.showModal();
  });
}

/* ------------------------------------------------------------ eventos */

$('#btn-salvar').onclick = async () => {
  const form = $('#form-atual');
  if (!calcularFormulario(form, 'atual')) return toast('Os números não fecham. Veja o aviso no formulário.', true);
  const d = await executar('salvar', { estado: lerFormulario(form, 'atual') }, 'Publicado.');
  if (d) { S.sujo.atual = false; render(d); }
};
$('#btn-descartar').onclick = () => preencherFormulario($('#form-atual'), 'atual', S.dados.estado);
$('#btn-zerar').onclick = async () => {
  if (!confirm('Zerar apuração e votos desta tela? Isso é publicado na hora.')) return;
  const d = await executar('zerar', {}, 'Valores zerados.');
  if (d) { S.sujo.atual = false; render(d); }
};
$('#btn-iniciar').onclick = async () => {
  const form = $('#form-destino');
  if (!calcularFormulario(form, 'destino')) return toast('Os números do destino não fecham.', true);
  if (S.previaErro) return toast(S.previaErro, true);
  if (S.sujo.atual && !confirm('Há alterações não publicadas em "Editar valores". A simulação parte do que está no ar agora. Continuar?')) return;
  const d = await executar('sim_iniciar', {
    destino: lerFormulario(form, 'destino'),
    minutos: parseFloat($('#sim-minutos').value),
    intervalo: parseInt($('#sim-intervalo').value, 10),
    curva: $('#sim-curva').value,
    ...opcoesSim(),
  }, 'Simulação iniciada.');
  if (d) { S.sujo.atual = false; render(d); }
};
$('#btn-parar').onclick = async () => {
  if (!confirm('Parar a simulação? Os valores ficam congelados onde estão.')) return;
  const d = await executar('sim_parar', {}, 'Simulação parada.');
  if (d) { render(d); S.sujo.inicio = false; projetarDestino(); }
};
$('#btn-projetar').onclick = projetarDestino;
$('#btn-modelo').onclick = async () => {
  if (!confirm('Baixar o arquivo atual do TSE e usar como modelo?')) return;
  const d = await executar('modelo_tse', {}, 'Modelo atualizado.');
  if (d) { S.montado = false; render(d); }
};
$$('[data-assistente]').forEach((b) => { b.onclick = () => abrirAssistente(b.dataset.assistente); });
$('#form-assistente').addEventListener('input', previaAssistente);
$('#dlg-assistente').addEventListener('close', () => {
  if ($('#dlg-assistente').returnValue !== 'aplicar') return;
  const alvo = S.assistenteAlvo;
  const form = $('#form-' + alvo);
  const e = porPercentuais(lerAssistente(), lerFormulario(form, alvo));
  const eleito = lerFormulario(form, alvo);
  preencherFormulario(form, alvo, { ...e, eleito: eleito.eleito, matematico: eleito.matematico, and: eleito.and, tf: eleito.tf });
  S.sujo[alvo] = true;
});
['#sim-minutos', '#sim-intervalo', '#sim-curva'].forEach((s) => $(s).addEventListener('input', () => { previaSimulacao(); agendarPrevia(); }));
$('#btn-nome').onclick = () => pedirNome().then(() => { if (S.dados?.trava.minha) travar(); });

// Ao sair da página, libera a tela para outra pessoa.
window.addEventListener('pagehide', () => {
  if (S.token && S.dados?.trava.minha) api('liberar', {}, { keepalive: true }).catch(() => {});
});
window.addEventListener('pageshow', (ev) => { if (ev.persisted) { S.liberouManual = false; travar(); } });

(async function iniciar() {
  if (!S.nome) await pedirNome();
  $('#btn-nome').textContent = 'Você: ' + (S.nome || 'sem nome');
  await travar();
  previaSimulacao();
  setInterval(atualizar, POLL_MS);
})();

// ── TASK-0029: alternativa B, dos grupos (capacidades / experiencias) ─────────────────────────────
// TASK-0030 (UAT 6919847012): dos VISTAS excluyentes, «Solo capacidades» (por defecto) y «Empresas con experiencias
// relacionadas». Cada vista muestra solo sus tarjetas; la otra queda oculta (atributo hidden), sin desplegable debajo.
// Se activa solo si el Worker devolvió `grupo` (search_empresas con grupos:true); si no, el formato de siempre.
// Todo texto que viene de datos pasa por txt() (escapado y en una línea: CIRA Test convierte cada \n en <br>).
//
// Empresas con ambas evidencias: el Worker las pone una sola vez, en el grupo de capacidades, con sus
// `experiencias_relacionadas`. En la vista de experiencias aparecen también, aparte y al final, SOLO con sus
// experiencias que coinciden (sin capacidades, sin puntaje de capacidad y sin atribuir rol); en ninguna vista se
// repite una empresa.
var GROUPED = rows.some(function (r) { return r && (r.grupo === 'capacidad' || r.grupo === 'experiencia'); });
function txt(s) { return esc(String(s == null ? '' : s).replace(/\s+/g, ' ').trim()); }
function oneLineExplain(ex) {
  if (!ex || !ex.fuentes) return ex;
  return Object.assign({}, ex, { fuentes: ex.fuentes.map(function (f) {
    return Object.assign({}, f, { detalle: String(f.detalle == null ? '' : f.detalle).replace(/\s+/g, ' ').trim() });
  }) });
}
var ORIGEN = {
  self_declared: { text: 'declarada por la empresa', color: '#1a7f37' },
  validated:     { text: 'validada por la C&aacute;mara', color: '#1a7f37' },
  suggested:     { text: 'sugerida por el sistema, no confirmada por la empresa', color: '#9a6700' },
  perfil:        { text: 'dato del perfil de la empresa', color: '#57606a' }
};
var CAP_TIPO = {
  categoria_cpv: 'categor&iacute;a CPV', servicio: 'servicio', sector: 'sector', nombre: 'nombre de la empresa',
  certificacion: 'certificaci&oacute;n', sostenibilidad: '&aacute;rea de sostenibilidad', texto_perfil: 'texto del perfil'
};
var EXP_NOTE = 'La experiencia no acredita por s&iacute; sola fabricaci&oacute;n o comercializaci&oacute;n.';
var VIEW_CAP = 'Solo capacidades';
var VIEW_EXP = 'Empresas con experiencias relacionadas';
function capHtml(cap) {
  if (!cap) return '';
  var tipo = CAP_TIPO[cap.tipo] || txt(cap.tipo);
  var how = cap.coincidencia === 'parecida'
    ? '<span style="color:#9a6700;font-weight:600">&#8776; parecido por significado</span>'
    : cap.coincidencia === 'relacionada'
      ? '<span style="color:#6e7781;font-weight:600">&#8764; familia CPV relacionada</span>'
      : '<span style="color:#1a7f37;font-weight:600">&#10004; coincide</span>';
  var o = ORIGEN[cap.origen] || null;
  var origen = o ? ' &middot; <span style="color:' + o.color + '">' + o.text + '</span>' : '';
  var code = cap.codigo ? ' <span style="opacity:.8">(' + txt(cap.codigo) + (cap.tipo_oferta ? ', ' + txt(cap.tipo_oferta) : '') + ')</span>' : '';
  return '<div style="font-size:12px;color:' + DARK + ';margin-top:3px;line-height:1.4">' + how + ' &middot; ' + tipo + ': <strong>' + txt(cap.nombre) + '</strong>' + code + origen + '</div>';
}
function capsHtml(r) {
  var caps = (r.capacidades && r.capacidades.length) ? r.capacidades : (r.capacidad ? [r.capacidad] : []);
  if (!caps.length) return '';
  return caps.slice(0, 3).map(capHtml).join('')
    + '<div style="font-size:11px;color:' + TEXT + ';margin-top:2px">Rol no acreditado en el perfil (fabricante, distribuidor o servicio)</div>';
}
function expList(list) {
  return (list || []).map(function (x) {
    var tag = x.via === 'similar' ? '&#8776; parecida' : '&#10004; menciona';
    return '<div style="font-size:12px;color:' + TEXT + ';margin-top:3px;line-height:1.4"><span style="white-space:nowrap">' + tag + '</span> &middot; ' + txt(x.fragmento) + '</div>';
  }).join('');
}
function expHint(r) {
  var n = (r.experiencias_relacionadas || []).length;
  if (!n) return '';
  return '<div style="font-size:11px;color:' + TEXT + ';margin-top:3px">Tambi&eacute;n tiene ' + n + ' experiencia(s) relacionada(s): ver en &laquo;' + VIEW_EXP + '&raquo;.</div>';
}
function explainDetails(ex) {
  if (!ex || !ex.fuentes || !ex.fuentes.length) return '';
  return '<details onclick="event.stopPropagation()" style="margin-top:4px;font-size:11px;color:' + TEXT + '">'
    + '<summary style="cursor:pointer">por qu&eacute; aparece y c&aacute;lculo del puntaje</summary>' + sourcesHtml(oneLineExplain(ex)) + '</details>';
}
// vista: 'cap' (tarjeta de capacidad), 'exp' (empresa solo con experiencias), 'exp_tambien' (empresa del grupo de
// capacidades mostrada en la vista de experiencias solo por sus experiencias).
function groupRow(r, idx, vista) {
  var c = norm(r);
  var call = 'sendPrompt(' + JSON.stringify('dame información sobre la empresa ' + String(c.name).replace(/\s+/g, ' ').trim()) + ')';
  var act = esc(call);
  var key = esc("if(event.key==='Enter'&&event.target===this){" + call + '}');
  var body, badge;
  if (vista === 'cap') {
    body = capsHtml(r) + expHint(r) + explainDetails(c.explain);
    badge = scoreBadge(c.explain);
  } else if (vista === 'exp') {
    body = expList((r.experiencias_relacionadas || []).slice(0, 3)) + '<div style="font-size:11px;color:#7a5800;margin-top:3px">' + EXP_NOTE + '</div>' + explainDetails(c.explain);
    badge = scoreBadge(c.explain);
  } else {
    body = expList((r.experiencias_relacionadas || []).slice(0, 3))
      + '<div style="font-size:11px;color:#7a5800;margin-top:3px">' + EXP_NOTE + ' Esta empresa tambi&eacute;n aparece en &laquo;' + VIEW_CAP + '&raquo; por su cat&aacute;logo.</div>';
    badge = '';
  }
  return '<div role="button" tabindex="0" onclick="' + act + '" onkeydown="' + key + '" '
    + 'style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:' + BG + ';border:0.5px solid ' + BORDER + ';border-radius:var(--border-radius-md);cursor:pointer">'
    + '<span style="font-size:12px;color:' + TEXT + ';min-width:18px;text-align:right;padding-top:2px">' + (idx + 1) + '</span>'
    + '<span style="flex:1;min-width:0;overflow-wrap:anywhere">'
      + '<span style="font-size:14px;color:' + DARK + ';font-weight:500">&#127970; ' + txt(c.name) + '</span>' + badge + voteBox(c.id)
      + body
    + '</span>'
    + '<span style="font-size:12px;color:' + GOLD + ';font-weight:500;white-space:nowrap;padding-top:2px">ver perfil &#8250;</span>'
    + '</div>';
}
// Cambia de vista: solo el panel elegido queda visible (hidden en el otro) y aria-pressed refleja la elección.
function switchJs(mode) {
  return "event.stopPropagation();var b=this.closest('.cira-b');b.setAttribute('data-vista','" + mode + "');"
    + "b.querySelectorAll('[data-vista-panel]').forEach(function(p){p.hidden=p.getAttribute('data-vista-panel')!=='" + mode + "';});"
    + "b.querySelectorAll('[data-vista-btn]').forEach(function(x){var on=x.getAttribute('data-vista-btn')==='" + mode + "';x.setAttribute('aria-pressed',on?'true':'false');x.style.background=on?'" + GOLD + "':'transparent';x.style.color=on?'#1a1a2e':'inherit';});";
}
function viewButton(label, mode, pressed) {
  return '<button type="button" data-vista-btn="' + mode + '" aria-pressed="' + (pressed ? 'true' : 'false') + '" onclick="' + esc(switchJs(mode)) + '" '
    + 'style="border:0;border-radius:16px;padding:5px 12px;font-size:12px;cursor:pointer;background:' + (pressed ? GOLD : 'transparent') + ';color:' + (pressed ? '#1a1a2e' : 'inherit') + '">' + label + '</button>';
}
function linkButton(label, mode) {
  return '<button type="button" onclick="' + esc(switchJs(mode)) + '" style="margin-top:6px;border:0.5px solid ' + BORDER + ';background:' + BG + ';border-radius:16px;padding:4px 12px;font-size:12px;cursor:pointer;color:' + DARK + '">' + label + '</button>';
}
function notice(html) {
  return '<div style="margin:2px 0 8px;font-size:12px;color:#7a5800;background:' + GOLDSM + ';border-radius:6px;padding:8px 10px;line-height:1.45">' + html + '</div>';
}
function groupedLegend() {
  return '<details style="margin-top:8px;font-size:12px;color:' + TEXT + '"><summary style="cursor:pointer">&iquest;Qu&eacute; muestra cada vista y c&oacute;mo se ordena?</summary>'
    + '<div style="margin-top:6px;line-height:1.45"><strong>' + VIEW_CAP + ':</strong> empresas cuyo servicio, sector, categor&iacute;a CPV o texto del perfil (sin contar sus experiencias) coincide con lo buscado. '
    + 'Se ordenan solo con esa evidencia: primero lo que coincide directamente y despu&eacute;s lo parecido por significado. Las experiencias no suman puntaje aqu&iacute;.<br>'
    + '<strong>' + VIEW_EXP + ':</strong> empresas que lo mencionan en proyectos ejecutados, ordenadas con esa evidencia. Al final aparecen, solo por sus experiencias, las empresas que tambi&eacute;n est&aacute;n en ' + VIEW_CAP + '. ' + EXP_NOTE + '<br>'
    + 'El <em>origen</em> indica si la categor&iacute;a la declar&oacute; la empresa, la valid&oacute; la C&aacute;mara o la sugiri&oacute; el sistema. El perfil no registra el rol (fabricante, distribuidor o servicio). '
    + 'Cada vista tiene su propio <em>puntaje</em>, relativo a su primer resultado (= 100): suma de peso / (60 + posici&oacute;n) en cada fuente.</div></details>';
}
function renderGrouped() {
  var cap = rows.filter(function (r) { return r.grupo === 'capacidad'; });
  var exp = rows.filter(function (r) { return r.grupo === 'experiencia'; });
  var also = cap.filter(function (r) { return (r.experiencias_relacionadas || []).length > 0; });
  var nExp = exp.length + also.length;
  var sum = txt(summary);
  var head = '&#9989; Para <em>' + sum + '</em>: <strong>' + cap.length + '</strong> empresa(s) que ofrecen productos o servicios relacionados y <strong>' + nExp + '</strong> con experiencias relacionadas.'
    + '<br><span style="font-size:12px;color:' + TEXT + '">Haz clic en una empresa para ver su perfil.</span>' + groupedLegend();
  var selector = '<div role="group" aria-label="Vista de resultados" style="display:inline-flex;flex-wrap:wrap;gap:2px;margin:0 0 10px;padding:2px;border:0.5px solid ' + BORDER + ';border-radius:18px;font-size:12px">'
    + viewButton(VIEW_CAP + ' (' + cap.length + ')', 'cap', true) + viewButton(VIEW_EXP + ' (' + nExp + ')', 'exp', false) + '</div>';
  var capPanel = '<div data-vista-panel="cap">'
    + (cap.length
      ? '<div style="font-weight:600;font-size:13px;color:' + DARK + ';margin:2px 0 6px">Empresas que ofrecen productos o servicios relacionados (' + cap.length + ')</div>'
        + '<div style="display:flex;flex-direction:column;gap:6px">' + cap.map(function (r, i) { return groupRow(r, i, 'cap'); }).join('') + '</div>'
      : notice('Ninguna empresa declara <em>' + sum + '</em> en su cat&aacute;logo (servicio, sector o categor&iacute;a CPV).'
          + (nExp ? ' Hay <strong>' + nExp + '</strong> empresa(s) que lo mencionan en sus experiencias.<br>' + linkButton('Ver ' + VIEW_EXP.toLowerCase() + ' (' + nExp + ')', 'exp') : ''))
    ) + '</div>';
  var expCards = exp.map(function (r, i) { return groupRow(r, i, 'exp'); }).join('');
  var alsoCards = also.map(function (r, i) { return groupRow(r, exp.length + i, 'exp_tambien'); }).join('');
  var expPanel = '<div data-vista-panel="exp" hidden>'
    + (nExp
      ? '<div style="font-weight:600;font-size:13px;color:' + DARK + ';margin:2px 0 6px">' + VIEW_EXP + ' (' + nExp + ')</div>'
        + '<div style="margin:0 0 6px;font-size:12px;color:#7a5800">' + EXP_NOTE + '</div>'
        + '<div style="display:flex;flex-direction:column;gap:6px">' + expCards
        + (also.length ? '<div style="font-size:12px;color:' + TEXT + ';margin:6px 0 0">Tambi&eacute;n con experiencias relacionadas (est&aacute;n en &laquo;' + VIEW_CAP + '&raquo; por su cat&aacute;logo):</div>' + alsoCards : '')
        + '</div>'
      : notice('No hay empresas con experiencias relacionadas con <em>' + sum + '</em>.'))
    + '</div>';
  return '<div class="cira-b" data-vista="cap" style="font-family:var(--font-sans);max-width:680px">'
    + header(head) + selector + capPanel + expPanel + resultVote() + '</div>';
}
// ─────────────────────────────────────────────────────────────────────────

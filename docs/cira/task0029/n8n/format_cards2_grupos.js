// ── TASK-0029: alternativa B, dos grupos (capacidades / experiencias) ─────────────────────────────
// Se activa solo si el Worker devolvió `grupo` en las filas (search_empresas con grupos:true). Si no (por
// ejemplo, con el Worker anterior), se usa el formato de siempre, más abajo, sin cambios.
// Todo texto que viene de datos (nombres, fragmentos, categorías, resumen del agente) pasa por esc().
// La salida no lleva saltos de línea: CIRA Test convierte cada \n en <br>.
var GROUPED = rows.some(function (r) { return r && (r.grupo === 'capacidad' || r.grupo === 'experiencia'); });
// Texto de datos en una línea y escapado (los fragmentos de experiencias traen saltos de línea y tabuladores).
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
function capHtml(cap) {
  if (!cap) return '';
  var tipo = CAP_TIPO[cap.tipo] || esc(cap.tipo);
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
var EXP_NOTE = 'La experiencia no acredita por s&iacute; sola fabricaci&oacute;n o comercializaci&oacute;n.';
function relatedExp(r) {
  var list = r.experiencias_relacionadas || [];
  if (!list.length) return '';
  return '<details class="cira-exp" onclick="event.stopPropagation()" style="margin-top:4px;font-size:12px;color:' + TEXT + '">'
    + '<summary style="cursor:pointer">Tambi&eacute;n tiene experiencias relacionadas (' + list.length + ')</summary>'
    + '<div style="font-size:11px;color:#7a5800;margin-top:3px">' + EXP_NOTE + ' No suman puntaje en este grupo.</div>'
    + expList(list) + '</details>';
}
function explainDetails(ex) {
  if (!ex || !ex.fuentes || !ex.fuentes.length) return '';
  return '<details onclick="event.stopPropagation()" style="margin-top:4px;font-size:11px;color:' + TEXT + '">'
    + '<summary style="cursor:pointer">por qu&eacute; aparece y c&aacute;lculo del puntaje</summary>' + sourcesHtml(oneLineExplain(ex)) + '</details>';
}
function groupRow(r, idx) {
  var c = norm(r);
  var prompt = 'dame información sobre la empresa ' + String(c.name).trim();
  var call = 'sendPrompt(' + JSON.stringify(prompt) + ')';
  var act = esc(call);
  var key = esc("if(event.key==='Enter'&&event.target===this){" + call + '}');
  var body = r.grupo === 'capacidad'
    ? capsHtml(r) + relatedExp(r)
    : expList((r.experiencias_relacionadas || []).slice(0, 2)) + '<div style="font-size:11px;color:#7a5800;margin-top:3px">' + EXP_NOTE + '</div>';
  return '<div role="button" tabindex="0" onclick="' + act + '" onkeydown="' + key + '" '
    + 'style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:' + BG + ';border:0.5px solid ' + BORDER + ';border-radius:var(--border-radius-md);cursor:pointer">'
    + '<span style="font-size:12px;color:' + TEXT + ';min-width:18px;text-align:right;padding-top:2px">' + (idx + 1) + '</span>'
    + '<span style="flex:1;min-width:0;overflow-wrap:anywhere">'
      + '<span style="font-size:14px;color:' + DARK + ';font-weight:500">&#127970; ' + txt(c.name) + '</span>' + scoreBadge(c.explain) + voteBox(c.id)
      + body + explainDetails(c.explain)
    + '</span>'
    + '<span style="font-size:12px;color:' + GOLD + ';font-weight:500;white-space:nowrap;padding-top:2px">ver perfil &#8250;</span>'
    + '</div>';
}
function modeButton(label, mode, pressed) {
  var js = "event.stopPropagation();var b=this.closest('.cira-b');b.setAttribute('data-modo','" + mode + "');"
    + "b.querySelectorAll('[data-modo-btn]').forEach(function(x){var on=x.getAttribute('data-modo-btn')==='" + mode + "';x.setAttribute('aria-pressed',on?'true':'false');x.style.background=on?'" + GOLD + "':'transparent';x.style.color=on?'#1a1a2e':'inherit';});"
    + "b.querySelectorAll('details.cira-exp').forEach(function(d){d.open=" + (mode === 'todo' ? 'true' : "d.hasAttribute('data-siempre')") + ";});";
  return '<button type="button" data-modo-btn="' + mode + '" aria-pressed="' + (pressed ? 'true' : 'false') + '" onclick="' + esc(js) + '" '
    + 'style="border:0;border-radius:16px;padding:4px 12px;font-size:12px;cursor:pointer;background:' + (pressed ? GOLD : 'transparent') + ';color:' + (pressed ? '#1a1a2e' : 'inherit') + '">' + label + '</button>';
}
function groupedLegend() {
  return '<details style="margin-top:8px;font-size:12px;color:' + TEXT + '"><summary style="cursor:pointer">&iquest;C&oacute;mo se separan y ordenan los resultados?</summary>'
    + '<div style="margin-top:6px;line-height:1.45"><strong>Empresas que ofrecen productos o servicios relacionados:</strong> lo buscado coincide con su servicio, sector, categor&iacute;a CPV o texto del perfil (sin contar sus experiencias). '
    + 'Se ordenan solo con esa evidencia: primero lo que coincide directamente y despu&eacute;s lo parecido por significado. Sus experiencias se muestran aparte y no suman puntaje.<br>'
    + '<strong>Empresas con experiencias relacionadas:</strong> solo lo mencionan en proyectos ejecutados. Se ordenan con esa evidencia, en su propio grupo. ' + EXP_NOTE + '<br>'
    + 'El <em>origen</em> indica si la categor&iacute;a la declar&oacute; la empresa, la valid&oacute; la C&aacute;mara o la sugiri&oacute; el sistema. El perfil no registra el rol (fabricante, distribuidor o servicio). '
    + 'Cada grupo tiene su propio <em>puntaje</em>, relativo a su primer resultado (= 100): suma de peso / (60 + posici&oacute;n) en cada fuente.</div></details>';
}
function renderGrouped() {
  var cap = rows.filter(function (r) { return r.grupo === 'capacidad'; });
  var exp = rows.filter(function (r) { return r.grupo === 'experiencia'; });
  var noCap = cap.length === 0;
  var sum = txt(summary);
  var head = noCap
    ? '&#128269; No encontr&eacute; empresas que declaren <em>' + sum + '</em> en su cat&aacute;logo (servicio, sector o categor&iacute;a CPV). Encontr&eacute; <strong>' + exp.length + '</strong> con experiencias relacionadas.'
    : '&#9989; Encontr&eacute; <strong>' + cap.length + '</strong> empresa(s) que ofrecen productos o servicios relacionados con <em>' + sum + '</em>'
      + (exp.length ? ' y <strong>' + exp.length + '</strong> con experiencias relacionadas' : '') + '.';
  head += '<br><span style="font-size:12px;color:' + TEXT + '">Haz clic en una empresa para ver su perfil.</span>' + groupedLegend();
  var selector = '<div role="group" aria-label="Qu&eacute; resultados mostrar" style="display:inline-flex;flex-wrap:wrap;gap:2px;margin:0 0 10px;padding:2px;border:0.5px solid ' + BORDER + ';border-radius:18px;font-size:12px">'
    + modeButton('Solo capacidades', 'cap', !noCap) + modeButton('Capacidades + experiencias', 'todo', noCap) + '</div>';
  var g1 = noCap ? ''
    : '<div style="font-weight:600;font-size:13px;color:' + DARK + ';margin:2px 0 6px">Empresas que ofrecen productos o servicios relacionados (' + cap.length + ')</div>'
      + '<div style="display:flex;flex-direction:column;gap:6px">' + cap.map(groupRow).join('') + '</div>';
  var g2 = '';
  if (exp.length) {
    var why = noCap
      ? '<div style="margin:6px 0;font-size:12px;color:#7a5800;background:' + GOLDSM + ';border-radius:6px;padding:6px 10px">&#9888;&#65039; Se muestran abiertas porque ninguna empresa lo declara en su cat&aacute;logo. ' + EXP_NOTE + '</div>'
      : '<div style="margin:6px 0;font-size:12px;color:#7a5800">' + EXP_NOTE + '</div>';
    g2 = '<details class="cira-exp cira-g2"' + (noCap ? ' open data-siempre' : '') + ' style="margin-top:12px">'
      + '<summary style="cursor:pointer;font-weight:600;font-size:13px;color:' + DARK + '">Empresas con experiencias relacionadas (' + exp.length + ')</summary>'
      + why + '<div style="display:flex;flex-direction:column;gap:6px">' + exp.map(groupRow).join('') + '</div></details>';
  }
  return '<div class="cira-b" data-modo="' + (noCap ? 'todo' : 'cap') + '" style="font-family:var(--font-sans);max-width:680px">'
    + header(head) + selector + g1 + g2 + resultVote() + '</div>';
}
// ─────────────────────────────────────────────────────────────────────────

// TASK-0030: arma el flujo de CIRA Test con las vistas excluyentes a partir del export VIVO (d2f8efb5 o posterior).
// Cambia solo: el bloque TASK-0029 de `Format Cards2` (entre sus marcadores) y la revisión en `Build Search Log`.
// Uso:
//   node build_flow.mjs <export_vivo.json> <salida.json>
//   node build_flow.mjs <export_vivo.json> <salida.json> --copia-prueba   (otro nombre y webhook; sin registro)
// Las salidas contienen el flujo completo: se escriben FUERA del repo.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const [src, out, flag] = process.argv.slice(2);
const here = path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const w = JSON.parse(fs.readFileSync(src, 'utf8'));
const node = (name) => {
	const n = w.nodes.find((x) => x.name === name);
	if (!n) throw new Error('falta el nodo ' + name);
	return n;
};
const once = (text, from, to, what) => {
	const i = text.indexOf(from);
	if (i < 0 || text.indexOf(from, i + 1) >= 0) throw new Error('ancla no única o ausente: ' + what);
	return text.slice(0, i) + to + text.slice(i + from.length);
};

// Format Cards2: reemplazar el bloque de TASK-0029 completo.
const fc = node('Format Cards2');
let code = fc.parameters.jsCode;
const START = '// ── TASK-0029: alternativa B, dos grupos (capacidades / experiencias)';
const END_RE = /\n\/\/ ─{20,}\n/g;
const s = code.indexOf(START);
if (s < 0 || code.indexOf(START, s + 1) >= 0) throw new Error('bloque TASK-0029 no encontrado o repetido');
END_RE.lastIndex = s;
const m = END_RE.exec(code);
if (!m) throw new Error('fin del bloque TASK-0029 no encontrado');
const e = m.index + m[0].length;
const block = fs.readFileSync(path.join(here, 'format_cards2_vistas.js'), 'utf8').replace(/\r\n/g, '\n').replace(/\n+$/, '\n');
code = code.slice(0, s) + block + code.slice(e);
code = once(
	code,
	'// TASK-0029 (2026-11-16): con grupos del Worker, dos secciones (capacidades / experiencias) y selector.',
	'// TASK-0029 (2026-11-16): con grupos del Worker, dos secciones (capacidades / experiencias) y selector.\n// TASK-0030 (2026-11-16, UAT 6919847012): dos vistas excluyentes, «Solo capacidades» / «Empresas con experiencias relacionadas».',
	'cabecera'
);
for (const must of ["output = renderGrouped();", 'var GROUPED', 'function renderGrouped']) if (!code.includes(must)) throw new Error('falta ' + must);
fc.parameters.jsCode = code;

// Build Search Log: revisión del flujo.
const bsl = node('Build Search Log');
bsl.parameters.jsCode = once(bsl.parameters.jsCode, "const FLOW_REVISION = 'T29-B r1';", "const FLOW_REVISION = 'T30-B r2';", 'revisión');

let body = { name: w.name, nodes: w.nodes, connections: w.connections, settings: { executionOrder: w.settings?.executionOrder ?? 'v1' } };
if (flag === '--copia-prueba') {
	const hook = node('Webhook1');
	hook.parameters.path = crypto.randomUUID();
	hook.webhookId = crypto.randomUUID();
	node('Log Search').disabled = true;
	node("Call 'CIRA_log'").disabled = true;
	for (const n of body.nodes) if (n.id) n.id = crypto.randomUUID();
	body = { ...body, name: 'Chat CIRA V5 - MCP [PRUEBA TASK-0030]' };
	fs.writeFileSync(out + '.webhook_path.txt', hook.parameters.path);
}
fs.writeFileSync(out, JSON.stringify(body));
console.log('ok', body.name, body.nodes.length);

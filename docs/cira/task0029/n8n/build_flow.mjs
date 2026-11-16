// TASK-0029: arma el flujo de CIRA Test con la alternativa B a partir del export VIVO (nunca de una copia vieja).
// Cambia solo 3 nodos: `Call MCP Empresas` (pide grupos:true a search_empresas), `Build Search Log` (grupo y
// capacidad por resultado; revisión del flujo en engine_version) y `Format Cards2` (dos grupos y selector; si la
// respuesta no trae `grupo`, el formato de siempre). Todo lo demás se copia tal cual.
//
// Uso:
//   node build_flow.mjs <export_vivo.json> <salida.json>                 → cuerpo para PUT del flujo vivo
//   node build_flow.mjs <export_vivo.json> <salida.json> --copia-prueba <url_mcp_preview>
//        → copia aislada: otro nombre y webhook, sin registro (Log Search y CIRA_log desactivados), Worker de vista previa
// Las salidas contienen el flujo completo (prompt, credenciales por id): se escriben FUERA del repo.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const [src, out, flag, previewUrl] = process.argv.slice(2);
const here = path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
const w = JSON.parse(fs.readFileSync(src, 'utf8'));
const node = (name) => {
	const n = w.nodes.find((x) => x.name === name);
	if (!n) throw new Error('falta el nodo ' + name);
	return n;
};
const replaceOnce = (text, from, to, what) => {
	const i = text.indexOf(from);
	if (i < 0 || text.indexOf(from, i + 1) >= 0) throw new Error('ancla no única o ausente: ' + what);
	return text.slice(0, i) + to + text.slice(i + from.length);
};

// 1) Call MCP Empresas: grupos:true solo para search_empresas.
const call = node('Call MCP Empresas');
const oldBody = '={{ JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/call", params: { name: $json.tool, arguments: $json.params } }) }}';
if (call.parameters.jsonBody !== oldBody) throw new Error('Call MCP Empresas cambió respecto de lo auditado');
call.parameters.jsonBody =
	'={{ JSON.stringify({ jsonrpc: "2.0", id: 1, method: "tools/call", params: { name: $json.tool, arguments: ($json.tool === "search_empresas" ? Object.assign({}, $json.params, { grupos: true }) : $json.params) } }) }}';

// 2) Build Search Log.
const bsl = node('Build Search Log');
let code = bsl.parameters.jsCode;
code = replaceOnce(
	code,
	'// TASK-0013: una fila por búsqueda en cira_search_log (vía Worker /search-log).',
	'// TASK-0013: una fila por búsqueda en cira_search_log (vía Worker /search-log).\n' +
		'// TASK-0029: cada resultado guarda también su grupo (capacidad / experiencia) y la capacidad que coincidió;\n' +
		'// engine_version lleva la revisión de este flujo y el Worker le agrega su versión desplegada.\n' +
		"const FLOW_REVISION = 'T29-B r1';",
	'cabecera Build Search Log'
);
code = replaceOnce(
	code,
	'  var ex = r.explain;\n  return {\n    id: Number(r.id), pos: i + 1,',
	'  var ex = r.explain;\n  var cap = r.capacidad;\n  return {\n    id: Number(r.id), pos: i + 1,\n    grupo: r.grupo || null,\n' +
		'    cap: cap ? { tipo: cap.tipo, codigo: cap.codigo || null, origen: cap.origen || null, coincidencia: cap.coincidencia || null } : null,\n' +
		'    nexp: Array.isArray(r.experiencias_relacionadas) ? r.experiencias_relacionadas.length : null,',
	'evidencia Build Search Log'
);
code = replaceOnce(
	code,
	"engine_version: 'flow ' + $workflow.id + ' ' + (bsp.promptVersion || ''),",
	"engine_version: 'flow ' + $workflow.id + ' ' + (bsp.promptVersion || '') + ' | ' + FLOW_REVISION,",
	'engine_version'
);
bsl.parameters.jsCode = code;

// 3) Format Cards2.
const fc = node('Format Cards2');
let fcode = fc.parameters.jsCode;
const grouped = fs.readFileSync(path.join(here, 'format_cards2_grupos.js'), 'utf8').replace(/\r\n/g, '\n');
fcode = replaceOnce(fcode, '// TASK-0013 paso 4 (2026-11-09): declarado primero, coincidencias adicionales marcadas.', '// TASK-0013 paso 4 (2026-11-09): declarado primero, coincidencias adicionales marcadas.\n// TASK-0029 (2026-11-16): con grupos del Worker, dos secciones (capacidades / experiencias) y selector.', 'cabecera Format Cards2');
fcode = replaceOnce(fcode, "var output = '';", grouped + "\nvar output = '';", 'inicio de salida');
fcode = replaceOnce(fcode, 'if (isFullCard) {', 'if (!isFullCard && GROUPED) {\n  output = renderGrouped();\n} else if (isFullCard) {', 'rama de salida');
fc.parameters.jsCode = fcode;

let body = { name: w.name, nodes: w.nodes, connections: w.connections, settings: { executionOrder: w.settings?.executionOrder ?? 'v1' } };

if (flag === '--copia-prueba') {
	if (!previewUrl || !/^https:\/\/[0-9a-f]{8}-perfilafiliados-mcp\.[a-z0-9.-]+\/mcp$/.test(previewUrl)) throw new Error('url de vista previa inválida');
	const hook = node('Webhook1');
	hook.parameters.path = crypto.randomUUID();
	hook.webhookId = crypto.randomUUID();
	call.parameters.url = previewUrl;
	node('Log Search').disabled = true;
	node("Call 'CIRA_log'").disabled = true;
	body = { ...body, name: 'Chat CIRA V5 - MCP [PRUEBA TASK-0029 B]' };
	for (const n of body.nodes) if (n.id) n.id = crypto.randomUUID();
	fs.writeFileSync(out + '.webhook_path.txt', hook.parameters.path);
}

fs.writeFileSync(out, JSON.stringify(body));
console.log('ok', body.name, 'nodos', body.nodes.length);

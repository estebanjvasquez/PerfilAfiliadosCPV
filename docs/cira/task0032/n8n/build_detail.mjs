// TASK-0032 fase B: pedido de ficha desde una tarjeta con ID estable.
// Causa raíz (ejecuciones 22716/22717): la tarjeta pedía la ficha con el NOMBRE; el agente lo reescribía según la regla
// de siglas («I.O.E.D» → "ioed") y `get_empresa` no lo encontraba, así que el flujo pintaba «Empresa no afiliada».
// Cambia SOLO 3 nodos, partiendo del export VIVO:
//   - `Format Cards2`: el clic envía «dame información sobre la empresa <nombre> [ID <id>]».
//   - `Parse Intent JSON3`: ese marcador fija `get_empresa {id}` de forma determinista (no depende del agente).
//     Texto libre (sin marcador): igual que antes; el Worker tolera siglas con o sin puntos.
//   - `Build Search Log`: revisión del flujo en engine_version (trazabilidad).
// Uso:
//   node build_detail.mjs <export_vivo.json> <salida.json>                 → cuerpo para PUT del flujo vivo
//   node build_detail.mjs <export_vivo.json> <salida.json> --copia-prueba <url_mcp_preview>
//        → copia aislada: otro nombre y webhook, sin registro, Worker de vista previa en ambos nodos MCP.
// Las salidas contienen el flujo completo: se escriben FUERA del repo.
import fs from 'node:fs';
import crypto from 'node:crypto';

const [src, out, flag, previewUrl] = process.argv.slice(2);
const w = JSON.parse(fs.readFileSync(src, 'utf8').replace(/^﻿/, ''));
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

// 1) Format Cards2: el pedido de ficha lleva el id.
const fc = node('Format Cards2');
let f = fc.parameters.jsCode;
f = replaceOnce(
	f,
	'function listRow(c, idx) {',
	'// TASK-0032: el pedido de ficha lleva el id estable de la empresa; Parse Intent JSON3 lo usa con get_empresa.\n' +
		"function idTag(c) { return /^\\d+$/.test(String(c.id || '')) ? ' [ID ' + c.id + ']' : ''; }\n\nfunction listRow(c, idx) {",
	'listRow'
);
f = replaceOnce(
	f,
	`var onclick = "sendPrompt('dame información sobre la empresa " + safeNameJs + "')";`,
	`var onclick = "sendPrompt('dame información sobre la empresa " + safeNameJs + idTag(c) + "')";`,
	'onclick de listRow'
);
f = replaceOnce(
	f,
	"var call = 'sendPrompt(' + JSON.stringify('dame información sobre la empresa ' + String(c.name).replace(/\\s+/g, ' ').trim()) + ')';",
	"var call = 'sendPrompt(' + JSON.stringify('dame información sobre la empresa ' + String(c.name).replace(/\\s+/g, ' ').trim() + idTag(c)) + ')';",
	'call de groupRow'
);
fc.parameters.jsCode = f;

// 2) Parse Intent JSON3: el marcador de tarjeta manda.
const pi = node('Parse Intent JSON3');
pi.parameters.jsCode = replaceOnce(
	pi.parameters.jsCode,
	'const isReady = parsed &&',
	'// TASK-0032: pedido de ficha desde una tarjeta («dame información sobre la empresa <nombre> [ID <id>]»). El id es\n' +
		'// estable: se pide get_empresa por id sin depender de cómo el agente reescriba el nombre (siglas, puntos).\n' +
		"const cardRequest = String($('Pre-Procesing3').first().json.chatInput || '').match(/^\\s*dame informaci[o\\u00f3]n sobre la empresa\\s+(.+?)\\s*\\[ID (\\d{1,9})\\]\\s*$/i);\n" +
		'if (cardRequest) {\n' +
		"  parsed = { tool: 'get_empresa', params: { id: Number(cardRequest[2]) }, queryIntent: 'COMPANY', isSearchReady: true, humanSummary: cardRequest[1].trim() };\n" +
		'}\n\n' +
		'const isReady = parsed &&',
	'isReady'
);

// 3) Build Search Log: revisión.
const bsl = node('Build Search Log');
bsl.parameters.jsCode = replaceOnce(bsl.parameters.jsCode, "const FLOW_REVISION = 'T30-B r2';", "const FLOW_REVISION = 'T30-B r2 + T32-ficha-id';", 'FLOW_REVISION');

let body = { name: w.name, nodes: w.nodes, connections: w.connections, settings: { executionOrder: w.settings?.executionOrder ?? 'v1' } };

if (flag === '--copia-prueba') {
	if (!previewUrl || !/^https:\/\/[0-9a-f]{8}-perfilafiliados-mcp\.[a-z0-9.-]+\/mcp$/.test(previewUrl)) throw new Error('url de vista previa inválida');
	const hook = node('Webhook1');
	hook.parameters.path = crypto.randomUUID();
	hook.webhookId = crypto.randomUUID();
	node('Call MCP Empresas').parameters.url = previewUrl;
	node('MCP Perfil Afiliados CPV').parameters.endpointUrl = previewUrl;
	node('Log Search').disabled = true;
	node("Call 'CIRA_log'").disabled = true;
	body = { ...body, name: 'Chat CIRA V5 - MCP [PRUEBA TASK-0032 ficha]' };
	for (const n of body.nodes) if (n.id) n.id = crypto.randomUUID();
	fs.writeFileSync(out + '.webhook_path.txt', hook.parameters.path);
}

fs.writeFileSync(out, JSON.stringify(body));
console.log('ok', body.name, 'nodos', body.nodes.length);

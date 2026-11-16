// TASK-0028: copia sanitizada del flujo n8n vivo para el repo público.
import fs from 'node:fs';
const [src, dst] = process.argv.slice(2);
const w = JSON.parse(fs.readFileSync(src, 'utf8').replace(/^﻿/, ''));
const redactStr = (s) =>
	s
		.replace(/https?:\/\/[^\s"'`)]+/g, '<URL_REDACTADA>')
		.replace(/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/gi, '<UUID>')
		.replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+.[a-z]{2,}/g, '<CORREO_REDACTADO>')
		.replace(/(Bearer\s+)[A-Za-z0-9._~+/=-]{8,}/g, '$1<REDACTADO>');
const walk = (v) => {
	if (typeof v === 'string') return redactStr(v);
	if (Array.isArray(v)) return v.map(walk);
	if (v && typeof v === 'object') {
		const o = {};
		for (const [k, vv] of Object.entries(v)) o[k] = /password|token|secret|apikey|api_key/i.test(k) ? '<REDACTADO>' : walk(vv);
		return o;
	}
	return v;
};
const out = {
	_sanitized_by: 'TASK-0028 (Issue #2 6913187637): copia de solo lectura del flujo vivo. Sin credenciales, contraseñas, hosts, rutas de webhook ni ids internos.',
	id: w.id, name: w.name, active: w.active, versionId: w.versionId, updatedAt: w.updatedAt,
	nodes: [], connections: w.connections,
};
for (const n of w.nodes) {
	const c = { name: n.name, type: n.type, typeVersion: n.typeVersion, position: n.position, parameters: walk(n.parameters) };
	if (n.name === 'Admin Password') c.parameters = { _nota: 'Nodo Set con la contraseña de los comandos de administración: valor REDACTADO.' };
	if (n.type === 'n8n-nodes-base.webhook') c.parameters.path = '<RUTA_WEBHOOK_REDACTADA>';
	if (n.credentials) c.credentials = Object.fromEntries(Object.keys(n.credentials).map((k) => [k, { name: '<REDACTADO>' }]));
	out.nodes.push(c);
}
fs.writeFileSync(dst, JSON.stringify(out, null, 1) + '\n');

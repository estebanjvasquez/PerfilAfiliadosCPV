// TASK-0029: corre las consultas de TASK-0028 contra el Worker.
//  - local (wrangler dev con el código nuevo, sobre la copia aislada de la BD): modo por defecto y modo `grupos`;
//  - vivo (opcional, solo `search_empresas`, que no escribe en la bitácora): modo por defecto, para comparar.
// Uso: node run_cases.mjs <cases.json> <salida.json> <url_local_mcp> [url_viva_mcp]
// Tokens por variable de entorno (nunca en el repo): LOCAL_MCP_TOKEN, CPV_MCP_TOKEN (solo si se pasa url viva).
import fs from 'node:fs';

const [casesPath, outPath, localUrl, liveUrl] = process.argv.slice(2);
const cases = JSON.parse(fs.readFileSync(casesPath, 'utf8'));

async function call(url, token, args) {
	try {
		return await callOnce(url, token, args);
	} catch (e) {
		// Un reintento: `wrangler dev` puede reiniciarse al recargar el código.
		await new Promise((r) => setTimeout(r, 3000));
		try {
			return await callOnce(url, token, args);
		} catch (e2) {
			return { error: 'falló dos veces: ' + String(e2?.message ?? e2).slice(0, 200), ms: null };
		}
	}
}

async function callOnce(url, token, args) {
	const t0 = performance.now();
	const r = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ' + token },
		body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'search_empresas', arguments: args } }),
	});
	const text = await r.text();
	const ms = Math.round(performance.now() - t0);
	const m = text.match(/data:\s*(\{[\s\S]*\})/);
	const rpc = JSON.parse(m ? m[1] : text);
	if (rpc.error) return { error: rpc.error.message, ms };
	const content = rpc.result?.content?.[0]?.text;
	let rows;
	try {
		rows = JSON.parse(content);
	} catch {
		return { error: 'no JSON: ' + String(content).slice(0, 200), ms };
	}
	return { rows, ms };
}

const slim = (rows) =>
	Array.isArray(rows)
		? rows.map((r) => ({
				id: Number(r.id),
				grupo: r.grupo ?? null,
				level: r.match_level ?? null,
				puntaje: r.explain?.puntaje ?? null,
				rel: r.explain?.puntaje_relativo ?? null,
				fuentes: r.explain?.fuentes?.map((f) => ({ lista: f.lista, frase: f.frase, pct: f.aporte_pct })) ?? null,
				capacidad: r.capacidad ? { tipo: r.capacidad.tipo, coincidencia: r.capacidad.coincidencia, codigo: r.capacidad.codigo, origen: r.capacidad.origen, rol: r.capacidad.rol } : null,
				n_capacidades: r.capacidades?.length ?? null,
				n_experiencias: r.experiencias_relacionadas?.length ?? null,
			}))
		: rows;

const out = [];
for (const c of cases) {
	const base = { query: c.query, resolvedPhrases: c.phrases, explain: true };
	const local_default = await call(localUrl, process.env.LOCAL_MCP_TOKEN, base);
	const local_grupos = await call(localUrl, process.env.LOCAL_MCP_TOKEN, { ...base, grupos: true });
	const live_default = liveUrl ? await call(liveUrl, process.env.CPV_MCP_TOKEN, base) : null;
	out.push({
		id: c.id,
		query: c.query,
		phrases: c.phrases,
		golden: c.golden ?? null,
		local_default: { ms: local_default.ms, error: local_default.error ?? null, rows: slim(local_default.rows) },
		local_grupos: { ms: local_grupos.ms, error: local_grupos.error ?? null, rows: slim(local_grupos.rows) },
		live_default: live_default ? { ms: live_default.ms, error: live_default.error ?? null, rows: slim(live_default.rows) } : null,
	});
	const g = local_grupos.rows ?? [];
	process.stderr.write(
		`${c.id} ${c.query} | default=${local_default.rows?.length ?? 'ERR'} live=${live_default?.rows?.length ?? '-'} | cap=${g.filter((r) => r.grupo === 'capacidad').length} exp=${g.filter((r) => r.grupo === 'experiencia').length} ${local_grupos.error ?? ''}\n`
	);
}
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));

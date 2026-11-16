// TASK-0032 fase B: `search_empresas` del Worker desplegado frente a la candidata, por defecto y con grupos.
// El cambio de la fase B solo toca get_empresa: las 55 consultas deben dar ids, orden, grupo y puntaje idénticos.
// Solo lectura (search_empresas no registra). Uso: CPV_MCP_TOKEN=... node search_equivalencia.mjs <url_vivo> <url_candidata> <salida.json>
import fs from 'node:fs';

const [urlLive, urlNew, outPath] = process.argv.slice(2);
const cases = JSON.parse(fs.readFileSync(new URL('../../task0028/experimentos/cases.json', import.meta.url), 'utf8'));
async function call(url, args) {
	for (let i = 0; i < 3; i++) {
		try {
			const r = await fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ' + process.env.CPV_MCP_TOKEN },
				body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'search_empresas', arguments: args } }),
			});
			const t = await r.text();
			const m = t.match(/data:\s*(\{[\s\S]*\})/);
			const rows = JSON.parse(JSON.parse(m ? m[1] : t).result.content[0].text);
			return Array.isArray(rows) ? rows.map((x) => [Number(x.id), x.grupo ?? null, x.explain?.puntaje ?? null]) : rows;
		} catch (e) {
			if (i === 2) return { error: String(e.message ?? e).slice(0, 80) };
			await new Promise((r) => setTimeout(r, 1500));
		}
	}
}
const out = { total: 0, iguales: 0, distintos: [] };
for (const c of cases) {
	for (const grupos of [false, true]) {
		const args = { query: c.query, ...(c.phrases ? { resolvedPhrases: c.phrases } : {}), explain: true, ...(grupos ? { grupos: true } : {}) };
		const a = await call(urlLive, args);
		const b = await call(urlNew, args);
		out.total++;
		if (JSON.stringify(a) === JSON.stringify(b)) out.iguales++;
		else out.distintos.push({ id: c.id, grupos, vivo: a, candidata: b });
	}
}
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
console.log('search_empresas idénticas:', out.iguales, '/', out.total, '; distintas', out.distintos.map((d) => d.id + (d.grupos ? 'g' : '')).join(','));

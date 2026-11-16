// TASK-0032 fase B: `get_empresa` del Worker desplegado frente a la versión candidata (vista previa sin tráfico).
// Solo lectura: get_empresa no escribe registros. La salida lleva ids de empresa, sin nombres.
// Casos:
//   fijos   - id (177, 157, inexistente), siglas con/sin puntos, nombres largos, variantes, RIF y negativos;
//   nombre  - las empresas activas por su nombre completo: ambas versiones deben devolver lo mismo;
//   siglas  - empresas con siglas entre paréntesis, reescritas como lo hace el agente (sin puntos ni sufijos).
// Uso: CPV_MCP_TOKEN=... node get_empresa_regresion.mjs <empresas.json> <url_vivo_mcp> <url_candidata_mcp> <salida.json>
//   empresas.json: [{id, name, rif, ch}] (lectura de la BD, fuera del repo)
import fs from 'node:fs';

const [empPath, urlLive, urlNew, outPath] = process.argv.slice(2);
const empresas = JSON.parse(fs.readFileSync(empPath, 'utf8'));
async function call(url, args) {
	for (let i = 0; i < 3; i++) {
		try {
			const r = await fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', Authorization: 'Bearer ' + process.env.CPV_MCP_TOKEN },
				body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'get_empresa', arguments: args } }),
			});
			const t = await r.text();
			const m = t.match(/data:\s*(\{[\s\S]*\})/);
			const rpc = JSON.parse(m ? m[1] : t);
			if (rpc.error) return { error: rpc.error.message?.slice(0, 80) };
			const parsed = JSON.parse(rpc.result.content[0].text);
			return Array.isArray(parsed) ? { ids: parsed.map((x) => Number(x.id)) } : { found: false, error: parsed.error ?? null };
		} catch (e) {
			if (i === 2) return { error: String(e.message ?? e).slice(0, 80) };
			await new Promise((r) => setTimeout(r, 1500));
		}
	}
}
const fixed = [
	{ caso: 'id 177', args: { id: 177 }, espera: [177] },
	{ caso: 'id 157', args: { id: 157 }, espera: [157] },
	{ caso: 'id inexistente', args: { id: 999999 }, espera: [] },
	{ caso: 'id 177 con nombre distinto (id manda)', args: { id: 177, nombre: 'lukiven' }, espera: [177] },
	{ caso: 'nombre "ioed" (lo que mandó el agente)', args: { nombre: 'ioed' }, contiene: 177 },
	{ caso: 'nombre "i.o.e.d"', args: { nombre: 'i.o.e.d' }, contiene: 177 },
	{ caso: 'nombre "ioed company"', args: { nombre: 'ioed company' }, contiene: 177 },
	{ caso: 'nombre completo 177', args: { nombre: 'INTERNATIONAL OFFSHORE ENGINEERING AND DEVELOPMENT I.O.E.D COMPANY, C.A (I.O.E.D COMPANY C.A.)' }, contiene: 177 },
	{ caso: 'nombre parcial largo 177', args: { nombre: 'international offshore engineering' }, contiene: 177 },
	{ caso: 'nombre "lukiven"', args: { nombre: 'lukiven' }, contiene: 157 },
	{ caso: 'nombre "taspsa"', args: { nombre: 'taspsa' } },
	{ caso: 'nombre "kala"', args: { nombre: 'kala' } },
	{ caso: 'nombre "n&v"', args: { nombre: 'n&v' } },
	{ caso: 'rif J070093501', args: { rif: 'J070093501' } },
	{ caso: 'negativo: "zapateria la bota"', args: { nombre: 'zapateria la bota' }, espera: [] },
	{ caso: 'negativo: "petroleos de venezuela"', args: { nombre: 'petroleos de venezuela' } },
	{ caso: 'negativo corto: "xq" (sin respaldo nuevo, < 3)', args: { nombre: 'xq' } },
	{ caso: 'sin parámetros', args: {} },
];
const out = { fijos: [], nombre: { total: 0, iguales: 0, distintos: [] }, siglas: { total: 0, vivo_encuentra: 0, candidata_encuentra: 0, perdidas: [], filas: [] }, sin_chamber: {} };
for (const f of fixed) {
	const a = await call(urlLive, f.args);
	const b = await call(urlNew, f.args);
	const ok = f.espera ? JSON.stringify(b.ids ?? []) === JSON.stringify(f.espera) : f.contiene ? (b.ids ?? []).includes(f.contiene) : null;
	out.fijos.push({ caso: f.caso, vivo: a, candidata: b, ok });
}
// Regresión por nombre completo: la ruta ilike no cambió; las respuestas deben ser idénticas.
for (const e of empresas) {
	const n = String(e.name).replace(/['"]/g, ' ').replace(/\s+/g, ' ').trim();
	const a = await call(urlLive, { nombre: n });
	const b = await call(urlNew, { nombre: n });
	out.nombre.total++;
	if (JSON.stringify(a) === JSON.stringify(b) && (b.ids ?? []).includes(e.id)) out.nombre.iguales++;
	else out.nombre.distintos.push({ id: e.id, vivo: a, candidata: b });
}
// Siglas entre paréntesis, como las reescribe el agente: sin puntos ni sufijo legal.
for (const e of empresas) {
	const m = String(e.name).match(/\(([^)]+)\)/);
	if (!m) continue;
	const term = m[1].replace(/\b(C\.?\s?A|S\.?\s?A|S\.?\s?R\.?\s?L)\.?\b/gi, '').replace(/[^\p{L}\p{N}& ]/gu, '').replace(/\s+/g, ' ').trim().toLowerCase();
	if (term.length < 3) continue;
	const a = await call(urlLive, { nombre: term });
	const b = await call(urlNew, { nombre: term });
	out.siglas.total++;
	const va = (a.ids ?? []).includes(e.id), vb = (b.ids ?? []).includes(e.id);
	if (va) out.siglas.vivo_encuentra++;
	if (vb) out.siglas.candidata_encuentra++;
	if (va && !vb) out.siglas.perdidas.push(e.id);
	out.siglas.filas.push({ id: e.id, vivo: a.ids ?? a, candidata: b.ids ?? b });
}
const noCh = empresas.filter((e) => Number(e.ch) === 0);
let okId = 0;
for (const e of noCh) if (JSON.stringify((await call(urlNew, { id: e.id })).ids) === JSON.stringify([e.id])) okId++;
out.sin_chamber = { total: noCh.length, encontradas_por_id: okId };
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
for (const f of out.fijos) console.log((f.ok === false ? 'FALLA ' : f.ok ? 'ok    ' : '      ') + f.caso, '| vivo', JSON.stringify(f.vivo), '| candidata', JSON.stringify(f.candidata));
console.log('nombre completo:', out.nombre.iguales, '/', out.nombre.total, 'idénticas y con la empresa; distintos', out.nombre.distintos.length);
console.log('siglas:', out.siglas.total, 'casos; vivo encuentra', out.siglas.vivo_encuentra, '; candidata', out.siglas.candidata_encuentra, '; perdidas', out.siglas.perdidas.length);
console.log('sin chamber_empresa por id:', okId, '/', noCh.length);

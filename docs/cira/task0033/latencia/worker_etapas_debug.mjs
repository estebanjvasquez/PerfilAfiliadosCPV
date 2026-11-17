// TASK-0033 E4: tiempos por etapa del Worker con /debug-search (SOLO lectura; no escribe registros). Frases nuevas, dos
// veces cada una (la 1.ª sin caché de Hyperdrive, la 2.ª con caché). El embedding de la frase no está instrumentado:
// se estima como total del Worker menos la señal SQL más lenta (las señales corren en paralelo).
// Nota: /debug-search usa el modo por defecto (sin `grupos`), así que es una aproximación del camino de CIRA Test.
// Uso: CPV_DEBUG_TOKEN=... node worker_etapas_debug.mjs <url_base_worker> <salida.json>
import fs from 'node:fs';

const [base, outPath] = process.argv.slice(2);
const frases = ['mantenimiento de compresores de gas', 'alquiler de montacargas', 'tratamiento de crudo extrapesado'];
const out = [];
for (const q of frases) {
	for (const intento of [1, 2]) {
		const t0 = performance.now();
		const r = await fetch(base.replace(/\/$/, '') + '/debug-search', { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer ' + process.env.CPV_DEBUG_TOKEN }, body: JSON.stringify({ query: q, resolvedPhrases: [] }) });
		const j = await r.json();
		const cliente = Math.round(performance.now() - t0);
		const t = j.timings_ms ?? j.timingsMs ?? {};
		const senales = Object.entries(t).filter(([k]) => k !== 'total');
		const maxSenal = senales.length ? Math.max(...senales.map(([, v]) => v)) : null;
		out.push({ frase: q, intento, status: r.status, cliente_ms: cliente, worker_total_ms: t.total ?? null, senal_sql_mas_lenta_ms: maxSenal, estimacion_embedding_y_resto_ms: t.total != null && maxSenal != null ? Math.round(t.total - maxSenal) : null, senales: Object.fromEntries(senales) });
	}
}
fs.writeFileSync(outPath, JSON.stringify(out, null, 1));
for (const x of out) console.log(x.frase, x.intento, x.status, 'cliente', x.cliente_ms, 'worker', x.worker_total_ms, 'sql_max', x.senal_sql_mas_lenta_ms, 'resto', x.estimacion_embedding_y_resto_ms, JSON.stringify(x.senales));

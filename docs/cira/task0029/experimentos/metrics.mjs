// TASK-0029: métricas de la corrida de run_cases.mjs. Solo ids de empresa; etiquetas del cliente de TASK-0028 (no se inventan).
// Uso: node metrics.mjs <run.json> <salida.json> [clave_actual=live_default] [clave_b=local_grupos]
import fs from 'node:fs';

const [runPath, outPath, actKey = 'live_default', bKey = 'local_grupos'] = process.argv.slice(2);
const run = JSON.parse(fs.readFileSync(runPath, 'utf8'));
const ids = (rows) => (Array.isArray(rows) ? rows.map((r) => r.id) : []);
const fold = (s) => String(s).normalize('NFD').replace(/\p{M}/gu, '').toLowerCase().trim();
const median = (xs) => {
	const s = xs.filter((x) => x != null).sort((a, b) => a - b);
	return s.length ? s[Math.floor(s.length / 2)] : null;
};

const m = {
	casos: run.length,
	errores: run.filter((c) => c[actKey]?.error || c[bKey]?.error || c.local_default?.error).map((c) => c.id),
	por_defecto_igual_al_vivo: { mismo_orden: 0, mismo_top5: 0, distintos: [] },
	actual: { rel_top5: 0, norel_top5: 0, sinjuicio_top5: 0, rel_top10: 0, vacias: 0, total: 0 },
	b: { rel_top5_g1: 0, norel_top5_g1: 0, sinjuicio_top5_g1: 0, rel_cobertura: 0, vacias: 0, g1_vacio: 0, total_g1: 0, total_g2: 0 },
	rel_etiquetadas: 0,
	consultas_etiquetadas: 0,
	invariantes: { duplicados_entre_grupos: [], rol_no_nulo: [], frases_repetidas: [], g2_antes_que_g1: [] },
	origen_capacidad: {},
	tipo_capacidad: {},
	omisiones_b_vs_actual: [],
	latencia_ms: {},
	por_consulta: [],
};

for (const c of run) {
	const act = ids(c[actKey]?.rows);
	const loc = ids(c.local_default?.rows);
	const brows = Array.isArray(c[bKey]?.rows) ? c[bKey].rows : [];
	const g1 = brows.filter((r) => r.grupo === 'capacidad').map((r) => r.id);
	const g2 = brows.filter((r) => r.grupo === 'experiencia').map((r) => r.id);

	if (c[actKey] && c.local_default) {
		if (JSON.stringify(act) === JSON.stringify(loc)) m.por_defecto_igual_al_vivo.mismo_orden++;
		else m.por_defecto_igual_al_vivo.distintos.push({ id: c.id, vivo: act.slice(0, 8), local: loc.slice(0, 8) });
		if (JSON.stringify(act.slice(0, 5)) === JSON.stringify(loc.slice(0, 5))) m.por_defecto_igual_al_vivo.mismo_top5++;
	}

	m.actual.total += act.length;
	if (act.length === 0) m.actual.vacias++;
	m.b.total_g1 += g1.length;
	m.b.total_g2 += g2.length;
	if (g1.length + g2.length === 0) m.b.vacias++;
	if (g1.length === 0) m.b.g1_vacio++;

	// invariantes
	const dup = g1.filter((x) => g2.includes(x));
	if (dup.length) m.invariantes.duplicados_entre_grupos.push({ id: c.id, dup });
	const firstG2 = brows.findIndex((r) => r.grupo === 'experiencia');
	if (firstG2 >= 0 && brows.slice(firstG2).some((r) => r.grupo === 'capacidad')) m.invariantes.g2_antes_que_g1.push(c.id);
	for (const r of brows) {
		if (r.capacidad && r.capacidad.rol !== null) m.invariantes.rol_no_nulo.push({ id: c.id, emp: r.id });
		if (r.capacidad) {
			m.origen_capacidad[r.capacidad.origen ?? 'null'] = (m.origen_capacidad[r.capacidad.origen ?? 'null'] ?? 0) + 1;
			const t = `${r.capacidad.tipo}/${r.capacidad.coincidencia}`;
			m.tipo_capacidad[t] = (m.tipo_capacidad[t] ?? 0) + 1;
		}
		const frases = new Set((r.fuentes ?? []).map((f) => f.frase));
		const folded = new Set([...frases].map(fold));
		if (folded.size !== frases.size) m.invariantes.frases_repetidas.push({ id: c.id, emp: r.id });
	}

	// pérdidas de cobertura respecto del actual (empresas del actual que B no muestra en ningún grupo)
	const shown = new Set([...g1, ...g2]);
	const lost = act.filter((x) => !shown.has(x));
	if (lost.length) m.omisiones_b_vs_actual.push({ id: c.id, query: c.query, perdidas: lost, posiciones_en_actual: lost.map((x) => act.indexOf(x) + 1) });

	const g = c.golden;
	const row = { id: c.id, query: c.query, actual_top5: act.slice(0, 5), g1_top5: g1.slice(0, 5), g2_top5: g2.slice(0, 5), n_actual: act.length, n_g1: g1.length, n_g2: g2.length };
	if (g) {
		m.consultas_etiquetadas++;
		m.rel_etiquetadas += g.rel.length;
		const judge = (list) => ({
			rel: list.filter((x) => g.rel.includes(x)).length,
			norel: list.filter((x) => g.norel.includes(x)).length,
			sin: list.filter((x) => !g.rel.includes(x) && !g.norel.includes(x)).length,
		});
		const a5 = judge(act.slice(0, 5));
		m.actual.rel_top5 += a5.rel;
		m.actual.norel_top5 += a5.norel;
		m.actual.sinjuicio_top5 += a5.sin;
		m.actual.rel_top10 += act.slice(0, 10).filter((x) => g.rel.includes(x)).length;
		const b5 = judge(g1.slice(0, 5));
		m.b.rel_top5_g1 += b5.rel;
		m.b.norel_top5_g1 += b5.norel;
		m.b.sinjuicio_top5_g1 += b5.sin;
		const cover = new Set([...g1.slice(0, 10), ...g2.slice(0, 10)]);
		m.b.rel_cobertura += g.rel.filter((x) => cover.has(x)).length;
		Object.assign(row, { test_id: g.test_id, rel: g.rel, norel: g.norel, actual_juicio_top5: a5, b_juicio_top5_g1: b5, rel_en_cobertura_b: g.rel.filter((x) => cover.has(x)) });
	}
	m.por_consulta.push(row);
}

const prec = (r, n) => (r + n ? Math.round((1000 * r) / (r + n)) / 10 : null);
m.actual.precision_juzgada = prec(m.actual.rel_top5, m.actual.norel_top5);
m.b.precision_juzgada_g1 = prec(m.b.rel_top5_g1, m.b.norel_top5_g1);
m.latencia_ms = {
	vivo_por_defecto_mediana: median(run.map((c) => c[actKey]?.ms)),
	local_por_defecto_mediana: median(run.map((c) => c.local_default?.ms)),
	b_mediana: median(run.map((c) => c[bKey]?.ms)),
};

// Casos fijados por la asignación
const by = (q) => run.find((c) => c.query === q);
const grp = (c) => {
	const rows = Array.isArray(c?.[bKey]?.rows) ? c[bKey].rows : [];
	return { cap: rows.filter((r) => r.grupo === 'capacidad').map((r) => r.id), exp: rows.filter((r) => r.grupo === 'experiencia').map((r) => r.id) };
};
const bal = grp(by('balancin'));
const mac = grp(by('macolla'));
m.casos_fijados = {
	balancin: { ...bal, lukiven_157_en_capacidades: bal.cap.includes(157), disteinsa_ogs_totalclean_en_experiencias: [91, 333, 391].every((x) => bal.exp.includes(x)) },
	macolla: { ...mac, conserva_resultados_en_secundario: mac.exp.length > 0, relevantes_cliente_en_secundario: [155, 171, 1, 76].filter((x) => mac.exp.includes(x)) },
};

fs.writeFileSync(outPath, JSON.stringify(m, null, 1));
const { por_consulta, ...resumen } = m;
console.log(JSON.stringify(resumen, null, 1));

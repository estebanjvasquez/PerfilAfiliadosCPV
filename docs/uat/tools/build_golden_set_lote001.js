// Genera docs/uat/golden_set_lote001.json a partir del Excel (ya pasado a JSON) y del volcado de empresas.
const fs = require('fs');
const [rowsF, empF, outF] = process.argv.slice(2);
const strip = (s) => s.replace(/^﻿/, '');
const rows = JSON.parse(strip(fs.readFileSync(rowsF, 'utf8')));
const emps = JSON.parse(strip(fs.readFileSync(empF, 'utf8')));
const byId = new Map(emps.map((e) => [e.id, e]));

const norm = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9& ]/g, ' ').replace(/\s+/g, ' ').trim();

// Resoluciones manuales: errores de tipeo del evaluador y ambigüedades (verificadas contra `empresas`).
const MANUAL = {
	'ADVANCE LOGGING AND EXPLORATIONS': 79,
	'MARITIME CONTRACTOS': 152,
	'METAL MECANICA DE MERIDA': 170,
	'TOTAL CLEAM': 391,
	'CONSORCIO PADALCA': 433,
	INELETCRA: 76,
};
const UNRESOLVED = {
	ECOPEC: 'No existe ninguna empresa con ese nombre ni variante cercana en `empresas` (406 filas, 2026-11-06).',
	INSERVEN: 'Ambiguo: hay dos empresas (122 "INSTALACIONES Y SERVICIOS DE VENEZUELA, C.A. INSERVEN" y 286 "INSERVEN, C.A."). Confirmar con el cliente.',
};
const AMBIGUOUS_CANDIDATES = { INSERVEN: [122, 286] };

function resolve(written) {
	const q = norm(written);
	if (UNRESOLVED[q]) return { written, empresa_id: null, unresolved_reason: UNRESOLVED[q], candidates: AMBIGUOUS_CANDIDATES[q] ?? [] };
	if (MANUAL[q]) return { written, empresa_id: MANUAL[q], db_name: byId.get(MANUAL[q]).name.trim(), match: 'manual' };
	const qc = q.replace(/ /g, '');
	const hits = emps.filter((e) => {
		const en = norm(e.name);
		return en.includes(q) || en.replace(/ /g, '').includes(qc);
	});
	if (hits.length === 1) return { written, empresa_id: hits[0].id, db_name: hits[0].name.trim(), match: 'auto' };
	throw new Error(`Sin resolución única para "${written}": ${hits.map((h) => h.id).join(',')}`);
}

const splitNames = (v) => (v ? v.split(',').map((x) => x.trim()).filter((x) => x && x !== '-' && x !== '–') : []);

// Etiquetas de análisis (técnicas, NO del cliente): solo donde el comentario o los datos lo sostienen.
const TAGS = {
	'G1-02': ['ruido_despues_de_top3'],
	'G1-04': ['similitud_superficial', 'termino_regional_sin_cobertura'],
	'G2-01': ['candidato_relacionado_mostrado', 'termino_regional_sin_cobertura'],
	'G2-02': ['candidato_relacionado_mostrado', 'termino_regional_sin_cobertura'],
	'G2-04': ['literal_mejor_que_semantico'],
	'G2-05': ['termino_regional_sin_cobertura', 'dato_de_prueba_en_resultados'],
	'G2-06': ['termino_generico_domina'],
	'G2-08': ['sin_resultados'],
	'G3-01': ['intencion_ignorada'],
	'G3-02': ['intencion_ignorada'],
	'G3-04': ['termino_generico_domina'],
	'G3-05': ['termino_generico_domina'],
	'G4-03': ['consulta_demasiado_generica'],
	'G4-04': ['consulta_demasiado_generica', 'ruido_despues_de_top10'],
	'G5-03': ['termino_generico_domina'],
	'G5-04': ['interpretacion_desviada'],
	'G6-01': ['consulta_ambigua'],
	'G6-02': ['contexto_vs_capacidad'],
	'G6-03': ['contexto_vs_capacidad', 'consulta_demasiado_generica'],
	'G6-04': ['relevantes_fuera_de_top5'],
	'G7-02': ['termino_regional_sin_cobertura'],
};

// Comportamiento esperado: "results" salvo donde el cliente aprobó otra salida (G7, G4-02).
const EXPECTED = {
	'G4-02': { expected: 'clarification', basis: 'cliente: "Pide un criterio más específico" (calificado 4)' },
	'G7-01': { expected: 'institutional_answer_no_companies', basis: 'cliente: mensaje adecuado con contactos de la Cámara (5)' },
	'G7-03': { expected: 'no_results', basis: 'cliente: "No encontramos empresas afiliadas..." (5)' },
	'G7-04': { expected: 'out_of_scope_message', basis: 'cliente: "No puedo ayudarte con xyzqw..." (5)' },
};

const EVALUATORS = {}; // nombre real -> seudónimo (el repo es público)
const pseudo = (name) => {
	const k = (name || '').trim();
	if (!k) return null;
	if (!EVALUATORS[k]) EVALUATORS[k] = 'EVAL_' + String.fromCharCode(65 + Object.keys(EVALUATORS).length);
	return EVALUATORS[k];
};

const toIsoDate = (d) => {
	const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(d || '');
	return m ? `${m[3]}-${m[2]}-${m[1]}` : null;
};

const cases = [];
const skipped = [];
for (const r of rows) {
	if (!r.Consulta) {
		skipped.push(r.Test_ID);
		continue;
	}
	const relevant = splitNames(r.Resultados_relevantes_top5).map(resolve);
	const notRelevant = splitNames(r.Resultados_no_relevantes_top5).map(resolve);
	const score = /^\d$/.test(r.Puntuacion_1_a_5 || '') ? Number(r.Puntuacion_1_a_5) : null;
	const exp = EXPECTED[r.Test_ID] ?? { expected: 'results', basis: 'consulta de dominio: se esperan empresas relevantes' };
	cases.push({
		test_id: r.Test_ID,
		group: r.Grupo,
		query: r.Consulta,
		proposed_by: r.Consulta_propuesta_por,
		evaluated_on: toIsoDate(r.Fecha),
		evaluator: pseudo(r.Evaluador),
		overall_label: (r.Resultado_general || '').trim().toLowerCase(),
		score_1_5: score,
		expected_behavior: exp.expected,
		expected_behavior_basis: exp.basis,
		judged_depth: r.Test_ID === 'G6-04' ? 'extended_beyond_top5' : 'top5_or_more',
		relevant: relevant.filter((x) => x.empresa_id !== null),
		not_relevant: notRelevant.filter((x) => x.empresa_id !== null),
		unresolved: [...relevant.filter((x) => x.empresa_id === null).map((x) => ({ ...x, list: 'relevant' })), ...notRelevant.filter((x) => x.empresa_id === null).map((x) => ({ ...x, list: 'not_relevant' }))],
		client_comment: r.Comentario_cliente ?? null,
		analysis_tags: TAGS[r.Test_ID] ?? [],
	});
}

// Empresas que el cliente marcó como NO relevantes en varias consultas distintas.
const noiseCount = new Map();
for (const c of cases) for (const n of c.not_relevant) {
	const e = noiseCount.get(n.empresa_id) ?? { empresa_id: n.empresa_id, db_name: n.db_name, queries: [], also_relevant_in: [] };
	if (!e.queries.includes(c.test_id)) e.queries.push(c.test_id);
	noiseCount.set(n.empresa_id, e);
}
for (const c of cases) for (const n of c.relevant) if (noiseCount.has(n.empresa_id)) noiseCount.get(n.empresa_id).also_relevant_in.push(c.test_id);
const recurringNoise = [...noiseCount.values()].filter((e) => e.queries.length >= 2).sort((a, b) => b.queries.length - a.queries.length);

const scored = cases.filter((c) => c.score_1_5 !== null);
const domain = scored.filter((c) => c.expected_behavior === 'results');
const avg = (xs) => Math.round((xs.reduce((s, c) => s + c.score_1_5, 0) / xs.length) * 100) / 100;

const out = {
	_comment:
		'Lista de referencia (golden set) del lote 001 de pruebas del cliente del buscador CIRA. Generada desde docs/uat/RESULTADOS_BUSCADOR_LOTE001.xlsx. Los nombres escritos por el evaluador se resolvieron a empresas.id contra la base (lectura, 2026-11-06). Ver docs/uat/GOLDEN_SET_LOTE001.md para semántica y uso.',
	schema_version: 1,
	source: {
		file: 'docs/uat/RESULTADOS_BUSCADOR_LOTE001.xlsx',
		sheet: 'Resultados buscador',
		evaluated_between: ['2026-11-03', '2026-11-04'],
		evaluators: Object.values(EVALUATORS),
		client_rows_skipped: skipped,
		generated_on: '2026-11-06',
	},
	semantics: {
		relevant: 'Juicio positivo del cliente (la empresa SÍ corresponde a la consulta).',
		not_relevant: 'Juicio negativo del cliente (apareció y NO corresponde).',
		unjudged: 'Toda empresa que no esté en ninguna de las dos listas es DESCONOCIDA, no negativa. El cliente no pudo indicar empresas ausentes (N vacía en todas las filas).',
		order: 'El orden dentro de cada lista es el que escribió el evaluador; NO es la posición en el ranking.',
		analysis_tags: 'Etiquetas técnicas añadidas al convertir; no las escribió el cliente.',
	},
	client_general_observations: [
		'Con más de ~10 resultados aparece mucho ruido; las relevantes suelen estar entre las primeras diez.',
		'"similar a" (semántico por vectores) rara vez acierta; necesita un criterio adicional.',
		'"coincidió con" fue el método que mejor funcionó.',
		'Es muy difícil indicar qué empresas faltan (más de 400 afiliadas, conocidas sobre todo por su perfil).',
		'Algunas filas tienen más de 5 empresas porque había relevantes fuera del top 5.',
		'Algunas casillas de relevantes quedaron vacías porque el resultado estaba muy contaminado.',
		'No se llenaron las filas propuestas por el cliente (G8).',
	],
	summary: {
		cases: cases.length,
		scored: scored.length,
		avg_score_all_scored: avg(scored),
		domain_scored: domain.length,
		avg_score_domain: avg(domain),
		domain_score_ge_4: domain.filter((c) => c.score_1_5 >= 4).map((c) => c.test_id),
		positive_judgments: cases.reduce((s, c) => s + c.relevant.length, 0),
		negative_judgments: cases.reduce((s, c) => s + c.not_relevant.length, 0),
		unresolved_names: cases.reduce((s, c) => s + c.unresolved.length, 0),
	},
	recurring_noise_companies: recurringNoise,
	cases,
};

fs.writeFileSync(outF, JSON.stringify(out, null, 2) + '\n', 'utf8');
console.log(JSON.stringify(out.summary, null, 2));
console.log('ruido recurrente:', recurringNoise.map((e) => `${e.empresa_id} ${e.db_name} x${e.queries.length}${e.also_relevant_in.length ? ' (relevante en ' + e.also_relevant_in.join(',') + ')' : ''}`).join(' | '));

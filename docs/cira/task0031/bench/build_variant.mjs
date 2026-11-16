// TASK-0031: arma una COPIA AISLADA del flujo de CIRA Test para una variante del benchmark.
// Siempre: otro nombre y webhook, `Log Search` y `Call 'CIRA_log'` desactivados (no escribe cira_search_log ni CIRA_log),
// ids de nodo nuevos. El flujo activo no se toca. La salida contiene el flujo completo: se escribe FUERA del repo.
// Uso: node build_variant.mjs <export_vivo.json> <variante> <salida.json>
//   V0  línea base (mismo modelo y prompt)
//   V1  sin resolve_search_intent: el agente arma el JSON en una sola llamada al modelo
//   M41 modelo gpt-4.1-mini (prompt y herramientas iguales)
//   M54 modelo gpt-5.4-mini (prompt y herramientas iguales)
//   V1M41 V1 + gpt-4.1-mini
import fs from 'node:fs';
import crypto from 'node:crypto';

const [src, variant, out] = process.argv.slice(2);
const raw = JSON.parse(fs.readFileSync(src, 'utf8'));
const w = raw.activeVersion ?? raw;
const node = (name) => {
	const n = w.nodes.find((x) => x.name === name);
	if (!n) throw new Error('falta el nodo ' + name);
	return n;
};

const V1_OVERRIDE =
	'\\n\\n== AJUSTE DE ESTA VERSION (prueba de rendimiento) ==\\n' +
	'La tool "resolve_search_intent" NO esta disponible en esta version: no la llames y no esperes su resultado. ' +
	'Arma el JSON de busqueda directamente en esta misma respuesta, siguiendo la regla CONSULTA COMPLETA. ' +
	'En "resolvedPhrases" pon la frase con tildes cuando "query" las pierde, y si la necesidad tiene varios conceptos independientes, ' +
	'una frase por concepto (ademas de "query"). Si la frase no tiene ninguna relacion con la industria petrolera o energetica, ' +
	'responde en texto plano declinando, con la misma EXCEPCION de jerga de campo. Si es ambigua, haz una sola pregunta corta.';

const variants = {
	V0: () => {},
	V1: () => {
		const tool = node('MCP Perfil Afiliados CPV');
		tool.parameters.includeTools = tool.parameters.includeTools.filter((t) => t !== 'resolve_search_intent');
		const bsp = node('Build System Prompt');
		const from = 'systemPrompt: basePrompt + learningsSection,';
		if (!bsp.parameters.jsCode.includes(from)) throw new Error('ancla del prompt no encontrada');
		bsp.parameters.jsCode = bsp.parameters.jsCode.replace(from, `systemPrompt: basePrompt + learningsSection + '${V1_OVERRIDE}',`);
	},
	// V1b: V1 + dos reglas que cubren lo que aportaba resolve_search_intent en las regresiones de V1 (tipeo y alcance).
	V1b: () => {
		variants.V1();
		const bsp = node('Build System Prompt');
		const extra =
			'\\n- Si una palabra tiene un error de tipeo evidente (ej. "balacines", "fuidos"), deja "query" como la escribio el usuario y agrega en "resolvedPhrases" la forma correcta con tildes (ej. "balancines").' +
			'\\n- Ante la duda sobre si un termino es de la industria (servicios, ambiente, aguas, residuos, construccion, energia), BUSCALO: declina solo lo que claramente no tiene relacion.';
		bsp.parameters.jsCode = bsp.parameters.jsCode.replace("una sola pregunta corta.',", "una sola pregunta corta." + extra + "',");
		if (!bsp.parameters.jsCode.includes('balacines')) throw new Error('no se aplicaron las reglas de V1b');
	},
	M41: () => setModel('gpt-4.1-mini'),
	M54: () => setModel('gpt-5.4-mini'),
	V1M41: () => {
		variants.V1();
		setModel('gpt-4.1-mini');
	},
};
function setModel(id) {
	const m = node('OpenAI Chat Model');
	m.parameters.model = { __rl: true, value: id, mode: 'list', cachedResultName: id };
}
if (!variants[variant]) throw new Error('variante desconocida ' + variant);
variants[variant]();

const hook = node('Webhook1');
hook.parameters.path = crypto.randomUUID();
hook.webhookId = crypto.randomUUID();
node('Log Search').disabled = true;
node("Call 'CIRA_log'").disabled = true;
for (const n of w.nodes) if (n.id) n.id = crypto.randomUUID();

const body = { name: `[PRUEBA TASK-0031] CIRA ${variant}`, nodes: w.nodes, connections: w.connections, settings: { executionOrder: 'v1' } };
fs.writeFileSync(out, JSON.stringify(body));
fs.writeFileSync(out + '.webhook_path.txt', hook.parameters.path);
console.log('ok', body.name);

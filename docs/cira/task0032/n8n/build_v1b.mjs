// TASK-0032 fase A: arma V1b a partir del export VIVO del flujo de CIRA Test (nunca de una copia vieja).
// V1b = el agente deja de llamar a `resolve_search_intent` (se quita de `includeTools`) y las reglas que aportaba
// (tipeo, varios conceptos, alcance) se integran en la sección existente «ENTENDER LA INTENCION» del prompt.
// Mismo modelo (gpt-6-luna), mismo Worker, ranking, grupos, registro, votos, evaluaciones y contratos.
// Cambian SOLO 2 nodos: `Build System Prompt` y `MCP Perfil Afiliados CPV`.
//
// Uso:
//   node build_v1b.mjs <export_vivo.json> <salida.json>                  → cuerpo para PUT del flujo vivo
//   node build_v1b.mjs <export_vivo.json> <salida.json> --copia-prueba [V0]
//        → copia aislada: otro nombre y webhook, sin registro (Log Search y CIRA_log desactivados), ids nuevos.
//          Con V0 la copia conserva el prompt vivo (línea base en las mismas condiciones).
// Las salidas contienen el flujo completo (prompt, credenciales por id): se escriben FUERA del repo.
import fs from 'node:fs';
import crypto from 'node:crypto';

const [src, out, flag, base] = process.argv.slice(2);
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

const SECTION_START = '== ENTENDER LA INTENCION ANTES DE BUSCAR (Fase MCP-6, 16 sep 2026) ==';
const SECTION_END = '== COMPORTAMIENTO ==';
const NEW_SECTION = `== ENTENDER LA INTENCION ANTES DE BUSCAR (TASK-0032, 16 nov 2026: sin tool adicional) ==
Antes de armar el JSON de busqueda del CASO C (termino de negocio), interpreta tu mismo la frase del
usuario como lo haria un especialista de la industria petrolera venezolana - no como un contador de
palabras - y arma el JSON en esta misma respuesta. Para este paso no hay ninguna tool: no la busques
ni esperes su resultado.
  - Un solo concepto (aunque tenga varias palabras): flujo normal de search_empresas con "query"
    segun CONSULTA COMPLETA. Si alguna palabra de la frase lleva tilde en espanol correcto, aunque el
    usuario la haya escrito sin ella (ej. "tuberia octg" -> "tubería octg", "gruas" -> "grúas"), pon
    en "resolvedPhrases" la frase con tildes.
  - Varios conceptos independientes en el mismo mensaje: ademas de "query", pon en "resolvedPhrases"
    una frase por concepto.
  - Error de tipeo evidente (ej. "balacines", "fuidos"): deja "query" como la escribio el usuario y
    agrega en "resolvedPhrases" la forma correcta con tildes (ej. "balancines").
  - Ambigua: solo una frase con verbo o contexto que admite dos lecturas de negocio muy distintas (ej.
    una obra de la industria frente a plomeria domestica). Un termino suelto o una sigla (1 a 4
    palabras, ej. "IPC", "OCTG", "macolla") NUNCA es ambiguo: buscalo con "query" tal cual. Si es
    ambigua, NO generes el JSON de busqueda todavia: responde en TEXTO PLANO (sin JSON, sin markdown,
    sin prefijos) una sola pregunta corta y natural. Cuando el usuario conteste esa aclaracion en su
    proximo mensaje, sigue el proceso de nuevo ya con el contexto completo.
  - Sin ninguna relacion real con la industria petrolera/energetica venezolana ni con la Camara
    Petrolera: NO generes el JSON de busqueda y NO llames a ninguna tool. Responde en TEXTO PLANO
    (sin JSON, sin markdown) declinando cortesmente, mismo tono que la regla "TEMAS GESTIONADOS POR LA
    CAMARA" de mas abajo para temas totalmente ajenos (sin obligacion de dar el contacto de la Camara,
    ya que tampoco es un tema que ella gestione). NUNCA reformules la frase inventando una conexion
    forzada con la industria para buscar igual.
  - Ante la duda sobre si un termino es de la industria (servicios, ambiente, aguas, residuos,
    construccion, energia), BUSCALO: declina solo lo que claramente no tiene relacion.
    EXCEPCION: si el mensaje es solo un termino suelto (1 a 4 palabras, sin verbo de charla) que en
    el campo petrolero venezolano puede nombrar un equipo, material o servicio, NO lo declines aunque
    te parezca un lugar o un nombre: buscalo igual con "query" tal cual (sin "resolvedPhrases", salvo
    la correccion de un error de tipeo evidente). Ejemplos de jerga de campo: guaya/guayas (cable de
    acero), cabria, mechurrio, chupon, balancin, macolla, arbolito, mecha, caballito. Si el termino
    es una ciudad o estado venezolano, va en "ciudad".
Esto NO aplica a "get_empresa" (CASO B) - una peticion de detalle de una empresa puntual nunca es
ambigua de esta forma.

`;

const v0 = flag === '--copia-prueba' && base === 'V0';
if (!v0) {
	// 1) MCP Perfil Afiliados CPV: sin resolve_search_intent.
	const tool = node('MCP Perfil Afiliados CPV');
	const tools = tool.parameters.includeTools;
	if (JSON.stringify(tools) !== JSON.stringify(['resolve_search_intent', 'search_taxonomy'])) throw new Error('includeTools cambió: ' + JSON.stringify(tools));
	tool.parameters.includeTools = ['search_taxonomy'];

	// 2) Build System Prompt: reglas V1b en la sección existente; sin menciones a la tool retirada.
	const bsp = node('Build System Prompt');
	let code = bsp.parameters.jsCode;
	if ((code.match(/resolve_search_intent/g) || []).length !== 7) throw new Error('el prompt cambió respecto de lo auditado');
	code = replaceOnce(
		code,
		'llamas directamente. Tus unicas tools son "resolve_search_intent" y "search_taxonomy".',
		'llamas directamente. Tu unica tool es "search_taxonomy".',
		'lista de tools'
	);
	code = replaceOnce(
		code,
		'  - el "search_phrases" de resolve_search_intent, tal cual;\n',
		'  - las que pide ENTENDER LA INTENCION (abajo): la forma con tildes, una frase por concepto\n' +
			'    independiente y la correccion de un error de tipeo evidente;\n',
		'frases adicionales'
	);
	const a = code.indexOf(SECTION_START);
	const b = code.indexOf(SECTION_END);
	if (a < 0 || b < a || code.indexOf(SECTION_START, a + 1) >= 0 || code.indexOf(SECTION_END, b + 1) >= 0) throw new Error('sección ENTENDER LA INTENCION no única');
	code = code.slice(0, a) + NEW_SECTION + code.slice(b);
	code = replaceOnce(
		code,
		'segui el proceso de "ENTENDER LA INTENCION ANTES DE BUSCAR": llama a\n"resolve_search_intent" y deja que la busqueda real decida',
		'segui el proceso de "ENTENDER LA INTENCION ANTES DE BUSCAR": arma el\nJSON y deja que la busqueda real decida',
		'antes de declinar'
	);
	code = replaceOnce(code, "promptVersion: 'TASK-0013-step1-v2+explain+votos'", "promptVersion: 'TASK-0013-step1-v2+explain+votos+T32-v1b'", 'promptVersion');
	if (code.includes('resolve_search_intent')) throw new Error('quedan menciones a resolve_search_intent');
	if (code.includes('`', code.indexOf('`') + 1) && code.split('`').length !== 3) throw new Error('backticks inesperados en el prompt');
	bsp.parameters.jsCode = code;
}

let body = { name: w.name, nodes: w.nodes, connections: w.connections, settings: { executionOrder: w.settings?.executionOrder ?? 'v1' } };

if (flag === '--copia-prueba') {
	const hook = node('Webhook1');
	hook.parameters.path = crypto.randomUUID();
	hook.webhookId = crypto.randomUUID();
	node('Log Search').disabled = true;
	node("Call 'CIRA_log'").disabled = true;
	body = { ...body, name: `Chat CIRA V5 - MCP [PRUEBA TASK-0032 ${v0 ? 'V0' : 'V1b'}]` };
	for (const n of body.nodes) if (n.id) n.id = crypto.randomUUID();
	fs.writeFileSync(out + '.webhook_path.txt', hook.parameters.path);
}

fs.writeFileSync(out, JSON.stringify(body));
console.log('ok', body.name, 'nodos', body.nodes.length);

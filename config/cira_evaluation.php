<?php

/*
 * TASK-0027 (Issue #2 `6852945146`): content of the Chamber's CIRA evaluation campaign.
 *
 * The eight guided tests are fixed by the assignment (CPV-01 ... CPV-08). Their texts are instructions, not expected
 * answers: no company name or "correct" result is fixed here without the Chamber's backing. Each evaluator chooses the
 * real search. Wording: plain, neutral Spanish.
 */

return [
    'estimated_minutes' => '35–50',

    'tests' => [
        'CPV-01' => [
            'title' => 'Búsqueda por actividad conocida',
            'goal' => 'Comprobar que CIRA encuentra empresas por una actividad o servicio que usted conoce bien.',
            'steps' => 'Escriba una actividad concreta del sector, como la diría a un colega. Revise si las empresas que conoce que la hacen aparecen arriba.',
            'examples' => ['mantenimiento de bombas de subsuelo', 'transporte de carga pesada', 'inspección de tuberías'],
            'look_for' => 'Empresas que hacen esa actividad y que faltan, o empresas que aparecen sin hacerla.',
        ],
        'CPV-02' => [
            'title' => 'Sinónimos y vocabulario del sector',
            'goal' => 'Comprobar que CIRA entiende otras formas de nombrar lo mismo.',
            'steps' => 'Busque la misma actividad con un sinónimo, una palabra coloquial del sector o una abreviatura. Compare con lo que esperaría con el término formal.',
            'examples' => ['gandola / camión de carga pesada', 'guaya / cable de acero', 'balancín / unidad de bombeo mecánico'],
            'look_for' => 'Si el sinónimo da resultados razonables o si se pierden empresas que sí salen con el término formal.',
        ],
        'CPV-03' => [
            'title' => 'Empresa por nombre',
            'goal' => 'Comprobar que CIRA encuentra una empresa afiliada cuando se escribe su nombre.',
            'steps' => 'Escriba el nombre (o parte del nombre) de una empresa afiliada que conozca, incluso con un pequeño error de escritura.',
            'examples' => ['el nombre comercial', 'una parte del nombre', 'el nombre con un error de tipeo'],
            'look_for' => 'Si la empresa aparece primera o entre las primeras.',
        ],
        'CPV-04' => [
            'title' => 'Capacidad específica frente a sector general',
            'goal' => 'Comprobar que una capacidad concreta no se confunde con todo un sector.',
            'steps' => 'Busque una capacidad específica (un servicio puntual) y revise si arriba salen las empresas especializadas y no solo empresas grandes del sector general.',
            'examples' => ['soldadura de tuberías de alta presión', 'calibración de instrumentos', 'alquiler de grúas'],
            'look_for' => 'Empresas generalistas por encima de especialistas, o especialistas ausentes.',
        ],
        'CPV-05' => [
            'title' => 'Búsqueda negativa (evitar falsos positivos)',
            'goal' => 'Comprobar que CIRA no inventa coincidencias cuando ninguna empresa debería salir, o muy pocas.',
            'steps' => 'Busque algo fuera del sector o muy poco común. Lo correcto puede ser que salgan pocos resultados o ninguno.',
            'examples' => ['un servicio que ninguna afiliada ofrece', 'una actividad de otro sector económico'],
            'look_for' => 'Resultados que aparecen solo porque coincide una palabra suelta.',
        ],
        'CPV-06' => [
            'title' => 'Relevancia y orden de los 5 primeros',
            'goal' => 'Comprobar que los cinco primeros resultados son los más útiles para esa búsqueda.',
            'steps' => 'Haga una búsqueda que conozca bien y mire solo los cinco primeros. ¿Están en un orden razonable?',
            'examples' => ['cualquier búsqueda de las pruebas anteriores'],
            'look_for' => 'Empresas muy relevantes por debajo del quinto puesto, o poco relevantes entre los cinco primeros.',
        ],
        'CPV-07' => [
            'title' => 'Actividad transversal y clasificación',
            'goal' => 'Comprobar actividades que sirven a varios sectores (por ejemplo, servicios generales) y cómo se clasifican.',
            'steps' => 'Busque una actividad que no es exclusiva del petróleo. Revise si las empresas del sector que la ofrecen aparecen y si la clasificación tiene sentido.',
            'examples' => ['seguridad industrial', 'servicios de laboratorio', 'logística'],
            'look_for' => 'Empresas clasificadas en un rubro que no corresponde, o ausentes por estar clasificadas de otra forma.',
        ],
        'CPV-08' => [
            'title' => 'Explicación de la coincidencia',
            'goal' => 'Comprobar que la explicación de por qué sale cada empresa está respaldada por sus datos.',
            'steps' => 'En cualquier búsqueda, lea la explicación de dos o tres resultados («por qué aparece») y compruebe si coincide con lo que sabe de la empresa.',
            'examples' => ['coincide por su servicio declarado', 'coincide por su experiencia', 'coincidencia adicional'],
            'look_for' => 'Explicaciones que no se sostienen con los datos de la empresa.',
        ],
    ],

    'ratings' => [
        'correcta' => 'Correcta',
        'parcial' => 'Parcial',
        'incorrecta' => 'Incorrecta',
    ],

    'reasons' => [
        'faltan_empresas' => 'Faltan empresas que deberían aparecer',
        'sobran_resultados' => 'Sobran resultados que no corresponden',
        'orden' => 'El orden no es el adecuado',
        'explicacion' => 'La explicación no se sostiene',
        'sin_resultados' => 'No devolvió resultados',
        'otro' => 'Otro motivo',
    ],

    // Classification of findings, set by the reviewer in the internal panel.
    'finding_categories' => [
        'datos' => 'Datos de la empresa',
        'taxonomia_sinonimos' => 'Taxonomía y sinónimos',
        'indexacion' => 'Indexación',
        'ranking' => 'Orden (ranking)',
        'explicacion' => 'Explicación',
        'otros' => 'Otros',
    ],

    // The Chamber's decision per test: always explicit, never computed.
    'decisions' => [
        'aprobado' => 'Aprobado',
        'observado' => 'Observado',
        'rechazado' => 'Rechazado',
    ],
];

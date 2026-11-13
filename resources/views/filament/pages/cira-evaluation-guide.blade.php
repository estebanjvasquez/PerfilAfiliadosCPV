<x-filament-panels::page>
    {{-- TASK-0027: evaluation guide for the Chamber. Same content as docs/manual_usuario/guia_evaluacion_cira.md. --}}
    <x-filament::section>
        <x-slot name="heading">Para qué sirve</x-slot>
        <div class="text-sm space-y-2">
            <p>La Cámara revisa si CIRA encuentra a las empresas afiliadas correctas. Usted hace búsquedas reales, como lo haría en su trabajo, y dice si el resultado es correcto. Su juicio sectorial es lo que vale: el sistema no da por aceptado el buscador por sí solo.</p>
            <p><strong>Duración estimada:</strong> {{ $minutes }} minutos. Puede guardar por partes y volver cuando quiera.</p>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Pasos</x-slot>
        <ol class="text-sm list-decimal pl-5 space-y-1">
            <li>Inicie sesión con su cuenta habitual del sistema.</li>
            <li>Abra <a class="text-primary-600 underline" href="/cira-test/">CIRA Test</a> en este mismo navegador y haga una búsqueda real.</li>
            <li>Si quiere, vote 👍/👎 en las empresas, como siempre.</li>
            <li>Debajo de los resultados pulse <strong>«Evaluar esta búsqueda»</strong>.</li>
            <li>Elija a qué prueba (CPV-01 a CPV-08) corresponde, marque <strong>Correcta / Parcial / Incorrecta</strong>, señale lo que sobra o falta y escriba un comentario breve.</li>
            <li>Pulse «Guardar borrador» para seguir después o «Enviar evaluación» cuando termine. En «Mis pruebas» verá su avance.</li>
        </ol>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Cómo valorar</x-slot>
        <ul class="text-sm space-y-1">
            <li><strong>{{ $ratings['correcta'] }}:</strong> los resultados son los que esperaba, en un orden razonable.</li>
            <li><strong>{{ $ratings['parcial'] }}:</strong> sirve, pero falta alguna empresa importante, sobra alguna o el orden no es el adecuado.</li>
            <li><strong>{{ $ratings['incorrecta'] }}:</strong> no sirve para lo que buscó.</li>
        </ul>
        <p class="text-sm mt-2">Comentarios útiles: «Faltan las empresas de X que conozco», «La tercera no hace esa actividad», «La explicación dice servicio declarado, pero esa empresa no lo ofrece». No hace falta escribir mucho.</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Las 8 pruebas</x-slot>
        <div class="space-y-4">
            @foreach ($tests as $code => $t)
                <div class="text-sm" data-cira-guide-test="{{ $code }}">
                    <p class="font-semibold">{{ $code }} · {{ $t['title'] }}</p>
                    <p>{{ $t['goal'] }}</p>
                    <p><em>Cómo:</em> {{ $t['steps'] }}</p>
                    <p><em>Ejemplos de búsqueda:</em> {{ implode(' · ', $t['examples']) }}</p>
                    <p><em>Fíjese en:</em> {{ $t['look_for'] }}</p>
                </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500 mt-3">Los ejemplos son orientativos. Use búsquedas que conozca: el sistema no fija resultados esperados.</p>
    </x-filament::section>
</x-filament-panels::page>

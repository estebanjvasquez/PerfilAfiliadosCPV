<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Posibles duplicados de concepto</x-slot>
        <x-slot name="description">Similitud léxica alta entre dos conceptos canónicos (TAXV3-2) - un admin decide si fusionarlos desde "Conceptos canónicos", no se fusionan solos.</x-slot>

        @php($pairs = $this->getDuplicateConceptPairs())

        @if (empty($pairs))
            <p class="text-sm text-gray-500 dark:text-gray-400">Ninguno por ahora.</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($pairs as $pair)
                    <li class="py-2 text-sm flex items-center justify-between gap-4">
                        <span>{{ $pair['a'] }} &harr; {{ $pair['b'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400">similitud {{ $pair['similarity'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>

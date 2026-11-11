<?php

namespace App\Services\CategoryAnalysis\Contracts;

/** TASK-0016, addendum: consumo de la última llamada de un buscador de fuentes, para sumarlo al costo del análisis. */
interface ReportsUsage
{
    /** @return array{input_tokens: int, output_tokens: int, web_searches: int} */
    public function lastUsage(): array;
}

<?php

namespace App\Services\CategoryAnalysis\Contracts;

/**
 * TASK-0016: recuperación de fuentes públicas técnicas, separada del juicio. Solo devuelve lo que la búsqueda real
 * encontró (URL, título, entidad, extracto y, si se conoce, fecha de publicación); nunca texto generado por un modelo.
 * Si no está disponible, `available()` es false y el análisis queda marcado como limitado.
 */
interface SourceRetriever
{
    public function name(): string;

    public function available(): bool;

    /**
     * @return list<array{url: string, title: string, entity: ?string, excerpt: string, published_at: ?string}>
     *
     * @throws \RuntimeException si la búsqueda falla (el análisis sigue, marcado como limitado)
     */
    public function search(string $query, int $max): array;
}

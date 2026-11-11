<?php

namespace App\Services\CategoryAnalysis;

use RuntimeException;

/** TASK-0016: el análisis no se ejecutó (desactivado, selección inválida o límite de frecuencia). Mensaje en español para el administrador. */
final class CategoryAnalysisException extends RuntimeException
{
}

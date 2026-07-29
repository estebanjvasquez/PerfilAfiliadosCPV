<?php

namespace App\Filament\Support;

/**
 * Genera graficos como SVG inline para el PDF del Tablero Gerencial
 * (resources/views/pdf/gerencia-dashboard.blade.php). dompdf no ejecuta
 * Chart.js (JS), pero renderiza SVG nativo, asi que estos metodos calculan
 * la geometria en PHP y devuelven markup <svg> listo para imprimir con
 * {!! !!} en la vista. Toda etiqueta de texto se escapa con htmlspecialchars()
 * porque los datos vienen de catalogos editables (sectores, servicios,
 * camaras).
 */
class PdfCharts
{
    private const PALETTE = ['#0284c7', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316', '#d946ef', '#6366f1', '#0ea5e9'];

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }

    /**
     * Barras verticales, una sola serie. Para segmentacion por calidad,
     * empleo, facturacion, composicion de capital, certificaciones (vertical).
     */
    public static function bar(array $labels, array $values, string | array $color = '#0284c7', int $width = 500, int $height = 190): string
    {
        $count = count($values);
        if ($count === 0) {
            return self::empty($width, $height);
        }

        $colors = is_array($color) ? $color : array_fill(0, $count, $color);
        $max = max(1, max($values));
        $padLeft = 30;
        $padBottom = 34;
        $chartW = $width - $padLeft - 10;
        $chartH = $height - $padBottom - 20;
        $slot = $chartW / $count;
        $barW = $slot * 0.55;

        $bars = '';
        foreach ($values as $i => $value) {
            $barH = $max > 0 ? ($value / $max) * $chartH : 0;
            $x = $padLeft + ($i * $slot) + (($slot - $barW) / 2);
            $y = 20 + ($chartH - $barH);
            $label = self::esc(self::truncate((string) ($labels[$i] ?? ''), 14));
            $barColor = $colors[$i % count($colors)];

            $bars .= sprintf(
                '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" rx="2"/>'
                . '<text x="%.1f" y="%.1f" font-size="10" text-anchor="middle" fill="#1f2937">%s</text>'
                . '<text x="%.1f" y="%.1f" font-size="9" text-anchor="middle" fill="#6b7280">%s</text>',
                $x, $y, $barW, $barH, $barColor,
                $x + ($barW / 2), max(12, $y - 4), $value,
                $x + ($barW / 2), $height - $padBottom + 12, $label
            );
        }

        return self::wrap($width, $height, $bars);
    }

    /**
     * Barras verticales agrupadas de 2 series (ej. No Aplica completo/parcial).
     */
    public static function groupedBar(array $labels, array $seriesA, array $seriesB, string $labelA, string $labelB, string $colorA, string $colorB, int $width = 500, int $height = 200): string
    {
        $count = count($labels);
        if ($count === 0) {
            return self::empty($width, $height);
        }

        $max = max(1, max(array_merge($seriesA, $seriesB)));
        $padLeft = 20;
        $padBottom = 44;
        $chartW = $width - $padLeft - 10;
        $chartH = $height - $padBottom - 20;
        $slot = $chartW / $count;
        $barW = $slot * 0.32;

        $bars = '';
        foreach ($labels as $i => $label) {
            $slotX = $padLeft + ($i * $slot);

            foreach ([[$seriesA[$i] ?? 0, $colorA, 0], [$seriesB[$i] ?? 0, $colorB, 1]] as [$value, $color, $offset]) {
                $barH = $max > 0 ? ($value / $max) * $chartH : 0;
                $x = $slotX + ($slot * 0.15) + ($offset * ($barW + 4));
                $y = 16 + ($chartH - $barH);

                $bars .= sprintf(
                    '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" rx="2"/>'
                    . '<text x="%.1f" y="%.1f" font-size="9" text-anchor="middle" fill="#1f2937">%s</text>',
                    $x, $y, $barW, max(0, $barH), $color,
                    $x + ($barW / 2), max(11, $y - 3), $value
                );
            }

            $bars .= sprintf(
                '<text x="%.1f" y="%.1f" font-size="8.5" text-anchor="middle" fill="#6b7280">%s</text>',
                $slotX + ($slot / 2), $height - $padBottom + 24, self::esc(self::truncate((string) $label, 16))
            );
        }

        $legend = sprintf(
            '<rect x="0" y="0" width="9" height="9" fill="%s"/><text x="13" y="8.5" font-size="9" fill="#374151">%s</text>'
            . '<rect x="110" y="0" width="9" height="9" fill="%s"/><text x="123" y="8.5" font-size="9" fill="#374151">%s</text>',
            $colorA, self::esc($labelA), $colorB, self::esc($labelB)
        );

        return self::wrap($width, $height, $bars, $legend);
    }

    /**
     * Barras horizontales (para etiquetas largas: sectores, servicios,
     * estados, camaras). $rowHeight controla el alto total segun cantidad
     * de filas.
     */
    public static function horizontalBar(array $labels, array $values, string $color = '#0284c7', int $width = 500, int $rowHeight = 22, int $labelMax = 26): string
    {
        $count = count($values);
        if ($count === 0) {
            return self::empty($width, 40);
        }

        $height = $count * $rowHeight + 10;
        $max = max(1, max($values));
        $labelW = 150;
        $chartW = $width - $labelW - 40;

        $bars = '';
        foreach ($values as $i => $value) {
            $y = $i * $rowHeight;
            $barW = $max > 0 ? ($value / $max) * $chartW : 0;
            $label = self::esc(self::truncate((string) ($labels[$i] ?? ''), $labelMax));

            $bars .= sprintf(
                '<text x="%d" y="%.1f" font-size="9" text-anchor="end" fill="#374151">%s</text>'
                . '<rect x="%d" y="%.1f" width="%.1f" height="%.1f" fill="%s" rx="2"/>'
                . '<text x="%.1f" y="%.1f" font-size="9" fill="#1f2937">%s</text>',
                $labelW - 6, $y + ($rowHeight * 0.65), $label,
                $labelW, $y + 2, max(0, $barW), $rowHeight - 6, $color,
                $labelW + $barW + 5, $y + ($rowHeight * 0.65), $value
            );
        }

        return self::wrap($width, $height, $bars);
    }

    /**
     * Dona (part-to-whole), con leyenda a la derecha.
     */
    public static function donut(array $labels, array $values, ?array $colors = null, int $width = 500, int $height = 150): string
    {
        $total = array_sum($values);
        $colors ??= self::PALETTE;
        $cx = 75;
        $cy = $height / 2;
        $r = 55;
        $strokeW = 24;
        $circumference = 2 * M_PI * $r;

        $segments = '';
        $legend = '';
        $offset = 0;
        foreach ($values as $i => $value) {
            $fraction = $total > 0 ? $value / $total : 0;
            $len = $fraction * $circumference;
            $color = $colors[$i % count($colors)];

            $segments .= sprintf(
                '<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="%d" '
                . 'stroke-dasharray="%.2f %.2f" stroke-dashoffset="%.2f" transform="rotate(-90 %d %d)"/>',
                $cx, $cy, $r, $color, $strokeW, $len, $circumference - $len, -$offset, $cx, $cy
            );
            $offset += $len;

            $percent = $total > 0 ? round(100 * $value / $total) : 0;
            $legendY = 20 + ($i * 18);
            $legend .= sprintf(
                '<rect x="170" y="%d" width="10" height="10" fill="%s"/>'
                . '<text x="186" y="%d" font-size="10" fill="#374151">%s (%d — %d%%)</text>',
                $legendY, $color, $legendY + 9, self::esc((string) ($labels[$i] ?? '')), $value, $percent
            );
        }

        return self::wrap($width, $height, $segments . $legend);
    }

    /**
     * Radar (cobertura multi-dimensional 0-100).
     */
    public static function radar(array $labels, array $values, string $color = '#0284c7', int $size = 260): string
    {
        $count = count($labels);
        if ($count < 3) {
            return self::bar($labels, $values, $color, $size, 190);
        }

        $cx = $size / 2;
        $cy = ($size / 2) + 6;
        $r = ($size / 2) - 46;

        $grid = '';
        foreach ([0.33, 0.66, 1.0] as $ring) {
            $points = [];
            for ($i = 0; $i < $count; $i++) {
                $angle = (M_PI * 2 * $i / $count) - (M_PI / 2);
                $points[] = round($cx + ($r * $ring * cos($angle)), 1) . ',' . round($cy + ($r * $ring * sin($angle)), 1);
            }
            $grid .= sprintf('<polygon points="%s" fill="none" stroke="#e5e7eb" stroke-width="1"/>', implode(' ', $points));
        }

        $axes = '';
        $dataPoints = [];
        $texts = '';
        for ($i = 0; $i < $count; $i++) {
            $angle = (M_PI * 2 * $i / $count) - (M_PI / 2);
            $ex = $cx + ($r * cos($angle));
            $ey = $cy + ($r * sin($angle));
            $axes .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#e5e7eb" stroke-width="1"/>', $cx, $cy, $ex, $ey);

            $value = max(0, min(100, (float) ($values[$i] ?? 0)));
            $dataPoints[] = round($cx + ($r * ($value / 100) * cos($angle)), 1) . ',' . round($cy + ($r * ($value / 100) * sin($angle)), 1);

            $lx = $cx + (($r + 30) * cos($angle));
            $ly = $cy + (($r + 12) * sin($angle));
            $anchor = cos($angle) > 0.3 ? 'start' : (cos($angle) < -0.3 ? 'end' : 'middle');
            $texts .= sprintf(
                '<text x="%.1f" y="%.1f" font-size="9.5" text-anchor="%s" fill="#374151">%s (%d%%)</text>',
                $lx, $ly, $anchor, self::esc(self::truncate((string) $labels[$i], 16)), $value
            );
        }

        $polygon = sprintf(
            '<polygon points="%s" fill="%s" fill-opacity="0.25" stroke="%s" stroke-width="2"/>',
            implode(' ', $dataPoints), $color, $color
        );

        return self::wrap($size, $size, $grid . $axes . $polygon . $texts);
    }

    /**
     * Linea (serie de tiempo).
     */
    public static function line(array $labels, array $values, string $color = '#0284c7', int $width = 500, int $height = 190): string
    {
        $count = count($values);
        if ($count === 0) {
            return self::empty($width, $height);
        }

        $max = max(1, max($values));
        $padLeft = 20;
        $padBottom = 34;
        $chartW = $width - $padLeft - 20;
        $chartH = $height - $padBottom - 20;
        $step = $count > 1 ? $chartW / ($count - 1) : 0;

        $points = [];
        $markers = '';
        foreach ($values as $i => $value) {
            $x = $padLeft + ($i * $step);
            $y = 16 + ($chartH - (($value / $max) * $chartH));
            $points[] = round($x, 1) . ',' . round($y, 1);

            $markers .= sprintf(
                '<circle cx="%.1f" cy="%.1f" r="2.5" fill="%s"/>'
                . '<text x="%.1f" y="%.1f" font-size="8.5" text-anchor="middle" fill="#1f2937">%s</text>'
                . '<text x="%.1f" y="%.1f" font-size="8" text-anchor="middle" fill="#6b7280">%s</text>',
                $x, $y, $color,
                $x, max(10, $y - 6), $value,
                $x, $height - $padBottom + 22, self::esc((string) ($labels[$i] ?? ''))
            );
        }

        $polyline = sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="2"/>', implode(' ', $points), $color);

        return self::wrap($width, $height, $polyline . $markers);
    }

    private static function wrap(int $width, int $height, string $content, string $overlay = ''): string
    {
        return sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg">%s%s</svg>',
            $width, $height, $width, $height, $content, $overlay
        );
    }

    private static function empty(int $width, int $height): string
    {
        return sprintf(
            '<svg width="%d" height="%d" viewBox="0 0 %d %d" xmlns="http://www.w3.org/2000/svg">'
            . '<text x="%d" y="%d" font-size="10" text-anchor="middle" fill="#9ca3af">Sin datos</text></svg>',
            $width, $height, $width, $height, $width / 2, $height / 2
        );
    }
}

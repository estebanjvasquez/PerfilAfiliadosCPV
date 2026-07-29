<?php

namespace App\Filament\Support;

/**
 * Genera graficos como imagenes PNG (via GD) para el PDF del Tablero
 * Gerencial (resources/views/pdf/gerencia-dashboard.blade.php).
 *
 * dompdf 2.0.1 no tiene NINGUN renderer para el tag <svg> (confirmado
 * revisando vendor/dompdf/dompdf/src: no existe ningun FrameReflower ni
 * Renderer para "svg" — solo para los tags HTML estandar). El SVG inline
 * simplemente se descarta durante el layout, por eso los graficos no
 * aparecian pese a que el HTML generado los incluia. dompdf si soporta de
 * forma nativa <img> con data URI base64, asi que estos metodos dibujan
 * con las primitivas de GD y devuelven un tag <img> con el PNG embebido.
 *
 * Requiere la extension GD en el servidor (viene habilitada por defecto en
 * XAMPP). Si no esta disponible, cada metodo cae a un placeholder de texto
 * en vez de fallar.
 */
class PdfCharts
{
    private const PALETTE = ['#0284c7', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#f97316', '#d946ef', '#6366f1', '#0ea5e9'];

    // Se dibuja a 2x y se reescala por CSS (width:100%) para que el texto salga nitido al imprimir.
    private const SCALE = 2;

    private static function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }

    private static function font(bool $bold = false): ?string
    {
        // Ruta relativa (no base_path()) para no depender de que el
        // contenedor de Laravel este arrancado.
        $path = dirname(__DIR__, 3) . '/vendor/dompdf/dompdf/lib/fonts/' . ($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf');

        return is_file($path) ? $path : null;
    }

    /**
     * @return int Identificador de color GD.
     */
    private static function rgb($im, string $hex, int $alpha = 0): int
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        return $alpha > 0 ? imagecolorallocatealpha($im, $r, $g, $b, $alpha) : imagecolorallocate($im, $r, $g, $b);
    }

    /**
     * Dibuja texto con la fuente TTF que ya trae dompdf (soporta acentos/UTF-8
     * de forma nativa). Si GD no tiene FreeType compilado, cae a la fuente
     * bitmap interna de GD (mas fea pero siempre disponible, nunca falla).
     */
    private static function text($im, float $size, float $x, float $y, int $color, string $text, string $align = 'left'): void
    {
        $font = self::font();

        if ($font !== null && function_exists('imagettftext')) {
            $box = @imagettfbbox($size, 0, $font, $text);
            if ($box !== false) {
                $w = $box[2] - $box[0];
                $tx = $align === 'center' ? $x - ($w / 2) : ($align === 'end' ? $x - $w : $x);
                @imagettftext($im, $size, 0, (int) round($tx), (int) round($y), $color, $font, $text);

                return;
            }
        }

        $gdFont = 3;
        $w = imagefontwidth($gdFont) * strlen($text);
        $tx = $align === 'center' ? $x - ($w / 2) : ($align === 'end' ? $x - $w : $x);
        imagestring($im, $gdFont, (int) round($tx), (int) round($y - imagefontheight($gdFont)), $text, $color);
    }

    private static function canvas(int $width, int $height)
    {
        $s = self::SCALE;
        $im = imagecreatetruecolor($width * $s, $height * $s);
        imagealphablending($im, true);
        imageantialias($im, true);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $width * $s, $height * $s, $white);

        return $im;
    }

    private static function output($im): string
    {
        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);

        return '<img src="data:image/png;base64,' . base64_encode($data) . '" style="width:100%;height:auto;display:block;">';
    }

    private static function unavailable(int $height): string
    {
        return sprintf(
            '<div style="width:100%%;height:%dpx;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:9px;border:1px dashed #d1d5db;">Gráfico no disponible (extensión GD no habilitada en el servidor)</div>',
            max(40, $height)
        );
    }

    private static function empty(int $width, int $height): string
    {
        $im = self::canvas($width, $height);
        $gray = self::rgb($im, '#9ca3af');
        self::text($im, 10 * self::SCALE, ($width / 2) * self::SCALE, ($height / 2) * self::SCALE, $gray, 'Sin datos', 'center');

        return self::output($im);
    }

    /**
     * Barras verticales, una sola serie. Para segmentacion por calidad,
     * empleo, facturacion, composicion de capital, certificaciones (vertical).
     */
    public static function bar(array $labels, array $values, string | array $color = '#0284c7', int $width = 500, int $height = 190): string
    {
        if (! extension_loaded('gd')) {
            return self::unavailable($height);
        }

        $count = count($values);
        if ($count === 0) {
            return self::empty($width, $height);
        }

        $s = self::SCALE;
        $colors = is_array($color) ? $color : array_fill(0, $count, $color);
        $max = max(1, max($values));
        $padLeft = 30;
        $padBottom = 34;
        $chartW = $width - $padLeft - 10;
        $chartH = $height - $padBottom - 20;
        $slot = $chartW / $count;
        $barW = $slot * 0.55;

        $im = self::canvas($width, $height);
        $dark = self::rgb($im, '#1f2937');
        $gray = self::rgb($im, '#6b7280');

        foreach ($values as $i => $value) {
            $barH = $max > 0 ? ($value / $max) * $chartH : 0;
            $x = $padLeft + ($i * $slot) + (($slot - $barW) / 2);
            $y = 20 + ($chartH - $barH);
            $label = self::truncate((string) ($labels[$i] ?? ''), 14);
            $barColor = self::rgb($im, $colors[$i % count($colors)]);

            imagefilledrectangle(
                $im,
                (int) round($x * $s), (int) round($y * $s),
                (int) round(($x + $barW) * $s), (int) round(($y + $barH) * $s),
                $barColor
            );
            self::text($im, 10 * $s, ($x + $barW / 2) * $s, max(12, $y - 4) * $s, $dark, (string) $value, 'center');
            self::text($im, 9 * $s, ($x + $barW / 2) * $s, ($height - $padBottom + 12) * $s, $gray, $label, 'center');
        }

        return self::output($im);
    }

    /**
     * Barras verticales agrupadas de 2 series (ej. No Aplica completo/parcial).
     */
    public static function groupedBar(array $labels, array $seriesA, array $seriesB, string $labelA, string $labelB, string $colorA, string $colorB, int $width = 500, int $height = 200): string
    {
        if (! extension_loaded('gd')) {
            return self::unavailable($height);
        }

        $count = count($labels);
        if ($count === 0) {
            return self::empty($width, $height);
        }

        $s = self::SCALE;
        $max = max(1, max(array_merge($seriesA, $seriesB)));
        $padLeft = 20;
        $padBottom = 44;
        $chartW = $width - $padLeft - 10;
        $chartH = $height - $padBottom - 20;
        $slot = $chartW / $count;
        $barW = $slot * 0.32;

        $im = self::canvas($width, $height);
        $dark = self::rgb($im, '#1f2937');
        $gray = self::rgb($im, '#6b7280');
        $textDark = self::rgb($im, '#374151');
        $gdColorA = self::rgb($im, $colorA);
        $gdColorB = self::rgb($im, $colorB);

        foreach ($labels as $i => $label) {
            $slotX = $padLeft + ($i * $slot);

            foreach ([[$seriesA[$i] ?? 0, $gdColorA, 0], [$seriesB[$i] ?? 0, $gdColorB, 1]] as [$value, $gdColor, $offset]) {
                $barH = $max > 0 ? ($value / $max) * $chartH : 0;
                $x = $slotX + ($slot * 0.15) + ($offset * ($barW + 4));
                $y = 16 + ($chartH - $barH);

                imagefilledrectangle(
                    $im,
                    (int) round($x * $s), (int) round($y * $s),
                    (int) round(($x + $barW) * $s), (int) round(($y + max(0, $barH)) * $s),
                    $gdColor
                );
                self::text($im, 9 * $s, ($x + $barW / 2) * $s, max(11, $y - 3) * $s, $dark, (string) $value, 'center');
            }

            self::text($im, 8.5 * $s, ($slotX + $slot / 2) * $s, ($height - $padBottom + 24) * $s, $gray, self::truncate((string) $label, 16), 'center');
        }

        imagefilledrectangle($im, 0, 0, (int) round(9 * $s), (int) round(9 * $s), $gdColorA);
        self::text($im, 9 * $s, 13 * $s, 8.5 * $s, $textDark, $labelA, 'left');
        imagefilledrectangle($im, (int) round(110 * $s), 0, (int) round(119 * $s), (int) round(9 * $s), $gdColorB);
        self::text($im, 9 * $s, 123 * $s, 8.5 * $s, $textDark, $labelB, 'left');

        return self::output($im);
    }

    /**
     * Barras horizontales (para etiquetas largas: sectores, servicios,
     * estados, camaras). $rowHeight controla el alto total segun cantidad
     * de filas.
     */
    public static function horizontalBar(array $labels, array $values, string $color = '#0284c7', int $width = 500, int $rowHeight = 22, int $labelMax = 26, int $labelW = 150): string
    {
        $count = count($values);

        if (! extension_loaded('gd')) {
            return self::unavailable($count === 0 ? 40 : $count * $rowHeight + 10);
        }

        if ($count === 0) {
            return self::empty($width, 40);
        }

        $height = $count * $rowHeight + 10;
        $max = max(1, max($values));
        $chartW = $width - $labelW - 40;
        $s = self::SCALE;

        $im = self::canvas($width, $height);
        $dark = self::rgb($im, '#1f2937');
        $textGray = self::rgb($im, '#374151');
        $barColor = self::rgb($im, $color);

        foreach ($values as $i => $value) {
            $y = $i * $rowHeight;
            $barW = $max > 0 ? ($value / $max) * $chartW : 0;
            $label = self::truncate((string) ($labels[$i] ?? ''), $labelMax);

            self::text($im, 9 * $s, ($labelW - 6) * $s, ($y + $rowHeight * 0.65) * $s, $textGray, $label, 'end');
            imagefilledrectangle(
                $im,
                (int) round($labelW * $s), (int) round(($y + 2) * $s),
                (int) round(($labelW + max(0, $barW)) * $s), (int) round(($y + $rowHeight - 4) * $s),
                $barColor
            );
            self::text($im, 9 * $s, ($labelW + $barW + 5) * $s, ($y + $rowHeight * 0.65) * $s, $dark, (string) $value, 'left');
        }

        return self::output($im);
    }

    /**
     * Dona (part-to-whole), con leyenda a la derecha. GD no tiene un
     * primitivo de "anillo con stroke" como SVG, asi que se dibuja como pie
     * chart (imagefilledarc) y se perfora el centro con un circulo blanco
     * para simular la dona.
     */
    public static function donut(array $labels, array $values, ?array $colors = null, int $width = 500, int $height = 150): string
    {
        if (! extension_loaded('gd')) {
            return self::unavailable($height);
        }

        $total = array_sum($values);
        if ($total <= 0) {
            return self::empty($width, $height);
        }

        $colors ??= self::PALETTE;
        $s = self::SCALE;
        $cx = 75;
        $cy = $height / 2;
        $outerR = 55 + 12;
        $innerR = 55 - 12;

        $im = self::canvas($width, $height);
        $textGray = self::rgb($im, '#374151');
        $white = self::rgb($im, '#ffffff');

        $startDeg = -90;
        foreach ($values as $i => $value) {
            $fraction = $value / $total;
            $sweep = $fraction * 360;
            $gdColor = self::rgb($im, $colors[$i % count($colors)]);

            if ($sweep > 0) {
                imagefilledarc(
                    $im,
                    (int) round($cx * $s), (int) round($cy * $s),
                    (int) round($outerR * 2 * $s), (int) round($outerR * 2 * $s),
                    (int) round($startDeg), (int) round($startDeg + max($sweep, 0.5)),
                    $gdColor, IMG_ARC_PIE
                );
            }

            $startDeg += $sweep;

            $percent = round(100 * $value / $total);
            $legendY = 20 + ($i * 18);
            imagefilledrectangle(
                $im,
                (int) round(170 * $s), (int) round($legendY * $s),
                (int) round(180 * $s), (int) round(($legendY + 10) * $s),
                $gdColor
            );
            self::text($im, 10 * $s, 186 * $s, ($legendY + 9) * $s, $textGray, sprintf('%s (%d — %d%%)', (string) ($labels[$i] ?? ''), $value, $percent), 'left');
        }

        imagefilledellipse($im, (int) round($cx * $s), (int) round($cy * $s), (int) round($innerR * 2 * $s), (int) round($innerR * 2 * $s), $white);

        return self::output($im);
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

        if (! extension_loaded('gd')) {
            return self::unavailable($size);
        }

        $s = self::SCALE;
        // Lienzo mas ancho que el circulo: las etiquetas de los ejes
        // izquierdo/derecho necesitan margen extra o quedan cortadas fuera
        // del canvas (no hay "overflow visible" en una imagen rasterizada).
        $width = $size + 320;
        $height = $size;
        $cx = $width / 2;
        $cy = ($height / 2) + 6;
        $r = ($size / 2) - 46;

        $im = self::canvas($width, $height);
        $gridColor = self::rgb($im, '#e5e7eb');
        $textGray = self::rgb($im, '#374151');
        $fillColor = self::rgb($im, $color, 95);
        $strokeColor = self::rgb($im, $color);

        foreach ([0.33, 0.66, 1.0] as $ring) {
            $points = [];
            for ($i = 0; $i < $count; $i++) {
                $angle = (M_PI * 2 * $i / $count) - (M_PI / 2);
                $points[] = ($cx + ($r * $ring * cos($angle))) * $s;
                $points[] = ($cy + ($r * $ring * sin($angle))) * $s;
            }
            imagepolygon($im, $points, $gridColor);
        }

        $dataPoints = [];
        for ($i = 0; $i < $count; $i++) {
            $angle = (M_PI * 2 * $i / $count) - (M_PI / 2);
            $ex = $cx + ($r * cos($angle));
            $ey = $cy + ($r * sin($angle));
            imageline($im, (int) round($cx * $s), (int) round($cy * $s), (int) round($ex * $s), (int) round($ey * $s), $gridColor);

            $value = max(0, min(100, (float) ($values[$i] ?? 0)));
            $dataPoints[] = ($cx + ($r * ($value / 100) * cos($angle))) * $s;
            $dataPoints[] = ($cy + ($r * ($value / 100) * sin($angle))) * $s;
        }

        imagefilledpolygon($im, $dataPoints, $fillColor);
        imagesetthickness($im, max(1, (int) round(2 * $s)));
        imagepolygon($im, $dataPoints, $strokeColor);
        imagesetthickness($im, 1);

        for ($i = 0; $i < $count; $i++) {
            $angle = (M_PI * 2 * $i / $count) - (M_PI / 2);
            $lx = $cx + (($r + 30) * cos($angle));
            $ly = $cy + (($r + 12) * sin($angle));
            $anchor = cos($angle) > 0.3 ? 'left' : (cos($angle) < -0.3 ? 'end' : 'center');
            $value = max(0, min(100, (float) ($values[$i] ?? 0)));
            self::text($im, 9.5 * $s, $lx * $s, $ly * $s, $textGray, sprintf('%s (%d%%)', self::truncate((string) $labels[$i], 13), $value), $anchor);
        }

        return self::output($im);
    }

    /**
     * Linea (serie de tiempo).
     */
    public static function line(array $labels, array $values, string $color = '#0284c7', int $width = 500, int $height = 190): string
    {
        if (! extension_loaded('gd')) {
            return self::unavailable($height);
        }

        $count = count($values);
        if ($count === 0) {
            return self::empty($width, $height);
        }

        $s = self::SCALE;
        $max = max(1, max($values));
        $padLeft = 20;
        $padBottom = 34;
        $chartW = $width - $padLeft - 20;
        $chartH = $height - $padBottom - 20;
        $step = $count > 1 ? $chartW / ($count - 1) : 0;

        $im = self::canvas($width, $height);
        $dark = self::rgb($im, '#1f2937');
        $gray = self::rgb($im, '#6b7280');
        $lineColor = self::rgb($im, $color);

        $points = [];
        foreach ($values as $i => $value) {
            $x = $padLeft + ($i * $step);
            $y = 16 + ($chartH - (($value / $max) * $chartH));
            $points[] = [$x, $y];
        }

        imagesetthickness($im, max(1, (int) round(2 * $s)));
        for ($i = 1; $i < count($points); $i++) {
            imageline(
                $im,
                (int) round($points[$i - 1][0] * $s), (int) round($points[$i - 1][1] * $s),
                (int) round($points[$i][0] * $s), (int) round($points[$i][1] * $s),
                $lineColor
            );
        }
        imagesetthickness($im, 1);

        foreach ($points as $i => [$x, $y]) {
            imagefilledellipse($im, (int) round($x * $s), (int) round($y * $s), (int) round(5 * $s), (int) round(5 * $s), $lineColor);
            self::text($im, 8.5 * $s, $x * $s, max(10, $y - 6) * $s, $dark, (string) $values[$i], 'center');
            self::text($im, 8 * $s, $x * $s, ($height - $padBottom + 22) * $s, $gray, (string) ($labels[$i] ?? ''), 'center');
        }

        return self::output($im);
    }
}

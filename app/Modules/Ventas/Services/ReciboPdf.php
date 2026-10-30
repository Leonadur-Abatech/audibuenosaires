<?php
namespace App\Modules\Ventas\Services;

use App\Core\Config;
use App\Core\Pdf;
use App\Core\Settings;

/**
 * PDF del recibo de seña (estilo "recibo de caja", diseño minimalista). Membrete + color de acento
 * por marca (config/concesionaria.php). Respeta la disposición del modelo del usuario:
 * encabezado con marca + RECIBO/N°, grilla de datos en 2 columnas, "DETALLE DE VALORES RECIBIDOS",
 * total, concepto y firma. Sin datos de imprenta / CAI. Moneda siempre USD.
 */
class ReciboPdf
{
    /** @return string bytes del PDF */
    public static function generar(array $rec): string
    {
        $marca  = strtoupper((string) ($rec['marca'] ?? ''));
        $conc   = self::letterhead($marca);
        $accent = self::rgb($conc['color'] ?? [25, 25, 25]);
        $gray   = [110, 110, 110];
        $hair   = [200, 198, 194];

        [$monedaLabel, $totalTxt] = self::formatoMoneda($rec);
        $importeSolo = number_format((float) ($rec['importe'] ?? 0), 0, ',', '.');

        $fecha = substr((string) ($rec['creado_en'] ?? ''), 0, 10);
        $fd    = $fecha ? (\DateTime::createFromFormat('Y-m-d', $fecha) ?: null) : null;
        $fecha = $fd ? $fd->format('d/m/Y') : date('d/m/Y');

        $pdf = new Pdf();
        $pdf->addPage();
        $L = 18.0; $R = $pdf->pageWidth() - 18.0; $W = $R - $L;

        // ── Encabezado ──
        // Bloque derecho primero (define hasta dónde puede llegar el membrete de la izquierda).
        $pdf->setFont('', 8); $pdf->setTextColor(...$gray); $pdf->text($R, 20, 'R E C I B O   D E   C A J A', 'R');
        $pdf->setFont('B', 15); $pdf->setTextColor(25); $pdf->text($R, 28, 'N° ' . (string) ($rec['numero'] ?? ''), 'R');
        $pdf->setFont('', 8); $pdf->setTextColor(...$gray); $pdf->text($R, 34, 'Buenos Aires, ' . $fecha, 'R');

        self::brandName($pdf, $L, 26, 17, (string) ($conc['nombre_marca'] ?? $marca), $accent);
        $bits = [];
        if (!empty($conc['condicion_iva']))    { $bits[] = 'IVA: ' . $conc['condicion_iva']; }
        if (!empty($conc['cuit']))             { $bits[] = 'CUIT ' . $conc['cuit']; }
        if (!empty($conc['iibb']))             { $bits[] = 'Ing. Brutos ' . $conc['iibb']; }
        if (!empty($conc['inicio_actividad'])) { $bits[] = 'Inicio ' . $conc['inicio_actividad']; }
        // Membrete: razón social / domicilios / datos fiscales, recortados al ancho izquierdo (no
        // deben invadir el bloque "RECIBO / N°" de la derecha).
        $leftW = 126.0; $ly = 31.0;
        foreach ([
            (string) ($conc['razon_social'] ?? ''),
            implode('   ·   ', (array) ($conc['domicilios'] ?? [])),
            implode('   ·   ', $bits),
        ] as $txt) {
            $txt = trim($txt, " \xC2\xB7·");
            if ($txt === '') { continue; }
            $pdf->setFont('', 7); $pdf->setTextColor(...$gray);
            $pdf->text($L, $ly, self::clip($pdf, $txt, $leftW)); $ly += 3.7;
        }
        $pdf->setDrawColor(...$hair); $pdf->line($L, 42, $R, 42, 0.4);

        // Título de sección minimal (tick de color + label + hairline).
        $sect = function (float $y, string $txt) use ($pdf, $L, $R, $accent, $hair) {
            $pdf->setFillColor(...$accent); $pdf->rect($L, $y - 3.2, 1.5, 4, 'F');
            $pdf->setFont('B', 8); $pdf->setTextColor(90); $pdf->text($L + 4, $y, mb_strtoupper($txt, 'UTF-8'));
            $pdf->setDrawColor(...$hair); $pdf->line($L, $y + 2.5, $R, $y + 2.5, 0.3);
        };
        $up = fn($s) => mb_strtoupper((string) $s, 'UTF-8');

        // ── Datos: cliente y auto (sin repetir lo que ya está en el encabezado / total) ──
        // Columna izquierda ancha (cliente / domicilio pueden ser largos), derecha angosta (datos cortos).
        $sect(51, 'Comprobante');
        $c1 = $L; $c2 = 136; $y = 60; $dy = 9.2;
        $rows = [
            ['Cliente',   (string) ($rec['cliente_nombre'] ?? ''),    'CUIL / CUIT',   self::formatDoc((string) ($rec['cliente_doc'] ?? ''))],
            ['Domicilio', (string) ($rec['cliente_domicilio'] ?? ''), 'Condición IVA', self::condFiscalLabel((string) ($rec['cliente_cond_fiscal'] ?? ''))],
            ['Modelo',    (string) ($rec['modelo'] ?: '—'),           'Interno',       (string) ($rec['interno'] ?: '—')],
            ['VIN',       (string) ($rec['vin'] ?: '—'),              '',              ''],
        ];
        foreach ($rows as $r) {
            $pdf->setFont('', 7.2); $pdf->setTextColor(...$gray); $pdf->text($c1, $y - 3.6, $up($r[0]));
            $pdf->setTextColor(25); self::fitText($pdf, $c1, $y + 1.2, $r[1] !== '' ? $r[1] : '—', $c2 - $c1 - 4);
            if ($r[2] !== '') {
                $pdf->setFont('', 7.2); $pdf->setTextColor(...$gray); $pdf->text($c2, $y - 3.6, $up($r[2]));
                $pdf->setTextColor(25); self::fitText($pdf, $c2, $y + 1.2, $r[3] !== '' ? $r[3] : '—', $R - $c2);
            }
            $y += $dy;
        }

        // ── Detalle de valores recibidos ── (arranca debajo de la grilla de datos)
        $dv = $y + 8.4;
        $sect($dv, 'Detalle de valores recibidos');
        $ty = $dv + 8;
        $pdf->setFont('', 6.8); $pdf->setTextColor(...$gray);
        $pdf->text($L, $ty, 'FECHA');
        $pdf->text($L + 40, $ty, 'MONEDA');
        $pdf->text($R, $ty, 'IMPORTE', 'R');
        $pdf->setDrawColor(...$hair); $pdf->line($L, $ty + 2, $R, $ty + 2, 0.3);
        $pdf->setFont('', 8.5); $pdf->setTextColor(30);
        $pdf->text($L, $ty + 8.4, $fecha);
        $pdf->text($L + 40, $ty + 8.4, $up($monedaLabel));
        $pdf->setFont('B', 9.8); $pdf->setTextColor(25); $pdf->text($R, $ty + 8.6, $importeSolo, 'R');   // importe ~15% más grande
        $pdf->setDrawColor(...$hair); $pdf->line($L, $ty + 12, $R, $ty + 12, 0.2);

        // ── Concepto ──
        $sect($dv + 28, 'Concepto');
        $pdf->setFont('', 10); $pdf->setTextColor(35);
        $cy = $dv + 36;
        foreach ($pdf->wrap((string) ($rec['concepto'] ?? ''), $W) as $ln) { $pdf->text($L, $cy, $ln); $cy += 5.6; }

        // ── Sello ANULADO ──
        if (!empty($rec['anulado'])) {
            $cx = $pdf->pageWidth() / 2;
            $pdf->setDrawColor(200, 30, 30);
            $pdf->rect($L + 30, 196, $W - 60, 22, 'D', 1.2);
            $pdf->setTextColor(200, 30, 30); $pdf->setFont('B', 32);
            $pdf->text($cx, 211, 'ANULADO', 'C');
            $pdf->setFont('', 9);
            $pdf->text($cx, 225, 'Anulado'
                . (!empty($rec['anulado_por']) ? ' por ' . $rec['anulado_por'] : '')
                . (!empty($rec['anulado_en']) ? ' el ' . substr((string) $rec['anulado_en'], 0, 10) : ''), 'C');
            $motivo = trim((string) ($rec['motivo_anulacion'] ?? ''));
            if ($motivo !== '') {
                $pdf->setTextColor(120);
                foreach ($pdf->wrap('Motivo: ' . $motivo, $W - 60) as $i => $ln) { $pdf->text($cx, 231 + $i * 4.5, $ln, 'C'); }
            }
        }

        // ── Firmas ──
        // Izquierda: firma digital (simulada) del usuario que emite + su nombre.
        $confec = trim((string) ($rec['confeccionado_por'] ?? ''));
        if ($confec !== '') { self::signature($pdf, $L + 2, 252, self::iniciales($confec), $accent); }
        $pdf->setDrawColor(...$hair); $pdf->line($L, 258, $L + 70, 258, 0.3);
        $pdf->setFont('', 7.5); $pdf->setTextColor(...$gray);
        $pdf->text($L, 263, 'Firma del vendedor' . ($confec !== '' ? ' · ' . $confec : ''));

        // Derecha: firma del cliente (solo firma; la aclaración ya va impresa con su nombre).
        $pdf->setDrawColor(...$hair); $pdf->line($R - 70, 258, $R, 258, 0.3);
        $pdf->setFont('', 7.5); $pdf->setTextColor(...$gray);
        $pdf->text($R - 70, 263, 'Firma: ' . self::clip($pdf, (string) ($rec['cliente_nombre'] ?? ''), 60));

        return $pdf->output();
    }

    /** Formatea CUIT/CUIL de 11 dígitos como 20-26363570-2 (si no tiene 11 dígitos, lo deja igual). */
    private static function formatDoc(string $doc): string
    {
        $d = preg_replace('/\D/', '', $doc);
        if (strlen($d) === 11) { return substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . substr($d, 10, 1); }
        return trim($doc);
    }

    /** Iniciales (primera + última palabra) para la firma. "Leo Nadur" ⇒ "LN". */
    private static function iniciales(string $nombre): string
    {
        $words = array_values(array_filter(preg_split('/\s+/u', $nombre) ?: [], fn($w) => preg_match('/\p{L}/u', $w)));
        if (!$words) { return ''; }
        $ini = mb_strtoupper(mb_substr($words[0], 0, 1, 'UTF-8'), 'UTF-8');
        if (count($words) > 1) { $ini .= mb_strtoupper(mb_substr(end($words), 0, 1, 'UTF-8'), 'UTF-8'); }
        return $ini;
    }

    /** Firma digital simulada: las iniciales en grande + una rúbrica (swoosh) debajo. */
    private static function signature(Pdf $pdf, float $x, float $y, string $iniciales, array $color): void
    {
        if ($iniciales === '') { return; }
        $pdf->setFont('B', 20);
        $pdf->setTextColor(...$color);
        $pdf->text($x, $y, $iniciales);
        self::swoosh($pdf, $x - 2, $y + 2.8, max($pdf->stringWidth($iniciales) + 10, 24), $color);
    }

    /** Rúbrica: arco con colita al final, dibujado con segmentos (imita el trazo de una firma). */
    private static function swoosh(Pdf $pdf, float $x, float $y, float $w, array $color): void
    {
        $pdf->setDrawColor(...$color);
        $n = 44; $px = $x; $py = $y;
        for ($i = 1; $i <= $n; $i++) {
            $t = $i / $n;
            $cx = $x + $t * $w;
            $cy = $y - sin($t * M_PI) * 2.0 - ($t > 0.82 ? ($t - 0.82) * 14 : 0);
            $pdf->line($px, $py, $cx, $cy, 0.4);
            $px = $cx; $py = $cy;
        }
    }

    /** Nombre de marca a dos colores: primera palabra en el acento, resto en gris oscuro. */
    private static function brandName(Pdf $pdf, float $x, float $y, float $size, string $nombre, array $accent): void
    {
        $parts = explode(' ', trim($nombre), 2);
        $pdf->setFont('B', $size);
        $pdf->setTextColor(...$accent);
        $pdf->text($x, $y, $parts[0]);
        if (isset($parts[1]) && $parts[1] !== '') {
            $w = $pdf->stringWidth($parts[0] . ' ');
            $pdf->setTextColor(25);
            $pdf->text($x + $w, $y, $parts[1]);
        }
    }

    /** Valor en negrita que entra en $maxW (mm): achica la fuente hasta $min y, si igual no entra, recorta con '…'. */
    private static function fitText(Pdf $pdf, float $x, float $y, string $txt, float $maxW, float $size = 9.8, float $min = 6.5): void
    {
        $pdf->setFont('B', $size);
        while ($size > $min && $pdf->stringWidth($txt) > $maxW) { $size -= 0.2; $pdf->setFont('B', $size); }
        $pdf->text($x, $y, self::clip($pdf, $txt, $maxW));
    }

    /** Recorta un texto con '…' para que entre en $maxW (mm) con la fuente/tamaño actual. */
    private static function clip(Pdf $pdf, string $txt, float $maxW): string
    {
        if ($pdf->stringWidth($txt) <= $maxW) { return $txt; }
        while ($txt !== '' && $pdf->stringWidth($txt . '…') > $maxW) { $txt = mb_substr($txt, 0, -1, 'UTF-8'); }
        return rtrim($txt) . '…';
    }

    /** @param array $c RGB [r,g,b] o gris [g]; devuelve [r,g,b]. */
    private static function rgb($c): array
    {
        if (!is_array($c) || !$c) { return [40, 40, 40]; }
        if (count($c) === 1) { return [(int) $c[0], (int) $c[0], (int) $c[0]]; }
        return [(int) $c[0], (int) $c[1], (int) $c[2]];
    }

    /**
     * Devuelve [etiqueta de moneda, total formateado]. USD ⇒ "Dólares" / "USD 1.500,00";
     * ARS/Pesos ⇒ "Pesos" / "$ 5.100.000,00".
     */
    public static function formatoMoneda(array $rec): array
    {
        $importe = (float) ($rec['importe'] ?? 0);
        $moneda  = strtoupper(trim((string) ($rec['moneda'] ?? 'USD')));
        $esPesos = in_array($moneda, ['ARS', 'PESOS', '$'], true);
        $label   = $esPesos ? 'Pesos' : 'Dólares';
        $total   = ($esPesos ? '$ ' : 'USD ') . number_format($importe, 0, ',', '.');
        return [$label, $total];
    }

    /** Condición fiscal del cliente (código de la reserva) ⇒ texto. 'resp_inscripto' ⇒ "Responsable Inscripto". */
    public static function condFiscalLabel(string $cod): string
    {
        $map = ['resp_inscripto' => 'Responsable Inscripto', 'consumidor_final' => 'Consumidor Final'];
        return $map[trim($cod)] ?? trim($cod);
    }

    /**
     * Membrete efectivo por marca: config/concesionaria.php (default + marca) con override de los
     * datos fiscales cargados en Admin → Configuración (settings 'recibo_fiscal_<MARCA>').
     */
    public static function letterhead(string $marca): array
    {
        $marca = strtoupper(trim($marca));
        $conf  = Config::get('concesionaria', []);
        $conc  = array_merge($conf['default'] ?? [], $conf['marcas'][$marca] ?? []);
        try { $ov = json_decode((string) Settings::get('recibo_fiscal_' . $marca, ''), true); }
        catch (\Throwable $e) { $ov = null; }   // sin DB/settings ⇒ usar la config por defecto
        if (is_array($ov)) {
            if (!empty($ov['razon']))            { $conc['razon_social'] = $ov['razon']; }
            if (!empty($ov['domicilio']))        { $conc['domicilios'] = [$ov['domicilio']]; }
            if (!empty($ov['cuit']))             { $conc['cuit'] = $ov['cuit']; }
            if (array_key_exists('iibb', $ov))   { $conc['iibb'] = $ov['iibb']; }
            if (array_key_exists('inicio', $ov)) { $conc['inicio_actividad'] = $ov['inicio']; }
            if (!empty($ov['iva']))              { $conc['condicion_iva'] = $ov['iva']; }
        }
        return $conc;
    }
}

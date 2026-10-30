<?php
namespace App\Modules\Ventas\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Mailer;
use App\Core\Parse;
use App\Core\Settings;
use App\Modules\Ventas\Models\Recibo;
use App\Modules\Ventas\Models\Reserva;
use App\Modules\Ventas\Services\ReciboPdf;

/**
 * Recibo de seña de una reserva aprobada. Lo pueden emitir el vendedor que la generó (esPropia) y
 * el supervisor que puede aprobar la marca (puedeAprobar). Uno por reserva. Se envía por mail (PDF
 * adjunto) a las direcciones configuradas por marca y al cliente.
 */
class ReciboController extends Controller
{

    /** ¿El usuario puede emitir el recibo de esta reserva? Vendedor propio o supervisor de la marca. */
    private function puede(array $r): bool
    {
        return Auth::isAdmin() || Reserva::esPropia($r) || Reserva::puedeAprobar($r);
    }

    /** Formulario de emisión (solo el importe; el email del cliente se confirma para el envío). */
    public function form(array $p = []): void
    {
        Auth::requireAccess('ventas_reservas');
        $r = Reserva::get((int) $p['id']);
        if (!$r) { $this->redirect('/ventas/reservas'); }
        if (!$this->puede($r)) {
            $this->flash('danger', 'No tenés permiso para emitir el recibo de esta reserva.');
            $this->redirect('/ventas/reservas/' . (int) $p['id']);
        }
        if (($r['estado'] ?? '') !== 'aprobada') {
            $this->flash('danger', 'El recibo se emite sobre una reserva aprobada.');
            $this->redirect('/ventas/reservas/' . (int) $p['id']);
        }
        $existente = Recibo::paraReserva((int) $r['id']);
        if ($existente) {
            $this->flash('info', 'Esta reserva ya tiene el recibo N° ' . $existente['numero'] . '.');
            $this->redirect('/ventas/reservas/' . (int) $p['id']);
        }

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $this->view('Ventas', 'reservas/recibo', [
            'title'      => 'Emitir recibo · Reserva N° ' . $r['numero'],
            'r'          => $r,
            'concepto'   => Recibo::conceptoPorDefecto($r),
            'brandMails' => Mailer::parseList((string) Settings::get('recibo_mails_' . strtoupper((string) $r['marca']), '')),
            'old'        => $_SESSION['old'] ?? [],
            'flash'      => $flash,
        ]);
        unset($_SESSION['old']);
    }

    /** Crea el recibo, genera el PDF y lo envía por mail (marca + cliente). */
    public function generar(array $p = []): void
    {
        Auth::requireAccess('ventas_reservas');
        $id = (int) $p['id'];
        $r  = Reserva::get($id);
        if (!$r) { $this->redirect('/ventas/reservas'); }
        if (!$this->puede($r)) {
            $this->flash('danger', 'No tenés permiso para emitir el recibo de esta reserva.');
            $this->redirect('/ventas/reservas/' . $id);
        }
        if (($r['estado'] ?? '') !== 'aprobada') {
            $this->flash('danger', 'El recibo se emite sobre una reserva aprobada.');
            $this->redirect('/ventas/reservas/' . $id);
        }

        $importe = Parse::num((string) ($_POST['importe'] ?? ''));
        $email   = trim((string) ($_POST['cliente_email'] ?? ($r['cliente_email'] ?? '')));
        $concepto = trim((string) ($_POST['concepto'] ?? '')) ?: Recibo::conceptoPorDefecto($r);
        if ($importe === null || $importe <= 0) {
            $_SESSION['old'] = $_POST;
            $this->flash('danger', 'Ingresá el importe de la seña (USD, mayor a cero).');
            $this->redirect('/ventas/reservas/' . $id . '/recibo');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['old'] = $_POST;
            $this->flash('danger', 'El email del cliente es obligatorio y debe ser válido para enviarle el recibo.');
            $this->redirect('/ventas/reservas/' . $id . '/recibo');
        }
        // Si la reserva no tenía email cargado, lo guardamos (el email del cliente es obligatorio).
        if (trim((string) ($r['cliente_email'] ?? '')) === '') {
            try { $this->db()->execute('UPDATE reservas SET cliente_email = ? WHERE id = ?', [$email, $id]); } catch (\Throwable $e) {}
        }

        $res = Recibo::crear($r, round((float) $importe), $concepto, $email);   // seña sin decimales
        if (empty($res['success'])) {
            $this->flash('danger', $res['error'] ?? 'No se pudo emitir el recibo.');
            $this->redirect('/ventas/reservas/' . $id);
        }
        $rec = Recibo::get((int) $res['id']);

        // Enviar por mail SOLO a las casillas configuradas por marca (NUNCA al cliente). Adjunta el PDF.
        $marca      = strtoupper((string) $r['marca']);
        $to         = Mailer::parseList((string) Settings::get('recibo_mails_' . $marca, ''));
        $sentMsg    = '';
        if (!$to) {
            $sentMsg = ' No hay casillas configuradas para ' . $marca . ' (Admin → Configuración); el recibo quedó generado y descargable.';
        } else {
            try {
                $pdfBytes = ReciboPdf::generar($rec);
                [$html, $text] = self::cuerpoMail($rec);
                $ok = Mailer::send($to, 'Recibo de seña N° ' . $rec['numero'] . ' · ' . $marca, $html, $text, [
                    ['name' => 'recibo-' . str_replace(['/', ' '], '-', (string) $rec['numero']) . '.pdf', 'content' => $pdfBytes, 'type' => 'application/pdf'],
                ]);
                if ($ok) {
                    Recibo::marcarEnviado((int) $rec['id'], $to);
                    $sentMsg = ' Enviado a: ' . implode(', ', $to) . '.';
                } else {
                    $sentMsg = ' No se pudo enviar el mail (' . (Mailer::lastError() ?? 'error') . '); podés descargar el PDF.';
                }
            } catch (\Throwable $e) {
                $sentMsg = ' No se pudo enviar el mail (' . $e->getMessage() . '); podés descargar el PDF.';
            }
        }

        $this->flash(strpos($sentMsg, 'No se pudo') !== false ? 'warning' : 'success',
            'Recibo N° ' . $rec['numero'] . ' emitido.' . $sentMsg);
        $this->redirect('/ventas/reservas/' . $id);
    }

    /** Descarga/visualiza el PDF del recibo (se regenera del registro). */
    public function pdf(array $p = []): void
    {
        Auth::requireAccess('ventas_reservas');
        $rec = Recibo::get((int) $p['id']);
        if (!$rec) { $this->redirect('/ventas/reservas'); }
        $r = Reserva::get((int) $rec['reserva_id']);
        if (!$r || !$this->puede($r)) {
            http_response_code(403);
            echo '<h1>403</h1><p>No tenés acceso a este recibo.</p>';
            return;
        }
        $bytes = ReciboPdf::generar($rec);
        $fname = 'recibo-' . str_replace(['/', ' '], '-', (string) $rec['numero']) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
    }

    /** Cuerpo del mail: tabla de datos del recibo (HTML + texto). */
    private static function cuerpoMail(array $rec): array
    {
        $h = fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        [$monedaLabel, $totalTxt] = ReciboPdf::formatoMoneda($rec);
        $fecha = substr((string) ($rec['creado_en'] ?? ''), 0, 10);
        $fd    = $fecha ? (\DateTime::createFromFormat('Y-m-d', $fecha) ?: null) : null;
        $fecha = $fd ? $fd->format('d/m/Y') : date('d/m/Y');

        $filas = [
            ['Recibo Nro',  $h($rec['numero'])],
            ['Fecha',       $fecha],
            ['Cliente',     $h($rec['cliente_nombre'])],
            ['CUIL/CUIT',   $h($rec['cliente_doc'])],
            ['Email',       $h($rec['cliente_email'])],
            ['Moneda',      $h($monedaLabel)],
            ['Total',       '<strong>' . $h($totalTxt) . '</strong>'],
        ];
        if (!empty($rec['vin']))     { $filas[] = ['VIN',     $h($rec['vin'])]; }
        if (!empty($rec['interno'])) { $filas[] = ['Interno', $h($rec['interno'])]; }
        if (!empty($rec['modelo']))  { $filas[] = ['Modelo',  $h($rec['modelo'])]; }
        $filas[] = ['Concepto',            $h($rec['concepto'])];
        $filas[] = ['Recibo emitido por',  $h($rec['confeccionado_por'])];

        $tr = '';
        foreach ($filas as $f) {
            $tr .= '<tr><td style="padding:6px 16px 6px 0;color:#6b7280;white-space:nowrap">' . $f[0]
                 . '</td><td style="padding:6px 0;font-weight:600">' . $f[1] . '</td></tr>';
        }
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#111827;font-size:14px">'
              . '<h2 style="margin:0 0 6px;font-size:18px">Recibo de seña N° ' . $h($rec['numero']) . '</h2>'
              . '<p style="margin:0 0 14px;color:#6b7280">Se adjunta el recibo en PDF. Detalle de la operación:</p>'
              . '<table style="border-collapse:collapse">' . $tr . '</table>'
              . '<p style="margin:18px 0 0;color:#9ca3af;font-size:12px">Documento no válido como factura.</p></div>';

        $text = 'Recibo de seña N° ' . $rec['numero'] . "\n\n";
        foreach ($filas as $f) { $text .= $f[0] . ': ' . html_entity_decode(strip_tags($f[1]), ENT_QUOTES, 'UTF-8') . "\n"; }
        $text .= "\nSe adjunta el recibo en PDF. Documento no válido como factura.\n";
        return [$html, $text];
    }
}

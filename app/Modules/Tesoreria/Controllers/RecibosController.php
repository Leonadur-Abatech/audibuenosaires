<?php
namespace App\Modules\Tesoreria\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Modules\Precios\Models\Precio;
use App\Modules\Ventas\Models\Recibo;
use App\Modules\Ventas\Services\ReciboPdf;

/**
 * Tesorería → Recibos. Listado con filtro por marca y buscador (cliente, auto, interno, VIN).
 * La anulación está gateada por la capacidad 'tesoreria_recibos_anular' (permiso aparte por usuario).
 */
class RecibosController extends Controller
{

    public function index(array $p = []): void
    {
        Auth::requireAccess('tesoreria_recibos');
        $filters = [
            'marca' => trim((string) ($_GET['marca'] ?? '')),
            'q'     => trim((string) ($_GET['q'] ?? '')),
        ];
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $this->view('Tesoreria', 'recibos/index', [
            'title'       => 'Recibos',
            'recibos'     => Recibo::listar($filters),
            'filters'     => $filters,
            'marcas'      => Precio::MARCAS,
            'puedeAnular' => Auth::canAccess('tesoreria_recibos_anular'),
            'flash'       => $flash,
        ]);
    }

    /** Descarga/visualiza el PDF del recibo (acceso por el permiso del módulo, no por la reserva). */
    public function pdf(array $p = []): void
    {
        Auth::requireAccess('tesoreria_recibos');
        $rec = Recibo::get((int) $p['id']);
        if (!$rec) { $this->redirect('/tesoreria/recibos'); }
        $bytes = ReciboPdf::generar($rec);
        $fname = 'recibo-' . str_replace(['/', ' '], '-', (string) $rec['numero']) . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        echo $bytes;
    }

    public function anular(array $p = []): void
    {
        Auth::requireAccess('tesoreria_recibos_anular');
        $id  = (int) $p['id'];
        $res = Recibo::anular($id, (string) ($_POST['motivo'] ?? ''), (int) Auth::id());
        $ok  = !empty($res['success']);
        $this->flash($ok ? 'success' : 'danger', $ok ? 'Recibo anulado.' : ($res['error'] ?? 'No se pudo anular.'));
        // Volver preservando los filtros de la lista.
        $qs = http_build_query(array_filter(['marca' => $_POST['marca'] ?? '', 'q' => $_POST['q'] ?? '']));
        $this->redirect('/tesoreria/recibos' . ($qs ? '?' . $qs : ''));
    }
}

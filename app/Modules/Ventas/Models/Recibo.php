<?php
namespace App\Modules\Ventas\Models;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Settings;

/**
 * Recibo de la seña de una reserva aprobada. Uno por reserva. Numeración correlativa por marca
 * ("0001-00000010") con número inicial y punto de venta configurables por marca (Admin → Config).
 * Todos los datos salen de la reserva; el único dato que se ingresa es el importe (siempre USD).
 */
class Recibo
{
    private static function db(): Database { return Database::instance(); }

    public static function get(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM recibos WHERE id = ?', [$id]);
    }

    /** Recibo ACTIVO (no anulado) de una reserva, o null. Un anulado libera a la reserva a re-emitir. */
    public static function paraReserva(int $reservaId): ?array
    {
        return self::db()->fetch('SELECT * FROM recibos WHERE reserva_id = ? AND anulado = 0', [$reservaId]);
    }

    /**
     * Lista de recibos para Tesorería. Filtros: 'marca' (exacta) y 'q' (busca en cliente, auto/modelo,
     * interno, VIN, número y documento). Más recientes primero.
     */
    public static function listar(array $f = []): array
    {
        $w = ['1=1']; $p = [];
        $marca = strtoupper(trim((string) ($f['marca'] ?? '')));
        if ($marca !== '') { $w[] = 'UPPER(marca) = ?'; $p[] = $marca; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $w[] = '(cliente_nombre LIKE ? OR modelo LIKE ? OR interno LIKE ? OR vin LIKE ? OR numero LIKE ? OR cliente_doc LIKE ?)';
            array_push($p, $like, $like, $like, $like, $like, $like);
        }
        $sql = 'SELECT * FROM recibos WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 1000';
        return self::db()->query($sql, $p);
    }

    /**
     * Anula un recibo (no lo borra; conserva el número). Requiere motivo. Si ya estaba anulado, error.
     * @return array{success:bool}|array{error:string}
     */
    public static function anular(int $id, string $motivo, int $userId): array
    {
        $rec = self::get($id);
        if (!$rec) { return ['error' => 'El recibo no existe.']; }
        if (!empty($rec['anulado'])) { return ['error' => 'El recibo ya está anulado.']; }
        if (trim($motivo) === '') { return ['error' => 'Indicá el motivo de la anulación.']; }
        $u = self::db()->fetch('SELECT nombre, email FROM users WHERE id = ?', [$userId]);
        self::db()->execute(
            "UPDATE recibos SET anulado = 1, anulado_por = ?, anulado_en = datetime('now','localtime'), motivo_anulacion = ?
             WHERE id = ? AND anulado = 0",
            [(string) ($u['nombre'] ?? $u['email'] ?? ''), trim($motivo), $id]
        );
        return ['success' => true];
    }

    /** Número inicial configurado para la marca (settings 'recibo_num_inicial_<MARCA>'); default 1. */
    public static function numeroInicial(string $marca): int
    {
        $n = (int) preg_replace('/\D/', '', (string) Settings::get('recibo_num_inicial_' . strtoupper($marca), ''));
        return $n > 0 ? $n : 1;
    }

    /** Punto de venta (prefijo de 4 dígitos) por marca; default "0001". */
    public static function puntoVenta(string $marca): string
    {
        $v = preg_replace('/\D/', '', (string) Settings::get('recibo_pv_' . strtoupper($marca), ''));
        return $v !== '' ? str_pad(substr($v, 0, 4), 4, '0', STR_PAD_LEFT) : '0001';
    }

    public static function formatNumero(string $marca, int $n): string
    {
        return self::puntoVenta($marca) . '-' . str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Crea el recibo de la seña de una reserva aprobada. $importe > 0, $moneda 'USD' o 'ARS'. El email
     * del cliente se toma de $clienteEmail (obligatorio) para poder enviárselo. Un recibo por reserva
     * (índice único).
     * @return array{success:bool,id:int,numero:string}|array{error:string}
     */
    public static function crear(array $reserva, float $importe, string $concepto, string $clienteEmail, string $moneda = 'USD'): array
    {
        $reservaId = (int) $reserva['id'];
        if ($importe <= 0) { return ['error' => 'El importe de la seña debe ser mayor a cero.']; }
        if (self::paraReserva($reservaId)) { return ['error' => 'Esta reserva ya tiene un recibo emitido.']; }
        $moneda = in_array(strtoupper($moneda), ['USD', 'ARS'], true) ? strtoupper($moneda) : 'USD';

        $db    = self::db();
        $marca = strtoupper(trim((string) ($reserva['marca'] ?? '')));

        // Interno / VIN de la unidad física (stock), si la reserva no es genérica.
        $vin     = trim((string) ($reserva['vin'] ?? ''));
        $interno = '';
        if (!empty($reserva['stock_id'])) {
            $s = $db->fetch('SELECT nro_interno, vin FROM stock WHERE id = ?', [(int) $reserva['stock_id']]);
            if ($s) {
                $interno = trim((string) ($s['nro_interno'] ?? ''));
                if ($vin === '') { $vin = trim((string) ($s['vin'] ?? '')); }
            }
        }

        $pdo = $db->pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if (self::paraReserva($reservaId)) { $pdo->exec('ROLLBACK'); return ['error' => 'Esta reserva ya tiene un recibo emitido.']; }

            $inicial = self::numeroInicial($marca);
            $db->execute('INSERT INTO recibo_seq (marca, contador) VALUES (?, 0) ON CONFLICT(marca) DO NOTHING', [$marca]);
            $actual = (int) ($db->fetch('SELECT contador FROM recibo_seq WHERE marca = ?', [$marca])['contador'] ?? 0);
            $next   = max($actual + 1, $inicial);
            $db->execute('UPDATE recibo_seq SET contador = ? WHERE marca = ?', [$next, $marca]);
            $numero = self::formatNumero($marca, $next);

            $u = $db->fetch('SELECT nombre, email FROM users WHERE id = ?', [(int) Auth::id()]);
            $db->execute(
                'INSERT INTO recibos
                   (numero, marca, reserva_id, reserva_numero, cliente_nombre, cliente_doc, cliente_email,
                    cliente_domicilio, cliente_cond_fiscal,
                    modelo, vin, interno, concepto, moneda, importe, confeccionado_por, creado_en)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, "USD", ?, ?, datetime("now","localtime"))',
                [
                    $numero, $marca, $reservaId, (int) $reserva['numero'],
                    (string) ($reserva['cliente_nombre'] ?? ''), (string) ($reserva['cliente_nro_doc'] ?? ''), $clienteEmail,
                    self::domicilio($reserva), (string) ($reserva['cliente_cond_fiscal'] ?? ''),
                    (string) ($reserva['modelo'] ?? ''), $vin, $interno, $concepto, $importe,
                    (string) ($u['nombre'] ?? $u['email'] ?? ''),
                ]
            );
            $id = (int) $db->lastInsertId();
            $pdo->exec('COMMIT');
            return ['success' => true, 'id' => $id, 'numero' => $numero];
        } catch (\Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (\Throwable $e2) {}
            return ['error' => $e->getMessage()];
        }
    }

    /** Domicilio del cliente de la reserva en una línea: "Calle 123, Localidad, Provincia (CP 1234)". '' si no tiene. */
    public static function domicilio(array $reserva): string
    {
        $f     = fn($k) => trim((string) ($reserva[$k] ?? ''));
        $calle = trim($f('cliente_calle') . ' ' . $f('cliente_numero'));
        $prov  = mb_strtoupper($f('cliente_provincia'), 'UTF-8') === mb_strtoupper($f('cliente_localidad'), 'UTF-8') ? '' : $f('cliente_provincia');
        $dom   = implode(', ', array_filter([$calle, $f('cliente_localidad'), $prov], fn($s) => $s !== ''));
        if ($f('cliente_cp') !== '') { $dom = trim($dom . ' (CP ' . $f('cliente_cp') . ')'); }
        return $dom;
    }

    /**
     * Recibo listo para el PDF. Los emitidos antes de la migración 072 tienen domicilio / condición
     * fiscal en NULL (no se guardaban): se completan desde la reserva. '' = se guardó vacío, se respeta.
     */
    public static function paraPdf(array $rec): array
    {
        if (($rec['cliente_domicilio'] ?? null) !== null && ($rec['cliente_cond_fiscal'] ?? null) !== null) { return $rec; }
        $r = self::db()->fetch('SELECT * FROM reservas WHERE id = ?', [(int) ($rec['reserva_id'] ?? 0)]);
        if (!$r) { return $rec; }
        $rec['cliente_domicilio']   = $rec['cliente_domicilio']   ?? self::domicilio($r);
        $rec['cliente_cond_fiscal'] = $rec['cliente_cond_fiscal'] ?? (string) ($r['cliente_cond_fiscal'] ?? '');
        return $rec;
    }

    /** Deja registro de a qué direcciones se envió el recibo. */
    public static function marcarEnviado(int $id, array $mails): void
    {
        $mails = array_values(array_unique(array_filter($mails)));
        if (!$mails) { return; }
        self::db()->execute('UPDATE recibos SET enviado_a = ? WHERE id = ?', [implode(', ', $mails), $id]);
    }

    /** Concepto por defecto. Para reservas genéricas (sin VIN/interno) agrega los datos del auto. */
    public static function conceptoPorDefecto(array $reserva): string
    {
        $c = 'Seña por reserva de unidad';
        if (!empty($reserva['es_generica'])) {
            $extra = trim(preg_replace('/\s+/', ' ',
                (string) ($reserva['modelo'] ?? '') . ' ' . (string) ($reserva['version'] ?? '') . ' ' . (string) ($reserva['color'] ?? '')));
            if ($extra !== '') { $c .= ' — ' . $extra; }
        }
        return $c;
    }
}

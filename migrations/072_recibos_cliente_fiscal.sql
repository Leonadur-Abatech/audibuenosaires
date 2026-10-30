-- Domicilio y condición fiscal del cliente en el recibo: se copian de la reserva al emitir, igual que
-- nombre y CUIT (el recibo no cambia si después se edita la reserva). Los recibos anteriores quedan en
-- NULL y el PDF los completa desde la reserva (Recibo::paraPdf).
ALTER TABLE recibos ADD COLUMN cliente_domicilio   TEXT;
ALTER TABLE recibos ADD COLUMN cliente_cond_fiscal TEXT;

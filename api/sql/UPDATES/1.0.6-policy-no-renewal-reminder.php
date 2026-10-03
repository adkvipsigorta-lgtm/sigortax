<?php
/**
 * Migration: policies tablosuna "yenileme gorevlerine ekleme" bayragi
 *
 * Bu .php migration ornegi. SchemaHelper kullanir, idempotent;
 * birden fazla kez calistirilsa bile hata vermez.
 */

SchemaHelper::ensureColumn(
    'policies',
    'no_renewal_reminder',
    'TINYINT(1) NOT NULL DEFAULT 0 COMMENT "Bu police icin otomatik yenileme gorevi olusturulmasin"',
    'is_cancelled' // is_cancelled kolonundan hemen sonra
);

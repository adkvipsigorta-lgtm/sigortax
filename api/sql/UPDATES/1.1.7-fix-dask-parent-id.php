<?php
/**
 * 1.1.7 - DASK yillik yenilemelerin parent_id'sini temizle
 *
 * DASK policelerinde Allianz her yil ayni police numarasini kullanir,
 * sadece zeyilname numarasini degistirir (20231, 20241, 20251, 20261...).
 * Sistem bunlari "zeyilname" olarak yorumlayip parent_id set ediyordu.
 * Oysa bunlar bagimsiz yillik yenileme policeleridir.
 *
 * endorsement_no > 100 olan kayitlar yil bazli yenileme = parent_id olmamali.
 * DB kontrolu: bu kayitlarin tamami DASK sigortasidir.
 */

$db = Database::getInstance();

$affected = $db->exec(
    "UPDATE policies
     SET parent_id = NULL
     WHERE parent_id IS NOT NULL
       AND deleted_at IS NULL
       AND endorsement_no REGEXP '^[0-9]+$'
       AND CAST(endorsement_no AS UNSIGNED) > 100"
);

 

<?php
/**
 * 1.1.6 - İŞYERİ sigorta türünü aktif et
 *
 * İŞYERİ (id=19) pasif durumda olduğundan poliçe düzenleme formunda
 * tür adı yerine ID (19) gösteriliyordu. Aktif edilerek düzeltildi.
 */

$db = Database::getInstance();

$db->exec("UPDATE insurance_types SET is_active = 1 WHERE id = 19 AND is_active = 0");
 
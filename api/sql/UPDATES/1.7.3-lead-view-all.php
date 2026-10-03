<?php
/**
 * Migration: 1.7.3-lead-view-all
 * Tüm leadleri görüntüleme izni
 */

$exists = Database::fetch("SELECT `key` FROM permissions WHERE `key` = 'leads.view_all'");
if (!$exists) {
    Database::insert('permissions', [
        'group' => 'leads',
        'group_label' => 'Lead Yonetimi',
        'key' => 'leads.view_all',
        'label' => 'Tum Leadleri Goruntule',
        'sort_order' => 99,
    ]);
}

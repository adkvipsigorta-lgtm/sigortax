<?php
/**
 * 1.3.2 - Poliçe tablosuna risk_address kolonu ekle
 *
 * DASK/KONUT poliçeleri için Allianz XML'deki RISK_ADRESI alanını
 * formatlanmış şekilde saklamak amacıyla eklendi.
 */

 

 SchemaHelper::ensureColumn(
    'policies',
    'risk_address',
    'TEXT NULL',
    'uavt_code'
);
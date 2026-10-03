<?php
/**
 * Migration: policies tablosuna sigorta ettiren telefonu (insured_no) kolonu
 */

SchemaHelper::ensureColumn(
    'policies',
    'insured_no',
    'VARCHAR(50) NULL',
    'insured_name'
);

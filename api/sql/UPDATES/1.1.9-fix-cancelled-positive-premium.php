<?php
/**
 * Migration: Yanlis pozitif kaydedilmis iptal zeyili primlerini duzelt.
 *
 * Sorun: allianz-import.vue'da cift-negatif hatasi nedeniyle
 * is_cancelled = 1 olan kayitlarin gross_premium ve net_premium degerleri
 * pozitif (+) kaydedilmisti. Dogru deger negatif (-) olmali.
 *
 * Kaynak: Allianz XML'de BRUT_PRIM zaten negatif gelir.
 * Frontend -grossAmount yaparak ikinci kez negatif yapmis, pozitife donmustur.
 *
 * Etkilenen kayitlar: is_cancelled = 1 AND gross_premium > 0
 * Manuel iptaller (cancel() metodu) zaten negatif kaydeder → etkilenmez.
 */

$pdo = Database::getInstance();

// 1. Once etkilenen kayit sayisini goster
$check = $pdo->query("
    SELECT COUNT(*) as cnt, SUM(gross_premium) as total
    FROM policies
    WHERE is_cancelled = 1
      AND gross_premium > 0
      AND deleted_at IS NULL
")->fetch(PDO::FETCH_ASSOC);

 

if ((int) $check['cnt'] > 0) { 
     // 2. Duzelt: gross_premium ve net_premium degerlerini negatife cevir
	$stmt = $pdo->prepare("
		UPDATE policies
		SET
			gross_premium = -gross_premium,
			net_premium   = CASE WHEN net_premium > 0 THEN -net_premium ELSE net_premium END,
			updated_at    = NOW()
		WHERE is_cancelled = 1
		  AND gross_premium > 0
		  AND deleted_at IS NULL
	");
	$stmt->execute();

	$affected = $stmt->rowCount();
}


 

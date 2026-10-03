<?php
/**
 * Migration: company_comm_amount eksik olan kayitlari duzelt.
 *
 * Sorun: Eski Allianz import versiyonlari company_comm_amount alanini
 * kaydetmiyordu. Portfolyo komisyon hesabi bu alani kullanamadigi icin
 * gross_premium * company_comm_rate / 100 ile hesapliyordu; bu da
 * XML'deki gercek KOMISYON degerinden sapiyordu.
 *
 * Duzeltme: company_comm_rate, import sirasinda komisyon/net*100 formulu
 * ile turetilmistir. Geri donusumu: net_premium * company_comm_rate / 100
 * ile gercek komisyon tutarina cok yakin bir deger elde edilir.
 *
 * Etkilenen kayitlar: company_comm_amount IS NULL veya 0, gross_premium != 0
 */

$pdo = Database::getInstance();

// 1. Etkilenen kayit sayisi
$check = $pdo->query("
    SELECT COUNT(*) as cnt
    FROM policies
    WHERE deleted_at IS NULL
      AND gross_premium != 0
      AND (company_comm_amount IS NULL OR company_comm_amount = 0)
      AND company_comm_rate > 0
")->fetch(PDO::FETCH_ASSOC);

 

if ((int) $check['cnt'] > 0) {
   $stmt = $pdo->prepare("
		UPDATE policies
		SET
			company_comm_amount = ROUND(net_premium * company_comm_rate / 100, 2),
			updated_at          = NOW()
		WHERE deleted_at IS NULL
		  AND gross_premium != 0
		  AND (company_comm_amount IS NULL OR company_comm_amount = 0)
		  AND company_comm_rate > 0
	");
	$stmt->execute();

	$affected = $stmt->rowCount();
}

 

 
<?php

class LeadHolidayController
{
    public function index(array $user, array $query): void
    {
        Permission::require($user, 'leads.settings');

        $rows = Database::fetchAll(
            "SELECT * FROM lead_holidays ORDER BY date ASC"
        );

        Response::success(array_map([$this, 'format'], $rows));
    }

    public function store(array $user, array $input): void
    {
        Permission::require($user, 'leads.settings');

        if (empty($input['name']) || empty($input['date'])) {
            Response::error('Tatil adi ve tarih zorunludur', 422);
        }

        // Ayni tarih var mi?
        $exists = Database::fetch("SELECT id FROM lead_holidays WHERE date = ?", [$input['date']]);
        if ($exists) {
            Response::error('Bu tarih zaten tanimli', 409);
        }

        $id = Database::insert('lead_holidays', [
            'name' => $input['name'],
            'date' => $input['date'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Tatil eklendi', 201);
    }

    public function destroy(array $user, int $id): void
    {
        Permission::require($user, 'leads.settings');

        $existing = Database::fetch("SELECT id FROM lead_holidays WHERE id = ?", [$id]);
        if (!$existing) Response::error('Tatil bulunamadi', 404);

        Database::query("DELETE FROM lead_holidays WHERE id = ?", [$id]);
        Response::success(null, 'Tatil silindi');
    }

    private function format(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'date' => $r['date'],
        ];
    }
}

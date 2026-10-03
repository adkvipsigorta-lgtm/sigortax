<?php

class MessagingController
{
    public function index(array $user, array $query): void
    {
        Permission::require($user, 'messages.view');
        $where = ["m.deleted_at IS NULL"];
        $params = [];

        if (!empty($query['customerId'])) {
            $where[] = "m.customer_id = ?";
            $params[] = (int) $query['customerId'];
        }

        if (!empty($query['channel'])) {
            $where[] = "m.channel = ?";
            $params[] = $query['channel'];
        }

        if (!empty($query['status'])) {
            $where[] = "m.status = ?";
            $params[] = $query['status'];
        }

        $whereSql = implode(' AND ', $where);
        $page = max(1, (int) ($query['page'] ?? 1));
        $limit = min(100, max(1, (int) ($query['limit'] ?? ITEMS_PER_PAGE)));

        $sql = "SELECT m.*, cu.name as customer_name, u.name as sent_by_name
                FROM messages m
                LEFT JOIN customers cu ON m.customer_id = cu.id
                LEFT JOIN users u ON m.sent_by = u.id
                WHERE $whereSql
                ORDER BY m.created_at DESC";

        $result = Database::paginate($sql, $params, $page, $limit);
        $result['data'] = array_map([$this, 'formatMessage'], $result['data']);

        Response::paginated($result);
    }

    public function show(array $user, int $id): void
    {
        $msg = Database::fetch(
            "SELECT m.*, cu.name as customer_name, u.name as sent_by_name
             FROM messages m
             LEFT JOIN customers cu ON m.customer_id = cu.id
             LEFT JOIN users u ON m.sent_by = u.id
             WHERE m.id = ? AND m.deleted_at IS NULL",
            [$id]
        );

        if (!$msg) {
            Response::error('Mesaj bulunamadi', 404);
        }

        Response::success($this->formatMessage($msg));
    }

    // Send message (single or bulk)
    public function store(array $user, array $input): void
    {
        Permission::require($user, 'messages.send');
        // Bulk send
        if (!empty($input['recipients']) && is_array($input['recipients'])) {
            return $this->sendBulk($user, $input);
        }

        $validator = new Validator();
        if (!$validator->validate($input, [
            'channel' => 'required|in:sms,email',
            'recipient' => 'required',
            'content' => 'required|min:1',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $id = Database::insert('messages', [
            'channel' => $input['channel'],
            'recipient' => $input['recipient'],
            'subject' => $input['subject'] ?? null,
            'content' => $input['content'],
            'customer_id' => $input['customerId'] ?? null,
            'template_id' => $input['templateId'] ?? null,
            'status' => 'sent', // In real app, this would be 'pending' until actually sent
            'sent_by' => $user['userId'],
            'sent_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Mesaj gonderildi', 201);
    }

    private function sendBulk(array $user, array $input): void
    {
        $sent = 0;
        $failed = 0;

        foreach ($input['recipients'] as $recipient) {
            try {
                // Replace placeholders in content
                $content = $input['content'] ?? '';
                $content = str_replace('{musteri}', $recipient['name'] ?? '', $content);
                $content = str_replace('{tarih}', date('d.m.Y'), $content);
                $content = str_replace('{telefon}', $recipient['phone'] ?? '', $content);

                Database::insert('messages', [
                    'channel' => $input['channel'] ?? 'sms',
                    'recipient' => $recipient['phone'] ?? $recipient['email'] ?? '',
                    'subject' => $input['subject'] ?? null,
                    'content' => $content,
                    'customer_id' => $recipient['customerId'] ?? null,
                    'template_id' => $input['templateId'] ?? null,
                    'status' => 'sent',
                    'sent_by' => $user['userId'],
                    'sent_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $sent++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        Response::success([
            'sent' => $sent,
            'failed' => $failed,
        ], "$sent mesaj gonderildi");
    }

    public function update(array $user, int $id, array $input): void
    {
        Response::error('Mesajlar guncellenemez', 400);
    }

    public function destroy(array $user, int $id): void
    {
        Database::softDelete('messages', $id);
        Response::success(null, 'Mesaj silindi');
    }

    // --- Templates ---
    public function templates(array $user): void
    {
        $templates = Database::fetchAll(
            "SELECT * FROM message_templates WHERE deleted_at IS NULL ORDER BY name"
        );

        $result = array_map(function ($t) {
            return [
                'id' => (int) $t['id'],
                'name' => $t['name'],
                'channel' => $t['channel'],
                'subject' => $t['subject'],
                'content' => $t['content'],
                'createdAt' => $t['created_at'],
            ];
        }, $templates);

        Response::success($result);
    }

    public function storeTemplate(array $user, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $validator = new Validator();
        if (!$validator->validate($input, [
            'name' => 'required|min:2',
            'content' => 'required|min:1',
        ])) {
            Response::error('Dogrulama hatasi', 422, $validator->getErrors());
        }

        $id = Database::insert('message_templates', [
            'name' => $input['name'],
            'channel' => $input['channel'] ?? 'sms',
            'subject' => $input['subject'] ?? null,
            'content' => $input['content'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Response::success(['id' => $id], 'Sablon olusturuldu', 201);
    }

    public function updateTemplate(array $user, int $id, array $input): void
    {
        AuthMiddleware::requireAdmin($user);

        $existing = Database::fetch("SELECT id FROM message_templates WHERE id = ? AND deleted_at IS NULL", [$id]);
        if (!$existing) {
            Response::error('Sablon bulunamadi', 404);
        }

        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if (isset($input['name'])) $data['name'] = $input['name'];
        if (isset($input['channel'])) $data['channel'] = $input['channel'];
        if (isset($input['subject'])) $data['subject'] = $input['subject'];
        if (isset($input['content'])) $data['content'] = $input['content'];

        Database::update('message_templates', $data, 'id = ?', [$id]);
        Response::success(null, 'Sablon guncellendi');
    }

    public function deleteTemplate(array $user, int $id): void
    {
        AuthMiddleware::requireAdmin($user);
        Database::softDelete('message_templates', $id);
        Response::success(null, 'Sablon silindi');
    }

    private function formatMessage(array $m): array
    {
        return [
            'id' => (int) $m['id'],
            'channel' => $m['channel'],
            'recipient' => $m['recipient'],
            'subject' => $m['subject'],
            'content' => $m['content'],
            'customerId' => $m['customer_id'] ? (int) $m['customer_id'] : null,
            'customerName' => $m['customer_name'] ?? null,
            'templateId' => $m['template_id'] ? (int) $m['template_id'] : null,
            'status' => $m['status'],
            'sentBy' => $m['sent_by'] ? (int) $m['sent_by'] : null,
            'sentByName' => $m['sent_by_name'] ?? null,
            'sentAt' => $m['sent_at'],
            'createdAt' => $m['created_at'],
        ];
    }
}

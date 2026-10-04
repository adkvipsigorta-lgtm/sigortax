<?php

// CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Serve static uploads
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#/api/uploads/(.+)$#', $requestPath, $m)) {
    $filePath = __DIR__ . '/uploads/' . $m[1];
    if (file_exists($filePath)) {
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400');
        readfile($filePath);
        exit;
    }
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/helpers/Database.php';
require_once __DIR__ . '/helpers/Auth.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/SocketEmitter.php';
require_once __DIR__ . '/helpers/Validator.php';
require_once __DIR__ . '/helpers/Permission.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';

// Auto-migration: yeni migration dosyası varsa otomatik uygula
(function () {
    $lockFile  = __DIR__ . '/.migration_check';
    $updateDir = __DIR__ . '/sql/UPDATES/';
    $fileCount = count(glob($updateDir . '*.php') ?: []);
    $lastCount = file_exists($lockFile) ? (int) file_get_contents($lockFile) : -1;
    if ($fileCount !== $lastCount) {
        require_once __DIR__ . '/helpers/UpdateHelper.php';
        try {
            UpdateHelper::runMigrations();
            // Başarılıysa lock dosyasını güncelle
            file_put_contents($lockFile, $fileCount);
        } catch (\Throwable $e) {
            // Hata logla — lock dosyasını GÜNCELLEME ki tekrar denesin
            error_log('[Migration Error] ' . $e->getMessage());
        }
    }
})();

// Parse request
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Remove query string and base path
$uri = parse_url($uri, PHP_URL_PATH);
$uri = preg_replace('#^.*/api/#', '', $uri);
$uri = trim($uri, '/');
$segments = $uri ? array_map('urldecode', explode('/', $uri)) : [];

// Get JSON body
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Query params
$query = $_GET;

// Route matching
$resource = $segments[0] ?? '';
$id = $segments[1] ?? null;
$action = $segments[2] ?? null;

// If id is not numeric, treat it as action
if ($id !== null && !is_numeric($id)) {
    $action = $id;
    $id = null;
}

// Controller mapping
$controllers = [
    'auth'                => 'AuthController',
    'customers'           => 'CustomerController',
    'policies'            => 'PolicyController',
    'insurance-types'     => 'InsuranceController',
    'companies'           => 'CompanyController',
    'branches'            => 'BranchController',
    'users'               => 'UserController',
    'dashboard'           => 'DashboardController',
'cities'              => 'LocationController',
    'countries'           => 'LocationController',
    'settings'            => 'SettingsController',
    'allianz'             => 'AllianzController',
    'ai-coach'            => 'AiCoachController',
    'audit-logs'          => 'AuditLogController',
    'customer-categories' => 'CustomerCategoryController',
    'documents'           => 'DocumentController',
    'tasks'               => 'TaskController',
    'trash'               => 'TrashController',
    'messages'            => 'MessagingController',
    'notifications'       => 'NotificationController',
    'reports'             => 'ReportController',
    'reference-sources'   => 'ReferenceSourceController',
    'updates'             => 'UpdateController',
    'import-mappings'     => 'ImportMappingController',
    'task-notes'          => 'TaskNoteController',
    'lost-policies'       => 'LostPolicyController',
    'agency'              => 'AgencyController',
    'personnel'           => 'PersonnelController',
    'leads'               => 'LeadController',
    'lead-sources'        => 'LeadSourceController',
    'lead-products'       => 'LeadProductController',
    'lead-holidays'       => 'LeadHolidayController',
];

// Portal endpoints (musteri portali - ozel auth)
if ($resource === 'portal') {
    require_once __DIR__ . '/controllers/PortalController.php';
    $portalCtrl = new PortalController();

    // Public portal routes
    if ($action === 'status' && $method === 'GET') { $portalCtrl->status(); exit; }
    if ($action === 'sms-login' && $method === 'POST') { $portalCtrl->smsLogin($input); exit; }
    if ($action === 'sms-verify' && $method === 'POST') { $portalCtrl->smsVerify($input); exit; }

    // Protected portal routes — role=3 zorunlu
    $portalUser = Auth::getCurrentUser();
    if (!$portalUser || (int) $portalUser['role'] !== 3) {
        Response::error('Oturum süresi dolmuş veya yetkiniz yok', 401);
    }
    // Portal açık mı kontrol et
    $portalSetting = Database::fetch("SELECT value FROM settings WHERE `key` = 'customer_portal_enabled'");
    if (!$portalSetting || ($portalSetting['value'] !== 'true' && $portalSetting['value'] !== '1')) {
        Response::error('Müşteri portalı şu anda kapatılmıştır', 403);
    }

    if ($action === 'me' && $method === 'GET') { $portalCtrl->me(); exit; }
    if ($action === 'profile' && $method === 'GET') { $portalCtrl->profile($portalUser); exit; }
    if ($action === 'notifications' && $method === 'GET') { $portalCtrl->notificationList($portalUser); exit; }
    if ($action === 'notifications' && $method === 'PUT') { $portalCtrl->notificationMarkRead($portalUser); exit; }
    // GET /portal/policies/export — segments: [portal, policies, export]
    if (($segments[1] ?? '') === 'policies' && ($segments[2] ?? '') === 'export' && $method === 'GET') { $portalCtrl->policiesExport($portalUser, $query); exit; }
    if ($action === 'policies' && $method === 'GET' && !$id) { $portalCtrl->policies($portalUser, $query); exit; }
    if ($action === 'summary' && $method === 'GET') { $portalCtrl->summary($portalUser); exit; }
    // Talep endpoint'leri
    if ($action === 'requests' && $method === 'GET' && !$id) { $portalCtrl->requestList($portalUser, $query); exit; }
    if ($action === 'requests' && $method === 'POST' && !$id) { $portalCtrl->requestCreate($portalUser, $input); exit; }
    // /portal/requests/{id}
    if (($segments[1] ?? '') === 'requests' && isset($segments[2]) && is_numeric($segments[2])) {
        $reqId = (int) $segments[2];
        if ($method === 'GET') { $portalCtrl->requestDetail($portalUser, $reqId); exit; }
        if ($method === 'PUT' && ($segments[3] ?? '') === 'cancel') { $portalCtrl->requestCancel($portalUser, $reqId); exit; }
    }
    // GET /portal/policies/{policyId}/documents — segments: [portal, policies, {id}, documents]
    if (($segments[1] ?? '') === 'policies' && isset($segments[2]) && is_numeric($segments[2]) && ($segments[3] ?? '') === 'documents' && $method === 'GET') {
        $portalCtrl->policyDocuments($portalUser, (int) $segments[2]);
        exit;
    }
    // GET /portal/documents/{docId}/download — segments: [portal, documents, {id}, download]
    if (($segments[1] ?? '') === 'documents' && isset($segments[2]) && is_numeric($segments[2]) && ($segments[3] ?? '') === 'download' && $method === 'GET') {
        $portalCtrl->downloadDocument($portalUser, (int) $segments[2]);
        exit;
    }

    Response::error('Endpoint bulunamadi', 404);
}

if (!isset($controllers[$resource])) {
    Response::error('Endpoint bulunamadi', 404);
}

$controllerFile = __DIR__ . '/controllers/' . $controllers[$resource] . '.php';
if (!file_exists($controllerFile)) {
    Response::error('Controller bulunamadi', 500);
}

require_once $controllerFile;
$controllerClass = $controllers[$resource];
$controller = new $controllerClass();

// Auth endpoints (public - no middleware)
if ($resource === 'auth') {
    // 2FA verify doesn't need auth (user is logging in)
    if ($action === '2fa' && isset($segments[2]) && $segments[2] === 'verify' && $method === 'POST') {
        $controller->twoFactorVerify($input);
        exit;
    }

    // 2FA mandatory setup (login flow, challenge token based)
    if ($action === '2fa' && isset($segments[2]) && $segments[2] === 'setup-login' && $method === 'POST') {
        $controller->twoFactorSetup($input);
        exit;
    }
    if ($action === '2fa' && isset($segments[2]) && $segments[2] === 'enable-login' && $method === 'POST') {
        $controller->twoFactorEnable($input);
        exit;
    }
    if ($action === '2fa' && isset($segments[2]) && $segments[2] === 'confirm-setup' && $method === 'POST') {
        $controller->twoFactorConfirmSetup($input);
        exit;
    }

    // Public auth routes
    if ($action === 'login' && $method === 'POST') { $controller->login($input); exit; }
    if ($action === 'logout' && $method === 'POST') { $controller->logout(); exit; }
    if ($action === 'me' && $method === 'GET') { $controller->me(); exit; }

    // SSE endpoint (handles own auth, needs own headers)
    // Protected auth routes (require auth)
    $user = AuthMiddleware::handle();

    if ($action === 'profile' && $method === 'PUT') { $controller->updateProfile($user, $input); exit; }
    if ($action === 'change-password' && $method === 'POST') { $controller->changePassword($user, $input); exit; }
    if ($action === 'notification-preferences' && $method === 'GET') { $controller->getNotificationPreferences($user); exit; }
    if ($action === 'notification-preferences' && $method === 'POST') { $controller->saveNotificationPreferences($user, $input); exit; }
    if ($action === 'sessions' && $method === 'GET') { $controller->sessions($user); exit; }
    if ($action === 'sessions' && $method === 'DELETE' && isset($segments[2]) && is_numeric($segments[2])) {
        $controller->deleteSession($user, (int) $segments[2]);
        exit;
    }
    if ($action === '2fa' && isset($segments[2])) {
        $sub = $segments[2];
        if ($sub === 'setup' && $method === 'POST') { $controller->twoFactorSetup($user); exit; }
        if ($sub === 'enable' && $method === 'POST') { $controller->twoFactorEnable($user, $input); exit; }
        if ($sub === 'disable' && $method === 'POST') { $controller->twoFactorDisable($user, $input); exit; }
    }

    Response::error('Endpoint bulunamadi', 404);
}

// Location endpoints (public)
if ($resource === 'cities') {
    match (true) {
        $method === 'GET' && $id && $action === 'districts' => $controller->districts((int) $id),
        $method === 'GET' && !$id => $controller->index($query),
        default => Response::error('Endpoint bulunamadi', 404),
    };
    exit;
}

if ($resource === 'countries') {
    match (true) {
        $method === 'GET' && !$id => $controller->countries(),
        default => Response::error('Endpoint bulunamadi', 404),
    };
    exit;
}

// Settings GET (public - logo/acente bilgisi icin)
if ($resource === 'settings' && $method === 'GET') {
    $controller->index();
    exit;
}

// Task cron endpoint (no auth, uses secret key)
if ($resource === 'tasks' && $action === 'cron' && $method === 'GET') {
    $controller->cron($query);
    exit;
}

// Lead cron endpoint (no auth, uses secret key)
if ($resource === 'leads' && $action === 'cron' && $method === 'GET') {
    require_once __DIR__ . '/controllers/LeadController.php';
    (new LeadController())->cron($query);
    exit;
}

// Lead webhook endpoint (no auth, uses API key header)
if ($resource === 'leads' && $action === 'webhook' && $method === 'POST') {
    require_once __DIR__ . '/controllers/LeadController.php';
    (new LeadController())->webhook($input);
    exit;
}

// Protected routes - require auth
$user = AuthMiddleware::handle();

// Onboarding routes
if ($resource === 'onboarding') {
    require_once __DIR__ . '/controllers/OnboardingController.php';
    $onboardingCtrl = new OnboardingController();
    match (true) {
        $action === 'status' && $method === 'GET'
            => $onboardingCtrl->status($user),
        $action === 'profile' && $method === 'POST'
            => $onboardingCtrl->saveProfile($user, $input),
        $action === 'agreement' && isset($segments[2]) && $method === 'GET'
            => $onboardingCtrl->getAgreement($segments[2]),
        $action === 'agreement' && isset($segments[2]) && isset($segments[3]) && $segments[3] === 'accept' && $method === 'POST'
            => $onboardingCtrl->acceptAgreement($user, $segments[2]),
        $action === 'agreement' && isset($segments[2]) && isset($segments[3]) && $segments[3] === 'verify' && $method === 'POST'
            => $onboardingCtrl->verifyAgreement($user, $segments[2], $input),
        $action === 'identity' && $method === 'POST'
            => $onboardingCtrl->uploadIdentity($user),
        $action === 'identity' && isset($segments[2]) && isset($segments[3]) && $method === 'GET'
            => $onboardingCtrl->serveIdentity($user, (int) $segments[2], $segments[3]),
        $action === 'admin' && isset($segments[2]) && $method === 'GET'
            => $onboardingCtrl->adminUserDetail($user, (int) $segments[2]),
        default => Response::error('Endpoint bulunamadi', 404),
    };
    exit;
}

// Dashboard routes
if ($resource === 'dashboard') {
    match (true) {
        $action === 'stats' && $method === 'GET'   => $controller->stats($user, $query),
        $action === 'charts' && $method === 'GET'   => $controller->charts($user, $query),
        $action === 'renewals' && $method === 'GET' => $controller->renewals($user, $query),
        $action === 'cross-sell' && $method === 'GET' => $controller->crossSell($user, $query),
        $action === 'user-reconciliation' && $method === 'GET' => $controller->userReconciliation($user, $query),
        $action === 'user-reconciliation-export' && $method === 'GET' => $controller->userReconciliationExport($user, $query),
        $action === 'reconciliation-lock' && $method === 'POST' => $controller->reconciliationLock($user, $input),
        $action === 'search' && $method === 'GET'   => $controller->search($user, $query),
        $action === 'task-counts' && $method === 'GET' => $controller->taskCounts($user, $query),
        $action === 'portfolio' && $method === 'GET' => $controller->portfolio($user, $query),
        $action === 'portfolio-forecast' && $method === 'GET' => $controller->portfolioForecast($user, $query),
        $action === 'task-performance' && $method === 'GET' => $controller->taskPerformance($user, $query),
        $action === 'task-performance-export' && $method === 'GET' => $controller->taskPerformanceExport($user, $query),
        $action === 'sales-performance' && $method === 'GET' => $controller->salesPerformance($user, $query),
        default => Response::error('Endpoint bulunamadi', 404),
    };
    exit;
}

// AI Coach routes
if ($resource === 'ai-coach') {
    match (true) {
        $action === 'status' && $method === 'GET'   => $controller->status($user),
        $action === 'analyze' && $method === 'POST' => $controller->analyze($user, $input),
        $action === 'expired' && $method === 'GET'  => $controller->expired($user),
        default => Response::error('Endpoint bulunamadi', 404),
    };
    exit;
}

// Allianz routes
if ($resource === 'allianz') {
    match (true) {
        $action === 'upload' && $method === 'POST'           => $controller->upload($user),
        $action === 'check-duplicates' && $method === 'POST' => $controller->checkDuplicates($user, $input),
        $action === 'save' && $method === 'POST'             => $controller->save($user, $input),
        default => Response::error('Endpoint bulunamadi', 404),
    };
    exit;
}

// Import mapping routes
if ($resource === 'import-mappings') {
    match (true) {
        $action === 'run' && $method === 'POST' => $controller->run($user, $input),
        $method === 'GET' && $id === null      => $controller->index($user),
        $method === 'POST' && $id === null     => $controller->store($user, $input),
        $method === 'DELETE' && $id !== null   => $controller->destroy($user, (int) $id),
        default => Response::error('Endpoint bulunamadi', 404),
    };
    exit;
}

// Settings routes
if ($resource === 'settings') {
    match (true) {
        $method === 'GET'  => $controller->index(),
        $method === 'POST' => $controller->save($user, $input),
        default => Response::error('Endpoint bulunamadi', 404),
    };
    exit;
}

// Branch special routes
if ($resource === 'branches') {
    if ($action === 'reconciliation' && $method === 'GET') {
        $controller->reconciliation($user, $query);
        exit;
    }
    if ($action === 'reconciliation-export' && $method === 'GET') {
        $controller->reconciliationExport($user, $query);
        exit;
    }
    if ($action === 'reconciliation-lock' && $method === 'POST') {
        $controller->reconciliationLock($user, $input);
        exit;
    }
    if ($action === 'policies' && $method === 'GET' && $id) {
        $controller->monthlyPolicies($user, (int) $id, $query);
        exit;
    }
    if ($action === 'ai-analysis' && $method === 'GET' && $id) {
        $controller->aiAnalysis($user, (int) $id);
        exit;
    }
}

// Company special routes
if ($resource === 'companies') {
    if ($action === 'upload-logo' && $method === 'POST') {
        $controller->uploadLogo($user);
        exit;
    }
}

// Customer special routes
if ($resource === 'customers') {
    if ($action === 'list-all' && $method === 'GET') {
        $controller->listAll($user, $query);
        exit;
    }
    if ($action === 'list' && $method === 'GET') {
        $controller->listPaged($user, $query);
        exit;
    }
    if ($action === 'search' && $method === 'GET') {
        $controller->searchByName($user, $query);
        exit;
    }
    if ($action === 'note' && $method === 'POST' && $id) {
        $controller->updateNote($user, (int) $id, $input);
        exit;
    }
    if ($action === 'notes' && $method === 'GET' && $id) {
        $controller->notes($user, (int) $id);
        exit;
    }
    if ($action === 'export' && $method === 'GET') {
        $controller->export($user, $query);
        exit;
    }
    // POST /api/customers/{id}/portal-group
    if ($action === 'portal-group' && $method === 'POST' && $id) {
        $controller->addToPortalGroup($user, (int) $id, $input);
        exit;
    }
    // DELETE /api/customers/{id}/portal-group/{memberId}
    if (($segments[1] ?? '') === 'portal-group' && isset($segments[2]) && is_numeric($segments[2]) && $method === 'DELETE' && $id) {
        $controller->removeFromPortalGroup($user, (int) $id, (int) $segments[2]);
        exit;
    }
    if ($action === 'vehicle-status' && $method === 'GET' && $id) {
        $controller->vehicleStatus($user, (int) $id);
        exit;
    }
    if ($action === 'notes' && $method === 'POST' && $id) {
        $controller->addNote($user, (int) $id, $input);
        exit;
    }
}

// Policy special routes
if ($resource === 'policies') {
    if ($action === 'check-duplicate' && $method === 'GET') {
        $controller->checkDuplicate($user, $query);
        exit;
    }
    if ($action === 'lookup-registration' && $method === 'GET') {
        $controller->lookupRegistration($user, $query);
        exit;
    }
    if ($action === 'daily' && $method === 'GET') {
        $controller->daily($user, $query);
        exit;
    }
    if ($action === 'cancel' && $method === 'POST' && $id) {
        $controller->cancel($user, (int) $id, $input);
        exit;
    }
    if ($action === 'zeyil' && $method === 'GET' && isset($segments[2])) {
        $controller->zeyilHistory($user, $segments[2]);
        exit;
    }
    if ($action === 'export' && $method === 'GET') {
        $controller->export($user, $query);
        exit;
    }
    if ($action === 'fetch' && $method === 'POST') {
        $controller->fetch($user, $input);
        exit;
    }
    if ($action === 'parse-pdf' && $method === 'POST') {
        $controller->parsePdf($user);
        exit;
    }
}

// Document special routes
if ($resource === 'documents') {
    if ($action === 'download' && $method === 'GET' && $id) {
        $controller->download($user, (int) $id);
        exit;
    }
}

// Messaging special routes
if ($resource === 'messages') {
    if ($action === 'templates' && $method === 'GET') {
        $controller->templates($user);
        exit;
    }
    if ($action === 'templates' && $method === 'POST') {
        $controller->storeTemplate($user, $input);
        exit;
    }
    // PUT /api/messages/templates/:id
    if ($segments[1] === 'templates' && isset($segments[2]) && is_numeric($segments[2])) {
        if ($method === 'PUT') {
            $controller->updateTemplate($user, (int) $segments[2], $input);
            exit;
        }
        if ($method === 'DELETE') {
            $controller->deleteTemplate($user, (int) $segments[2]);
            exit;
        }
    }
}

// Notification special routes - mark all read via PUT /api/notifications/read-all
if ($resource === 'notifications' && $action === 'read-all' && $method === 'PUT') {
    $controller->update($user, 0, ['markAllRead' => true]);
    exit;
}

// Updates special routes (admin-only, UpdateController icinde kontrol ediliyor)
if ($resource === 'updates') {
    if ($action === 'check' && $method === 'GET') {
        $controller->check($user);
        exit;
    }
    if ($action === 'apply' && $method === 'POST') {
        $controller->apply($user, $input);
        exit;
    }
    if ($action === 'history' && $method === 'GET') {
        $controller->history($user);
        exit;
    }
    if ($action === 'rollback' && $method === 'POST') {
        $controller->rollback($user, $input);
        exit;
    }
    Response::error('Endpoint bulunamadi', 404);
}

// Task special routes
if ($resource === 'tasks') {
    if ($action === 'export' && $method === 'GET') {
        $controller->export($user, $query);
        exit;
    }
    if ($action === 'stats' && $method === 'GET') {
        $controller->stats($user, $query);
        exit;
    }
    if ($action === 'assign' && $method === 'POST' && $id) {
        $controller->assign($user, (int) $id, $input);
        exit;
    }
    if ($action === 'complete' && $method === 'POST' && $id) {
        $controller->complete($user, (int) $id, $input);
        exit;
    }
    if ($action === 'bulk-smart-close' && $method === 'POST') {
        $controller->bulkSmartClose($user, $input);
        exit;
    }
}

// Lost policies special routes
if ($resource === 'lost-policies') {
    if ($action === 'stats' && $method === 'GET') {
        $controller->stats($user);
        exit;
    }
    if ($action === 'export' && $method === 'GET') {
        $controller->export($user, $query);
        exit;
    }
    if ($action === 'sync' && $method === 'POST') {
        $controller->syncNewLostEvents($user);
        exit;
    }
    if ($method === 'GET' && !$id) {
        $controller->index($user, $query);
        exit;
    }
    if ($method === 'PUT' && $id) {
        $controller->update($user, (int) $id, $input);
        exit;
    }
    Response::error('Endpoint bulunamadi', 404);
}

// Agency routes
if ($resource === 'agency') {
    if ($action === 'stats' && $method === 'GET') {
        $controller->stats($user);
        exit;
    }
    Response::error('Endpoint bulunamadi', 404);
}

// Users routes (permissions)
if ($resource === 'users') {
    // /api/users/my-permissions
    if ($action === 'my-permissions' && $method === 'GET') { $controller->myPermissions($user); exit; }
    // /api/users/{id}/permissions
    if ($id && $action === 'permissions' && $method === 'GET') { $controller->getPermissions($user, (int) $id); exit; }
    if ($id && $action === 'permissions' && $method === 'PUT') { $controller->updatePermissions($user, (int) $id, $input); exit; }
    // /api/users/{id}/reset-password
    if ($id && $action === 'reset-password' && $method === 'POST') { $controller->resetPassword($user, (int) $id); exit; }
}

// Personnel routes
if ($resource === 'personnel') {
    // /api/personnel/stats
    if ($action === 'stats' && $method === 'GET') { $controller->stats($user, $query); exit; }
    // /api/personnel/leave-types
    if ($action === 'leave-types' && $method === 'GET') { $controller->leaveTypes($user); exit; }
    // /api/personnel/leave-requests (GET: list, POST: create)
    if ($action === 'leave-requests' && !$id && $method === 'GET') { $controller->leaveRequests($user, $query); exit; }
    if ($action === 'leave-requests' && !$id && $method === 'POST') { $controller->createLeaveRequest($user, $input); exit; }
    // /api/personnel/{id}/leaves — kullanicinin izin bakiyeleri
    if ($id && $action === 'leaves' && $method === 'GET') { $controller->leaveBalances($user, (int) $id, $query); exit; }
    // /api/personnel/{id}/leaves — izin bakiyesi guncelle
    if ($id && $action === 'leaves' && $method === 'PUT') { $controller->updateLeaveBalance($user, (int) $id, $input); exit; }
    // /api/personnel/{id}/performance
    if ($id && $action === 'performance' && $method === 'GET') { $controller->performance($user, (int) $id, $query); exit; }
    // /api/personnel/leave-requests/{id} — onayla/reddet (PUT via action+id pattern)
    // We need special handling: /personnel/leave-requests/5
    // In this case: resource=personnel, id=null (leave-requests is not numeric), action=leave-requests
    // So we use segments directly
    if (($segments[1] ?? '') === 'leave-requests' && isset($segments[2]) && is_numeric($segments[2])) {
        $lrId = (int) $segments[2];
        if ($method === 'PUT') { $controller->updateLeaveRequest($user, $lrId, $input); exit; }
        if ($method === 'DELETE') { $controller->deleteLeaveRequest($user, $lrId); exit; }
    }
    // Standard: GET /personnel — list, GET /personnel/{id} — detail, PUT /personnel/{id} — update
    if ($method === 'GET' && !$id && !$action) { $controller->index($user, $query); exit; }
    if ($method === 'GET' && $id && !$action) { $controller->show($user, (int) $id); exit; }
    if ($method === 'PUT' && $id && !$action) { $controller->update($user, (int) $id, $input); exit; }
    Response::error('Endpoint bulunamadi', 404);
}

// Lead special routes
if ($resource === 'leads') {
    if ($action === 'counts' && $method === 'GET') { $controller->counts($user); exit; }
    if ($action === 'start-process' && $method === 'POST' && $id) { $controller->startProcess($user, (int) $id); exit; }
    if ($action === 'close-process' && $method === 'POST' && $id) { $controller->closeProcess($user, (int) $id, $input); exit; }
    if ($action === 'assign' && $method === 'POST' && $id) { $controller->assign($user, (int) $id, $input); exit; }
    if ($action === 'reopen-won' && $method === 'POST' && $id) { $controller->reopenWon($user, (int) $id); exit; }
    if ($action === 'upload-file' && $method === 'POST' && $id) { $controller->uploadFile($user, (int) $id); exit; }
    if ($action === 'files' && $method === 'GET' && $id) { $controller->files($user, (int) $id); exit; }
    if ($action === 'note-counts' && $method === 'GET') { $controller->noteCounts($user, $query); exit; }
    if ($action === 'notes' && $method === 'GET' && $id) { $controller->notes($user, (int) $id); exit; }
    if ($action === 'notes' && $method === 'POST' && $id) { $controller->addNote($user, (int) $id, $input); exit; }
    // DELETE /leads/{id}/notes/{noteId}
    if (($segments[2] ?? '') === 'notes' && isset($segments[3]) && is_numeric($segments[3]) && $method === 'DELETE') {
        $controller->deleteNote($user, (int) $id, (int) $segments[3]); exit;
    }
    // DELETE /leads/{id}/files/{fileId} → segments: [leads, {id}, files, {fileId}]
    if (($segments[2] ?? '') === 'files' && isset($segments[3]) && is_numeric($segments[3]) && $method === 'DELETE') {
        $controller->deleteFile($user, (int) $id, (int) $segments[3]); exit;
    }
}

// Standard CRUD routing
match (true) {
    $method === 'GET' && !$id    => $controller->index($user, $query),
    $method === 'GET' && $id     => $controller->show($user, (int) $id),
    $method === 'POST' && !$id   => $controller->store($user, $input),
    $method === 'PUT' && $id     => $controller->update($user, (int) $id, $input),
    $method === 'DELETE' && $id  => $controller->destroy($user, (int) $id),
    default => Response::error('Endpoint bulunamadi', 404),
};

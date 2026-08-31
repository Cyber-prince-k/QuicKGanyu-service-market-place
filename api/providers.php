<?php
// Public directory: only fully completed providers are visible or available to clients.
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $rows = $pdo->query(
        'SELECT u.id, u.full_name, sp.location, sp.service_category, sp.skills, sp.about, sp.experience_years
           FROM users u
           INNER JOIN service_providers sp ON sp.user_id = u.id
          WHERE u.role = "provider" AND u.status = "active"
            AND TRIM(COALESCE(sp.phone, "")) <> ""
            AND TRIM(COALESCE(sp.location, "")) <> ""
            AND TRIM(COALESCE(sp.service_category, "")) <> ""
            AND TRIM(COALESCE(sp.skills, "")) <> ""
            AND TRIM(COALESCE(sp.qualifications, "")) <> ""
            AND TRIM(COALESCE(sp.about, "")) <> ""
            AND COALESCE(sp.experience_years, 0) >= 1
            AND EXISTS (SELECT 1 FROM provider_portfolio pp WHERE pp.provider_id = u.id)
            AND EXISTS (SELECT 1 FROM provider_testimonials pt WHERE pt.provider_id = u.id)
          ORDER BY u.full_name'
    )->fetchAll();
    foreach ($rows as &$row) {
        $evidence = provider_evidence($pdo, (int)$row['id']);
        $row['portfolio'] = $evidence['portfolio'];
        $row['showcase'] = provider_products($pdo, (int)$row['id']);
    }
    unset($row);
    respond(['ok' => true, 'providers' => $rows]);
} catch (Throwable $e) {
    error_log('QuickGanyu providers list error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'Could not load providers.'], 500);
}

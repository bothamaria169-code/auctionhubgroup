<?php
// ============================================================
// AUCTION HUB GROUP — leads_api.php
// Admin-only actions for Admin → Leads and Admin → Templates.
//   POST status        key, stage?, outcome?, note?, call_back_at?, called?
//   POST email         key, to, name, template, subject, body
//   POST wa_log        key, template, to
//   POST preview       subject, body, key?
//   POST save_template channel(email|wa), tpl, subject?, body
//   POST reset_template channel, tpl
//   POST settings      agent_name
//   POST enquiry_read  id
//   GET  export        days  → CSV download
// ============================================================
ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/leads_helper.php';

function leadsOut(bool $ok, string $message = '', array $extra = []): void {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => $ok, 'message' => $message] + $extra);
    exit;
}

$admin = requireAuth(); // dies with 401 JSON when not logged in / session revoked
if (($admin['role'] ?? '') !== 'admin') {
    http_response_code(403);
    leadsOut(false, 'Admin access required.');
}
$by = (string)($admin['full_name'] ?? 'Admin');
ahgSchemaReady();

$action = $_REQUEST['action'] ?? '';

// CSV export is a plain GET download.
if ($action === 'export') {
    $days = max(1, min(3650, (int)($_GET['days'] ?? 30)));
    $people = leadPeople($days);
    ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ahg-leads-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8
    fputcsv($out, ['Name', 'Phone', 'WhatsApp', 'Email', 'Lead type', 'Detail', 'Stage', 'Outcome', 'Call back', 'Calls', 'Note', 'Last activity', 'First seen']);
    foreach ($people as $p) {
        fputcsv($out, [$p['name'], $p['phone'], $p['wa'], $p['email'], LEAD_TYPES[$p['type']][0], $p['detail'],
            LEAD_STAGES[$p['stage']] ?? $p['stage'], $p['outcome'] ? LEAD_OUTCOMES[$p['outcome']][0] : '',
            $p['call_back_at'], $p['calls'], $p['note'], $p['at'], $p['first_at']]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') leadsOut(false, 'POST required.');

$key = trim((string)($_POST['key'] ?? ''));
$validKey = fn(string $k) => (bool)preg_match('/^[epx]:.{1,195}$/', $k);

switch ($action) {
    case 'status': {
        if (!$validKey($key)) leadsOut(false, 'Unknown lead.');
        $fields = [];
        foreach (['stage', 'outcome', 'note', 'call_back_at'] as $f) if (array_key_exists($f, $_POST)) $fields[$f] = $_POST[$f];
        if (!empty($_POST['called'])) $fields['called'] = true;
        if (isset($fields['stage']) && !isset(LEAD_STAGES[$fields['stage']])) leadsOut(false, 'Unknown stage.');
        leadSaveStatus($key, $fields, $by);
        leadsOut(true, 'Saved.');
    }

    case 'email': {
        if (!$validKey($key)) leadsOut(false, 'Unknown lead.');
        $to = trim((string)($_POST['to'] ?? ''));
        $person = ['name' => $_POST['name'] ?? '', 'user_id' => (int)($_POST['user_id'] ?? 0) ?: null];
        [$ok, $msg] = leadSendEmail($to, (string)($_POST['name'] ?? ''), trim((string)($_POST['subject'] ?? '')), (string)($_POST['body'] ?? ''), $person);
        leadLogMessage($key, 'email', substr((string)($_POST['template'] ?? ''), 0, 40) ?: null, $to, (string)($_POST['subject'] ?? ''), $ok, $by);
        if ($ok) leadSaveStatus($key, ['emailed' => true], $by);
        leadsOut($ok, $msg);
    }

    case 'wa_log': {
        if (!$validKey($key)) leadsOut(false, 'Unknown lead.');
        leadLogMessage($key, 'whatsapp', substr((string)($_POST['template'] ?? ''), 0, 40) ?: null, substr((string)($_POST['to'] ?? ''), 0, 30), null, true, $by);
        leadSaveStatus($key, ['emailed' => true], $by); // first contact made: New → Contacted
        leadsOut(true, 'Logged.');
    }

    case 'preview': {
        $subject = trim((string)($_POST['subject'] ?? 'Preview'));
        $body = (string)($_POST['body'] ?? '');
        $vars = leadCommonVars();
        unset($vars['{bank_details}']); // rendered as a table by leadEmailHtml
        $sample = leadPersonVars(['name' => $_POST['name'] ?? 'Thabo Mokoena', 'vehicle' => $_POST['vehicle'] ?? '2019 Toyota Hilux 2.4 GD-6', 'criteria' => 'Bakkies under R250,000']);
        $text = leadFill($body, $vars + $sample);
        leadsOut(true, '', ['html' => leadEmailHtml(leadFill($subject, $vars + $sample), $text, ['user_id' => (int)($_POST['user_id'] ?? 0) ?: null])]);
    }

    case 'save_template':
    case 'reset_template': {
        $channel = ($_POST['channel'] ?? '') === 'wa' ? 'wa' : 'email';
        $tpl = (string)($_POST['tpl'] ?? '');
        $defaults = $channel === 'wa' ? leadWaDefaults() : leadEmailDefaults();
        if (!isset($defaults[$tpl])) leadsOut(false, 'Unknown template.');
        if ($channel === 'wa') {
            leadSaveSetting('tpl_wa_' . $tpl, $action === 'reset_template' ? null : mb_substr((string)$_POST['body'], 0, 4000));
        } else {
            $reset = $action === 'reset_template';
            leadSaveSetting('tpl_email_' . $tpl . '_subject', $reset ? null : mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 255));
            leadSaveSetting('tpl_email_' . $tpl . '_body',    $reset ? null : mb_substr((string)($_POST['body'] ?? ''), 0, 10000));
        }
        $fresh = leadTemplates($channel)[$tpl];
        leadsOut(true, $action === 'reset_template' ? 'Back to the original wording.' : 'Template saved.', ['template' => $fresh]);
    }

    case 'settings': {
        $name = trim(mb_substr((string)($_POST['agent_name'] ?? ''), 0, 100));
        leadSaveSetting('lead_agent_name', $name !== '' ? $name : null);
        leadsOut(true, 'Saved.');
    }

    case 'enquiry_read': {
        $pdo->prepare("UPDATE enquiries SET status = 'read' WHERE id = ? AND status = 'new'")->execute([(int)($_POST['id'] ?? 0)]);
        leadsOut(true);
    }
}

leadsOut(false, 'Unknown action.');

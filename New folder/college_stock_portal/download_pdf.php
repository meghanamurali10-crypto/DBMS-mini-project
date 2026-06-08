<?php
require_once __DIR__ . '/includes/auth.php';
require_login();

require_once __DIR__ . '/includes/SimplePdf.php';

function report_period(): array
{
    $quick = $_GET['quick'] ?? '';
    $today = new DateTimeImmutable('today');

    if ($quick === '3m') {
        return [
            $today->modify('-3 months')->format('Y-m-d'),
            $today->format('Y-m-d'),
            'Last_3_months'
        ];
    }

    if ($quick === '6m') {
        return [
            $today->modify('-6 months')->format('Y-m-d'),
            $today->format('Y-m-d'),
            'Last_6_months'
        ];
    }

    if ($quick === 'current_month') {
        return [
            $today->modify('first day of this month')->format('Y-m-d'),
            $today->format('Y-m-d'),
            'Current_month'
        ];
    }

    $from = trim($_GET['from'] ?? '');
    $to = trim($_GET['to'] ?? '');

    if ($from !== '' && $to !== '') {
        return [
            $from,
            $to,
            date('Y-m-d', strtotime($from)) . '_to_' . date('Y-m-d', strtotime($to))
        ];
    }

    return ['', '', 'All_dates'];
}

$type = $_GET['type'] ?? 'stockbook';
$generationDate = date('Y-m-d');
$collegeName = defined('APP_SHORT_NAME') ? APP_SHORT_NAME : 'College';

// --- Helper to generate filename ---
function get_pdf_filename(string $prefix, string $identifier, string $date): string
{
    return $prefix . '_' . $identifier . '_' . $date . '.pdf';
}

// --- Request PDF ---
if ($type === 'request') {
    $pdf = new SimplePdf('P');
    $id = (int)($_GET['id'] ?? 0);

    $r = one(
        'SELECT r.*, d.name department_name, u.name requested_by_name
        FROM requests r
        JOIN departments d ON d.id=r.department_id
        JOIN users u ON u.id=r.requested_by
        WHERE r.id=?',
        [$id]
    );

    if (!$r) exit('Request not found.');
    if (current_user()['role'] === 'DEPARTMENT' && (int)$r['requested_by'] !== current_user()['id']) exit('Access denied.');

    $items = rows(
        'SELECT ri.*, i.item_name, i.item_code, i.unit, i.quantity available_quantity
        FROM request_items ri
        JOIN items i ON i.id=ri.item_id
        WHERE ri.request_id=?',
        [$id]
    );

    $pdf->title('Department Indent / Stock Request');
    $pdf->keyValues([
        'Indent No' => $r['request_no'],
        'Date' => date('d-m-Y', strtotime($r['created_at'])),
        'Department' => $r['department_name'],
        'Requested By' => $r['requested_by_name'],
        'Status' => visible_request_status($r),
    ], 2);

    $pdf->line('To,');
    $pdf->line('The GSSSR Admin,');
    $pdf->line(APP_SHORT_NAME . ', Mysuru.');
    $pdf->line('');
    $pdf->line('Through,');
    $pdf->line('The HOD,');
    $pdf->line($r['department_name'] . '.');
    $pdf->line('');
    $pdf->line('Respected Sir / Madam,');
    $pdf->line('Sub: ' . $r['purpose']);
    $pdf->paragraph('The following stock / stationery items are required for the department. Kindly review the request so that the IETW and GSSSR stock workflow can process the approved items.');

    $tableRows = [];
    $sl = 1;
    foreach ($items as $it) {
        $tableRows[] = [
            (string)$sl++,
            $it['item_code'],
            $it['item_name'],
            $it['available_quantity'] . ' ' . $it['unit'],
            $it['requested_quantity'] . ' ' . $it['unit'],
            $it['justification'] ?: '-',
        ];
    }
    $pdf->wrappedTable(['Sl No', 'Item Code', 'Particulars', 'Available Qty', 'Required Qty', 'Justification'], $tableRows, [25, 50, 140, 60, 60, 120]);
    $pdf->line('');
    $pdf->line('GSSSR Remarks: ' . ($r['gsssr_remarks'] ?: ''));
    $pdf->line('Thanking you,');
    $pdf->signatures(['HOD Signature', 'GSSSR Signature', 'IETW Signature']);

    // Filename: Request_DepartmentName_IndentNo_Date.pdf
    $deptSafe = preg_replace('/[^A-Za-z0-9]/', '_', $r['department_name']);
    $filename = get_pdf_filename('Request', $deptSafe, $generationDate);

    Database::conn()->prepare('INSERT INTO pdf_export_logs (user_id, report_type, file_name, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([current_user()['id'], 'REQUEST', $filename, $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
    log_activity('Exported request PDF ' . $r['request_no']);
    $pdf->output($filename);
}

// --- Get period details for filename ---
[$from, $to, $periodLabel] = report_period();
$params = [];
$where = '';
if ($from !== '' && $to !== '') {
    $where = 'WHERE DATE(created_at) BETWEEN ? AND ?';
    $params = [$from, $to];
}

// --- Import/Export logs (GSSSR only) ---
if (in_array($type, ['import_logs', 'pdf_exports'], true)) {
    if (current_user()['role'] !== 'GSSSR') exit('Access denied.');

    $pdf = new SimplePdf('L');
    $pdf->title($type === 'import_logs' ? 'Excel Import / Export Log Report' : 'PDF Export Log Report');
    $pdf->keyValues([
        'Period' => $periodLabel === 'All_dates' ? 'All dates' : str_replace('_', ' to ', $periodLabel),
        'Generated On' => date('d-m-Y h:i A'),
        'Generated By' => current_user()['name'],
        'Report Type' => strtoupper(str_replace('_', ' ', $type)),
    ], 2);

    if ($type === 'import_logs') {
        $logWhere = $where ? str_replace('created_at', 'eil.created_at', $where) : '';
        $records = rows("SELECT eil.*, u.name user_name FROM excel_import_logs eil LEFT JOIN users u ON u.id=eil.user_id $logWhere ORDER BY eil.created_at ASC", $params);
        $tableRows = [];
        foreach ($records as $row) {
            $tableRows[] = [
                date('d-m-Y h:i A', strtotime($row['created_at'])),
                $row['user_name'],
                $row['file_name'],
                $row['action_type'],
                $row['status'],
                $row['rows_processed'],
                $row['remarks'],
            ];
        }
        $pdf->table(['Date', 'User', 'File', 'Action', 'Status', 'Rows', 'Remarks'], $tableRows, [105, 105, 165, 70, 70, 50, 235]);
    } else {
        $logWhere = $where ? str_replace('created_at', 'pel.created_at', $where) : '';
        $records = rows("SELECT pel.*, u.name user_name FROM pdf_export_logs pel LEFT JOIN users u ON u.id=pel.user_id $logWhere ORDER BY pel.created_at ASC", $params);
        $tableRows = [];
        foreach ($records as $row) {
            $tableRows[] = [
                date('d-m-Y h:i A', strtotime($row['created_at'])),
                $row['user_name'],
                $row['report_type'],
                $row['file_name'],
                $row['ip_address'],
            ];
        }
        $pdf->table(['Date', 'User', 'Report Type', 'File Name', 'IP Address'], $tableRows, [120, 140, 120, 300, 120]);
    }
    $pdf->signatures(['Prepared By', 'Checked By', 'GSSSR']);
    $filename = get_pdf_filename('LogReport', $collegeName . '_' . $periodLabel, $generationDate);
    Database::conn()->prepare('INSERT INTO pdf_export_logs (user_id, report_type, file_name, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([current_user()['id'], strtoupper($type), $filename, $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
    log_activity('Exported PDF report ' . $type . ' for ' . $periodLabel);
    $pdf->output($filename);
}

// --- Stock Book or Monthly Report (with category grouping) ---
$title = $type === 'monthly' ? 'Monthly Stock Report' : 'Stock Book Report';
$pdf = new SimplePdf('L');
$pdf->title($title);
$pdf->keyValues([
    'Period' => $periodLabel === 'All_dates' ? 'All dates' : str_replace('_', ' to ', $periodLabel),
    'Generated On' => date('d-m-Y h:i A'),
    'Generated By' => current_user()['name'],
    'Report Type' => strtoupper($type),
], 2);

$stockWhere = $where ? str_replace('created_at', 'sb.created_at', $where) : '';

$records = rows("
    SELECT sb.*, i.item_code, i.item_name, i.unit, c.name as category_name
    FROM stock_book sb
    JOIN items i ON i.id = sb.item_id
    JOIN categories c ON c.id = i.category_id
    $stockWhere
    ORDER BY c.name, i.item_name, sb.created_at ASC
", $params);

if (empty($records)) {
    $pdf->line('No records found for the selected period.');
    $pdf->line('');
    $pdf->signatures(['Prepared By', 'Store Keeper', 'GSSSR']);
    $filename = get_pdf_filename($type === 'monthly' ? 'MonthlyReport' : 'StockBook', $collegeName . '_' . $periodLabel, $generationDate);
    Database::conn()->prepare('INSERT INTO pdf_export_logs (user_id, report_type, file_name, ip_address) VALUES (?, ?, ?, ?)')
        ->execute([current_user()['id'], strtoupper($type), $filename, $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
    log_activity('Exported PDF report ' . $type . ' for ' . $periodLabel);
    $pdf->output($filename);
    exit;
}

$grouped = [];
foreach ($records as $row) {
    $cat = $row['category_name'];
    if (!isset($grouped[$cat])) {
        $grouped[$cat] = [];
    }
    $grouped[$cat][] = $row;
}

$pdf->line(''); // small gap before first category

foreach ($grouped as $category => $rows) {
    $pdf->categoryHeading('Category: ' . $category);

    $tableRows = [];
    foreach ($rows as $row) {
        $tableRows[] = [
            date('d-m-Y', strtotime($row['created_at'])),
            $row['item_code'],
            $row['item_name'],
            $row['transaction_type'],
            $row['inward_qty'] . ' ' . $row['unit'],
            $row['outward_qty'] . ' ' . $row['unit'],
            $row['balance_qty'] . ' ' . $row['unit'],
            $row['remarks'] ?: '-',
        ];
    }
    $pdf->table(
        ['Date', 'Item Code', 'Item Name', 'Type', 'Inward', 'Outward', 'Balance', 'Remarks'],
        $tableRows,
        [75, 90, 175, 75, 65, 65, 90, 165]
    );
    // Extra space between categories
    $pdf->line('');
    $pdf->line('');
}

$pdf->signatures(['Prepared By', 'Store Keeper', 'GSSSR']);

$filename = get_pdf_filename($type === 'monthly' ? 'MonthlyReport' : 'StockBook', $collegeName . '_' . $periodLabel, $generationDate);
Database::conn()->prepare('INSERT INTO pdf_export_logs (user_id, report_type, file_name, ip_address) VALUES (?, ?, ?, ?)')
    ->execute([current_user()['id'], strtoupper($type), $filename, $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
log_activity('Exported PDF report ' . $type . ' for ' . $periodLabel);
$pdf->output($filename);
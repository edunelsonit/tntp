<?php
require_once '../config/config.php';

// Enforce access control barriers
checkRouteAccess('admin');
$db = getDB();

// -------------------------------------------------------------------
// PAGINATION SETTINGS SETUP
// -------------------------------------------------------------------
$limit = 50; // Dynamic block volume count per page view index
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// -------------------------------------------------------------------
// QUERY CONDITIONS ASSEMBLY
// -------------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$where = [];
$params = [];

if ($search !== '') {
    // Dynamic text scanning framework targeting multiple database string entities
    $where[] = '(l.action_type LIKE ? OR l.action_details LIKE ? OR l.details LIKE ? OR a.username LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}

// -------------------------------------------------------------------
// EXECUTE TOTAL COUNT CALCULATION (FOR PAGINATION UI MAPS)
// -------------------------------------------------------------------
$countSql = "SELECT COUNT(*) FROM admin_action_logs l LEFT JOIN admin_settings a ON a.id = l.admin_id";
if (!empty($where)) {
    $countSql .= ' WHERE ' . implode(' AND ', $where);
}
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRecords = intval($countStmt->fetchColumn());
$totalPages = ceil($totalRecords / $limit);

// -------------------------------------------------------------------
// FETCH AGGREGATED TELEMETRY ROWS DATA
// -------------------------------------------------------------------
// Using LEFT JOIN ensures critical audit trails persist even if an operator account gets culled
$sql = "SELECT l.*, a.username 
        FROM admin_action_logs l 
        LEFT JOIN admin_settings a ON a.id = l.admin_id";

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY l.created_at DESC LIMIT ? OFFSET ?';

// Merge limit and offset safely inside strict emulation arrays
$mergedParams = array_merge($params, [$limit, $offset]);

$stmt = $db->prepare($sql);
$stmt->execute($mergedParams);
$logs = $stmt->fetchAll();

include_once '../partials/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 pb-2 border-bottom">
    <div>
        <h1 class="h3 mb-1 text-dark fw-bold"><i class="bi bi-shield-lock-fill text-secondary me-2"></i>System Security Audit Matrix</h1>
        <p class="text-muted small mb-0">Review chronological operation sequences and core table changes completed by system administrators.</p>
    </div>
    <div class="mt-2 mt-md-0">
        <a class="btn btn-sm btn-outline-secondary fw-semibold" href="<?php echo APP_BASE_URL; ?>/admin/index.php">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard Base
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm bg-white p-3 mb-4" style="border-radius: 12px;">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-md-9">
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light text-muted border-end-0"><i class="bi bi-search"></i></span>
                <input type="text" name="search" class="form-control border-start-0" placeholder="Search across actions keywords, admin handles, tracking references, or payload values..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                <i class="bi bi-funnel-fill me-1"></i> Apply Filter Criteria
            </button>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm bg-white p-4" style="border-radius: 14px;">
    <div class="table-responsive">
        <table class="table table-hover align-middle small mb-0">
            <thead class="table-light text-secondary">
                <tr>
                    <th style="width: 8%;">Log ID</th>
                    <th style="width: 15%;">Operator User Node</th>
                    <th style="width: 22%;">Action Type Key</th>
                    <th>Structural Payload Parameters (JSON/Context Details)</th>
                    <th style="width: 15%;">Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-folder-x fs-2 d-block mb-2 text-light"></i>
                            No administrative action log indexes matched the criteria window.
                        </td>
                    </tr>
                <?php endif; ?>
                
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="font-monospace text-muted fw-bold">#<?php echo intval($log['id']); ?></td>
                        <td class="fw-semibold text-dark">
                            <i class="bi bi-person-badge me-1 text-secondary"></i>
                            <?php echo htmlspecialchars($log['username'] ?? 'SYSTEM_AUTOMATION'); ?>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark font-monospace border border-secondary-subtle px-2 py-1.5 text-uppercase">
                                <?php echo htmlspecialchars($log['action_type']); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            // Determine the correct raw text parameter value fallback mapping path
                            $rawDetails = !empty($log['action_details']) ? $log['action_details'] : ($log['details'] ?? '');
                            $decoded = json_decode($rawDetails, true);
                            
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)): ?>
                                <div class="p-2 bg-light rounded border border-light-subtle font-monospace" style="font-size: 0.75rem; max-height: 180px; overflow-y: auto;">
                                    <?php foreach ($decoded as $key => $val): ?>
                                        <div class="mb-1">
                                            <strong class="text-primary"><?php echo htmlspecialchars($key); ?>:</strong> 
                                            <span class="text-dark"><?php echo htmlspecialchars(is_array($val) ? json_encode($val) : $val); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-secondary text-wrap" style="font-size: 0.8rem; display: block; max-width: 550px;">
                                    <?php echo htmlspecialchars($rawDetails); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted font-monospace" style="font-size:0.75rem;">
                            <i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($log['created_at']); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-3">
            <div class="text-muted small">
                Showing entries <strong><?php echo $offset + 1; ?></strong> - <strong><?php echo min($totalRecords, $offset + $limit); ?></strong> of <strong><?php echo $totalRecords; ?></strong> lines.
            </div>
            <nav aria-label="Page navigation data grid maps">
                <ul class="pagination pagination-sm mb-0 justify-content-end">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">Previous</a>
                    </li>
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $startPage + 4);
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                        <li class="page-item <?php echo ($page === $i) ? 'active' : ''; ?>">
                            <a class="page-link font-monospace" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php include_once '../partials/footer.php'; ?>
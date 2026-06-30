<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(PROJECT_NAME, ENT_QUOTES, 'UTF-8'); ?> - Management Core</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bs-primary: #2563eb;
            --bs-primary-rgb: 37, 99, 235;
        }
        body { 
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; 
            letter-spacing: -0.01em;
        }
        .navbar {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%) !important;
        }
        .dashboard-card { 
            border: 1px solid #e2e8f0; 
            border-radius: 14px; 
            transition: all 0.25s ease-in-out; 
        }
        .dashboard-card:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05) !important;
        }
        .nav-link-custom {
            font-size: 0.875rem;
            font-weight: 500;
            padding: 0.4rem 0.75rem !important;
            border-radius: 6px;
            transition: all 0.15s ease;
        }
        .nav-link-custom:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
    </style>
</head>
<body class="bg-light">

<?php if (isset($_SESSION['role'])): ?>
<nav class="navbar navbar-expand-xl navbar-dark shadow-sm mb-4 py-3">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="<?php echo APP_BASE_URL; ?>/index.php">
            <i class="bi bi-cpu-fill me-2 text-info"></i><?php echo htmlspecialchars(PROJECT_NAME, ENT_QUOTES, 'UTF-8'); ?>
        </a>
        
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#payclusterNavbar" aria-controls="payclusterNavbar" aria-expanded="false" aria-label="Toggle Navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="payclusterNavbar">
            <div class="navbar-nav ms-auto align-items-xl-center gap-1 mt-3 mt-xl-0">
                
                <?php if (in_array($_SESSION['role'], ['admin', 'super_admin'], true)): ?>
                    <a href="<?php echo APP_BASE_URL; ?>/admin/index.php" class="nav-link text-white nav-link-custom"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
                    <a href="<?php echo APP_BASE_URL; ?>/admin/monnify_hub.php" class="nav-link text-white nav-link-custom"><i class="bi bi-bank me-1"></i>Monnify</a>
                    <a href="<?php echo APP_BASE_URL; ?>/admin/analytics.php" class="nav-link text-white nav-link-custom"><i class="bi bi-bar-chart-line me-1"></i>Analytics</a>
                    <a href="<?php echo APP_BASE_URL; ?>/admin/admin_action_logs.php" class="nav-link text-white nav-link-custom"><i class="bi bi-journal-text me-1"></i>Logs</a>
                <?php endif; ?>
                
                <?php if ($_SESSION['role'] === 'cluster_manager'): ?>
                    <a href="<?php echo APP_BASE_URL; ?>/cluster/index.php" class="nav-link text-white nav-link-custom"><i class="bi bi-grid-1x2 me-1"></i>Cluster Dash</a>
                    <a href="<?php echo APP_BASE_URL; ?>/cluster/users.php" class="nav-link text-white nav-link-custom"><i class="bi bi-people me-1"></i>Registries</a>
                <?php endif; ?>
                
                <?php if ($_SESSION['role'] === 'super_admin'): ?>
                    <a href="<?php echo APP_BASE_URL; ?>/admin/approve_requests.php" class="nav-link text-white nav-link-custom"><i class="bi bi-shield-check me-1"></i>Approvals</a>
                    <a href="<?php echo APP_BASE_URL; ?>/admin/salary_paid.php" class="nav-link text-white nav-link-custom"><i class="bi bi-calendar-plus me-1"></i>Cycle Open</a>
                <?php endif; ?>
                
                <?php if (in_array($_SESSION['role'], ['admin', 'super_admin'], true)): ?>
                    <a href="<?php echo APP_BASE_URL; ?>/admin/manage_disputes.php" class="nav-link text-white nav-link-custom"><i class="bi bi-exclamation-octagon me-1"></i>Disputes</a>
                <?php endif; ?>
                
                <a href="<?php echo APP_BASE_URL; ?>/users/settings.php" class="nav-link text-white nav-link-custom me-xl-2"><i class="bi bi-gear me-1"></i>Settings</a>
                
                <div class="d-xl-flex align-items-center gap-2 border-top border-xl-0 pt-2 pt-xl-0 border-white-50">
                    <span class="badge bg-white-50 text-white font-monospace text-uppercase py-2 px-3 d-block d-xl-inline mb-2 mb-xl-0" style="background-color: rgba(255,255,255,0.125); font-size: 0.75rem;">
                        <i class="bi bi-person-badge me-1"></i>Role: <?php echo htmlspecialchars(currentUserLabel() ?? $_SESSION['role']); ?>
                    </span>
                    <a href="<?php echo APP_BASE_URL; ?>/logout.php" class="btn btn-sm btn-light text-primary fw-bold rounded-pill px-3 py-2 w-100 w-xl-auto shadow-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </a>
                </div>

            </div>
        </div>
    </div>
</nav>
<?php endif; ?>

<div class="container mb-5">
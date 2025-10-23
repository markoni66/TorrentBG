<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Language.php';
require_once __DIR__ . '/../includes/TranslationManager.php';

$pdo = Database::getInstance();
$auth = new Auth($pdo);
$lang = new Language($_SESSION['lang'] ?? 'en');
$translationManager = new TranslationManager($pdo);

if ($auth->getRank() < 5) { // Moderator+
    die($lang->get('no_permission'));
}

$message = '';

// Обработка на форми
if ($_POST['action'] ?? false) {
    if ($_POST['action'] === 'approve' && isset($_POST['translation_id'])) {
        $translationId = (int)$_POST['translation_id'];
        if ($translationManager->approveTranslation($translationId, $auth->getUser()['id'])) {
            $message = '<div class="alert alert-success">' . $lang->get('translation_approved') . '</div>';
        } else {
            $message = '<div class="alert alert-danger">' . $lang->get('translation_approval_failed') . '</div>';
        }
    }
    
    if ($_POST['action'] === 'reject' && isset($_POST['translation_id'])) {
        $translationId = (int)$_POST['translation_id'];
        if ($translationManager->rejectTranslation($translationId, $auth->getUser()['id'])) {
            $message = '<div class="alert alert-success">' . $lang->get('translation_rejected') . '</div>';
        } else {
            $message = '<div class="alert alert-danger">' . $lang->get('translation_rejection_failed') . '</div>';
        }
    }
}

// Вземаме предложените преводи
$translations = $translationManager->getPendingTranslations();

// Статистики
$stats = $translationManager->getTranslationStats();

require_once __DIR__ . '/../templates/header.php';
?>

<style>
/* Тъмна навигация */
.navbar {
    background-color: #212529 !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.navbar-brand,
.navbar-nav .nav-link {
    color: #dee2e6 !important;
}

.navbar-nav .nav-link:hover,
.navbar-nav .nav-link.active {
    color: #ffffff !important;
    background-color: #495057;
}

/* Заглавие */
.admin-panel-title {
    background-color: #0d6efd;
    color: white;
    padding: 0.75rem 1rem;
    margin-bottom: 1rem;
    border-radius: 4px;
    font-weight: bold;
    font-size: 1.3rem;
}

/* Карти */
.admin-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 1rem;
    overflow: hidden;
}

/* Цветни заглавия */
.card-header.bg-primary { background-color: #0d6efd !important; }
.card-header.bg-success { background-color: #28a745 !important; }
.card-header.bg-info { background-color: #0dcaf0 !important; }
.card-header.bg-warning { background-color: #ffc107 !important; color: #212529 !important; }
.card-header.bg-danger { background-color: #dc3545 !important; }
.card-header.bg-secondary { background-color: #6c757d !important; }
.card-header.bg-dark { background-color: #212529 !important; }

/* Пурпурен цвят */
.bg-purple {
    background-color: #6f42c1 !important;
}
.btn-purple {
    background-color: #6f42c1;
    color: white;
    border-color: #6f42c1;
}
.btn-purple:hover {
    background-color: #5a35a3;
    border-color: #543196;
}
</style>

<div class="container-fluid">
    <h2><?= $lang->get('manage_translations') ?></h2>
    <?= $message ?>
    
    <div class="row">
        <div class="col-md-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h4><?= $lang->get('translation_statistics') ?></h4>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= $lang->get('total_translations') ?>:</span>
                            <strong><?= $stats['total'] ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= $lang->get('pending_translations') ?>:</span>
                            <strong class="text-warning"><?= $stats['pending'] ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= $lang->get('approved_translations') ?>:</span>
                            <strong class="text-success"><?= $stats['approved'] ?></strong>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= $lang->get('rejected_translations') ?>:</span>
                            <strong class="text-danger"><?= $stats['rejected'] ?></strong>
                        </li>
                    </ul>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h4><?= $lang->get('translations_by_language') ?></h4>
                </div>
                <div class="card-body">
                    <canvas id="languagesChart" height="200"></canvas>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h4><?= $lang->get('pending_translations') ?></h4>
                </div>
                <div class="card-body">
                    <?php if (empty($translations)): ?>
                        <div class="alert alert-info"><?= $lang->get('no_pending_translations') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th><?= $lang->get('key') ?></th>
                                        <th><?= $lang->get('language') ?></th>
                                        <th><?= $lang->get('translation') ?></th>
                                        <th><?= $lang->get('suggested_by') ?></th>
                                        <th><?= $lang->get('actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($translations as $translation): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($translation['key']) ?></td>
                                            <td><?= strtoupper($translation['language']) ?></td>
                                            <td><?= htmlspecialchars($translation['translation']) ?></td>
                                            <td><?= htmlspecialchars($translation['suggested_by']) ?></td>
                                            <td>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="translation_id" value="<?= $translation['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-success" title="<?= $lang->get('approve') ?>">✅</button>
                                                </form>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="translation_id" value="<?= $translation['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger" title="<?= $lang->get('reject') ?>">❌</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('languagesChart').getContext('2d');
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: <?= json_encode(array_keys($stats['languages'])) ?>,
            datasets: [{
                data: <?= json_encode(array_values($stats['languages'])) ?>,
                backgroundColor: [
                    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#C9CBCF', '#7CFC00', '#FF1493', '#00BFFF'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
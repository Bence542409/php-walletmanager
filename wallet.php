<?php
// --- ADMIN hitelesítés ellenőrzés ---
session_start();
if (empty($_SESSION['is_admin'])) {
    // REQUEST_URI a teljes útvonalat adja vissza a mappával és paraméterekkel együtt
    $current = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /server1/php/login.php?redirect=$current");
    exit;
}

$dataFile = 'data.json';

// Alapértelmezett adatok inicializálása, ha a fájl még nem létezik
if (!file_exists($dataFile)) {
    $initialData = [
        'accounts' => [
            ['id' => 'cash', 'name' => 'Készpénz', 'balance' => 0],
            ['id' => 'card', 'name' => 'Bankkártya', 'balance' => 0]
        ],
        'categories' => ['Fizetés', 'Élelmiszer', 'Rezsi', 'Szórakozás', 'Utazás', 'Ruházkodás', 'Egyéb'],
        'transactions' => []
    ];
    file_put_contents($dataFile, json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Adatok beolvasása
$fileContent = file_get_contents($dataFile);
$data = json_decode($fileContent, true);

// BIZTONSÁGI HÁLÓ: Ha a fájl sérült, üres, vagy másik projektből származik (pl. hiányzik az 'accounts' kulcs)
if (!is_array($data) || !isset($data['accounts']) || !isset($data['transactions'])) {
    $data = [
        'accounts' => [],
        'categories' => ['Fizetés', 'Élelmiszer', 'Rezsi', 'Szórakozás', 'Utazás', 'Ruházkodás', 'Egyéb'],
        'transactions' => []
    ];
}

// Űrlapok feldolgozása
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ÚJ TRANZAKCIÓ
    if (isset($_POST['action']) && $_POST['action'] === 'add_transaction') {
        $type = $_POST['type'];
        $amount = floatval($_POST['amount']);
        $category = $_POST['category'];
        $account_id = $_POST['account_id'];
        $description = trim($_POST['description']);
        $payee = trim($_POST['payee'] ?? '');
        $date = !empty($_POST['date']) ? $_POST['date'] : date('Y-m-d\TH:i');

        foreach ($data['accounts'] as &$account) {
            if ($account['id'] === $account_id) {
                $account['balance'] += ($type === 'income') ? $amount : -$amount;
                break;
            }
        }

        $data['transactions'][] = [
            'id' => uniqid('tx_'),
            'date' => $date,
            'type' => $type,
            'amount' => $amount,
            'category' => $category,
            'account_id' => $account_id,
            'description' => $description,
            'payee' => $payee
        ];

        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // TRANZAKCIÓ SZERKESZTÉSE
    if (isset($_POST['action']) && $_POST['action'] === 'edit_transaction') {
        $tx_id = $_POST['tx_id'];
        $new_type = $_POST['type'];
        $new_amount = floatval($_POST['amount']);
        $new_category = $_POST['category'];
        $new_account_id = $_POST['account_id'];
        $new_description = trim($_POST['description']);
        $new_payee = trim($_POST['payee'] ?? '');
        $new_date = !empty($_POST['date']) ? $_POST['date'] : date('Y-m-d\TH:i');

        $txIndex = array_search($tx_id, array_column($data['transactions'], 'id'));
        
        if ($txIndex !== false) {
            $oldTx = $data['transactions'][$txIndex];
            
            // 1. Visszavonjuk a régi tranzakció hatását a régi számlán
            foreach ($data['accounts'] as &$account) {
                if ($account['id'] === $oldTx['account_id']) {
                    $account['balance'] -= ($oldTx['type'] === 'income') ? $oldTx['amount'] : -$oldTx['amount'];
                    break;
                }
            }

            // 2. Érvényesítjük az új tranzakciót az új számlán
            foreach ($data['accounts'] as &$account) {
                if ($account['id'] === $new_account_id) {
                    $account['balance'] += ($new_type === 'income') ? $new_amount : -$new_amount;
                    break;
                }
            }

            // 3. Frissítjük magát a tranzakciót
            $data['transactions'][$txIndex] = [
                'id' => $tx_id,
                'date' => $new_date,
                'type' => $new_type,
                'amount' => $new_amount,
                'category' => $new_category,
                'account_id' => $new_account_id,
                'description' => $new_description,
                'payee' => $new_payee
            ];

            file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // TRANZAKCIÓ TÖRLÉSE
    if (isset($_POST['action']) && $_POST['action'] === 'delete_transaction') {
        $tx_id = $_POST['tx_id'];
        $txIndex = array_search($tx_id, array_column($data['transactions'], 'id'));
        
        if ($txIndex !== false) {
            $oldTx = $data['transactions'][$txIndex];
            
            // Visszavonjuk az egyenlegből
            foreach ($data['accounts'] as &$account) {
                if ($account['id'] === $oldTx['account_id']) {
                    $account['balance'] -= ($oldTx['type'] === 'income') ? $oldTx['amount'] : -$oldTx['amount'];
                    break;
                }
            }
            
            // Töröljük a tömbből
            array_splice($data['transactions'], $txIndex, 1);
            file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // ÚJ SZÁMLA HOZZÁADÁSA
    if (isset($_POST['action']) && $_POST['action'] === 'add_account') {
        $account_name = trim($_POST['account_name']);
        if (!empty($account_name)) {
            $data['accounts'][] = [
                'id' => uniqid('acc_'),
                'name' => $account_name,
                'balance' => 0
            ];
            file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }

    // SZÁMLA TÖRLÉSE
    if (isset($_POST['action']) && $_POST['action'] === 'delete_account') {
        $account_id = $_POST['account_id'];
        $data['accounts'] = array_filter($data['accounts'], function($acc) use ($account_id) {
            return $acc['id'] !== $account_id;
        });
        $data['accounts'] = array_values($data['accounts']);
        file_put_contents($dataFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// -- STATISZTIKÁK KISZÁMÍTÁSA --
$totalBalance = empty($data['accounts']) ? 0 : array_sum(array_column($data['accounts'], 'balance'));

// Havi Cash Flow számítás (Aktuális hónap)
$currentMonthStr = date('Y-m');
$monthlyIncome = 0;
$monthlyExpense = 0;

if (!empty($data['transactions'])) {
    foreach ($data['transactions'] as $t) {
        if (strpos($t['date'], $currentMonthStr) === 0) {
            if ($t['type'] === 'income') {
                $monthlyIncome += $t['amount'];
            } else {
                $monthlyExpense += $t['amount'];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pénzügyek</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased min-h-screen pb-12 relative">

<div class="max-w-5xl mx-auto pt-6 px-4">
    
    <div class="flex justify-end mb-4 gap-2">
        <button onclick="window.history.back()" class="p-2 text-gray-400 hover:text-gray-800 hover:bg-gray-100 rounded-full transition cursor-pointer outline-none" title="Vissza az előző oldalra (vagy nyomd meg az ESC gombot)">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
        </button>

        <button onclick="toggleModal('settingsModal')" class="p-2 text-gray-400 hover:text-gray-800 hover:bg-gray-100 rounded-full transition cursor-pointer outline-none" title="Számlák kezelése">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
        </button>
    </div>

    <header class="mb-8 text-center">
        <h1 class="text-xl font-medium text-gray-500 mb-2">Teljes egyenleg</h1>
        <div class="text-5xl font-bold text-gray-900 tracking-tight">
            <?= number_format($totalBalance, 0, ',', ' ') ?> Ft
        </div>
    </header>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
        
        <div class="md:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
            <?php if (empty($data['accounts'])): ?>
                <div class="col-span-2 p-6 text-center text-gray-400 bg-white rounded-2xl border border-gray-100 shadow-sm">
                    Nincsenek számlák. Hozz létre egyet a beállításokban!
                </div>
            <?php else: ?>
                <?php foreach ($data['accounts'] as $account): ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex flex-col justify-center">
                        <div class="text-sm text-gray-400 font-medium mb-1"><?= htmlspecialchars($account['name']) ?></div>
                        <div class="text-2xl font-semibold text-gray-800">
                            <?= number_format($account['balance'], 0, ',', ' ') ?> Ft
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex flex-col">
            <h3 class="text-sm font-medium text-gray-400 mb-2">Cash Flow</h3>
            <div class="flex-1 relative w-full h-32">
                <canvas id="cashFlowChart"></canvas>
            </div>
            <div class="flex justify-between text-xs mt-3 px-2">
                <span class="text-emerald-500 font-medium">+<?= number_format($monthlyIncome, 0, ',', ' ') ?> Ft</span>
                <span class="text-red-500 font-medium">-<?= number_format($monthlyExpense, 0, ',', ' ') ?> Ft</span>
            </div>
        </div>

    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-1">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sticky top-6">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Új tranzakció</h2>
                
                <?php if (empty($data['accounts'])): ?>
                    <div class="text-sm text-red-500 bg-red-50 p-3 rounded-lg border border-red-100">
                        Nincs elérhető számla. Kérlek, hozz létre egyet a beállításokban!
                    </div>
                <?php else: ?>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_transaction">
                        
                        <div>
                            <label class="block text-sm text-gray-500 mb-1">Dátum és Idő</label>
                            <input type="datetime-local" name="date" value="<?= date('Y-m-d\TH:i') ?>" class="w-full bg-gray-50 border border-gray-200 text-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 p-2.5 outline-none transition" required>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm text-gray-500 mb-1">Típus</label>
                                <select name="type" class="w-full bg-gray-50 border border-gray-200 text-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 p-2.5 outline-none transition" required>
                                    <option value="expense">Kiadás</option>
                                    <option value="income">Bevétel</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm text-gray-500 mb-1">Összeg (Ft)</label>
                                <input type="number" name="amount" min="1" class="w-full bg-gray-50 border border-gray-200 text-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 p-2.5 outline-none transition" required>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm text-gray-500 mb-1">Számla és Kategória</label>
                            <div class="flex gap-2">
                                <select name="account_id" class="w-1/2 bg-gray-50 border border-gray-200 text-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 p-2.5 outline-none transition" required>
                                    <?php foreach ($data['accounts'] as $account): ?>
                                        <option value="<?= $account['id'] ?>"><?= htmlspecialchars($account['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="category" class="w-1/2 bg-gray-50 border border-gray-200 text-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 p-2.5 outline-none transition" required>
                                    <?php foreach ($data['categories'] as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm text-gray-500 mb-1">Kedvezményezett</label>
                            <input type="text" name="payee" class="w-full bg-gray-50 border border-gray-200 text-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 p-2.5 outline-none transition">
                        </div>

                        <div>
                            <label class="block text-sm text-gray-500 mb-1">Megjegyzés</label>
                            <input type="text" name="description" class="w-full bg-gray-50 border border-gray-200 text-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 p-2.5 outline-none transition">
                        </div>

                        <button type="submit" class="w-full bg-gray-900 hover:bg-gray-800 text-white font-medium rounded-lg py-3 transition shadow-sm">
                            Mentés
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-gray-50 bg-white">
                    <h2 class="text-lg font-semibold text-gray-800">Előzmények</h2>
                </div>
                
                <div class="divide-y divide-gray-50">
                    <?php 
                    $transactions = $data['transactions'] ?? [];
                    // Rendezzük dátum szerint csökkenőbe
                    usort($transactions, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
                    
                    if (empty($transactions)): 
                    ?>
                        <div class="p-8 text-center text-gray-400">Nincsenek még tranzakciók.</div>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <?php
                            $accountName = 'Törölt számla';
                            foreach ($data['accounts'] as $acc) {
                                if ($acc['id'] === $t['account_id']) { $accountName = $acc['name']; break; }
                            }
                            $isExpense = $t['type'] === 'expense';
                            $amountColor = $isExpense ? 'text-gray-900' : 'text-emerald-500';
                            $sign = $isExpense ? '-' : '+';
                            $payeeText = !empty($t['payee']) ? htmlspecialchars($t['payee']) : htmlspecialchars($t['category']);
                            ?>
                            
                            <div onclick='openEditModal(<?= json_encode($t) ?>)' class="p-4 hover:bg-gray-50 transition flex items-center justify-between cursor-pointer">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center <?= $isExpense ? 'bg-red-50 text-red-500' : 'bg-emerald-50 text-emerald-500' ?>">
                                        <?php if ($isExpense): ?>
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>
                                        <?php else: ?>
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="font-medium text-gray-800">
                                            <?= $payeeText ?>
                                            <?php if(!empty($t['description'])): ?>
                                                <span class="text-sm font-normal text-gray-500 ml-1">- <?= htmlspecialchars($t['description']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-sm text-gray-400">
                                            <?= date('Y. m. d. H:i', strtotime($t['date'])) ?> • <?= htmlspecialchars($accountName) ?>
                                            <?php if(!empty($t['payee'])): ?> • <span class="italic"><?= htmlspecialchars($t['category']) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="font-semibold <?= $amountColor ?>">
                                    <?= $sign ?><?= number_format($t['amount'], 0, ',', ' ') ?> Ft
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<div id="settingsModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden transform transition-all">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800">Számlák kezelése</h3>
            <button onclick="toggleModal('settingsModal')" class="text-gray-400 hover:text-gray-600 outline-none">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-6 space-y-6">
            <div>
                <div class="space-y-2">
                    <?php foreach ($data['accounts'] as $account): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border border-gray-100">
                            <span class="font-medium text-gray-800"><?= htmlspecialchars($account['name']) ?></span>
                            <form method="POST" class="m-0" onsubmit="return confirm('Biztosan törlöd ezt a számlát?');">
                                <input type="hidden" name="action" value="delete_account">
                                <input type="hidden" name="account_id" value="<?= $account['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700 p-1.5 rounded outline-none">Törlés</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <form method="POST" class="flex gap-2">
                <input type="hidden" name="action" value="add_account">
                <input type="text" name="account_name" placeholder="Új számla neve" class="flex-1 bg-gray-50 border border-gray-200 rounded-lg p-2.5 outline-none" required>
                <button type="submit" class="bg-gray-900 text-white px-4 rounded-lg font-medium">Hozzáadás</button>
            </form>
        </div>
    </div>
</div>

<div id="editTxModal" class="hidden fixed inset-0 bg-gray-900 bg-opacity-50 z-50 flex items-center justify-center p-4 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden transform transition-all">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-semibold text-gray-800">Tranzakció szerkesztése</h3>
            <button onclick="toggleModal('editTxModal')" class="text-gray-400 hover:text-gray-600 outline-none">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        
        <div class="p-6">
            <form method="POST" id="editForm" class="space-y-4">
                <input type="hidden" name="action" value="edit_transaction">
                <input type="hidden" name="tx_id" id="edit_tx_id">
                
                <div>
                    <label class="block text-sm text-gray-500 mb-1">Dátum és Idő</label>
                    <input type="datetime-local" name="date" id="edit_date" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 outline-none" required>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm text-gray-500 mb-1">Típus</label>
                        <select name="type" id="edit_type" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 outline-none" required>
                            <option value="expense">Kiadás (-)</option>
                            <option value="income">Bevétel (+)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-500 mb-1">Összeg (Ft)</label>
                        <input type="number" name="amount" id="edit_amount" min="1" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 outline-none" required>
                    </div>
                </div>

                <div>
                    <label class="block text-sm text-gray-500 mb-1">Számla</label>
                    <select name="account_id" id="edit_account_id" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 outline-none" required>
                        <?php foreach ($data['accounts'] as $account): ?>
                            <option value="<?= $account['id'] ?>"><?= htmlspecialchars($account['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm text-gray-500 mb-1">Kategória</label>
                    <select name="category" id="edit_category" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 outline-none" required>
                        <?php foreach ($data['categories'] as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm text-gray-500 mb-1">Kedvezményezett</label>
                    <input type="text" name="payee" id="edit_payee" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 outline-none">
                </div>

                <div>
                    <label class="block text-sm text-gray-500 mb-1">Megjegyzés</label>
                    <input type="text" name="description" id="edit_description" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-2.5 outline-none">
                </div>

                <div class="flex gap-3 pt-2">
                    <button type="submit" class="flex-1 bg-gray-900 hover:bg-gray-800 text-white font-medium rounded-lg py-2.5 transition">Mentés</button>
                    <button type="button" onclick="deleteTx()" class="bg-red-50 text-red-600 hover:bg-red-100 font-medium rounded-lg px-4 py-2.5 transition">Törlés</button>
                </div>
            </form>
            
            <form method="POST" id="deleteForm" class="hidden">
                <input type="hidden" name="action" value="delete_transaction">
                <input type="hidden" name="tx_id" id="delete_tx_id">
            </form>
        </div>
    </div>
</div>

<script>
    // Modal kezelő
    function toggleModal(modalID) {
        const modal = document.getElementById(modalID);
        modal.classList.toggle('hidden');
    }

    // Bezárás kattintásra a háttérben
    document.querySelectorAll('.fixed.inset-0').forEach(el => {
        el.addEventListener('click', function(e) {
            if (e.target === this) toggleModal(this.id);
        });
    });

    // ESC gomb eseménykezelő - ablakbezárás vagy "Vissza" funkció
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const settingsModal = document.getElementById('settingsModal');
            const editTxModal = document.getElementById('editTxModal');
            let isModalOpen = false;

            // Ha valamelyik ablak nyitva van, csak azt zárja be
            if (!settingsModal.classList.contains('hidden')) {
                settingsModal.classList.add('hidden');
                isModalOpen = true;
            }
            if (!editTxModal.classList.contains('hidden')) {
                editTxModal.classList.add('hidden');
                isModalOpen = true;
            }

            // Ha egyik ablak sem volt nyitva, akkor navigáljon vissza
            if (!isModalOpen) {
                window.history.back();
            }
        }
    });

    // Szerkesztő ablak megnyitása adatokkal
    function openEditModal(tx) {
        document.getElementById('edit_tx_id').value = tx.id;
        document.getElementById('delete_tx_id').value = tx.id;
        
        let dateVal = tx.date;
        if(dateVal.length === 10) dateVal += 'T00:00';
        document.getElementById('edit_date').value = dateVal.substring(0, 16);
        
        document.getElementById('edit_type').value = tx.type;
        document.getElementById('edit_amount').value = tx.amount;
        document.getElementById('edit_account_id').value = tx.account_id;
        document.getElementById('edit_category').value = tx.category;
        document.getElementById('edit_payee').value = tx.payee || '';
        document.getElementById('edit_description').value = tx.description || '';
        
        toggleModal('editTxModal');
    }

    function deleteTx() {
        if (confirm('Biztosan törölni szeretnéd ezt a tranzakciót? Az összeg visszakerül a számlára.')) {
            document.getElementById('deleteForm').submit();
        }
    }

    // Chart.js - Cash Flow Diagram inicializálása
    const ctx = document.getElementById('cashFlowChart').getContext('2d');
    const income = <?= $monthlyIncome ?>;
    const expense = <?= $monthlyExpense ?>;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: ['Bevétel', 'Kiadás'],
            datasets: [{
                data: [income, expense],
                backgroundColor: [
                    'rgba(16, 185, 129, 0.8)',
                    'rgba(239, 68, 68, 0.8)'
                ],
                borderRadius: 6,
                barThickness: 30
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.raw.toLocaleString('hu-HU') + ' Ft';
                        }
                    }
                }
            },
            scales: {
                x: { display: false, beginAtZero: true },
                y: { grid: { display: false }, border: { display: false } }
            }
        }
    });
</script>

</body>
</html>
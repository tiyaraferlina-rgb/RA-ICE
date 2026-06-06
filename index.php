<?php
session_start();

/* ===== CONFIG DATABASE ===== */
/* Untuk hosting, sesuaikan dbHost, dbName, dbUser, dan dbPass dengan database hosting. */
$dbHost = 'localhost';
$dbName = 'raice_db';
$dbUser = 'root';
$dbPass = '';

$pdo = null;
$dbError = null;

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $error) {
    $dbError = 'Koneksi database belum tersedia.';
}

/* ===== HELPER FUNCTIONS ===== */
function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rupiah($value)
{
    return 'Rp' . number_format((int) $value, 0, ',', '.');
}

function redirect($path)
{
    header('Location: ' . $path);
    exit;
}

function set_flash($message, $type = 'success')
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function get_flash()
{
    $flash = [
        'message' => $_SESSION['flash_message'] ?? '',
        'type' => $_SESSION['flash_type'] ?? 'success',
    ];

    unset($_SESSION['flash_message'], $_SESSION['flash_type']);

    return $flash;
}

function is_admin_logged_in()
{
    return !empty($_SESSION['admin_id']);
}

function require_admin()
{
    if (!is_admin_logged_in()) {
        redirect('index.php?page=login');
    }
}

function is_hashed_password($password)
{
    return is_string($password) && preg_match('/^(\$2y\$|\$argon2)/i', $password);
}

function safe_filename_base($text)
{
    $text = strtolower(trim((string) $text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

function product_image_src($image)
{
    $image = trim((string) $image);
    $defaultImage = 'images/STRAWBERRY.png';

    if ($image === '') {
        return $defaultImage;
    }

    if (preg_match('/^https?:\/\//i', $image)) {
        return $image;
    }

    $image = ltrim($image, '/');
    $image = preg_replace('#^assets/images/#', 'images/', $image);
    $imagePath = __DIR__ . '/' . $image;

    return is_file($imagePath) ? $image : $defaultImage;
}

function getMoodData($moodKey)
{
    $moods = [
        'happy' => [
            'mood_name' => 'Happy',
            'mood_label' => 'Cocok untuk mood ceria',
            'mood_reason' => 'Rasa ini cocok untuk suasana hati yang ceria dan menyenangkan.',
        ],
        'tired' => [
            'mood_name' => 'Tired',
            'mood_label' => 'Cocok saat lelah',
            'mood_reason' => 'Rasa ini cocok untuk menemani saat tubuh terasa lelah dan butuh sesuatu yang segar.',
        ],
        'stressed' => [
            'mood_name' => 'Stressed',
            'mood_label' => 'Cocok saat stres',
            'mood_reason' => 'Rasa ini cocok untuk membantu membuat suasana terasa lebih santai.',
        ],
        'need_energy' => [
            'mood_name' => 'Need Energy',
            'mood_label' => 'Cocok saat butuh energi',
            'mood_reason' => 'Rasa ini cocok saat membutuhkan semangat tambahan untuk beraktivitas.',
        ],
    ];

    return $moods[$moodKey] ?? null;
}

function ensure_product_upload_dir($uploadDir)
{
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('Folder upload gambar belum bisa dibuat.');
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Folder upload gambar tidak bisa ditulis.');
    }
}

function save_product_image_upload($file, $fileNameBase, $uploadDir, $uploadPath, $maxSize, array $allowedExtensions)
{
    if (!$file || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload gambar gagal. Pilih file lain lalu coba lagi.');
    }

    if ((int) $file['size'] > $maxSize) {
        throw new RuntimeException('Ukuran gambar maksimal 2MB.');
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Format gambar hanya boleh jpg, jpeg, png, atau webp.');
    }

    $allowedMimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    $detectedMime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = (string) finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }

    if ($detectedMime !== '' && $detectedMime !== $allowedMimeTypes[$extension]) {
        throw new RuntimeException('File yang diupload bukan gambar yang valid.');
    }

    ensure_product_upload_dir($uploadDir);

    $safeFileNameBase = safe_filename_base($fileNameBase) ?: 'produk';
    $baseFileName = $safeFileNameBase . '-' . date('YmdHis');
    $fileName = $baseFileName . '.' . $extension;
    $targetPath = $uploadDir . $fileName;
    $counter = 1;

    while (is_file($targetPath)) {
        $fileName = $baseFileName . '-' . $counter . '.' . $extension;
        $targetPath = $uploadDir . $fileName;
        $counter++;
    }

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Gambar produk belum berhasil disimpan.');
    }

    return $uploadPath . $fileName;
}

function load_home_products($pdo, array $moodColors)
{
    $products = [];
    $moodGroups = [];

    if (!$pdo) {
        return [$products, $moodGroups];
    }

    $stmt = $pdo->query('SELECT * FROM products WHERE is_active = 1 ORDER BY product_id ASC');
    $products = $stmt->fetchAll();

    foreach ($products as $index => $product) {
        $image = product_image_src($product['image'] ?? '');
        $products[$index]['image'] = $image;
        $key = $product['mood_key'];

        if (!isset($moodGroups[$key])) {
            $moodGroups[$key] = [
                'mood_key' => $key,
                'mood_name' => $product['mood_name'],
                'items' => [],
            ];
        }

        $moodGroups[$key]['items'][] = [
            'product_id' => (int) $product['product_id'],
            'name' => $product['name'],
            'price' => (int) $product['price'],
            'stock' => (int) $product['stock'],
            'flavor' => $product['flavor'],
            'mood_key' => $product['mood_key'],
            'mood_name' => $product['mood_name'],
            'mood_label' => $product['mood_label'],
            'mood_reason' => $product['mood_reason'],
            'image' => $image,
            'color' => $moodColors[$product['mood_key']] ?? '#B8DFAF',
        ];
    }

    return [$products, $moodGroups];
}

function render_header($pageTitle, $pageDescription, $bodyClass = '')
{
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <meta name="description" content="<?= h($pageDescription) ?>">
    <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body<?= $bodyClass ? ' class="' . h($bodyClass) . '"' : '' ?>>
    <?php
}

function render_footer()
{
    ?>
<script src="bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="script.js"></script>
</body>
</html>
    <?php
}

function render_user_navbar()
{
    ?>
<header class="sticky-top">
    <nav class="navbar navbar-expand-md raice-navbar" aria-label="Navigasi utama RA-ICE">
        <div class="container">
            <div class="glass-navbar">
                <a class="navbar-brand raice-brand raice-logo" href="index.php?page=home#home" aria-label="RA-ICE Home">
                    <span class="logo-pop">RA</span><span class="logo-dash">-</span><span class="logo-ice">ICE</span>
                </a>
                <button class="navbar-toggler raice-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="mainNavbar">
                    <ul class="navbar-nav raice-nav-menu">
                        <li class="nav-item"><a class="nav-link raice-nav-link" href="index.php?page=home#home">Home</a></li>
                        <li class="nav-item"><a class="nav-link raice-nav-link" href="index.php?page=home#menu">Menu</a></li>
                        <li class="nav-item"><a class="nav-link raice-nav-link" href="index.php?page=home#mood">Mood</a></li>
                        <li class="nav-item"><a class="nav-link raice-nav-link" href="index.php?page=home#order">Order</a></li>
                        <li class="nav-item"><a class="nav-link raice-nav-link" href="index.php?page=home#about">About</a></li>
                    </ul>
                    <a class="nav-admin-btn" href="index.php?page=login" aria-label="Admin Login RA-ICE">Admin</a>
                </div>
            </div>
        </div>
    </nav>
</header>
    <?php
}

function render_user_footer()
{
    ?>
<footer class="footer-section footer-clean">
    <div class="container">
        <div class="footer-content">
            <div class="footer-left">
                <a class="footer-brand raice-logo raice-logo-footer" href="index.php?page=home#home">
                    <span class="logo-pop">RA</span><span class="logo-dash">-</span><span class="logo-ice">ICE</span>
                </a>
            </div>
            <div class="footer-center">
                <small class="footer-copyright">&copy; 2026 RA-ICE. All rights reserved.</small>
            </div>
            <div class="footer-right">
                <nav class="footer-menu mb-3" aria-label="Menu footer">
                    <a href="index.php?page=home#home">Home</a>
                    <a href="index.php?page=home#menu">Menu</a>
                    <a href="index.php?page=home#order">Order</a>
                    <a href="index.php?page=home#about">About</a>
                </nav>
                <div class="footer-contact-list">
                    <p class="footer-contact mb-2"><strong>No HP:</strong> 0822 8678 7554</p>
                    <p class="footer-contact mb-3"><strong>Alamat:</strong> Kampung Mangun Harjo, Blok B, No 28</p>
                </div>
                <a class="footer-wa-link" href="https://wa.me/6282286787554" target="_blank" rel="noopener" aria-label="Chat RA-ICE lewat WhatsApp">
                    <img src="images/whatsapp.png" alt="WhatsApp" class="footer-wa-icon">
                </a>
            </div>
        </div>
    </div>
</footer>
    <?php
}

function render_admin_sidebar($activeAdminPage)
{
    ?>
<aside class="admin-sidebar">
    <a class="admin-sidebar-brand" href="index.php?page=dashboard"><span>RA</span>-ICE</a>
    <div class="sticker-label sticker-label-hot my-3">Admin Booth</div>
    <nav class="admin-nav" aria-label="Admin navigation">
        <a class="admin-nav-link<?= $activeAdminPage === 'dashboard' ? ' active' : '' ?>" href="index.php?page=dashboard">Dashboard</a>
        <a class="admin-nav-link<?= $activeAdminPage === 'products' ? ' active' : '' ?>" href="index.php?page=products">Products</a>
        <a class="admin-nav-link<?= $activeAdminPage === 'orders' ? ' active' : '' ?>" href="index.php?page=orders">Orders</a>
        <a class="admin-nav-link<?= $activeAdminPage === 'mood' ? ' active' : '' ?>" href="index.php?page=mood">Mood</a>
    </nav>
    <div class="admin-sidebar-footer">
        <a class="admin-sidebar-action admin-sidebar-back" href="index.php?page=home">Back to Website</a>
        <a class="admin-sidebar-action admin-sidebar-logout" href="index.php?action=logout">Logout</a>
    </div>
</aside>
    <?php
}

/* ===== DATA PILIHAN PRODUK DAN MOOD ===== */
$cardClasses = [
    'strawberry' => 'card-strawberry',
    'melon-milk' => 'card-melon',
    'chocolate-milk' => 'card-chocolate',
    'durian-cream' => 'card-durian',
];

$buttonClasses = [
    'strawberry' => 'raice-btn-primary',
    'melon-milk' => 'raice-btn-melon',
    'chocolate-milk' => 'raice-btn-chocolate',
    'durian-cream' => 'raice-btn-durian',
];

$moodClasses = [
    'happy' => 'mood-happy',
    'tired' => 'mood-tired',
    'stressed' => 'mood-stressed',
    'need_energy' => 'mood-energy',
];

$moodColors = [
    'happy' => '#F28BAE',
    'tired' => '#B8DFAF',
    'stressed' => '#C9A27B',
    'need_energy' => '#F6E48F',
];

$statusOptions = ['pending', 'processing', 'completed', 'cancelled'];
$productUploadPath = 'images/products/';
$productUploadDir = __DIR__ . '/' . $productUploadPath;
$maxProductImageSize = 2 * 1024 * 1024;
$allowedProductImageExtensions = ['jpg', 'jpeg', 'png', 'webp'];

/* ===== ROUTING HALAMAN ===== */
$allowedPages = ['home', 'login', 'dashboard', 'products', 'orders', 'mood'];
$page = $_GET['page'] ?? 'home';
$action = $_GET['action'] ?? '';

if (!in_array($page, $allowedPages, true)) {
    $page = 'home';
}

/* ===== LOGOUT ===== */
if ($action === 'logout') {
    session_destroy();
    redirect('index.php?page=login');
}

if (in_array($page, ['dashboard', 'products', 'orders', 'mood'], true)) {
    require_admin();
}

if ($page === 'login' && is_admin_logged_in()) {
    redirect('index.php?page=dashboard');
}

$pageMessage = '';
$pageMessageType = 'success';
$orderMessage = '';
$orderMessageType = 'success';
$loginError = '';

/* ===== PROSES FORM ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($page === 'home' && ($_POST['form_action'] ?? '') === 'create_order') {
        $customerName = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $postedItems = $_POST['items'] ?? [];
        $orderQuantities = [];

        if (is_array($postedItems)) {
            foreach ($postedItems as $postedProductId => $postedQuantity) {
                $productId = (int) $postedProductId;
                $quantity = (int) $postedQuantity;

                if ($productId > 0 && $quantity > 0) {
                    $orderQuantities[$productId] = $quantity;
                }
            }
        }

        if (!$pdo) {
            $orderMessage = $dbError;
            $orderMessageType = 'danger';
        } elseif ($customerName === '' || $phone === '') {
            $orderMessage = 'Lengkapi data pesanan terlebih dahulu.';
            $orderMessageType = 'danger';
        } elseif (!$orderQuantities) {
            $orderMessage = 'Pilih minimal satu produk.';
            $orderMessageType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                $productIds = array_keys($orderQuantities);
                $placeholders = implode(',', array_fill(0, count($productIds), '?'));

                $stmt = $pdo->prepare("
                    SELECT product_id, name, price, stock
                    FROM products
                    WHERE product_id IN ($placeholders) AND is_active = 1
                    FOR UPDATE
                ");
                $stmt->execute($productIds);
                $selectedProducts = [];
                foreach ($stmt->fetchAll() as $product) {
                    $selectedProducts[(int) $product['product_id']] = $product;
                }

                $orderItems = [];
                $totalAmount = 0;

                foreach ($orderQuantities as $productId => $quantity) {
                    if (!isset($selectedProducts[$productId])) {
                        throw new RuntimeException('Produk tidak tersedia.');
                    }

                    $product = $selectedProducts[$productId];
                    if ((int) $product['stock'] <= 0) {
                        throw new RuntimeException('Maaf, saat ini stok ' . $product['name'] . ' belum tersedia.');
                    }

                    if ((int) $product['stock'] < $quantity) {
                        throw new RuntimeException('Stok produk ' . $product['name'] . ' tidak cukup.');
                    }

                    $unitPrice = (int) $product['price'];
                    $subtotal = $quantity * $unitPrice;
                    $totalAmount += $subtotal;
                    $orderItems[] = [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'subtotal' => $subtotal,
                    ];
                }

                $stmt = $pdo->prepare('INSERT INTO customers (name, phone, email) VALUES (?, ?, ?)');
                $stmt->execute([$customerName, $phone, $email !== '' ? $email : null]);
                $customerId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare('INSERT INTO orders (customer_id, total_amount, status, notes) VALUES (?, ?, ?, ?)');
                $stmt->execute([$customerId, $totalAmount, 'pending', $notes !== '' ? $notes : null]);
                $orderId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)');
                $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE product_id = ?');

                foreach ($orderItems as $item) {
                    $itemStmt->execute([$orderId, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']]);
                    $stockStmt->execute([$item['quantity'], $item['product_id']]);
                }

                $pdo->commit();
                set_flash('Pesanan berhasil dibuat dengan Order ID #' . $orderId . '. Admin RA-ICE akan memproses pesanan kamu.');
                redirect('index.php?page=home#order');
            } catch (Throwable $error) {
                if ($pdo && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $orderMessage = $error instanceof RuntimeException ? $error->getMessage() : 'Pesanan belum berhasil diproses.';
                $orderMessageType = 'danger';
            }
        }
    }

    /* ===== ADMIN LOGIN ===== */
    if ($page === 'login') {
        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($pdo && $identity !== '' && $password !== '') {
            try {
                $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1');
                $stmt->execute([$identity, $identity]);
                $admin = $stmt->fetch();

                $validPassword = false;
                if ($admin) {
                    if (is_hashed_password($admin['password'])) {
                        $validPassword = password_verify($password, $admin['password']);
                    } else {
                        $validPassword = hash_equals($admin['password'], $password);
                    }
                }

                if ($admin && $validPassword) {
                    $_SESSION['admin_id'] = (int) $admin['admin_id'];
                    $_SESSION['admin_name'] = $admin['name'];
                    redirect('index.php?page=dashboard');
                }
            } catch (Throwable $error) {
                $loginError = 'Login belum bisa diproses. Pastikan database sudah di-import.';
            }
        }

        if (!$loginError) {
            $loginError = 'Username/email atau password tidak sesuai.';
        }
    }

    /* ===== MANAGE PRODUCTS ===== */
    if ($page === 'products' && $pdo) {
        try {
            if ($action === 'add' || $action === 'edit') {
                $productId = (int) ($_GET['id'] ?? $_POST['product_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $stock = max(0, (int) ($_POST['stock'] ?? 0));
                $flavor = trim($_POST['flavor'] ?? '');
                $moodKey = trim($_POST['mood_key'] ?? '');
                $isActive = (int) ($_POST['is_active'] ?? 1);
                $price = 1000;
                $moodData = getMoodData($moodKey);

                if ($name === '' || $description === '' || $flavor === '' || $moodKey === '') {
                    throw new RuntimeException('Lengkapi semua data produk dan mood recommendation.');
                }

                if (!$moodData) {
                    throw new RuntimeException('Mood produk tidak valid.');
                }

                $currentImage = '';
                if ($action === 'edit') {
                    $stmt = $pdo->prepare('SELECT image FROM products WHERE product_id = ?');
                    $stmt->execute([$productId]);
                    $currentProduct = $stmt->fetch();

                    if (!$currentProduct) {
                        throw new RuntimeException('Produk tidak ditemukan.');
                    }

                    $currentImage = (string) ($currentProduct['image'] ?? '');
                }

                $uploadedImage = save_product_image_upload(
                    $_FILES['image'] ?? null,
                    $name,
                    $productUploadDir,
                    $productUploadPath,
                    $maxProductImageSize,
                    $allowedProductImageExtensions
                );
                $image = $uploadedImage !== '' ? $uploadedImage : $currentImage;

                if ($action === 'add' && $image === '') {
                    throw new RuntimeException('Upload gambar produk terlebih dahulu.');
                }

                if ($action === 'edit') {
                    $stmt = $pdo->prepare("
                        UPDATE products
                        SET name = ?, description = ?, price = ?, stock = ?, flavor = ?,
                            mood_key = ?, mood_name = ?, mood_label = ?, mood_reason = ?,
                            image = ?, is_active = ?
                        WHERE product_id = ?
                    ");
                    $stmt->execute([
                        $name,
                        $description,
                        $price,
                        $stock,
                        $flavor,
                        $moodKey,
                        $moodData['mood_name'],
                        $moodData['mood_label'],
                        $moodData['mood_reason'],
                        $image,
                        $isActive,
                        $productId,
                    ]);
                    set_flash('Produk berhasil diperbarui.');
                } else {
                    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
                    if ($adminId <= 0) {
                        throw new RuntimeException('Session admin tidak valid. Silakan login ulang.');
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO products
                        (admin_id, name, description, price, stock, flavor, mood_key, mood_name, mood_label, mood_reason, image, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $adminId,
                        $name,
                        $description,
                        $price,
                        $stock,
                        $flavor,
                        $moodKey,
                        $moodData['mood_name'],
                        $moodData['mood_label'],
                        $moodData['mood_reason'],
                        $image,
                        $isActive,
                    ]);
                    set_flash('Produk baru berhasil ditambahkan.');
                }

                redirect('index.php?page=products');
            }

            if ($action === 'delete') {
                $productId = (int) ($_GET['id'] ?? 0);
                if ($productId <= 0) {
                    throw new RuntimeException('Produk tidak ditemukan.');
                }

                $stmt = $pdo->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = ?');
                $stmt->execute([$productId]);
                $usedInOrders = (int) $stmt->fetchColumn() > 0;

                if ($usedInOrders) {
                    $stmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE product_id = ?');
                    $stmt->execute([$productId]);
                    set_flash('Produk punya riwayat pesanan, jadi produk dinonaktifkan dari katalog.');
                } else {
                    $stmt = $pdo->prepare('DELETE FROM products WHERE product_id = ?');
                    $stmt->execute([$productId]);
                    set_flash('Produk berhasil dihapus.');
                }

                redirect('index.php?page=products');
            }
        } catch (Throwable $error) {
            $pageMessage = $error instanceof RuntimeException ? $error->getMessage() : 'Data produk belum berhasil disimpan.';
            $pageMessageType = 'danger';
        }
    }

    /* ===== MANAGE ORDERS ===== */
    if ($page === 'orders' && $pdo && $action === 'update_status') {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if ($orderId > 0 && in_array($status, $statusOptions, true)) {
            try {
                $stmt = $pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?');
                $stmt->execute([$status, $orderId]);
                set_flash('Status pesanan berhasil diperbarui.');
                redirect('index.php?page=orders');
            } catch (Throwable $error) {
                $pageMessage = 'Status pesanan belum berhasil diperbarui.';
                $pageMessageType = 'danger';
            }
        } else {
            $pageMessage = 'Status pesanan tidak valid.';
            $pageMessageType = 'danger';
        }
    }

    /* ===== MOOD MANAGEMENT ===== */
    if ($page === 'mood' && $pdo && $action === 'update') {
        $productId = (int) ($_GET['id'] ?? 0);
        $moodKey = trim($_POST['mood_key'] ?? '');
        $moodData = getMoodData($moodKey);

        if ($productId > 0 && $moodData) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE products
                    SET mood_key = ?, mood_name = ?, mood_label = ?, mood_reason = ?
                    WHERE product_id = ?
                ");
                $stmt->execute([
                    $moodKey,
                    $moodData['mood_name'],
                    $moodData['mood_label'],
                    $moodData['mood_reason'],
                    $productId,
                ]);
                set_flash('Mood produk berhasil diperbarui.');
                redirect('index.php?page=mood');
            } catch (Throwable $error) {
                $pageMessage = 'Mood produk belum berhasil diperbarui.';
                $pageMessageType = 'danger';
            }
        } else {
            $pageMessage = 'Mood produk tidak valid.';
            $pageMessageType = 'danger';
        }
    }
}

$flash = get_flash();
if ($flash['message'] !== '') {
    if ($page === 'home') {
        $orderMessage = $flash['message'];
        $orderMessageType = $flash['type'];
    } else {
        $pageMessage = $flash['message'];
        $pageMessageType = $flash['type'];
    }
}

/* ===== USER HOME PAGE ===== */
if ($page === 'home') {
    $products = [];
    $moodGroups = [];

    if ($pdo) {
        try {
            [$products, $moodGroups] = load_home_products($pdo, $moodColors);
        } catch (Throwable $error) {
            $dbError = 'Data produk belum bisa dibaca. Pastikan database sudah di-import.';
        }
    }

    $moodJson = json_encode(
        $moodGroups,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    render_header('RA-ICE | Es Lilin Segar untuk Setiap Mood', 'RA-ICE adalah UMKM es lilin homemade dengan katalog produk dan mood recommendation.');
    render_user_navbar();
    ?>

<main>
    <section class="hero-section section-pad position-relative overflow-hidden" id="home">
        <div class="container position-relative">
            <div class="row align-items-center raice-row">
                <div class="col-md-6">
                    <div class="sticker-label sticker-label-hot mb-4">Fresh Drop</div>
                    <h1 class="hero-title mb-4">
                        RA-ICE
                        <span>Rasa Ice</span>
                    </h1>
                    <p class="hero-tagline mb-4">Es Lilin Segar untuk Setiap Mood</p>
                    <p class="hero-copy mb-5">RA-ICE hadir dengan rasa es lilin yang playful, creamy, dan cocok dipilih sesuai mood kamu hari ini.</p>
                    <div class="hero-buttons">
                        <a class="btn raice-btn raice-btn-primary" href="index.php?page=home#menu">Lihat Menu</a>
                        <a class="btn raice-btn raice-btn-lilac" href="index.php?page=home#mood">Coba Mood Recommendation</a>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="hero-art mx-auto">
                        <img src="images/hero.png" class="img-fluid hero-image" alt="Aneka es lilin RA-ICE">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <div class="section-divider" aria-hidden="true">
        <span class="divider-dot divider-dot-one"></span>
        <span class="divider-dot divider-dot-two"></span>
    </div>

    <section class="section-pad menu-section" id="menu">
        <div class="container">
            <div class="section-heading text-center mx-auto mb-5">
                <span class="sticker-label sticker-label-blue">Menu Pop</span>
                <h2 class="section-title mt-3 mb-3">Pilih Rasa Favoritmu</h2>
                <p class="section-subtitle">Pilihan rasa RA-ICE tersedia untuk menemani momen segar kamu setiap hari.</p>
            </div>

            <?php if (!$pdo): ?>
                <div class="admin-empty-state"><?= h($dbError) ?></div>
            <?php elseif (!$products): ?>
                <div class="admin-empty-state">Belum ada produk aktif.</div>
            <?php else: ?>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 menu-row g-4">
                    <?php foreach ($products as $product): ?>
                        <?php
                        $styleKey = safe_filename_base($product['flavor'] ?: $product['name']);
                        $cardClass = $cardClasses[$styleKey] ?? '';
                        $buttonClass = $buttonClasses[$styleKey] ?? 'raice-btn-primary';
                        ?>
                        <div class="col">
                            <article class="card menu-card <?= h($cardClass) ?> h-100">
                                <div class="menu-card-media">
                                    <img src="<?= h($product['image']) ?>" class="card-img-top" alt="<?= h($product['name']) ?> RA-ICE">
                                    <span class="menu-sticker flavor-sticker"><?= h($product['mood_label']) ?></span>
                                </div>
                                <div class="card-body">
                                    <div class="menu-card-top mb-3">
                                        <div>
                                            <h3 class="card-title"><?= h($product['name']) ?></h3>
                                        </div>
                                        <span class="price-pill flavor-price"><?= rupiah($product['price']) ?></span>
                                    </div>
                                    <p class="card-text"><?= h($product['description']) ?></p>
                                    <a class="btn raice-btn <?= h($buttonClass) ?> w-100" href="index.php?page=home#order" data-order-product="<?= (int) $product['product_id'] ?>">Pesan Rasa Ini</a>
                                </div>
                            </article>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-pad mood-section position-relative overflow-hidden" id="mood">
        <div class="container position-relative">
            <div class="section-heading text-center mx-auto mb-5">
                <span class="sticker-label sticker-label-yellow">Mood Radar</span>
                <h2 class="section-title mt-3 mb-3">Pick Your Mood</h2>
                <p class="section-subtitle">Pilih mood kamu hari ini, nanti RA-ICE bantu rekomendasiin rasa yang paling cocok.</p>
            </div>

            <?php if (!$moodGroups): ?>
                <div class="admin-empty-state">Mood recommendation belum tersedia.</div>
            <?php else: ?>
                <div class="row mood-button-row mb-5" role="group" aria-label="Pilih mood untuk rekomendasi rasa">
                    <?php foreach ($moodGroups as $key => $group): ?>
                        <div class="col-6 col-md-3">
                            <button class="mood-button <?= h($moodClasses[$key] ?? '') ?> w-100" type="button" data-mood="<?= h($key) ?>">
                                <span class="mood-icon" aria-hidden="true"><?= h($key === 'happy' ? ':)' : ($key === 'tired' ? 'zz' : ($key === 'stressed' ? '!!' : '++'))) ?></span>
                                <span><?= h($group['mood_name']) ?></span>
                            </button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mood-search-row text-center mb-4">
                    <button class="btn raice-btn raice-btn-lilac" type="button" id="moodSearchBtn" disabled>Cari Rasa yang Cocok</button>
                    <p class="mood-loading-text d-none mt-4 mb-0" id="moodLoading">Mood radar sedang mencari rasa yang cocok dengan mood kamu...</p>
                </div>

                <div id="recommendation-result" class="mood-card mx-auto d-none" aria-live="polite"></div>
                <script type="application/json" id="raiceMoodData"><?= $moodJson ?></script>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-pad order-section" id="order">
        <div class="container">
            <div class="row align-items-center raice-row">
                <div class="col-md-5">
                    <span class="sticker-label sticker-label-hot">Order Time</span>
                    <h2 class="section-title section-title-left mt-3 mb-3">Pesan Kebahagiaan Dinginmu</h2>
                    <p class="section-subtitle section-title-left">Lengkapi data pesanan kamu, lalu tim RA-ICE akan memproses pesanan dengan segera.</p>
                    <div class="order-burst mt-4">
                        <span>Harga</span>
                        <strong>Rp1.000</strong>
                        <small>per pcs</small>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="raice-card order-card">
                        <?php if ($orderMessage): ?>
                            <div class="alert alert-<?= h($orderMessageType) ?> mb-4"><?= h($orderMessage) ?></div>
                        <?php endif; ?>
                        <form id="orderForm" class="row order-form-row" method="post" action="index.php?page=home#order">
                            <input type="hidden" name="form_action" value="create_order">
                            <div class="col-md-6">
                                <label for="nama" class="form-label">Nama</label>
                                <input type="text" class="form-control" id="nama" name="name" placeholder="Nama kamu" required>
                            </div>
                            <div class="col-md-6">
                                <label for="whatsapp" class="form-label">Nomor WhatsApp</label>
                                <input type="tel" class="form-control" id="whatsapp" name="phone" placeholder="0812xxxx" required>
                            </div>
                            <div class="col-12">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email" placeholder="Opsional">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Pilih Rasa</label>
                                <div class="product-choice-grid">
                                    <?php foreach ($products as $product): ?>
                                        <?php
                                        $orderInputId = 'orderItem' . (int) $product['product_id'];
                                        $styleKey = safe_filename_base($product['flavor'] ?: $product['name']);
                                        $cardClass = $cardClasses[$styleKey] ?? '';
                                        $isOutOfStock = (int) $product['stock'] <= 0;
                                        ?>
                                        <div class="product-choice-card <?= h($cardClass) ?><?= $isOutOfStock ? ' product-choice-empty' : '' ?>">
                                            <div class="product-choice-head">
                                                <div class="product-choice-media">
                                                    <img src="<?= h($product['image']) ?>" alt="<?= h($product['name']) ?> RA-ICE">
                                                </div>
                                                <div class="product-choice-copy">
                                                    <h4 class="product-choice-name"><?= h($product['name']) ?></h4>
                                                    <p class="product-choice-meta"><?= rupiah($product['price']) ?> &middot; <?= $isOutOfStock ? 'Stok habis' : 'Stok ' . h($product['stock']) ?></p>
                                                </div>
                                            </div>
                                            <?php if ($isOutOfStock): ?>
                                                <p class="product-choice-stock-message mb-0">Maaf, saat ini stok belum tersedia.</p>
                                            <?php endif; ?>

                                            <div class="quantity-box">
                                                <button type="button" class="quantity-btn minus-btn" aria-label="Kurangi jumlah">-</button>
                                                <input
                                                    type="text"
                                                    class="quantity-input"
                                                    id="<?= h($orderInputId) ?>"
                                                    name="items[<?= (int) $product['product_id'] ?>]"
                                                    value="0"
                                                    data-min="0"
                                                    data-max="<?= (int) $product['stock'] ?>"
                                                    data-order-quantity
                                                    data-product-id="<?= (int) $product['product_id'] ?>"
                                                    data-product-name="<?= h($product['name']) ?>"
                                                    data-price="<?= (int) $product['price'] ?>"
                                                    inputmode="numeric"
                                                >
                                                <button type="button" class="quantity-btn plus-btn" aria-label="Tambah jumlah">+</button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <label for="catatan" class="form-label">Catatan</label>
                                <textarea class="form-control" id="catatan" name="notes" rows="3" placeholder="Contoh: ambil sore, tanpa campur, atau catatan lainnya"></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn raice-btn raice-btn-primary w-100" <?= !$products ? 'disabled' : '' ?>>Kirim Pesanan</button>
                            </div>
                        </form>
                        <div class="order-summary mt-4 d-none" id="orderSummary" aria-live="polite"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="section-pad about-section" id="about">
        <div class="container">
            <div class="about-shell mx-auto">
                <div class="about-collage">
                    <img src="images/About1.png" class="about-img about-img-main" alt="Produk RA-ICE">
                    <img src="images/about2.png" class="about-img about-img-small" alt="Kemasan produk RA-ICE">
                </div>
                <div class="about-story">
                    <div class="about-story-head">
                        <div class="about-sticker">Homemade<br>UMKM</div>
                    </div>
                    <span class="sticker-label sticker-label-yellow about-title-sticker">About RA-ICE</span>
                    <h2 class="section-title section-title-left about-story-title mt-3 mb-3">Segarnya Bikin Nostalgia</h2>
                    <p class="about-copy">Es lilin bukan sekadar jajanan biasa, tetapi bagian dari kenangan manis masa kecil yang tetap bertahan hingga sekarang. Dengan tekstur lembut dan rasa yang menyegarkan, RA-ICE menjadi camilan favorit yang cocok dinikmati kapan saja. Tetap sederhana, tetap lezat, dan selalu membawa sensasi segar di setiap gigitan.</p>
                    <div class="row about-list-row mt-4">
                        <div class="col-md-6">
                            <div class="about-mini-card">Homemade Batch</div>
                        </div>
                        <div class="col-md-6">
                            <div class="about-mini-card">Cute Dessert Vibes</div>
                        </div>
                        <div class="col-md-6">
                            <div class="about-mini-card">Mood Based Pick</div>
                        </div>
                        <div class="col-md-6">
                            <div class="about-mini-card">Harga Ramah</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

    <?php
    render_user_footer();
    render_footer();
    exit;
}

/* ===== ADMIN LOGIN ===== */
if ($page === 'login') {
    render_header('Admin Login | RA-ICE', 'Login admin RA-ICE.', 'admin-login-page');
    ?>

<main class="admin-login-shell">
    <section class="container position-relative">
        <div class="row min-vh-100 align-items-center justify-content-center py-5">
            <div class="col-md-8 admin-login-col">
                <div class="card admin-login-card">
                    <a class="admin-login-brand" href="index.php?page=home"><span>RA</span>-ICE</a>
                    <div class="sticker-label sticker-label-yellow mt-3 mb-4">Admin Street Desk</div>
                    <h1 class="admin-page-title text-center mb-2">Login Admin</h1>
                    <p class="text-center admin-muted mb-4">Kelola produk, order, dan mood recommendation RA-ICE.</p>

                    <?php if (!$pdo): ?>
                        <div class="admin-inline-message mb-4"><?= h($dbError) ?></div>
                    <?php endif; ?>

                    <?php if ($loginError): ?>
                        <div class="admin-inline-message mb-4"><?= h($loginError) ?></div>
                    <?php endif; ?>

                    <form method="post" action="index.php?page=login" autocomplete="off">
                        <div class="mb-3">
                            <label for="adminEmail" class="form-label">Username atau Email</label>
                            <input type="text" class="form-control" id="adminEmail" name="identity" placeholder="Username atau email" autocomplete="off" required>
                        </div>
                        <div class="mb-4">
                            <label for="adminPassword" class="form-label">Password</label>
                            <input type="password" class="form-control" id="adminPassword" name="password" placeholder="Masukkan password" autocomplete="new-password" required>
                        </div>
                        <button type="submit" class="btn admin-btn admin-btn-primary w-100" <?= !$pdo ? 'disabled' : '' ?>>Login</button>
                    </form>

                    <div class="text-center mt-4">
                        <a class="admin-back-link" href="index.php?page=home">Back to Website</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

    <?php
    render_footer();
    exit;
}

/* ===== ADMIN DASHBOARD ===== */
if ($page === 'dashboard') {
    $stats = [
        'active_products' => 0,
        'total_stock' => 0,
        'total_orders' => 0,
        'total_revenue' => 0,
    ];
    $recentOrders = [];
    $moodProducts = [];

    if ($pdo) {
        try {
            $stats['active_products'] = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
            $stats['total_stock'] = (int) $pdo->query('SELECT COALESCE(SUM(stock), 0) FROM products WHERE is_active = 1')->fetchColumn();
            $stats['total_orders'] = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
            $stats['total_revenue'] = (int) $pdo->query('SELECT COALESCE(SUM(total_amount), 0) FROM orders')->fetchColumn();

            $stmt = $pdo->query("
                SELECT
                    o.order_id,
                    o.status,
                    c.name AS customer_name,
                    GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS products
                FROM orders o
                JOIN customers c ON o.customer_id = c.customer_id
                LEFT JOIN order_items oi ON o.order_id = oi.order_id
                LEFT JOIN products p ON oi.product_id = p.product_id
                GROUP BY o.order_id, o.status, c.name
                ORDER BY o.order_date DESC
                LIMIT 5
            ");
            $recentOrders = $stmt->fetchAll();

            $stmt = $pdo->query('SELECT name, mood_name, mood_key FROM products WHERE is_active = 1 ORDER BY product_id ASC LIMIT 6');
            $moodProducts = $stmt->fetchAll();
        } catch (Throwable $error) {
            $dbError = 'Data dashboard belum bisa dibaca. Pastikan database sudah di-import.';
        }
    }

    render_header('Admin Dashboard | RA-ICE', 'Dashboard admin RA-ICE.', 'admin-page');
    ?>

<div class="admin-layout">
    <?php render_admin_sidebar('dashboard'); ?>

    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <span class="sticker-label sticker-label-blue">Live Desk</span>
                <h1 class="admin-page-title mt-3 mb-1">Welcome, <?= h($_SESSION['admin_name'] ?? 'RA-ICE Admin') ?></h1>
                <p class="admin-muted mb-0">Ringkasan aktivitas RA-ICE untuk memantau produk, stok, pesanan, dan rekomendasi mood.</p>
            </div>
        </header>

        <?php if (!$pdo): ?>
            <div class="admin-inline-message mb-4"><?= h($dbError) ?></div>
        <?php endif; ?>

        <?php if ($pageMessage): ?>
            <div class="alert alert-<?= h($pageMessageType) ?> mb-4"><?= h($pageMessage) ?></div>
        <?php endif; ?>

        <section class="row admin-summary-row mb-5" aria-label="Dashboard summary">
            <div class="col-md-6 admin-summary-col">
                <article class="card admin-card admin-summary-card admin-card-strawberry">
                    <div class="admin-card-icon">PRD</div>
                    <p class="admin-card-label mb-1">Active Products</p>
                    <h2 class="admin-card-number mb-0"><?= h($stats['active_products']) ?></h2>
                </article>
            </div>
            <div class="col-md-6 admin-summary-col">
                <article class="card admin-card admin-summary-card admin-card-blue">
                    <div class="admin-card-icon">STK</div>
                    <p class="admin-card-label mb-1">Total Stock</p>
                    <h2 class="admin-card-number mb-0"><?= h($stats['total_stock']) ?></h2>
                </article>
            </div>
            <div class="col-md-6 admin-summary-col">
                <article class="card admin-card admin-summary-card admin-card-yellow">
                    <div class="admin-card-icon">ORD</div>
                    <p class="admin-card-label mb-1">Total Orders</p>
                    <h2 class="admin-card-number mb-0"><?= h($stats['total_orders']) ?></h2>
                </article>
            </div>
            <div class="col-md-6 admin-summary-col">
                <article class="card admin-card admin-summary-card admin-card-lilac">
                    <div class="admin-card-icon">REV</div>
                    <p class="admin-card-label mb-1">Total Revenue</p>
                    <h2 class="admin-card-number mb-0"><?= rupiah($stats['total_revenue']) ?></h2>
                </article>
            </div>
        </section>

        <section class="row admin-content-row">
            <div class="col-md-7">
                <div class="card admin-card h-100">
                    <div class="admin-card-head mb-3">
                        <div>
                            <span class="sticker-label sticker-label-yellow">Order Flow</span>
                            <h2 class="admin-section-title mt-3 mb-0">Recent Orders</h2>
                        </div>
                        <a class="btn admin-btn admin-btn-small admin-btn-blue" href="index.php?page=orders">Open Orders</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table admin-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Product</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$recentOrders): ?>
                                    <tr>
                                        <td colspan="4"><div class="admin-empty-state">Belum ada pesanan.</div></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td><?= h($order['order_id']) ?></td>
                                            <td><?= h($order['customer_name']) ?></td>
                                            <td><?= h($order['products'] ?? '-') ?></td>
                                            <td><span class="admin-badge badge-<?= h($order['status']) ?>"><?= h(ucfirst($order['status'])) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-5">
                <div class="card admin-card admin-mood-panel h-100">
                    <span class="sticker-label sticker-label-hot">Mood Data</span>
                    <h2 class="admin-section-title mt-3">Product Mood Map</h2>
                    <?php if (!$moodProducts): ?>
                        <div class="admin-empty-state">Belum ada produk aktif.</div>
                    <?php else: ?>
                        <?php foreach ($moodProducts as $product): ?>
                            <div class="admin-flavor-pulse">
                                <div>
                                    <span style="--pulse-color: <?= h($moodColors[$product['mood_key']] ?? '#F2619C') ?>;"></span>
                                    <?= h($product['name']) ?>
                                </div>
                                <strong><?= h($product['mood_name']) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <p class="admin-muted mb-0">Data mood diambil dari tabel products.</p>
                </div>
            </div>
        </section>
    </main>
</div>

    <?php
    render_footer();
    exit;
}

/* ===== MANAGE PRODUCTS ===== */
if ($page === 'products') {
    $products = [];
    $formProduct = [
        'product_id' => '',
        'name' => '',
        'description' => '',
        'price' => 1000,
        'stock' => '',
        'flavor' => '',
        'image' => '',
        'is_active' => 1,
        'mood_key' => '',
    ];

    if ($pdo) {
        try {
            if ($action === 'edit') {
                $productId = (int) ($_GET['id'] ?? 0);
                $stmt = $pdo->prepare('SELECT * FROM products WHERE product_id = ? LIMIT 1');
                $stmt->execute([$productId]);
                $selectedProduct = $stmt->fetch();
                if ($selectedProduct) {
                    $formProduct = $selectedProduct;
                } else {
                    $pageMessage = 'Produk tidak ditemukan.';
                    $pageMessageType = 'danger';
                    $action = '';
                }
            }

            $stmt = $pdo->query('SELECT * FROM products ORDER BY product_id ASC');
            $products = $stmt->fetchAll();
        } catch (Throwable $error) {
            $pageMessage = 'Data produk belum bisa dibaca. Pastikan database sudah di-import.';
            $pageMessageType = 'danger';
        }
    }

    $showProductForm = $action === 'add' || $action === 'edit';
    $productFormTitle = $action === 'edit' ? 'Edit Product' : 'Add Product';
    $productFormAction = $action === 'edit'
        ? 'index.php?page=products&action=edit&id=' . (int) $formProduct['product_id']
        : 'index.php?page=products&action=add';

    render_header('Products | RA-ICE Admin', 'Manajemen produk RA-ICE.', 'admin-page');
    ?>

<div class="admin-layout">
    <?php render_admin_sidebar('products'); ?>

    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <span class="sticker-label sticker-label-blue">Product Shelf</span>
                <h1 class="admin-page-title mt-3 mb-1">Product Management</h1>
                <p class="admin-muted mb-0">Kelola produk, stok, gambar, dan mood recommendation dari database.</p>
            </div>
            <a class="btn admin-btn admin-btn-primary" href="index.php?page=products&action=add">Add Product</a>
        </header>

        <?php if (!$pdo): ?>
            <div class="admin-inline-message mb-4"><?= h($dbError) ?></div>
        <?php endif; ?>

        <?php if ($pageMessage): ?>
            <div class="alert alert-<?= h($pageMessageType) ?> mb-4"><?= h($pageMessage) ?></div>
        <?php endif; ?>

        <?php if ($showProductForm): ?>
            <section class="card admin-card mb-4">
                <div class="admin-card-head mb-3">
                    <div>
                        <span class="sticker-label sticker-label-yellow">Product Form</span>
                        <h2 class="admin-section-title mt-3 mb-0"><?= h($productFormTitle) ?></h2>
                    </div>
                    <a class="btn admin-btn admin-btn-small admin-btn-ghost" href="index.php?page=products">Cancel</a>
                </div>

                <form method="post" enctype="multipart/form-data" action="<?= h($productFormAction) ?>">
                    <input type="hidden" name="product_id" value="<?= (int) ($formProduct['product_id'] ?? 0) ?>">
                    <div class="row modal-form-row">
                        <div class="col-md-6">
                            <label for="productName" class="form-label">Product Name</label>
                            <input type="text" class="form-control" id="productName" name="name" value="<?= h($formProduct['name']) ?>" placeholder="Strawberry" required>
                        </div>
                        <div class="col-md-6">
                            <label for="productPrice" class="form-label">Price</label>
                            <input type="number" class="form-control" id="productPrice" name="price" value="1000" readonly>
                        </div>
                        <div class="col-md-6">
                            <label for="productStock" class="form-label">Stock</label>
                            <input type="number" class="form-control" id="productStock" name="stock" min="0" value="<?= h($formProduct['stock']) ?>" placeholder="50" required>
                        </div>
                        <div class="col-12">
                            <label for="productDescription" class="form-label">Description</label>
                            <textarea class="form-control" id="productDescription" name="description" rows="3" placeholder="Deskripsi produk" required><?= h($formProduct['description']) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="productFlavor" class="form-label">Flavor</label>
                            <input type="text" class="form-control" id="productFlavor" name="flavor" value="<?= h($formProduct['flavor']) ?>" placeholder="Strawberry" required>
                        </div>
                        <div class="col-md-6">
                            <label for="productImage" class="form-label">Product Image</label>
                            <input type="file" class="form-control" id="productImage" name="image" accept=".jpg,.jpeg,.png,.webp" <?= $action === 'add' ? 'required' : '' ?>>
                            <div class="form-text">JPG, JPEG, PNG, WEBP. Maks 2MB.</div>
                            <?php if ($action === 'edit' && !empty($formProduct['image'])): ?>
                                <div class="admin-product-preview mt-3">
                                    <span>Current image</span>
                                    <img src="<?= h(product_image_src($formProduct['image'])) ?>" alt="Current product image">
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label for="productActive" class="form-label">Status</label>
                            <select class="form-select" id="productActive" name="is_active">
                                <option value="1" <?= (int) $formProduct['is_active'] === 1 ? 'selected' : '' ?>>Active</option>
                                <option value="0" <?= (int) $formProduct['is_active'] === 0 ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="productMoodKey" class="form-label">Mood</label>
                            <select class="form-select" id="productMoodKey" name="mood_key" required>
                                <option value="">Pilih Mood</option>
                                <?php foreach ($moodColors as $moodKey => $color): ?>
                                    <?php $moodData = getMoodData($moodKey); ?>
                                    <option value="<?= h($moodKey) ?>" <?= $formProduct['mood_key'] === $moodKey ? 'selected' : '' ?>>
                                        <?= h($moodData['mood_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn admin-btn admin-btn-primary">Save Product</button>
                        </div>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="card admin-card">
            <div class="table-responsive">
                <table class="table admin-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product Name</th>
                            <th>Description</th>
                            <th>Price</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Mood Key</th>
                            <th>Mood Label</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$products): ?>
                            <tr>
                                <td colspan="9"><div class="admin-empty-state">Belum ada produk.</div></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td><?= h($product['product_id']) ?></td>
                                    <td><?= h($product['name']) ?></td>
                                    <td><?= h($product['description']) ?></td>
                                    <td><?= rupiah($product['price']) ?></td>
                                    <td><?= h($product['stock']) ?></td>
                                    <td>
                                        <span class="admin-badge <?= (int) $product['is_active'] === 1 ? 'badge-completed' : 'badge-cancelled' ?>">
                                            <?= (int) $product['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= h($product['mood_key']) ?></td>
                                    <td><?= h($product['mood_label']) ?></td>
                                    <td>
                                        <div class="admin-action-row">
                                            <a class="btn admin-btn admin-btn-small admin-btn-blue" href="index.php?page=products&action=edit&id=<?= (int) $product['product_id'] ?>">Edit</a>
                                            <form method="post" action="index.php?page=products&action=delete&id=<?= (int) $product['product_id'] ?>" class="d-inline" data-confirm-message="Hapus produk ini dari katalog?">
                                                <button class="btn admin-btn admin-btn-small admin-btn-danger" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

    <?php
    render_footer();
    exit;
}

/* ===== MANAGE ORDERS ===== */
if ($page === 'orders') {
    $orders = [];
    $selectedOrder = null;
    $selectedItems = [];

    if ($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT
                    o.order_id,
                    o.order_date,
                    o.total_amount,
                    o.status,
                    o.notes,
                    c.name AS customer_name,
                    c.phone,
                    item_summary.products,
                    item_summary.total_quantity
                FROM orders o
                JOIN customers c ON o.customer_id = c.customer_id
                LEFT JOIN (
                    SELECT
                        oi.order_id,
                        SUM(oi.quantity) AS total_quantity,
                        GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) ORDER BY oi.order_item_id SEPARATOR ', ') AS products
                    FROM order_items oi
                    JOIN products p ON oi.product_id = p.product_id
                    GROUP BY oi.order_id
                ) item_summary ON item_summary.order_id = o.order_id
                ORDER BY o.order_date DESC
            ");
            $orders = $stmt->fetchAll();

            $viewId = (int) ($_GET['view'] ?? 0);
            if ($viewId > 0) {
                $stmt = $pdo->prepare("
                    SELECT
                        o.*,
                        c.customer_id,
                        c.name AS customer_name,
                        c.phone,
                        c.email
                    FROM orders o
                    JOIN customers c ON o.customer_id = c.customer_id
                    WHERE o.order_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$viewId]);
                $selectedOrder = $stmt->fetch();

                if ($selectedOrder) {
                    $stmt = $pdo->prepare("
                        SELECT
                            oi.quantity,
                            oi.unit_price,
                            oi.subtotal,
                            p.product_id,
                            p.name AS product_name
                        FROM order_items oi
                        JOIN products p ON oi.product_id = p.product_id
                        WHERE oi.order_id = ?
                        ORDER BY oi.order_item_id ASC
                    ");
                    $stmt->execute([$viewId]);
                    $selectedItems = $stmt->fetchAll();
                }
            }
        } catch (Throwable $error) {
            $pageMessage = 'Data pesanan belum bisa dibaca. Pastikan database sudah di-import.';
            $pageMessageType = 'danger';
        }
    }

    render_header('Orders | RA-ICE Admin', 'Manajemen pesanan RA-ICE.', 'admin-page');
    ?>

<div class="admin-layout">
    <?php render_admin_sidebar('orders'); ?>

    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <span class="sticker-label sticker-label-yellow">Order Counter</span>
                <h1 class="admin-page-title mt-3 mb-1">Order Management</h1>
                <p class="admin-muted mb-0">Lihat pesanan pelanggan dan ubah status pemrosesan dari database.</p>
            </div>
        </header>

        <?php if (!$pdo): ?>
            <div class="admin-inline-message mb-4"><?= h($dbError) ?></div>
        <?php endif; ?>

        <?php if ($pageMessage): ?>
            <div class="alert alert-<?= h($pageMessageType) ?> mb-4"><?= h($pageMessage) ?></div>
        <?php endif; ?>

        <?php if ($selectedOrder): ?>
            <section class="card admin-card mb-4">
                <div class="admin-card-head mb-3">
                    <div>
                        <span class="sticker-label sticker-label-blue">Order Detail</span>
                        <h2 class="admin-section-title mt-3 mb-1">Order ID #<?= h($selectedOrder['order_id']) ?></h2>
                        <p class="admin-muted mb-0"><?= h($selectedOrder['customer_name']) ?> - <?= h($selectedOrder['phone']) ?></p>
                    </div>
                    <a class="btn admin-btn admin-btn-small admin-btn-ghost" href="index.php?page=orders">Close Detail</a>
                </div>

                <div class="admin-inline-message mb-4">
                    <strong>Catatan:</strong><br>
                    <?= h($selectedOrder['notes'] ?: '-') ?>
                </div>

                <div class="table-responsive">
                    <table class="table admin-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Product ID</th>
                                <th>Product</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($selectedItems as $item): ?>
                                <tr>
                                    <td><?= h($item['product_id']) ?></td>
                                    <td><?= h($item['product_name']) ?></td>
                                    <td><?= h($item['quantity']) ?></td>
                                    <td><?= rupiah($item['unit_price']) ?></td>
                                    <td><?= rupiah($item['subtotal']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="4">Total</td>
                                <td><?= rupiah($selectedOrder['total_amount']) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>

        <section class="card admin-card">
            <div class="table-responsive">
                <table class="table admin-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Order ID</th>
                            <th>Customer</th>
                            <th>WhatsApp</th>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Total</th>
                            <th>Order Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$orders): ?>
                            <tr>
                                <td colspan="9"><div class="admin-empty-state">Belum ada pesanan masuk.</div></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><?= h($order['order_id']) ?></td>
                                    <td><?= h($order['customer_name']) ?></td>
                                    <td><?= h($order['phone']) ?></td>
                                    <td><?= h($order['products'] ?: '-') ?></td>
                                    <td><?= h($order['total_quantity'] ?: 0) ?></td>
                                    <td><?= rupiah($order['total_amount']) ?></td>
                                    <td><?= h(date('d M Y H:i', strtotime($order['order_date']))) ?></td>
                                    <td>
                                        <span class="admin-badge badge-<?= h($order['status']) ?>">
                                            <?= h(ucfirst($order['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="post" action="index.php?page=orders&action=update_status" class="admin-action-row">
                                            <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                            <select class="form-select form-select-sm" name="status" aria-label="Update status Order ID <?= h($order['order_id']) ?>">
                                                <?php foreach ($statusOptions as $status): ?>
                                                    <option value="<?= h($status) ?>" <?= $order['status'] === $status ? 'selected' : '' ?>>
                                                        <?= h(ucfirst($status)) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn admin-btn admin-btn-small admin-btn-blue" type="submit">Save</button>
                                            <a class="btn admin-btn admin-btn-small admin-btn-ghost" href="index.php?page=orders&view=<?= (int) $order['order_id'] ?>">Detail</a>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

    <?php
    render_footer();
    exit;
}

/* ===== MOOD MANAGEMENT ===== */
if ($page === 'mood') {
    $products = [];
    $moodSummary = [];

    foreach ($moodColors as $moodKey => $color) {
        $moodData = getMoodData($moodKey);
        $moodSummary[$moodKey] = [
            'count' => 0,
            'color' => $color,
            'mood_name' => $moodData['mood_name'],
            'mood_label' => $moodData['mood_label'],
        ];
    }

    if ($pdo) {
        try {
            $stmt = $pdo->query('SELECT product_id, name, mood_key, mood_name, mood_label, is_active FROM products ORDER BY product_id ASC');
            $products = $stmt->fetchAll();

            foreach ($products as $product) {
                $key = $product['mood_key'];
                if (isset($moodSummary[$key])) {
                    $moodSummary[$key]['count']++;
                }
            }
        } catch (Throwable $error) {
            $pageMessage = 'Data mood belum bisa dibaca. Pastikan database sudah di-import.';
            $pageMessageType = 'danger';
        }
    }

    render_header('Mood Management | RA-ICE Admin', 'Manajemen mood recommendation RA-ICE.', 'admin-page');
    ?>

<div class="admin-layout">
    <?php render_admin_sidebar('mood'); ?>

    <main class="admin-main">
        <header class="admin-topbar">
            <div>
                <span class="sticker-label sticker-label-hot">Mood Radar</span>
                <h1 class="admin-page-title mt-3 mb-1">Mood Management</h1>
                <p class="admin-muted mb-0">Atur mood recommendation produk tanpa mengubah struktur database.</p>
            </div>
        </header>

        <?php if (!$pdo): ?>
            <div class="admin-inline-message mb-4"><?= h($dbError) ?></div>
        <?php endif; ?>

        <?php if ($pageMessage): ?>
            <div class="alert alert-<?= h($pageMessageType) ?> mb-4"><?= h($pageMessage) ?></div>
        <?php endif; ?>

        <section class="row admin-summary-row mb-5" aria-label="Mood summary">
            <?php foreach ($moodSummary as $moodKey => $summary): ?>
                <div class="col-md-6 admin-summary-col">
                    <article class="card admin-card admin-summary-card">
                        <div class="admin-card-icon" style="background-color: <?= h($summary['color']) ?>;"><?= h(strtoupper(substr($summary['mood_name'], 0, 3))) ?></div>
                        <p class="admin-card-label mb-1"><?= h($summary['mood_label']) ?></p>
                        <h2 class="admin-card-number mb-0"><?= h($summary['count']) ?></h2>
                    </article>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="card admin-card">
            <div class="admin-card-head mb-3">
                <div>
                    <span class="sticker-label sticker-label-yellow">Mood Product Map</span>
                    <h2 class="admin-section-title mt-3 mb-0">Product Mood</h2>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table admin-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Product ID</th>
                            <th>Product</th>
                            <th>Status</th>
                            <th>Current Mood</th>
                            <th>Mood Label</th>
                            <th>Update Mood</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$products): ?>
                            <tr>
                                <td colspan="6"><div class="admin-empty-state">Belum ada produk.</div></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td><?= h($product['product_id']) ?></td>
                                    <td><?= h($product['name']) ?></td>
                                    <td>
                                        <span class="admin-badge <?= (int) $product['is_active'] === 1 ? 'badge-completed' : 'badge-cancelled' ?>">
                                            <?= (int) $product['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td><?= h($product['mood_name']) ?></td>
                                    <td><?= h($product['mood_label']) ?></td>
                                    <td>
                                        <form method="post" action="index.php?page=mood&action=update&id=<?= (int) $product['product_id'] ?>" class="admin-action-row">
                                            <select class="form-select form-select-sm" name="mood_key" aria-label="Update mood <?= h($product['name']) ?>">
                                                <?php foreach ($moodColors as $moodKey => $color): ?>
                                                    <?php $moodData = getMoodData($moodKey); ?>
                                                    <option value="<?= h($moodKey) ?>" <?= $product['mood_key'] === $moodKey ? 'selected' : '' ?>>
                                                        <?= h($moodData['mood_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn admin-btn admin-btn-small admin-btn-blue" type="submit">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

    <?php
    render_footer();
    exit;
}

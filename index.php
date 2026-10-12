<?php

declare(strict_types=1);

/* KHMER SHOP PRO - Single-file PHP + MySQL backend
   XAMPP defaults: host=127.0.0.1, user=root, password empty.
   Change DB_PASS below if your MySQL password is different.
*/
const DB_HOST = '127.0.0.1';
const DB_NAME = 'khmer_shop';
const DB_USER = 'root';
const DB_PASS = '';

function shop_json_response(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function shop_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    // Connect without selecting a database first, so this one file can create it.
    $server = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $server = null;
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_number VARCHAR(80) NOT NULL,
        invoice_date VARCHAR(100) NULL,
        customer_name VARCHAR(150) NOT NULL,
        customer_phone VARCHAR(40) NOT NULL,
        customer_address VARCHAR(1000) NOT NULL,
        total_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_khr BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status ENUM('pending','confirmed','processing','shipped','completed','cancelled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY uq_orders_invoice_number (invoice_number),
        KEY idx_orders_created_at (created_at), KEY idx_orders_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT UNSIGNED NOT NULL,
        product_code VARCHAR(80) NOT NULL,
        product_name VARCHAR(250) NOT NULL,
        quantity INT UNSIGNED NOT NULL,
        unit_price DECIMAL(12,2) NOT NULL,
        line_total DECIMAL(12,2) NOT NULL,
        PRIMARY KEY (id), KEY idx_order_items_order_id (order_id),
        CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $pdo;
}

// Same PHP file handles checkout POST requests and normal page visits.
if (($_GET['action'] ?? '') === 'save_order') {
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        shop_json_response(405, ['success' => false, 'message' => 'Method not allowed']);
    }
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        shop_json_response(415, ['success' => false, 'message' => 'Expected JSON request']);
    }
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) shop_json_response(400, ['success' => false, 'message' => 'Invalid JSON']);

    $invoice = trim((string)($data['invoice'] ?? ''));
    $date = trim((string)($data['date'] ?? ''));
    $customer = $data['customer'] ?? null;
    $items = $data['items'] ?? null;
    $totalUSD = $data['totalUSD'] ?? null;
    $totalKHR = $data['totalKHR'] ?? null;
    if (
        $invoice === '' || strlen($invoice) > 80 || !is_array($customer) || !is_array($items) ||
        count($items) < 1 || count($items) > 100 || !is_numeric($totalUSD) || !is_numeric($totalKHR)
    ) {
        shop_json_response(422, ['success' => false, 'message' => 'Missing or invalid order fields']);
    }
    $name = trim((string)($customer['name'] ?? ''));
    $phone = trim((string)($customer['phone'] ?? ''));
    $address = trim((string)($customer['address'] ?? ''));
    if (
        $name === '' || mb_strlen($name) > 150 || $phone === '' || mb_strlen($phone) > 40 ||
        preg_match('/\d/', $phone) !== 1 || mb_strlen($address) < 3 || mb_strlen($address) > 1000
    ) {
        shop_json_response(422, ['success' => false, 'message' => 'Please provide valid customer details']);
    }
    $cleanItems = [];
    $calculatedTotal = 0.0;
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $id = trim((string)($item['id'] ?? ''));
        $itemName = trim((string)($item['name'] ?? ''));
        $qty = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
        $price = $item['price'] ?? null;
        if (
            $id === '' || $itemName === '' || mb_strlen($itemName) > 250 || $qty === false || $qty < 1 || $qty > 10000 ||
            !is_numeric($price) || (float)$price < 0 || (float)$price > 1000000
        ) {
            shop_json_response(422, ['success' => false, 'message' => 'Invalid item in order']);
        }
        $price = round((float)$price, 2);
        $lineTotal = round($price * $qty, 2);
        $calculatedTotal += $lineTotal;
        $cleanItems[] = ['id' => $id, 'name' => $itemName, 'qty' => $qty, 'price' => $price, 'total' => $lineTotal];
    }
    if (!$cleanItems) shop_json_response(422, ['success' => false, 'message' => 'Order has no valid items']);
    $calculatedTotal = round($calculatedTotal, 2);
    if (abs($calculatedTotal - (float)$totalUSD) > 0.03) {
        shop_json_response(422, ['success' => false, 'message' => 'Order total does not match item totals']);
    }
    try {
        $pdo = shop_db();
        $pdo->beginTransaction();
        $find = $pdo->prepare('SELECT id FROM orders WHERE invoice_number = ? LIMIT 1');
        $find->execute([$invoice]);
        $existing = $find->fetch();
        if ($existing) {
            $pdo->commit();
            shop_json_response(200, ['success' => true, 'orderId' => (int)$existing['id'], 'duplicate' => true]);
        }
        $stmt = $pdo->prepare('INSERT INTO orders (invoice_number, invoice_date, customer_name, customer_phone, customer_address, total_usd, total_khr, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $invoice,
            $date !== '' ? $date : date('Y-m-d H:i:s'),
            $name,
            $phone,
            $address,
            $calculatedTotal,
            (int)round($calculatedTotal * 4000),
            'pending'
        ]);
        $orderId = (int)$pdo->lastInsertId();
        $insertItem = $pdo->prepare('INSERT INTO order_items (order_id, product_code, product_name, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($cleanItems as $item) {
            $insertItem->execute([$orderId, $item['id'], $item['name'], $item['qty'], $item['price'], $item['total']]);
        }
        $pdo->commit();
        shop_json_response(201, ['success' => true, 'orderId' => $orderId, 'message' => 'Order saved']);
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        error_log('KHMER SHOP order save error: ' . $e->getMessage());
        shop_json_response(500, ['success' => false, 'message' => 'Could not save order. Check PHP/MySQL configuration.']);
    }
}
?>

<!DOCTYPE html>
<html lang="km">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#4f46e5">

    <title>KHMER SHOP PRO | Online Store</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        img,
        canvas,
        svg,
        video {
            max-width: 100%;
        }

        button,
        input,
        select,
        textarea {
            max-width: 100%;
        }

        button,
        a,
        input,
        select {
            -webkit-tap-highlight-color: transparent;
        }

        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #8b5cf6;

            --bg: #f5f7fb;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;

            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;

            --telegram: #229ed9;

            --shadow: 0 15px 40px rgba(15, 23, 42, .08);
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: "Kantumruy Pro", Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        body.no-scroll {
            overflow: hidden;
        }

        button,
        input,
        select,
        textarea {
            font-family: inherit;
        }

        button {
            cursor: pointer;
        }

        /* =========================================================
           HEADER
        ========================================================= */

        header {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, .94);
            backdrop-filter: blur(18px);
            border-bottom: 1px solid var(--border);
        }

        .navbar {
            max-width: 1400px;
            min-height: 72px;
            margin: auto;
            padding: 12px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
            font-weight: 800;
            white-space: nowrap;
        }

        .logo-icon {
            width: 43px;
            height: 43px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 13px;
            color: white;
            font-size: 22px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            box-shadow: 0 8px 20px rgba(99, 102, 241, .25);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            justify-content: flex-end;
        }

        .search-box {
            position: relative;
            width: min(420px, 100%);
        }

        .search-box input {
            width: 100%;
            height: 46px;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 0 48px 0 16px;
            outline: none;
            background: white;
            font-size: 14px;
            transition: .2s;
        }

        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, .1);
        }

        .search-icon {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }

        .desktop-cart-btn {
            position: relative;
            width: 46px;
            height: 46px;
            border: none;
            border-radius: 13px;
            background: #eef2ff;
            color: var(--primary);
            font-size: 21px;
            transition: .2s;
        }

        .desktop-cart-btn:hover {
            transform: translateY(-2px);
            background: #e0e7ff;
        }

        .cart-count {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 21px;
            height: 21px;
            padding: 0 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--danger);
            color: white;
            font-size: 11px;
            font-weight: 700;
            border: 2px solid white;
        }

        /* =========================================================
           HERO
        ========================================================= */

        .hero {
            max-width: 1400px;
            margin: 25px auto;
            padding: 48px;
            min-height: 310px;
            border-radius: 26px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
            overflow: hidden;
            position: relative;

            background:
                radial-gradient(circle at 90% 20%,
                    rgba(255, 255, 255, .2),
                    transparent 30%),
                radial-gradient(circle at 60% 100%,
                    rgba(255, 255, 255, .08),
                    transparent 35%),
                linear-gradient(135deg, #4f46e5, #7c3aed);

            box-shadow: 0 20px 50px rgba(79, 70, 229, .2);
        }

        .hero-text {
            max-width: 700px;
            position: relative;
            z-index: 2;
        }

        .hero h1 {
            font-size: clamp(32px, 5vw, 58px);
            line-height: 1.15;
            margin-bottom: 15px;
            font-weight: 800;
        }

        .hero p {
            color: rgba(255, 255, 255, .86);
            font-size: 16px;
            line-height: 1.8;
            margin-bottom: 25px;
        }

        .hero-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 22px;
            border-radius: 12px;
            background: white;
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            transition: .2s;
        }

        .hero-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, .15);
        }

        .hero-icon {
            font-size: 145px;
            opacity: .18;
            user-select: none;
        }

        /* =========================================================
           MAIN
        ========================================================= */

        .container {
            max-width: 1400px;
            margin: auto;
            padding: 0 25px 80px;
        }

        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin: 30px 0 20px;
        }

        .section-title h2 {
            font-size: 26px;
            font-weight: 800;
        }

        .section-title p {
            margin-top: 3px;
            color: var(--muted);
            font-size: 13px;
        }

        .sort-select {
            padding: 11px 14px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: white;
            outline: none;
            min-width: 190px;
            color: var(--text);
        }

        /* =========================================================
           CATEGORY
        ========================================================= */

        .categories {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 8px;
            scrollbar-width: none;
        }

        .categories::-webkit-scrollbar {
            display: none;
        }

        .category-btn {
            flex-shrink: 0;
            border: 1px solid var(--border);
            background: white;
            padding: 10px 18px;
            border-radius: 30px;
            font-size: 13px;
            transition: .2s;
            min-height: 42px;
        }

        .category-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .category-btn.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            box-shadow: 0 5px 15px rgba(99, 102, 241, .2);
        }

        /* =========================================================
           PRODUCTS
        ========================================================= */

        .products-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 18px;
        }

        .product-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            position: relative;
            transition: .25s;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .025);
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
        }

        /* REAL IMAGE */

        .product-image {
            height: 205px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;

            background:
                radial-gradient(circle at center,
                    #ffffff 0%,
                    #f8fafc 65%,
                    #eef2ff 100%);
        }

        .product-image img {
            width: 100%;
            height: 100%;
            padding: 18px;
            object-fit: contain;
            display: block;
            transition: transform .35s ease;
            filter: drop-shadow(0 10px 13px rgba(15, 23, 42, .10));
        }

        .product-card:hover .product-image img {
            transform: scale(1.08);
        }

        .product-category {
            position: absolute;
            top: 11px;
            left: 11px;
            z-index: 2;
            padding: 5px 8px;
            border-radius: 7px;
            background: rgba(255, 255, 255, .94);
            color: var(--primary);
            font-size: 9px;
            font-weight: 700;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .05);
        }

        .stock {
            position: absolute;
            bottom: 10px;
            right: 10px;
            z-index: 2;
            padding: 5px 8px;
            border-radius: 7px;
            background: rgba(255, 255, 255, .94);
            color: var(--success);
            font-size: 9px;
            font-weight: 600;
            box-shadow: 0 4px 10px rgba(0, 0, 0, .05);
        }

        .stock.low {
            color: var(--warning);
        }

        .stock.out {
            color: var(--danger);
        }

        .product-content {
            padding: 15px;
        }

        .product-id {
            color: #9ca3af;
            font-size: 10px;
        }

        .product-name {
            font-size: 14px;
            font-weight: 600;
            line-height: 1.55;
            min-height: 43px;
            margin: 4px 0 10px;
        }

        .product-bottom {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .price {
            font-size: 18px;
            font-weight: 800;
            color: var(--primary);
            white-space: nowrap;
        }

        .add-btn {
            min-width: 48px;
            height: 44px;
            padding: 0 13px;
            border: none;
            border-radius: 11px;
            background: var(--primary);
            color: white;
            font-size: 14px;
            font-weight: 700;
            transition: .2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            touch-action: manipulation;
        }

        .add-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }

        .add-btn:active {
            transform: scale(.96);
        }

        .add-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
            transform: none;
        }

        /* =========================================================
           EMPTY
        ========================================================= */

        .empty {
            display: none;
            text-align: center;
            padding: 80px 20px;
            color: var(--muted);
        }

        .empty-icon {
            font-size: 55px;
            margin-bottom: 10px;
        }

        /* =========================================================
           OVERLAY
        ========================================================= */

        .overlay {
            position: fixed;
            inset: 0;
            z-index: 1500;
            background: rgba(0, 0, 0, .48);
            opacity: 0;
            visibility: hidden;
            transition: .3s;
        }

        .overlay.show {
            opacity: 1;
            visibility: visible;
        }

        /* =========================================================
           CART
        ========================================================= */

        .cart {
            position: fixed;
            top: 0;
            right: -460px;
            width: min(440px, 100%);
            height: 100vh;
            background: white;
            z-index: 1600;
            display: flex;
            flex-direction: column;
            transition: .35s;
            box-shadow: -15px 0 50px rgba(0, 0, 0, .15);
        }

        .cart.show {
            right: 0;
        }

        .cart-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .cart-header h3 {
            font-size: 20px;
        }

        .close-cart {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 10px;
            background: #f3f4f6;
            font-size: 18px;
            touch-action: manipulation;
        }

        .cart-items {
            flex: 1;
            overflow-y: auto;
            padding: 15px 18px;
        }

        .cart-item {
            display: flex;
            gap: 12px;
            padding: 13px 0;
            border-bottom: 1px solid var(--border);
        }

        .cart-item-image {
            width: 72px;
            height: 72px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            overflow: hidden;
            background: #f8fafc;
            border: 1px solid #eef2f7;
        }

        .cart-item-image img {
            width: 100%;
            height: 100%;
            padding: 5px;
            object-fit: contain;
        }

        .cart-info {
            flex: 1;
            min-width: 0;
        }

        .cart-name {
            font-size: 13px;
            font-weight: 700;
            line-height: 1.5;
        }

        .cart-price {
            margin-top: 3px;
            color: var(--primary);
            font-size: 14px;
            font-weight: 700;
        }

        .quantity {
            display: flex;
            align-items: center;
            gap: 7px;
            margin-top: 7px;
        }

        .quantity button {
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 8px;
            background: #f3f4f6;
            font-size: 16px;
            touch-action: manipulation;
        }

        .quantity button:hover {
            background: #e5e7eb;
        }

        .delete-item {
            color: var(--danger);
            background: #fff1f2 !important;
            margin-left: 5px;
        }

        .cart-footer {
            padding: 18px 20px;
            border-top: 1px solid var(--border);
            background: white;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .total {
            font-size: 22px;
            color: var(--primary);
        }

        .checkout {
            width: 100%;
            min-height: 54px;
            margin-top: 15px;
            border: none;
            border-radius: 13px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            font-size: 15px;
            font-weight: 800;
            touch-action: manipulation;
        }

        .checkout:hover {
            box-shadow: 0 10px 25px rgba(99, 102, 241, .25);
            transform: translateY(-1px);
        }

        /* =========================================================
           MOBILE CART
        ========================================================= */

        .mobile-cart-bar {
            display: none;
            position: fixed;
            left: 10px;
            right: 10px;
            bottom: 10px;
            z-index: 1200;
            min-height: 60px;
            padding: 8px 10px;
            border-radius: 17px;
            background: rgba(17, 24, 39, .96);
            backdrop-filter: blur(15px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, .25);
            color: white;
        }

        .mobile-cart-inner {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .mobile-cart-icon {
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            border-radius: 12px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            position: relative;
        }

        .mobile-cart-info {
            flex: 1;
            min-width: 0;
        }

        .mobile-cart-info small {
            display: block;
            color: #cbd5e1;
            font-size: 10px;
        }

        .mobile-cart-total {
            font-size: 16px;
            font-weight: 800;
        }

        .mobile-cart-btn {
            min-height: 44px;
            padding: 0 15px;
            border: none;
            border-radius: 11px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            font-weight: 800;
            touch-action: manipulation;
        }

        /* =========================================================
           INVOICE
        ========================================================= */

        .invoice-modal {
            position: fixed;
            inset: 0;
            z-index: 5000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(0, 0, 0, .65);
            overflow-y: auto;
        }

        .invoice-modal.show {
            display: flex;
        }

        .invoice-box {
            width: min(650px, 100%);
            max-height: 95vh;
            overflow-y: auto;
            background: white;
            border-radius: 22px;
            padding: 25px;
            position: relative;
            box-shadow: 0 30px 90px rgba(0, 0, 0, .35);
        }

        .invoice-close {
            position: absolute;
            right: 15px;
            top: 15px;
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 10px;
            background: #f3f4f6;
            font-size: 18px;
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding-right: 45px;
        }

        .invoice-header h2 {
            color: var(--primary);
            margin-bottom: 4px;
        }

        .invoice-header p {
            color: var(--muted);
            font-size: 13px;
        }

        .invoice-number {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
            font-size: 12px;
        }

        .invoice-number strong {
            color: var(--text);
        }

        .invoice-number small {
            color: var(--muted);
        }

        .invoice-line {
            height: 1px;
            background: var(--border);
            margin: 20px 0;
        }

        /* =========================================================
           CUSTOMER FORM
        ========================================================= */

        .customer-form {
            margin: 18px 0 8px;
            padding: 18px;
            border: 1px solid var(--border);
            border-radius: 16px;
            background: #f8fafc;
        }

        .customer-form h3 {
            margin-bottom: 12px;
            font-size: 17px;
        }

        .customer-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .customer-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .customer-field.full {
            grid-column: 1 / -1;
        }

        .customer-field label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text);
        }

        .customer-field input,
        .customer-field textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            padding: 11px 12px;
            outline: none;
            font-family: inherit;
            font-size: 13px;
        }

        .customer-field textarea {
            min-height: 76px;
            resize: vertical;
        }

        .customer-field input:focus,
        .customer-field textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .1);
        }

        .customer-required {
            color: var(--danger);
        }

        .order-status {
            margin-top: 10px;
            font-size: 12px;
            font-weight: 700;
        }

        /* =========================================================
           INVOICE TABLE
        ========================================================= */

        .invoice-table-wrap {
            overflow-x: auto;
        }

        .invoice-table {
            width: 100%;
            min-width: 520px;
            border-collapse: collapse;
            font-size: 12px;
        }

        .invoice-table th,
        .invoice-table td {
            padding: 9px 5px;
            text-align: left;
            border-bottom: 1px solid #edf0f3;
            vertical-align: middle;
        }

        .invoice-table th {
            background: #f8fafc;
            font-weight: 800;
        }

        .invoice-product {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 170px;
        }

        .invoice-product-image {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            background: #f8fafc;
            border: 1px solid #eef2f7;
            object-fit: contain;
            padding: 3px;
            flex-shrink: 0;
        }

        .invoice-total {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 8px 0;
            font-size: 14px;
        }

        .invoice-total strong {
            color: var(--primary);
        }

        .invoice-total.grand {
            border-top: 2px solid var(--primary);
            margin-top: 5px;
            padding-top: 14px;
            font-size: 20px;
        }

        /* =========================================================
           QR
        ========================================================= */

        .qr-section {
            margin-top: 20px;
            padding: 20px;
            border-radius: 16px;
            background: #f8fafc;
            text-align: center;
        }

        .qr-section h3 {
            margin-bottom: 4px;
        }

        .qr-section p {
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 15px;
        }

        #qrCode {
            display: flex;
            justify-content: center;
            margin: 0 auto 10px;
        }

        #qrCode img,
        #qrCode canvas {
            width: 220px !important;
            height: 220px !important;
            padding: 8px;
            background: white;
            border-radius: 12px;
        }

        #qrAmount {
            font-size: 22px;
            color: var(--primary);
            font-weight: 800;
        }

        .qr-note {
            margin-top: 8px;
            color: var(--muted);
            font-size: 10px;
        }

        /* =========================================================
           ACTIONS
        ========================================================= */

        .invoice-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 20px;
        }

        .invoice-actions button {
            min-height: 48px;
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 800;
            font-size: 13px;
        }

        .btn-print {
            background: #111827;
        }

        .btn-telegram {
            background: var(--telegram);
        }

        .btn-close {
            background: #ef4444;
        }

        .btn-confirm-order {
            background: #10b981;
        }

        .invoice-actions button:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        /* =========================================================
           TOAST
        ========================================================= */

        .toast {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 8000;
            min-width: 260px;
            max-width: 360px;
            padding: 14px 16px;
            border-radius: 13px;
            color: white;
            background: #111827;
            box-shadow: 0 15px 40px rgba(0, 0, 0, .2);
            transform: translateY(30px);
            opacity: 0;
            pointer-events: none;
            transition: .3s;
        }

        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        /* =========================================================
           PRINT
        ========================================================= */

        @media print {

            body {
                background: white !important;
            }

            body>* {
                display: none !important;
            }

            #invoiceModal {
                display: block !important;
                position: static !important;
                background: white !important;
                padding: 0 !important;
            }

            .invoice-box {
                width: 100% !important;
                max-width: 100% !important;
                max-height: none !important;
                overflow: visible !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 10px !important;
            }

            .invoice-close,
            .invoice-actions,
            .customer-form,
            .qr-note {
                display: none !important;
            }

            .qr-section {
                break-inside: avoid;
            }
        }

        /* =========================================================
           RESPONSIVE
        ========================================================= */

        @media (max-width: 1199px) {

            .navbar,
            .hero,
            .container {
                max-width: 100%;
            }

            .navbar {
                padding-left: 20px;
                padding-right: 20px;
            }

            .hero {
                margin-left: 20px;
                margin-right: 20px;
                padding: 40px;
            }

            .container {
                padding-left: 20px;
                padding-right: 20px;
            }

            .products-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 16px;
            }
        }

        @media (max-width: 1024px) {

            .navbar {
                gap: 12px;
            }

            .search-box {
                width: min(320px, 100%);
            }

            .hero {
                min-height: 290px;
                padding: 35px;
            }

            .hero h1 {
                font-size: clamp(30px, 5vw, 48px);
            }

            .hero-icon {
                font-size: 110px;
            }

            .products-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 15px;
            }

            .product-image {
                height: 190px;
            }
        }

        @media (max-width: 768px) {

            body {
                padding-bottom: 82px;
            }

            .navbar {
                min-height: 64px;
                padding: 9px 14px;
                gap: 10px;
            }

            .logo {
                flex-shrink: 0;
                font-size: 18px;
                gap: 8px;
            }

            .logo-icon {
                width: 39px;
                height: 39px;
                font-size: 19px;
                border-radius: 11px;
            }

            .nav-actions {
                min-width: 0;
                gap: 8px;
            }

            .desktop-cart-btn {
                display: none;
            }

            .search-box {
                width: 100%;
                min-width: 0;
            }

            .search-box input {
                height: 44px;
                padding-left: 13px;
                padding-right: 42px;
                font-size: 13px;
                border-radius: 11px;
            }

            .hero {
                margin: 12px;
                min-height: 270px;
                padding: 30px 22px;
                border-radius: 19px;
            }

            .hero-text {
                width: 100%;
                max-width: 100%;
            }

            .hero h1 {
                font-size: clamp(28px, 7vw, 42px);
                line-height: 1.2;
            }

            .hero p {
                font-size: 14px;
                line-height: 1.7;
            }

            .hero-btn {
                min-height: 46px;
                padding: 0 18px;
            }

            .hero-icon {
                position: absolute;
                right: -20px;
                bottom: -10px;
                font-size: 110px;
            }

            .container {
                padding: 0 14px 100px;
            }

            .top-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                margin: 24px 0 16px;
            }

            .section-title h2 {
                font-size: 23px;
            }

            .sort-select {
                width: 100%;
                min-width: 0;
                min-height: 44px;
            }

            .categories {
                width: 100%;
                overflow-x: auto;
                padding-bottom: 7px;
                gap: 8px;
                -webkit-overflow-scrolling: touch;
            }

            .category-btn {
                min-height: 42px;
                padding: 9px 15px;
                white-space: nowrap;
            }

            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .product-card {
                min-width: 0;
                border-radius: 15px;
            }

            .product-image {
                height: 160px;
            }

            .product-image img {
                padding: 12px;
            }

            .product-content {
                padding: 11px;
            }

            .product-name {
                font-size: 12px;
                min-height: 42px;
                overflow-wrap: anywhere;
            }

            .product-bottom {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .price {
                font-size: 16px;
            }

            .add-btn {
                width: 100%;
                min-height: 44px;
                height: 44px;
            }

            .mobile-cart-bar {
                display: block;
                left: 8px;
                right: 8px;
                bottom: calc(8px + env(safe-area-inset-bottom));
                min-height: 60px;
                padding: 7px 9px;
                border-radius: 16px;
            }

            .mobile-cart-inner {
                gap: 8px;
            }

            .mobile-cart-icon {
                width: 42px;
                height: 42px;
                border-radius: 11px;
            }

            .mobile-cart-info {
                min-width: 0;
            }

            .mobile-cart-total {
                font-size: 15px;
            }

            .mobile-cart-btn {
                min-height: 44px;
                padding: 0 13px;
                font-size: 12px;
                white-space: nowrap;
            }

            .cart {
                width: 100%;
                max-width: 100%;
                right: -100%;
            }

            .cart.show {
                right: 0;
            }

            .cart-items {
                padding: 12px 14px;
            }

            .cart-footer {
                padding: 14px 14px calc(14px + env(safe-area-inset-bottom));
            }

            .invoice-modal {
                padding: 10px;
                align-items: flex-start;
            }

            .invoice-box {
                width: 100%;
                max-width: 100%;
                max-height: calc(100vh - 20px);
                margin-top: 5px;
                padding: 17px;
                border-radius: 17px;
            }

            .invoice-header {
                flex-direction: column;
                gap: 10px;
                padding-right: 42px;
            }

            .invoice-number {
                align-items: flex-start;
            }

            .invoice-actions {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .invoice-actions button {
                min-height: 46px;
            }

            .customer-grid {
                grid-template-columns: 1fr;
            }

            .customer-field.full {
                grid-column: auto;
            }

            .toast {
                left: 12px;
                right: 12px;
                bottom: 82px;
                min-width: 0;
                max-width: none;
                text-align: center;
            }
        }

        @media (max-width: 480px) {

            .navbar {
                padding: 8px 10px;
            }

            .logo {
                font-size: 15px;
                gap: 6px;
            }

            .logo-icon {
                width: 36px;
                height: 36px;
                font-size: 18px;
            }

            .search-box input {
                height: 42px;
                font-size: 12px;
            }

            .hero {
                margin: 10px;
                padding: 25px 18px;
                min-height: 245px;
                border-radius: 17px;
            }

            .hero h1 {
                font-size: 30px;
            }

            .hero p {
                font-size: 12px;
                line-height: 1.65;
            }

            .hero-btn {
                width: 100%;
            }

            .hero-icon {
                font-size: 85px;
                opacity: .13;
            }

            .container {
                padding-left: 10px;
                padding-right: 10px;
            }

            .section-title h2 {
                font-size: 21px;
            }

            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 9px;
            }

            .product-card {
                border-radius: 13px;
            }

            .product-image {
                height: 135px;
            }

            .product-image img {
                padding: 8px;
            }

            .product-category {
                top: 6px;
                left: 6px;
                padding: 4px 6px;
                font-size: 7px;
            }

            .stock {
                right: 6px;
                bottom: 6px;
                padding: 4px 6px;
                font-size: 7px;
            }

            .product-content {
                padding: 9px;
            }

            .product-id {
                font-size: 8px;
            }

            .product-name {
                font-size: 11px;
                line-height: 1.45;
                min-height: 47px;
            }

            .price {
                font-size: 14px;
            }

            .add-btn {
                min-height: 42px;
                height: 42px;
                font-size: 11px;
            }

            .mobile-cart-btn {
                padding: 0 10px;
                font-size: 11px;
            }

            .mobile-cart-total {
                font-size: 13px;
            }

            .invoice-box {
                padding: 14px;
            }

            #qrCode img,
            #qrCode canvas {
                width: min(190px, 65vw) !important;
                height: min(190px, 65vw) !important;
            }
        }

        @media (max-width: 359px) {

            .navbar {
                min-height: 58px;
                padding: 7px 8px;
            }

            .logo {
                font-size: 13px;
            }

            .logo-icon {
                width: 33px;
                height: 33px;
                font-size: 16px;
            }

            .search-box input {
                height: 40px;
                font-size: 11px;
            }

            .hero {
                margin: 8px;
                padding: 22px 15px;
                min-height: 230px;
            }

            .hero h1 {
                font-size: 27px;
            }

            .hero p {
                font-size: 11px;
            }

            .container {
                padding-left: 8px;
                padding-right: 8px;
            }

            .products-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .product-image {
                height: 175px;
            }

            .product-image img {
                padding: 15px;
            }

            .product-name {
                font-size: 13px;
                min-height: auto;
            }

            .price {
                font-size: 16px;
            }

            .add-btn {
                height: 44px;
                font-size: 12px;
            }

            .mobile-cart-bar {
                left: 5px;
                right: 5px;
            }

            .mobile-cart-btn {
                padding: 0 8px;
                font-size: 10px;
            }

            .invoice-box {
                padding: 12px;
            }

            .invoice-header h2 {
                font-size: 18px;
            }

            .invoice-table {
                min-width: 470px;
            }
        }

        @media (max-height: 500px) and (orientation: landscape) {

            .hero {
                min-height: 210px;
                padding: 25px;
            }

            .hero-icon {
                font-size: 80px;
            }

            .mobile-cart-bar {
                bottom: 5px;
            }

            .invoice-box {
                max-height: 96vh;
            }
        }

        @media (hover: none) and (pointer: coarse) {

            .product-card:hover,
            .add-btn:hover,
            .desktop-cart-btn:hover {
                transform: none;
            }

            .product-card:hover .product-image img {
                transform: none;
            }
        }
    </style>
</head>

<body>

    <header>
        <div class="navbar">

            <div class="logo">
                <div class="logo-icon">🛍️</div>
                KHMER SHOP
            </div>

            <div class="nav-actions">

                <div class="search-box">
                    <input id="search" type="search" placeholder="🔎 ស្វែងរកទំនិញ..." autocomplete="off">
                    <span class="search-icon">🔎</span>
                </div>

                <button class="desktop-cart-btn" onclick="openCart()" aria-label="Cart">
                    🛒
                    <span class="cart-count" id="cartCount">0</span>
                </button>

            </div>
        </div>
    </header>

    <section class="hero">

        <div class="hero-text">

            <h1>
                ទិញទំនិញ
                <br>
                ងាយស្រួល & ទាន់សម័យ
            </h1>

            <p>
                ស្វែងរកទំនិញដែលអ្នកចូលចិត្ត
                និងបញ្ជាទិញបានយ៉ាងងាយស្រួល
                ទាំងកុំព្យូទ័រ និងទូរស័ព្ទ។
            </p>

            <a href="#products" class="hero-btn">
                🛍️ មើលទំនិញ
            </a>

        </div>

        <div class="hero-icon">🛒</div>

    </section>

    <main class="container" id="products">

        <div class="top-bar">

            <div class="section-title">

                <h2>ទំនិញទាំងអស់</h2>

                <p id="productCount">
                    100 Products
                </p>

            </div>

            <select id="sort" class="sort-select">

                <option value="default">
                    តម្រៀបតាម
                </option>

                <option value="low">
                    💰 តម្លៃទាប → ខ្ពស់
                </option>

                <option value="high">
                    💰 តម្លៃខ្ពស់ → ទាប
                </option>

                <option value="name">
                    🔤 ឈ្មោះ A → Z
                </option>

            </select>

        </div>

        <div class="categories" id="categories">

            <button class="category-btn active" data-category="All">
                🛍️ ទាំងអស់
            </button>

            <button class="category-btn" data-category="Beverages">
                🥤 ភេសជ្ជៈ
            </button>

            <button class="category-btn" data-category="Snacks">
                🍫 អាហារសម្រន់
            </button>

            <button class="category-btn" data-category="Groceries">
                🍚 គ្រឿងទេស
            </button>

            <button class="category-btn" data-category="Personal Care">
                🧴 ថែរក្សាខ្លួន
            </button>

            <button class="category-btn" data-category="Household">
                🏠 របស់ប្រើប្រាស់
            </button>

        </div>

        <br>

        <div class="products-grid" id="productGrid"></div>

        <div class="empty" id="empty">

            <div class="empty-icon">🔍</div>

            <h3>មិនមានទំនិញ</h3>

            <p>
                សាកល្បងស្វែងរកពាក្យផ្សេងទៀត
            </p>

        </div>

    </main>

    <div class="overlay" id="overlay" onclick="closeCart()"></div>

    <aside class="cart" id="cart">

        <div class="cart-header">

            <h3>🛒 កន្ត្រកទំនិញ</h3>

            <button class="close-cart" onclick="closeCart()">
                ✕
            </button>

        </div>

        <div class="cart-items" id="cartItems"></div>

        <div class="cart-footer">

            <div class="summary-row">

                <span>ចំនួនទំនិញ</span>

                <strong id="subtotal">
                    $0.00
                </strong>

            </div>

            <div class="summary-row">

                <span>ដឹកជញ្ជូន</span>

                <strong>FREE</strong>

            </div>

            <div class="summary-row">

                <span>សរុប</span>

                <strong class="total" id="total">
                    $0.00
                </strong>

            </div>

            <button class="checkout" onclick="checkout()">
                🧾 បញ្ជាទិញ / បង្កើតវិក្កយបត្រ
            </button>

        </div>

    </aside>

    <div class="mobile-cart-bar">

        <div class="mobile-cart-inner">

            <div class="mobile-cart-icon">

                🛒

                <span class="cart-count" id="mobileCartCount">
                    0
                </span>

            </div>

            <div class="mobile-cart-info">

                <small>
                    កន្ត្រកទំនិញ
                </small>

                <div class="mobile-cart-total" id="mobileCartTotal">
                    $0.00
                </div>

            </div>

            <button class="mobile-cart-btn" onclick="openCart()">
                មើលកន្ត្រក →
            </button>

        </div>

    </div>

    <!-- =========================================================
     INVOICE
========================================================= -->

    <div class="invoice-modal" id="invoiceModal">

        <div class="invoice-box">

            <button class="invoice-close" onclick="closeInvoice()">
                ✕
            </button>

            <div class="invoice-header">

                <div>

                    <h2>
                        🛍️ KHMER SHOP
                    </h2>

                    <p>
                        វិក្កយបត្រទិញទំនិញ
                    </p>

                </div>

                <div class="invoice-number">

                    <strong id="invoiceNumber">
                        INV-000000
                    </strong>

                    <small id="invoiceDate">
                        -
                    </small>

                </div>

            </div>

            <div class="invoice-line"></div>

            <!-- CUSTOMER -->

            <div class="customer-form">

                <h3>
                    👤 ព័ត៌មានភ្ញៀវ
                </h3>

                <div class="customer-grid">

                    <div class="customer-field">

                        <label>
                            ឈ្មោះ
                            <span class="customer-required">*</span>
                        </label>

                        <input id="customerName" type="text" placeholder="បញ្ចូលឈ្មោះ" autocomplete="name">

                    </div>

                    <div class="customer-field">

                        <label>
                            លេខទូរស័ព្ទ
                            <span class="customer-required">*</span>
                        </label>

                        <input id="customerPhone" type="tel" placeholder="0XX XXX XXX" autocomplete="tel">

                    </div>

                    <div class="customer-field full">

                        <label>
                            អាសយដ្ឋាន
                            <span class="customer-required">*</span>
                        </label>

                        <textarea id="customerAddress"
                            placeholder="ផ្ទះលេខ, ផ្លូវ, ភូមិ, ឃុំ/សង្កាត់, ស្រុក/ខណ្ឌ, ខេត្ត/ក្រុង"
                            autocomplete="street-address"></textarea>

                    </div>

                </div>

                <div id="orderStatus" class="order-status"></div>

            </div>

            <!-- INVOICE ITEMS -->

            <div class="invoice-table-wrap">

                <table class="invoice-table">

                    <thead>

                        <tr>

                            <th>ទំនិញ</th>
                            <th>ចំនួន</th>
                            <th>តម្លៃ</th>
                            <th>សរុប</th>

                        </tr>

                    </thead>

                    <tbody id="invoiceItems"></tbody>

                </table>

            </div>

            <br>

            <div class="invoice-total">

                <span>
                    សរុប USD
                </span>

                <strong id="invoiceUSD">
                    $0.00
                </strong>

            </div>

            <div class="invoice-total">

                <span>
                    អត្រាប្តូរ
                </span>

                <strong>
                    1 USD = 4,000 KHR
                </strong>

            </div>

            <div class="invoice-total grand">

                <span>
                    សរុប KHR
                </span>

                <strong id="invoiceKHR">
                    ៛0
                </strong>

            </div>

            <!-- QR -->

            <div class="qr-section">

                <h3>
                    🇰🇭 KHQR PAYMENT
                </h3>

                <p>
                    ស្កេន QR ដើម្បីទូទាត់
                </p>

                <div id="qrCode"></div>

                <strong id="qrAmount">
                    $0.00
                </strong>

                <div class="qr-note">

                    QR ខាងលើប្រើ KHQR
                    ដែលបានកំណត់ក្នុងប្រព័ន្ធ។

                    <br>

                    សូមពិនិត្យចំនួនទឹកប្រាក់
                    មុនពេលទូទាត់។

                </div>

            </div>

            <!-- ACTIONS -->

            <div class="invoice-actions">

                <button class="btn-confirm-order" onclick="confirmOrder()">
                    ✅ បញ្ជាក់ & ផ្ញើ Order
                </button>

                <button class="btn-print" onclick="printInvoice()">
                    🖨️ Print
                </button>

                <button class="btn-telegram" onclick="sendTelegram()">
                    📲 Telegram
                </button>

                <button class="btn-close" onclick="closeInvoice()">
                    ✕ បិទ
                </button>

            </div>

        </div>

    </div>

    <div class="toast" id="toast"></div>

    <script>
        /* =========================================================
           CONFIG
        ========================================================= */

        const EXCHANGE_RATE = 4000;

        // PHP backend: orders are saved to MySQL through this endpoint.
        const PHP_ORDER_API = window.location.pathname + "?action=save_order";

        const TELEGRAM_USERNAME =
            "Meng2026hong1681708";

        const SHOP_NAME =
            "KHMER SHOP";

        const ACLEDA_QR_STRING =
            "00020101021129380009khqr@aclb0111855277731080206ACLEDA391300042CCY010145204599953031165802KH5914HOUCH SENGHONG6010PHNOM PENH62140210097787143363043695";

        const ABA_QR_STRING =
            "00020101021129450016abaakhppxxx@abaa01090108731930208ABA Bank40600006abaP2P0112B6BD5B453A17020901087319303090108731910404Dual5204000053031165802KH5914SENGHONG HOUCH6010Phnom Penh6304B3B2";


        /* =========================================================
           IMAGE HELPERS
        ========================================================= */

        /*
            រូបខាងក្រៅសម្រាប់ Demo។
            បើចង់ដាក់រូប Local:
            image: "images/products/P-001.webp"
        */

        const IMG = (keyword, lock) =>
            `https://loremflickr.com/700/700/${encodeURIComponent(keyword)}?lock=${lock}`;


        /* រូប Product ដែលរកបានជាក់លាក់ */

        const REAL_IMAGES = {

            coca: "https://assets.woolworths.com.au/images/2010/142420.jpg?h=900&impolicy=wowcdxwbjbx&w=900",

            cocaTransparent: "https://www.pngkey.com/png/detail/798-7987684_more-views-coca-cola-330ml.png",

            pepsi: "https://assets.woolworths.com.au/images/2010/142420.jpg?h=900&impolicy=wowcdxwbjbx&w=900",

            vital: "https://bongveggy.com/cdn/shop/files/Vital-Drinking-Water-500ml-Bottle.jpg"
        };


        /* =========================================================
           PRODUCT DATA
        ========================================================= */

        const products = [

            /* =====================================================
                BEVERAGES
            ===================================================== */

            {
                id: "P-001",
                name: "កូកាកូឡា 330ml",
                category: "Beverages",
                price: 0.75,
                stock: 120,
                image: "images/products/P-001.webp"
            },
            {
                id: "P-002",
                name: "ប៉ិបស៊ី 330ml",
                category: "Beverages",
                price: 0.75,
                stock: 95,
                image: "images/products/P-002.webp"
            },
            {
                id: "P-003",
                name: "ទឹកបរិសុទ្ធ វីតាល់ 500ml",
                category: "Beverages",
                price: 0.35,
                stock: 250,
                image: "images/products/P-003.webp"
            },
            {
                id: "P-004",
                name: "ទឹកបរិសុទ្ធ គូលែន 1.5L",
                category: "Beverages",
                price: 0.60,
                stock: 180,
                image: "images/products/P-004.webp"
            },
            {
                id: "P-005",
                name: "ភេសជ្ជៈពង្រឹងកម្លាំង wurkz",
                category: "Beverages",
                price: 0.85,
                stock: 110,
                image: "images/products/P-005.webp"
            },
            {
                id: "P-006",
                name: "ភេសជ្ជៈ 7UP",
                category: "Beverages",
                price: 0.90,
                stock: 140,
                image: "images/products/P-006.webp"
            },
            {
                id: "P-007",
                name: "ភេសជ្ជៈ MIRINDA",
                category: "Beverages",
                price: 0.80,
                stock: 75,
                image: "images/products/P-007.webp"
            },
            {
                id: "P-008",
                name: "ហ្វានតា ក្លិនក្រូច 330ml",
                category: "Beverages",
                price: 0.75,
                stock: 85,
                image: "images/products/P-008.webp"
            },
            {
                id: "P-009",
                name: "sprite 330ml",
                category: "Beverages",
                price: 0.75,
                stock: 100,
                image: "images/products/P-009.webp"
            },
            {
                id: "P-010",
                name: "សេវេនអាប់ 330ml",
                category: "Beverages",
                price: 0.70,
                stock: 90,
                image: "images/products/P-010.webp"
            },
            {
                id: "P-011",
                name: "ទឹកក្រូច Minute Maid 350ml",
                category: "Beverages",
                price: 1.00,
                stock: 70,
                image: "images/products/P-011.webp"
            },
            {
                id: "P-012",
                name: "ទឹកដោះគោ Angkor Milk",
                category: "Beverages",
                price: 1.20,
                stock: 60,
                image: "images/products/P-012.webp"
            },
            {
                id: "P-013",
                name: "ទឹកដោះគោ Meiji 200ml",
                category: "Beverages",
                price: 0.95,
                stock: 85,
                image: "images/products/P-013.webp"
            },
            {
                id: "P-014",
                name: "កាហ្វេកំប៉ុង Birdy",
                category: "Beverages",
                price: 0.90,
                stock: 75,
                image: "images/products/P-014.webp"
            },
            {
                id: "P-015",
                name: "កាហ្វេកំប៉ុង Black coffee",
                category: "Beverages",
                price: 1.10,
                stock: 65,
                image: "images/products/P-015.webp"
            },
            {
                id: "P-016",
                name: "ទឹកដូងកំប៉ុង",
                category: "Beverages",
                price: 1.30,
                stock: 45,
                image: "images/products/P-016.webp"
            },
            {
                id: "P-017",
                name: "ទឹកផ្លែឈើស្វាយ",
                category: "Beverages",
                price: 1.15,
                stock: 55,
                image: "images/products/P-017.webp"
            },
            {
                id: "P-018",
                name: "ទឹកផ្លែឈើទំពាំងបាយជូរ",
                category: "Beverages",
                price: 1.15,
                stock: 50,
                image: "images/products/P-018.webp"
            },
            {
                id: "P-019",
                name: "ទឹកផ្លែឈើផ្លែប៉ោម",
                category: "Beverages",
                price: 1.10,
                stock: 55,
                image: "images/products/P-019.webp"
            },
            {
                id: "P-020",
                name: "ទឹកសណ្តែក Soy Milk",
                category: "Beverages",
                price: 0.85,
                stock: 70,
                image: "images/products/P-020.webp"
            },

            /* =====================================================
                SNACKS
            ===================================================== */

            {
                id: "P-021",
                name: "ដំឡូងបារាំងបំពង Lay's",
                category: "Snacks",
                price: 1.20,
                stock: 65,
                image: "images/products/P-021.webp"
            },
            {
                id: "P-022",
                name: "Pringles 107g",
                category: "Snacks",
                price: 2.10,
                stock: 45,
                image: "images/products/P-022.webp"
            },
            {
                id: "P-023",
                name: "នំអូរីអូ Oreo 137g",
                category: "Snacks",
                price: 0.90,
                stock: 110,
                image: "images/products/P-023.webp"
            },
            {
                id: "P-024",
                name: "នំស្រួយ Ritz 100g",
                category: "Snacks",
                price: 1.10,
                stock: 50,
                image: "images/products/P-024.webp"
            },
            {
                id: "P-025",
                name: "ស្ករគ្រាប់ Chupa Chups",
                category: "Snacks",
                price: 1.50,
                stock: 35,
                image: "images/products/P-025.webp"
            },
            {
                id: "P-026",
                name: "សូកូឡា KitKat",
                category: "Snacks",
                price: 1.00,
                stock: 80,
                image: "images/products/P-026.webp"
            },
            {
                id: "P-027",
                name: "សូកូឡា Snickers",
                category: "Snacks",
                price: 1.10,
                stock: 70,
                image: "images/products/P-027.webp"
            },
            {
                id: "P-028",
                name: "សូកូឡា Mars",
                category: "Snacks",
                price: 1.10,
                stock: 65,
                image: "images/products/P-028.webp"
            },
            {
                id: "P-029",
                name: "សូកូឡា Twix",
                category: "Snacks",
                price: 1.10,
                stock: 60,
                image: "images/products/P-029.webp"
            },
            {
                id: "P-030",
                name: "នំ Wafer សូកូឡា",
                category: "Snacks",
                price: 0.80,
                stock: 90,
                image: "images/products/P-030.webp"
            },
            {
                id: "P-031",
                name: "ភ្លៅមាន់បំពង",
                category: "Snacks",
                price: 1.00,
                stock: 75,
                image: "images/products/P-031.webp"
            },
            {
                id: "P-032",
                name: "នំស្រួយរសជាតិឈីស",
                category: "Snacks",
                price: 0.95,
                stock: 80,
                image: "images/products/P-032.webp"
            },
            {
                id: "P-033",
                name: "ពោតលីង Popcorn",
                category: "Snacks",
                price: 1.30,
                stock: 55,
                image: "images/products/P-033.webp"
            },
            {
                id: "P-034",
                name: "សណ្តែកដីលីង",
                category: "Snacks",
                price: 0.90,
                stock: 70,
                image: "images/products/P-034.webp"
            },
            {
                id: "P-035",
                name: "គ្រាប់ស្វាយចន្ទី",
                category: "Snacks",
                price: 2.50,
                stock: 40,
                image: "images/products/P-035.webp"
            },
            {
                id: "P-036",
                name: "ចេកចៀន",
                category: "Snacks",
                price: 1.20,
                stock: 65,
                image: "images/products/P-036.webp"
            },
            {
                id: "P-037",
                name: "នំខេក Chocolate",
                category: "Snacks",
                price: 1.80,
                stock: 45,
                image: "images/products/P-037.webp"
            },
            {
                id: "P-038",
                name: "នំខេក Vanilla",
                category: "Snacks",
                price: 1.60,
                stock: 40,
                image: "images/products/P-038.webp"
            },
            {
                id: "P-039",
                name: "នំដូណាត់",
                category: "Snacks",
                price: 1.50,
                stock: 50,
                image: "images/products/P-039.webp"
            },
            {
                id: "P-040",
                name: "នំខូគី Chocolate Chip",
                category: "Snacks",
                price: 1.40,
                stock: 60,
                image: "images/products/P-040.webp"
            },

            /* =====================================================
                GROCERIES
            ===================================================== */

            {
                id: "P-041",
                name: "អង្ករផ្កាម្លិះ 5kg",
                category: "Groceries",
                price: 6.50,
                stock: 25,
                image: "images/products/P-041.webp"
            },
            {
                id: "P-042",
                name: "មីជាតិ រសជាតិសាច់ជ្រូក",
                category: "Groceries",
                price: 0.45,
                stock: 200,
                image: "images/products/P-042.webp"
            },
            {
                id: "P-043",
                name: "មីជាតិ រសជាតិម្ជូរបង្គា",
                category: "Groceries",
                price: 0.30,
                stock: 300,
                image: "images/products/P-043.webp"
            },
            {
                id: "P-044",
                name: "មីកូរ៉េហឹរ Koreno",
                category: "Groceries",
                price: 0.85,
                stock: 120,
                image: "images/products/P-044.webp"
            },
            {
                id: "P-045",
                name: "ប្រេងឆាសណ្តែកសៀង 5L",
                category: "Groceries",
                price: 2.40,
                stock: 45,
                image: "images/products/P-045.webp"
            },
            {
                id: "P-046",
                name: "ស្ករស 1kg",
                category: "Groceries",
                price: 1.20,
                stock: 80,
                image: "images/products/P-046.webp"
            },
            {
                id: "P-047",
                name: "អំបិល 1kg",
                category: "Groceries",
                price: 0.50,
                stock: 100,
                image: "images/products/P-047.webp"
            },
            {
                id: "P-048",
                name: "ទឹកត្រី 700ml",
                category: "Groceries",
                price: 0.90,
                stock: 65,
                image: "images/products/P-048.webp"
            },
            {
                id: "P-049",
                name: "ទឹកស៊ីអ៊ីវ 450ml",
                category: "Groceries",
                price: 0.90,
                stock: 70,
                image: "images/products/P-049.webp"
            },
            {
                id: "P-050",
                name: "ទឹកខ្មេះ 450ml",
                category: "Groceries",
                price: 0.50,
                stock: 55,
                image: "images/products/P-050.webp"
            },
            {
                id: "P-051",
                name: "ទឹកជ្រលក់ម្ទេស",
                category: "Groceries",
                price: 1.10,
                stock: 60,
                image: "images/products/P-051.webp"
            },
            {
                id: "P-052",
                name: "ទឹកជ្រលក់ប៉េងប៉ោះ",
                category: "Groceries",
                price: 1.10,
                stock: 65,
                image: "images/products/P-052.webp"
            },
            {
                id: "P-053",
                name: "ម្សៅស៊ុប Knorr",
                category: "Groceries",
                price: 0.80,
                stock: 90,
                image: "images/products/P-053.webp"
            },
            {
                id: "P-054",
                name: "ម្រេចខ្មៅ 100g",
                category: "Groceries",
                price: 1.30,
                stock: 50,
                image: "images/products/P-054.webp"
            },
            {
                id: "P-055",
                name: "ខ្ទឹមស 500g",
                category: "Groceries",
                price: 1.50,
                stock: 45,
                image: "images/products/P-055.webp"
            },
            {
                id: "P-056",
                name: "ខ្ទឹមបារាំង 1kg",
                category: "Groceries",
                price: 1.40,
                stock: 50,
                image: "images/products/P-056.webp"
            },
            {
                id: "P-057",
                name: "ដំឡូងបារាំង 1kg",
                category: "Groceries",
                price: 1.30,
                stock: 55,
                image: "images/products/P-057.webp"
            },
            {
                id: "P-058",
                name: "ស៊ុតមាន់ 10 គ្រាប់",
                category: "Groceries",
                price: 2.20,
                stock: 40,
                image: "images/products/P-058.webp"
            },
            {
                id: "P-059",
                name: "ស្ករត្នោត 500g",
                category: "Groceries",
                price: 1.80,
                stock: 35,
                image: "images/products/P-059.webp"
            },
            {
                id: "P-060",
                name: "ទឹកឃ្មុំ 250ml",
                category: "Groceries",
                price: 3.50,
                stock: 30,
                image: "images/products/P-060.webp"
            },

            /* =====================================================
                PERSONAL CARE
            ===================================================== */

            {
                id: "P-061",
                name: "សាប៊ូកក់សក់ Clear 330ml",
                category: "Personal Care",
                price: 3.20,
                stock: 35,
                image: "images/products/P-061.webp"
            },
            {
                id: "P-062",
                name: "សាប៊ូកក់សក់ Sunsilk 320ml",
                category: "Personal Care",
                price: 2.90,
                stock: 40,
                image: "images/products/P-062.webp"
            },
            {
                id: "P-063",
                name: "ថ្នាំដុសធ្មេញ ស្តាយយើង ",
                category: "Personal Care",
                price: 1.60,
                stock: 80,
                image: "images/products/P-063.webp"
            },
            {
                id: "P-064",
                name: "ច្រាសដុសធ្មេញ Signal",
                category: "Personal Care",
                price: 1.10,
                stock: 90,
                image: "images/products/P-064.webp"
            },
            {
                id: "P-065",
                name: "សាប៊ូដុំ Dove",
                category: "Personal Care",
                price: 0.65,
                stock: 110,
                image: "images/products/P-065.webp"
            },
            {
                id: "P-066",
                name: "សាប៊ូដុំ Lifebuoy 85g",
                category: "Personal Care",
                price: 0.70,
                stock: 100,
                image: "images/products/P-066.webp"
            },
            {
                id: "P-067",
                name: "ឡូសិនលាបស្បែក Nivea",
                category: "Personal Care",
                price: 4.10,
                stock: 25,
                image: "images/products/P-067.webp"
            },
            {
                id: "P-068",
                name: "ឡូសិន Vaseline",
                category: "Personal Care",
                price: 3.50,
                stock: 30,
                image: "images/products/P-068.webp"
            },
            {
                id: "P-069",
                name: "Deodorant Rexona",
                category: "Personal Care",
                price: 2.80,
                stock: 35,
                image: "images/products/P-069.webp"
            },
            {
                id: "P-070",
                name: "Deodorant Nivea",
                category: "Personal Care",
                price: 3.20,
                stock: 30,
                image: "images/products/P-070.webp"
            },
            {
                id: "P-071",
                name: "សាប៊ូលាងមុខ Garnier",
                category: "Personal Care",
                price: 3.80,
                stock: 25,
                image: "images/products/P-071.webp"
            },
            {
                id: "P-072",
                name: "សាប៊ូលាងមុខ Pond's",
                category: "Personal Care",
                price: 3.50,
                stock: 30,
                image: "images/products/P-072.webp"
            },
            {
                id: "P-073",
                name: "ក្រដាសជូតមុខ",
                category: "Personal Care",
                price: 1.50,
                stock: 80,
                image: "images/products/P-073.webp"
            },
            {
                id: "P-074",
                name: "កន្សែងជូតមុខ",
                category: "Personal Care",
                price: 2.20,
                stock: 45,
                image: "images/products/P-074.webp"
            },
            {
                id: "P-075",
                name: "ឡេការពារកំដៅថ្ងៃ SPF50",
                category: "Personal Care",
                price: 6.50,
                stock: 20,
                image: "images/products/P-075.webp"
            },
            {
                id: "P-076",
                name: "Lip Balm",
                category: "Personal Care",
                price: 2.00,
                stock: 50,
                image: "images/products/P-076.webp"
            },
            {
                id: "P-077",
                name: "សាប៊ូកក់សក់ Head & Shoulders",
                category: "Personal Care",
                price: 3.80,
                stock: 35,
                image: "images/products/P-077.webp"
            },
            {
                id: "P-078",
                name: "ទឹកអប់ក្លិនបុរស",
                category: "Personal Care",
                price: 8.50,
                stock: 15,
                image: "images/products/P-078.webp"
            },
            {
                id: "P-079",
                name: "ទឹកអប់ក្លិននារី",
                category: "Personal Care",
                price: 8.50,
                stock: 15,
                image: "images/products/P-079.webp"
            },
            {
                id: "P-080",
                name: "កាត់ក្រចក",
                category: "Personal Care",
                price: 1.50,
                stock: 50,
                image: "images/products/P-080.webp"
            },

            /* =====================================================
                HOUSEHOLD
            ===================================================== */

            {
                id: "P-081",
                name: "ម្សៅសាប៊ូបោកខោអាវ Viso",
                category: "Household",
                price: 2.10,
                stock: 50,
                image: "images/products/P-081.webp"
            },
            {
                id: "P-082",
                name: "ទឹកលាងចាន Sunlight",
                category: "Household",
                price: 1.45,
                stock: 60,
                image: "images/products/P-082.webp"
            },
            {
                id: "P-083",
                name: "ក្រដាសអនាម័យ Scott",
                category: "Household",
                price: 3.50,
                stock: 25,
                image: "images/products/P-083.webp"
            },
            {
                id: "P-084",
                name: "សាប៊ូលាងបន្ទប់ទឹក",
                category: "Household",
                price: 2.30,
                stock: 40,
                image: "images/products/P-084.webp"
            },
            {
                id: "P-085",
                name: "ទឹកសម្អាតកញ្ចក់",
                category: "Household",
                price: 2.00,
                stock: 45,
                image: "images/products/P-085.webp"
            },
            {
                id: "P-086",
                name: "អំពូលភ្លើង LED 9W",
                category: "Household",
                price: 2.50,
                stock: 30,
                image: "images/products/P-086.webp"
            },
            {
                id: "P-087",
                name: "ថ្មពិល Panasonic AA",
                category: "Household",
                price: 1.80,
                stock: 65,
                image: "images/products/P-087.webp"
            },
            {
                id: "P-088",
                name: "ថង់សំរាម",
                category: "Household",
                price: 1.50,
                stock: 80,
                image: "images/products/P-088.webp"
            },
            {
                id: "P-089",
                name: "កន្ត្រៃកាត់ក្រដាស",
                category: "Household",
                price: 1.20,
                stock: 40,
                image: "images/products/P-089.webp"
            },
            {
                id: "P-090",
                name: "ស្កុតបិទប្រអប់",
                category: "Household",
                price: 1.30,
                stock: 55,
                image: "images/products/P-090.webp"
            },
            {
                id: "P-091",
                name: "ថង់ប្លាស្ទិក",
                category: "Household",
                price: 1.00,
                stock: 100,
                image: "images/products/P-091.webp"
            },
            {
                id: "P-092",
                name: "អំបោស",
                category: "Household",
                price: 2.50,
                stock: 35,
                image: "images/products/P-092.webp"
            },
            {
                id: "P-093",
                name: "ប្រដាប់ជូតជើង",
                category: "Household",
                price: 3.20,
                stock: 30,
                image: "images/products/P-093.webp"
            },
            {
                id: "P-094",
                name: "ក្រដាស A4 500 សន្លឹក",
                category: "Household",
                price: 4.50,
                stock: 40,
                image: "images/products/P-094.webp"
            },
            {
                id: "P-095",
                name: "ប៊ិចសរសេរពណ៌ខៀវ",
                category: "Household",
                price: 2.00,
                stock: 60,
                image: "images/products/P-095.webp"
            },
            {
                id: "P-096",
                name: "ខ្មៅដៃ HB",
                category: "Household",
                price: 1.20,
                stock: 75,
                image: "images/products/P-096.webp"
            },
            {
                id: "P-097",
                name: "ជ័រលុប",
                category: "Household",
                price: 0.50,
                stock: 100,
                image: "images/products/P-097.webp"
            },
            {
                id: "P-098",
                name: "ប្រអប់ដាក់ប៊ិច",
                category: "Household",
                price: 2.50,
                stock: 35,
                image: "images/products/P-098.webp"
            },
            {
                id: "P-099",
                name: "ព្រីភ្លើង 3 រន្ធ",
                category: "Household",
                price: 5.50,
                stock: 15,
                image: "images/products/P-099.webp"
            },
            {
                id: "P-100",
                name: "កន្សែង Microfiber",
                category: "Household",
                price: 2.30,
                stock: 45,
                image: "images/products/P-100.webp"
            }

        ];

        /* =========================================================
           STATE
        ========================================================= */

        let cart = [];
        let currentCategory = "All";
        let searchText = "";
        let currentInvoice = null;
        let orderSubmitted = false;


        /* =========================================================
           LOAD CART
        ========================================================= */

        try {

            cart =
                JSON.parse(
                    localStorage.getItem(
                        "khmerShopCart"
                    )
                ) || [];

        } catch (error) {

            cart = [];

        }


        /* =========================================================
           ELEMENTS
        ========================================================= */

        const productGrid =
            document.getElementById("productGrid");

        const search =
            document.getElementById("search");

        const sort =
            document.getElementById("sort");

        const productCount =
            document.getElementById("productCount");

        const empty =
            document.getElementById("empty");

        const cartItems =
            document.getElementById("cartItems");

        const cartCount =
            document.getElementById("cartCount");

        const mobileCartCount =
            document.getElementById("mobileCartCount");

        const subtotal =
            document.getElementById("subtotal");

        const total =
            document.getElementById("total");

        const mobileCartTotal =
            document.getElementById("mobileCartTotal");

        const cartElement =
            document.getElementById("cart");

        const overlay =
            document.getElementById("overlay");

        const toast =
            document.getElementById("toast");


        /* =========================================================
           IMAGE FALLBACK
        ========================================================= */

        function imageError(img) {

            img.onerror = null;

            img.src =
                "https://placehold.co/700x700/f8fafc/6366f1?text=KHMER+SHOP";

        }


        /* =========================================================
           SAVE CART
        ========================================================= */

        function saveCart() {

            localStorage.setItem(
                "khmerShopCart",
                JSON.stringify(cart)
            );

        }


        /* =========================================================
           TOAST
        ========================================================= */

        let toastTimer;

        function showToast(message) {

            toast.textContent = message;

            toast.classList.add("show");

            clearTimeout(toastTimer);

            toastTimer =
                setTimeout(
                    () => {

                        toast.classList.remove("show");

                    },
                    2200
                );

        }


        /* =========================================================
           RENDER PRODUCTS
        ========================================================= */

        function renderProducts() {

            let result =
                products.filter(
                    product => {

                        const categoryMatch =
                            currentCategory === "All" ||
                            product.category === currentCategory;

                        const searchMatch =
                            product.name
                            .toLowerCase()
                            .includes(
                                searchText.toLowerCase()
                            );

                        return (
                            categoryMatch &&
                            searchMatch
                        );

                    }
                );


            if (sort.value === "low") {

                result.sort(
                    (a, b) =>
                    a.price - b.price
                );

            }


            if (sort.value === "high") {

                result.sort(
                    (a, b) =>
                    b.price - a.price
                );

            }


            if (sort.value === "name") {

                result.sort(
                    (a, b) =>
                    a.name.localeCompare(
                        b.name,
                        "km"
                    )
                );

            }


            productGrid.innerHTML = "";

            productCount.textContent =
                `${result.length} Products`;


            if (result.length === 0) {

                empty.style.display =
                    "block";

                return;

            }


            empty.style.display =
                "none";


            result.forEach(
                product => {

                    const card =
                        document.createElement("div");

                    card.className =
                        "product-card";


                    let stockClass = "";

                    if (product.stock <= 0) {

                        stockClass = "out";

                    } else if (product.stock <= 10) {

                        stockClass = "low";

                    }


                    const stockText =
                        product.stock > 0 ?
                        `ស្តុក ${product.stock}` :
                        "អស់ស្តុក";


                    card.innerHTML = `

                <div class="product-image">

                    <span class="product-category">
                        ${product.category}
                    </span>

                    <img
                        src="${product.image}"
                        alt="${product.name}"
                        loading="lazy"
                        onerror="imageError(this)"
                    >

                    <span class="stock ${stockClass}">
                        ${stockText}
                    </span>

                </div>

                <div class="product-content">

                    <div class="product-id">
                        ${product.id}
                    </div>

                    <div class="product-name">
                        ${product.name}
                    </div>

                    <div class="product-bottom">

                        <div class="price">
                            $${product.price.toFixed(2)}
                        </div>

                        <button
                            class="add-btn"
                            ${product.stock <= 0 ? "disabled" : ""}
                            data-id="${product.id}"
                        >
                            ${product.stock <= 0
                            ? "អស់ស្តុក"
                            : "🛒 បន្ថែម"
                        }
                        </button>

                    </div>

                </div>

            `;


                    productGrid.appendChild(card);

                }
            );

        }


        /* =========================================================
           PRODUCT BUTTON
        ========================================================= */

        productGrid.addEventListener(
            "click",
            function(event) {

                const button =
                    event.target.closest(".add-btn");

                if (!button) return;

                const id =
                    button.dataset.id;

                addToCart(id);

            }
        );


        /* =========================================================
           ADD CART
        ========================================================= */

        function addToCart(id) {

            const product =
                products.find(
                    p => p.id === id
                );


            if (!product) return;


            if (product.stock <= 0) {

                showToast(
                    "❌ ទំនិញអស់ស្តុក!"
                );

                return;

            }


            const existing =
                cart.find(
                    item => item.id === id
                );


            if (existing) {

                if (
                    existing.qty >=
                    product.stock
                ) {

                    showToast(
                        "⚠️ មិនអាចបន្ថែមលើសស្តុក!"
                    );

                    return;

                }

                existing.qty++;

            } else {

                cart.push({

                    id: product.id,

                    name: product.name,

                    category: product.category,

                    price: product.price,

                    stock: product.stock,

                    image: product.image,

                    qty: 1

                });

            }


            saveCart();

            updateCart();

            showToast(
                `✅ បានបន្ថែម ${product.name}`
            );

        }


        /* =========================================================
           UPDATE CART
        ========================================================= */

        function updateCart() {

            cartItems.innerHTML = "";

            let totalQty = 0;

            let totalPrice = 0;


            if (cart.length === 0) {

                cartItems.innerHTML = `

            <div
                style="
                    text-align:center;
                    padding:70px 10px;
                    color:#9ca3af;
                "
            >

                <div
                    style="
                        font-size:55px;
                        margin-bottom:10px;
                    "
                >
                    🛒
                </div>

                <p>
                    កន្ត្រកទំនិញទទេ
                </p>

                <small>
                    ចុច "បន្ថែម" លើទំនិញ
                    ដើម្បីចាប់ផ្តើម
                </small>

            </div>

        `;

            }


            cart.forEach(
                item => {

                    totalQty += item.qty;

                    totalPrice +=
                        item.price *
                        item.qty;


                    const div =
                        document.createElement("div");

                    div.className =
                        "cart-item";


                    div.innerHTML = `

                <div class="cart-item-image">

                    <img
                        src="${item.image}"
                        alt="${item.name}"
                        onerror="imageError(this)"
                    >

                </div>

                <div class="cart-info">

                    <div class="cart-name">
                        ${item.name}
                    </div>

                    <div class="cart-price">
                        $${item.price.toFixed(2)}
                    </div>

                    <div class="quantity">

                        <button
                            onclick="
                                changeQty(
                                    '${item.id}',
                                    -1
                                )
                            "
                        >
                            −
                        </button>

                        <strong>
                            ${item.qty}
                        </strong>

                        <button
                            onclick="
                                changeQty(
                                    '${item.id}',
                                    1
                                )
                            "
                        >
                            +
                        </button>

                        <button
                            class="delete-item"
                            onclick="
                                removeFromCart(
                                    '${item.id}'
                                )
                            "
                        >
                            🗑️
                        </button>

                    </div>

                </div>

            `;


                    cartItems.appendChild(div);

                }
            );


            cartCount.textContent =
                totalQty;

            mobileCartCount.textContent =
                totalQty;

            subtotal.textContent =
                `$${totalPrice.toFixed(2)}`;

            total.textContent =
                `$${totalPrice.toFixed(2)}`;

            mobileCartTotal.textContent =
                `$${totalPrice.toFixed(2)}`;

        }


        /* =========================================================
           CHANGE QTY
        ========================================================= */

        function changeQty(id, amount) {

            const item =
                cart.find(
                    item => item.id === id
                );

            if (!item) return;


            const product =
                products.find(
                    p => p.id === id
                );


            item.qty += amount;


            if (item.qty <= 0) {

                cart =
                    cart.filter(
                        item =>
                        item.id !== id
                    );

            } else if (
                product &&
                item.qty > product.stock
            ) {

                item.qty =
                    product.stock;

                showToast(
                    "⚠️ ដល់ចំនួនស្តុកអតិបរមា!"
                );

            }


            saveCart();

            updateCart();

        }


        /* =========================================================
           REMOVE
        ========================================================= */

        function removeFromCart(id) {

            cart =
                cart.filter(
                    item =>
                    item.id !== id
                );

            saveCart();

            updateCart();

            showToast(
                "🗑️ បានលុបទំនិញ"
            );

        }


        /* =========================================================
           CATEGORY
        ========================================================= */

        document
            .getElementById("categories")
            .addEventListener(
                "click",
                function(event) {

                    const button =
                        event.target.closest(
                            ".category-btn"
                        );

                    if (!button) return;


                    document
                        .querySelectorAll(".category-btn")
                        .forEach(
                            btn =>
                            btn.classList.remove("active")
                        );


                    button.classList.add("active");


                    currentCategory =
                        button.dataset.category;


                    renderProducts();

                }
            );


        /* =========================================================
           SEARCH
        ========================================================= */

        search.addEventListener(
            "input",
            function() {

                searchText =
                    this.value;

                renderProducts();

            }
        );


        /* =========================================================
           SORT
        ========================================================= */

        sort.addEventListener(
            "change",
            renderProducts
        );


        /* =========================================================
           OPEN CART
        ========================================================= */

        function openCart() {

            cartElement.classList.add("show");

            overlay.classList.add("show");

            document.body.classList.add("no-scroll");

        }


        /* =========================================================
           CLOSE CART
        ========================================================= */

        function closeCart() {

            cartElement.classList.remove("show");

            overlay.classList.remove("show");

            document.body.classList.remove("no-scroll");

        }


        /* =========================================================
           INVOICE NUMBER
        ========================================================= */

        function generateInvoiceNumber() {

            const now =
                new Date();


            const date =
                now.getFullYear() +
                String(
                    now.getMonth() + 1
                ).padStart(2, "0") +
                String(
                    now.getDate()
                ).padStart(2, "0");


            const random =
                Math.floor(
                    1000 +
                    Math.random() * 9000
                );


            return `INV-${date}-${random}`;

        }


        /* =========================================================
           TOTAL
        ========================================================= */

        function getCartTotal() {

            return cart.reduce(
                (sum, item) =>
                sum +
                item.price *
                item.qty,
                0
            );

        }


        /* =========================================================
           CREATE INVOICE
        ========================================================= */

        function createInvoice() {

            const invoiceNumber =
                generateInvoiceNumber();


            const totalUSD =
                getCartTotal();


            const totalKHR =
                Math.round(
                    totalUSD *
                    EXCHANGE_RATE
                );


            const date =
                new Date().toLocaleString(
                    "km-KH", {
                        dateStyle: "medium",
                        timeStyle: "short"
                    }
                );


            let itemsHTML = "";


            cart.forEach(
                item => {

                    const lineTotal =
                        item.price *
                        item.qty;


                    itemsHTML += `

                <tr>

                    <td>

                        <div class="invoice-product">

                            <img
                                class="invoice-product-image"
                                src="${item.image}"
                                alt="${item.name}"
                                onerror="imageError(this)"
                            >

                            <span>
                                ${item.name}
                            </span>

                        </div>

                    </td>

                    <td>
                        ${item.qty}
                    </td>

                    <td>
                        $${item.price.toFixed(2)}
                    </td>

                    <td>
                        $${lineTotal.toFixed(2)}
                    </td>

                </tr>

            `;

                }
            );


            return {

                invoiceNumber,

                date,

                totalUSD,

                totalKHR,

                itemsHTML

            };

        }


        /* =========================================================
           CHECKOUT
        ========================================================= */

        function checkout() {

            if (cart.length === 0) {

                showToast(
                    "🛒 សូមបន្ថែមទំនិញជាមុនសិន!"
                );

                return;

            }


            orderSubmitted = false;


            document.getElementById(
                "orderStatus"
            ).textContent = "";


            const confirmButton =
                document.querySelector(
                    ".btn-confirm-order"
                );


            confirmButton.disabled =
                false;

            confirmButton.textContent =
                "✅ បញ្ជាក់ & ផ្ញើ Order";


            currentInvoice =
                createInvoice();


            document.getElementById(
                    "invoiceNumber"
                ).textContent =
                currentInvoice.invoiceNumber;


            document.getElementById(
                    "invoiceDate"
                ).textContent =
                currentInvoice.date;


            document.getElementById(
                    "invoiceItems"
                ).innerHTML =
                currentInvoice.itemsHTML;


            document.getElementById(
                    "invoiceUSD"
                ).textContent =
                `$${currentInvoice.totalUSD.toFixed(2)}`;


            document.getElementById(
                    "invoiceKHR"
                ).textContent =
                `៛${currentInvoice.totalKHR.toLocaleString()}`;


            document.getElementById(
                    "qrAmount"
                ).textContent =
                `$${currentInvoice.totalUSD.toFixed(2)}`;


            generateQR(
                ACLEDA_QR_STRING
            );


            document.getElementById(
                "invoiceModal"
            ).classList.add("show");


            closeCart();

        }


        /* =========================================================
           CUSTOMER
        ========================================================= */

        function getCustomerData() {

            const name =
                document
                .getElementById("customerName")
                .value
                .trim();


            const phone =
                document
                .getElementById("customerPhone")
                .value
                .trim();


            const address =
                document
                .getElementById("customerAddress")
                .value
                .trim();


            if (
                !name ||
                !phone ||
                !address
            ) {

                showToast(
                    "⚠️ សូមបំពេញឈ្មោះ លេខទូរស័ព្ទ និងអាសយដ្ឋាន!"
                );

                return null;

            }


            if (
                phone.replace(/\D/g, "").length < 8
            ) {

                showToast(
                    "⚠️ លេខទូរស័ព្ទមិនត្រឹមត្រូវ!"
                );

                return null;

            }


            return {
                name,
                phone,
                address
            };

        }


        /* =========================================================
           CONFIRM ORDER
        ========================================================= */

        async function confirmOrder() {

            if (
                !currentInvoice ||
                orderSubmitted
            ) {
                return;
            }


            const customer =
                getCustomerData();


            if (!customer) return;


            const btn =
                document.querySelector(
                    ".btn-confirm-order"
                );


            btn.disabled = true;

            btn.textContent =
                "⏳ កំពុងផ្ញើ Order...";


            document.getElementById(
                    "orderStatus"
                ).textContent =
                "កំពុងបញ្ជូនទៅហាង...";


            /*
                IMPORTANT:
                កុំប្រើ cart បន្ទាប់ពី clearCart
                សម្រាប់ Telegram។
                ដូច្នេះរក្សាទុក snapshot។
            */

            currentInvoice.customer =
                customer;


            currentInvoice.orderItems =
                cart.map(
                    item => ({
                        id: item.id,
                        name: item.name,
                        qty: item.qty,
                        price: item.price,
                        total: item.price *
                            item.qty,
                        image: item.image
                    })
                );


            const ok =
                await sendToGoogleSheets(
                    customer
                );


            if (ok) {

                orderSubmitted = true;


                document.getElementById(
                        "orderStatus"
                    ).textContent =
                    "✅ Order បានផ្ញើទៅហាងរួចរាល់!";


                showToast(
                    "✅ Order បានផ្ញើទៅហាងរួចរាល់!"
                );


                btn.textContent =
                    "✅ Order បានផ្ញើរួច";


                localStorage.removeItem(
                    "khmerShopCart"
                );


                cart = [];

                updateCart();

            } else {

                btn.disabled = false;

                btn.textContent =
                    "✅ បញ្ជាក់ & ផ្ញើ Order";


                document.getElementById(
                        "orderStatus"
                    ).textContent =
                    "❌ ផ្ញើ Order មិនបានជោគជ័យ។ សូមសាកល្បងម្តងទៀត។";


                showToast(
                    "❌ មិនអាចផ្ញើ Order ទៅហាងបានទេ!"
                );

            }

        }


        /* =========================================================
           QR
        ========================================================= */

        function generateQR(qrString) {

            const qrContainer =
                document.getElementById(
                    "qrCode"
                );


            qrContainer.innerHTML = "";


            if (
                typeof QRCode ===
                "undefined"
            ) {

                qrContainer.innerHTML = `

            <p style="color:red">
                មិនអាច Load QR Library បានទេ
            </p>

        `;

                return;

            }


            new QRCode(
                qrContainer, {

                    text: qrString,

                    width: 220,

                    height: 220,

                    correctLevel: QRCode.CorrectLevel.H

                }
            );

        }


        /* =========================================================
           CLOSE INVOICE
        ========================================================= */

        function closeInvoice() {

            document
                .getElementById(
                    "invoiceModal"
                )
                .classList.remove(
                    "show"
                );

        }


        /* =========================================================
           PRINT
        ========================================================= */

        function printInvoice() {

            window.print();

        }


        /* =========================================================
           TELEGRAM MESSAGE
        ========================================================= */

        function buildTelegramMessage() {

            if (!currentInvoice) {

                return "";

            }


            let message =
                `🛍️ ${SHOP_NAME}\n`;


            message +=
                `━━━━━━━━━━━━━━━━━━\n`;


            message +=
                `🧾 Invoice: ${currentInvoice.invoiceNumber}\n`;


            message +=
                `📅 ${currentInvoice.date}\n`;


            message +=
                `━━━━━━━━━━━━━━━━━━\n`;


            const customer =
                currentInvoice.customer || {

                    name: "-",
                    phone: "-",
                    address: "-"

                };


            message +=
                `👤 ភ្ញៀវ: ${customer.name}\n`;


            message +=
                `📞 ទូរស័ព្ទ: ${customer.phone}\n`;


            message +=
                `📍 អាសយដ្ឋាន: ${customer.address}\n`;


            message +=
                `━━━━━━━━━━━━━━━━━━\n`;


            message +=
                `📦 ORDER\n`;


            /*
                Use orderItems snapshot.
                ដើម្បីឱ្យ Telegram នៅតែមាន
                ទំនិញ ទោះបី cart ត្រូវបាន clear។
            */

            const orderItems =
                currentInvoice.orderItems ||
                cart;


            orderItems.forEach(
                (
                    item,
                    index
                ) => {

                    const line =
                        item.price *
                        item.qty;


                    message +=
                        `${index + 1}. ${item.name}\n`;


                    message +=
                        `   ${item.qty} × $${item.price.toFixed(2)} = $${line.toFixed(2)}\n`;

                }
            );


            message +=
                `━━━━━━━━━━━━━━━━━━\n`;


            message +=
                `💵 USD: $${currentInvoice.totalUSD.toFixed(2)}\n`;


            message +=
                `🇰🇭 KHR: ៛${currentInvoice.totalKHR.toLocaleString()}\n`;


            message +=
                `💳 Payment: KHQR\n`;


            message +=
                `━━━━━━━━━━━━━━━━━━\n`;


            message +=
                `📲 Telegram: @${TELEGRAM_USERNAME}`;


            return message;

        }


        /* =========================================================
           TELEGRAM
        ========================================================= */

        function sendTelegram() {

            if (!currentInvoice) {

                showToast(
                    "❌ មិនមាន Invoice!"
                );

                return;

            }


            /*
                បើ Customer មិនទាន់បំពេញ
                ក៏អាចបើក Telegram បាន។
            */


            if (
                !currentInvoice.customer
            ) {

                const customer =
                    getCustomerData();


                if (!customer) {

                    return;

                }


                currentInvoice.customer =
                    customer;


                currentInvoice.orderItems =
                    cart.map(
                        item => ({
                            id: item.id,
                            name: item.name,
                            qty: item.qty,
                            price: item.price,
                            total: item.price *
                                item.qty,
                            image: item.image
                        })
                    );

            }


            const message =
                buildTelegramMessage();


            const url =
                `https://t.me/${TELEGRAM_USERNAME}?text=${encodeURIComponent(
                    message
                )}`;


            window.open(
                url,
                "_blank"
            );

        }


        /* =========================================================
           GOOGLE SHEETS
        ========================================================= */

        async function sendToGoogleSheets(customer) {
            // Keep the existing function name to avoid changing the checkout flow.
            // Orders are now stored securely by the PHP/MySQL backend.
            if (!currentInvoice || !customer) return false;

            const items = currentInvoice.orderItems || cart.map(item => ({
                id: item.id,
                name: item.name,
                qty: item.qty,
                price: item.price,
                total: item.price * item.qty
            }));

            const payload = {
                invoice: currentInvoice.invoiceNumber,
                date: currentInvoice.date,
                customer,
                totalUSD: Number(currentInvoice.totalUSD),
                totalKHR: Number(currentInvoice.totalKHR),
                items: items.map(item => ({
                    id: String(item.id),
                    name: String(item.name),
                    qty: Number(item.qty),
                    price: Number(item.price),
                    total: Number(item.price) * Number(item.qty)
                }))
            };

            try {
                const response = await fetch(PHP_ORDER_API, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify(payload)
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    console.error("PHP order API:", result.message || response.statusText);
                    return false;
                }
                currentInvoice.savedOrderId = result.orderId;
                return true;
            } catch (error) {
                console.error("PHP/MySQL order save error:", error);
                return false;
            }
        }


        /* =========================================================
           ESC
        ========================================================= */

        document.addEventListener(
            "keydown",
            function(event) {

                if (
                    event.key ===
                    "Escape"
                ) {

                    closeCart();

                    closeInvoice();

                }

            }
        );


        /* =========================================================
           CLICK OUTSIDE INVOICE
        ========================================================= */

        document
            .getElementById("invoiceModal")
            .addEventListener(
                "click",
                function(event) {

                    if (
                        event.target ===
                        this
                    ) {

                        closeInvoice();

                    }

                }
            );


        /* =========================================================
           SWIPE CART MOBILE
        ========================================================= */

        let touchStartX = 0;


        cartElement.addEventListener(
            "touchstart",
            function(event) {

                touchStartX =
                    event.changedTouches[0].screenX;

            }, {
                passive: true
            }
        );


        cartElement.addEventListener(
            "touchend",
            function(event) {

                const touchEndX =
                    event.changedTouches[0].screenX;


                const diff =
                    touchEndX -
                    touchStartX;


                if (
                    diff > 100
                ) {

                    closeCart();

                }

            }, {
                passive: true
            }
        );


        /* =========================================================
           INITIALIZE
        ========================================================= */

        renderProducts();

        updateCart();
    </script>

</body>

</html>
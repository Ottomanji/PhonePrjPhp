<?php
session_start();

// --- DATABASE CONNECTION ---
$host = 'localhost';
$db = 'mobile_store';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// --- AUTHENTICATION ---
$auth_error = '';

if (isset($_POST['register_submit'])) {
    $name = trim($_POST['reg_name']);
    $email = trim($_POST['reg_email']);
    $pass_plain = $_POST['reg_pass'];
    $hashed = password_hash($pass_plain, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
    try {
        $stmt->execute([$name, $email, $hashed]);
        $_SESSION['user_id'] = $pdo->lastInsertId();
        $_SESSION['user_name'] = $name;
        header("Location: ?page=dashboard");
        exit;
    } catch (Exception $e) {
        $auth_error = "Email already in use.";
    }
}

if (isset($_POST['login_submit'])) {
    $email = trim($_POST['login_email']);
    $plain = $_POST['login_pass'];
    $stmt = $pdo->prepare("SELECT id, name, password FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if ($u && password_verify($plain, $u['password'])) {
        $_SESSION['user_id'] = $u['id'];
        $_SESSION['user_name'] = $u['name'];
        header("Location: ?page=dashboard");
        exit;
    } else {
        $auth_error = "Invalid credentials.";
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: ?page=home");
    exit;
}

$page = $_GET['page'] ?? 'home';
$user_id = $_SESSION['user_id'] ?? null;
$user_name = $_SESSION['user_name'] ?? 'MEMBER';
$view_id = (int) ($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';
$open_cart = isset($_GET['open_cart']);

// --- CART LOGIC ---
if ($action && $user_id) {
    $p_id = (int) ($_GET['id'] ?? 0);
    $qty = (int) ($_GET['qty'] ?? 1);

    if ($action == 'add' && $p_id) {
        $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE quantity = quantity + 1");
        $stmt->execute([$user_id, $p_id]);
        header("Location: ?page=$page" . ($view_id && $page == 'product_view' ? "&id=$view_id" : "") . "&open_cart=1");
        exit;
    }
    if ($action == 'remove' && $p_id) {
        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$user_id, $p_id]);
        header("Location: ?page=$page" . ($view_id && $page == 'product_view' ? "&id=$view_id" : "") . "&open_cart=1");
        exit;
    }
    if ($action == 'update_qty' && $p_id && $qty > 0) {
        $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$qty, $user_id, $p_id]);
        header("Location: ?page=$page" . ($view_id && $page == 'product_view' ? "&id=$view_id" : "") . "&open_cart=1");
        exit;
    }
    if ($action == 'wishlist_add' && $p_id) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $p_id]);
        header("Location: ?page=$page" . ($view_id ? "&id=$view_id" : ""));
        exit;
    }
    if ($action == 'wishlist_remove' && $p_id) {
        $stmt = $pdo->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$user_id, $p_id]);
        header("Location: ?page=wishlist");
        exit;
    }
}

// Handle checkout
if (isset($_POST['checkout_submit']) && $user_id && !empty($cart_items ?? [])) {
    // Calculate cart for checkout
    $stmt = $pdo->prepare("SELECT c.*, p.name, p.price FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
    $stmt->execute([$user_id]);
    $ci = $stmt->fetchAll();
    $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $ci));

    if ($total > 0) {
        $pdo->prepare("INSERT INTO orders (user_id, total, status) VALUES (?, ?, 'Processing')")->execute([$user_id, $total]);
        $order_id = $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        foreach ($ci as $i)
            $ins->execute([$order_id, $i['product_id'], $i['quantity'], $i['price']]);
        $pdo->prepare("DELETE FROM cart WHERE user_id = ?")->execute([$user_id]);
        header("Location: ?page=orders&success=1");
        exit;
    }
}

// --- DATA FETCHING ---
$cart_items = [];
$total_price = 0;
$total_qty = 0;
$wishlist_ids = [];

if ($user_id) {
    $stmt = $pdo->prepare("SELECT c.*, p.name, p.price, p.image FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ? ORDER BY c.id DESC");
    $stmt->execute([$user_id]);
    $cart_items = $stmt->fetchAll();
    foreach ($cart_items as $item) {
        $total_price += $item['price'] * $item['quantity'];
        $total_qty += $item['quantity'];
    }
    $wstmt = $pdo->prepare("SELECT product_id FROM wishlist WHERE user_id = ?");
    $wstmt->execute([$user_id]);
    $wishlist_ids = array_column($wstmt->fetchAll(), 'product_id');
}

// Fetch single product for product_view
$product = null;
if ($page == 'product_view' && $view_id) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$view_id]);
    $product = $stmt->fetch();
    // Related products
    $rel = $pdo->prepare("SELECT * FROM products WHERE id != ? ORDER BY RAND() LIMIT 4");
    $rel->execute([$view_id]);
    $related = $rel->fetchAll();
}

// Search / filter
$search = trim($_GET['q'] ?? '');
$category = trim($_GET['cat'] ?? '');
$sort = $_GET['sort'] ?? 'default';

$shop_sql = "SELECT * FROM products WHERE 1=1";
$params = [];
if ($search) {
    $shop_sql .= " AND name LIKE ?";
    $params[] = "%$search%";
}
if ($category) {
    $shop_sql .= " AND category = ?";
    $params[] = $category;
}
if ($sort == 'price_asc')
    $shop_sql .= " ORDER BY price ASC";
elseif ($sort == 'price_desc')
    $shop_sql .= " ORDER BY price DESC";
elseif ($sort == 'newest')
    $shop_sql .= " ORDER BY id DESC";

$products = [];
if ($page == 'shop' || $page == 'home') {
    $pstmt = $pdo->prepare($shop_sql);
    $pstmt->execute($params);
    $products = $pstmt->fetchAll();
}

// Categories for filter
$categories = [];
try {
    $cats = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != ''")->fetchAll();
    $categories = array_column($cats, 'category');
} catch (Exception $e) {
}

// Orders
$orders = [];
if ($page == 'orders' && $user_id) {
    $stmt = $pdo->prepare("SELECT o.*, COUNT(oi.id) as item_count FROM orders o LEFT JOIN order_items oi ON o.id = oi.order_id WHERE o.user_id = ? GROUP BY o.id ORDER BY o.created_at DESC");
    $stmt->execute([$user_id]);
    $orders = $stmt->fetchAll();
}

// Wishlist page
$wishlist_products = [];
if ($page == 'wishlist' && $user_id) {
    $stmt = $pdo->prepare("SELECT p.* FROM wishlist w JOIN products p ON w.product_id = p.id WHERE w.user_id = ?");
    $stmt->execute([$user_id]);
    $wishlist_products = $stmt->fetchAll();
}

// Stats for dashboard
$stats = [];
if ($page == 'dashboard' && $user_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_orders, COALESCE(SUM(total),0) as total_spent FROM orders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHONO // PERFORMANCE</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,300;0,700;0,900;1,900&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --ink: #0a0a0a;
            --paper: #f8f6f1;
            --white: #ffffff;
            --accent: #c8382b;
            --accent2: #1a5cff;
            --muted: #8a8680;
            --border: #e2dfd9;
            --card-bg: #ffffff;
            --success: #2d7a4f;

            --font-display: 'Barlow Condensed', sans-serif;
            --font-body: 'DM Sans', sans-serif;

            --nav-h: 64px;
            --radius: 2px;
            --shadow: 0 2px 20px rgba(10, 10, 10, 0.08);
        }

        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            background: var(--paper);
            color: var(--ink);
            font-family: var(--font-body);
            font-size: 15px;
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* ── TYPOGRAPHY ── */
        h1,
        h2,
        h3,
        h4,
        h5,
        .logo,
        .display {
            font-family: var(--font-display);
            font-style: italic;
            text-transform: uppercase;
            letter-spacing: -0.5px;
            line-height: 0.92;
        }

        /* ── SCROLLBAR ── */
        ::-webkit-scrollbar {
            width: 5px;
        }

        ::-webkit-scrollbar-track {
            background: var(--paper);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--ink);
            border-radius: 10px;
        }

        /* ── NAV ── */
        nav {
            height: var(--nav-h);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
            background: var(--ink);
            color: var(--white);
            position: sticky;
            top: 0;
            z-index: 900;
            border-bottom: 1px solid #222;
        }

        .logo {
            font-size: 26px;
            font-weight: 900;
            color: var(--white);
            text-decoration: none;
            letter-spacing: -1px;
        }

        .logo span {
            color: var(--accent);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .nav-links a {
            color: #aaa;
            text-decoration: none;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: color .2s;
            padding: 4px 0;
            position: relative;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent);
            transition: width .2s;
        }

        .nav-links a:hover,
        .nav-links a.active {
            color: var(--white);
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .cart-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--accent);
            color: var(--white);
            padding: 8px 18px;
            border: none;
            cursor: pointer;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: background .2s, transform .1s;
            border-radius: var(--radius);
        }

        .cart-btn:hover {
            background: #a82e24;
            transform: translateY(-1px);
        }

        .search-form {
            display: flex;
            align-items: center;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: var(--radius);
            overflow: hidden;
            height: 36px;
        }

        .search-form input {
            background: transparent;
            border: none;
            outline: none;
            color: var(--white);
            padding: 0 12px;
            font-family: var(--font-body);
            font-size: 13px;
            width: 160px;
        }

        .search-form input::placeholder {
            color: #555;
            text-transform: none;
        }

        .search-form button {
            background: transparent;
            border: none;
            color: #777;
            padding: 0 12px;
            cursor: pointer;
            transition: color .2s;
        }

        .search-form button:hover {
            color: var(--white);
        }

        /* ── CART SIDEBAR ── */
        .overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 1099;
            opacity: 0;
            pointer-events: none;
            backdrop-filter: blur(3px);
            transition: opacity .35s;
        }

        .overlay.active {
            opacity: 1;
            pointer-events: all;
        }

        .sidebar {
            position: fixed;
            top: 0;
            right: -480px;
            width: min(480px, 100vw);
            height: 100vh;
            background: var(--white);
            z-index: 1100;
            transition: right .4s cubic-bezier(.77, 0, .18, 1);
            display: flex;
            flex-direction: column;
            box-shadow: -4px 0 40px rgba(0, 0, 0, 0.15);
        }

        .sidebar.open {
            right: 0;
        }

        .sidebar-head {
            padding: 24px 28px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sidebar-head h2 {
            font-size: 28px;
        }

        .sidebar-close {
            width: 36px;
            height: 36px;
            border: 1px solid var(--border);
            border-radius: 50%;
            background: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: .2s;
        }

        .sidebar-close:hover {
            background: var(--ink);
            color: var(--white);
            border-color: var(--ink);
        }

        .sidebar-body {
            flex: 1;
            overflow-y: auto;
            padding: 20px 28px;
        }

        .cart-item {
            display: flex;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid var(--border);
            align-items: flex-start;
        }

        .cart-item-img {
            width: 72px;
            height: 72px;
            object-fit: contain;
            background: #f5f5f5;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .cart-item-info {
            flex: 1;
        }

        .cart-item-name {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 15px;
            text-transform: uppercase;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .cart-item-price {
            font-size: 13px;
            color: var(--muted);
        }

        .qty-control {
            display: flex;
            align-items: center;
            gap: 0;
            margin-top: 8px;
            border: 1px solid var(--border);
            width: fit-content;
            border-radius: 2px;
            overflow: hidden;
        }

        .qty-btn {
            width: 28px;
            height: 28px;
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            transition: .15s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qty-btn:hover {
            background: var(--ink);
            color: var(--white);
        }

        .qty-num {
            padding: 0 10px;
            font-size: 13px;
            font-weight: 600;
            border-left: 1px solid var(--border);
            border-right: 1px solid var(--border);
            line-height: 28px;
        }

        .cart-item-del {
            background: none;
            border: none;
            color: #ccc;
            cursor: pointer;
            font-size: 14px;
            padding: 4px;
            transition: .2s;
            align-self: center;
        }

        .cart-item-del:hover {
            color: var(--accent);
        }

        .sidebar-foot {
            padding: 20px 28px;
            border-top: 2px solid var(--ink);
        }

        .cart-total {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .cart-total span:first-child {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--muted);
        }

        .cart-total span:last-child {
            font-family: var(--font-display);
            font-weight: 900;
            font-size: 28px;
        }

        /* ── BUTTONS ── */
        .btn-primary {
            display: block;
            width: 100%;
            background: var(--ink);
            color: var(--white);
            padding: 16px 28px;
            border: 2px solid var(--ink);
            font-family: var(--font-display);
            font-weight: 900;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: .2s;
            border-radius: var(--radius);
        }

        .btn-primary:hover {
            background: transparent;
            color: var(--ink);
        }

        .btn-accent {
            display: block;
            background: var(--accent);
            color: var(--white);
            padding: 16px 28px;
            border: 2px solid var(--accent);
            font-family: var(--font-display);
            font-weight: 900;
            font-size: 16px;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: .2s;
            border-radius: var(--radius);
        }

        .btn-accent:hover {
            background: transparent;
            color: var(--accent);
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            color: var(--ink);
            padding: 12px 22px;
            border: 1.5px solid var(--ink);
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            text-decoration: none;
            transition: .2s;
            border-radius: var(--radius);
        }

        .btn-ghost:hover {
            background: var(--ink);
            color: var(--white);
        }

        .btn-ghost.white {
            border-color: rgba(255, 255, 255, .4);
            color: var(--white);
        }

        .btn-ghost.white:hover {
            background: var(--white);
            color: var(--ink);
        }

        /* ── INPUTS ── */
        .input {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            background: var(--white);
            color: var(--ink);
            font-family: var(--font-body);
            font-size: 14px;
            outline: none;
            transition: .2s;
        }

        .input:focus {
            border-color: var(--ink);
        }

        .input::placeholder {
            color: var(--muted);
            text-transform: none;
        }

        .input-group {
            margin-bottom: 16px;
        }

        .input-label {
            display: block;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--muted);
            margin-bottom: 6px;
        }

        /* ── HERO ── */
        .hero {
            position: relative;
            height: 88vh;
            min-height: 600px;
            background: #0a0a0a;
            display: flex;
            align-items: flex-end;
            overflow: hidden;
        }

        .hero-bg {
            position: absolute;
            inset: 0;
            background-image: url('https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?q=80&w=2080');
            background-size: cover;
            background-position: center 30%;
            opacity: .35;
            transform: scale(1.05);
            animation: zoomOut 12s ease forwards;
        }

        @keyframes zoomOut {
            to {
                transform: scale(1);
            }
        }

        .hero-noise {
            position: absolute;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            padding: 0 60px 70px;
            max-width: 860px;
        }

        .hero-eyebrow {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 4px;
            color: var(--accent);
            margin-bottom: 20px;
            opacity: 0;
            animation: fadeUp .6s .2s forwards;
        }

        .hero-title {
            font-size: clamp(72px, 10vw, 140px);
            line-height: .88;
            color: var(--white);
            margin-bottom: 28px;
            opacity: 0;
            animation: fadeUp .7s .4s forwards;
        }

        .hero-title em {
            color: var(--accent);
            font-style: italic;
        }

        .hero-sub {
            font-size: 16px;
            color: rgba(255, 255, 255, .6);
            max-width: 440px;
            line-height: 1.7;
            margin-bottom: 40px;
            opacity: 0;
            animation: fadeUp .7s .6s forwards;
            text-transform: none;
        }

        .hero-actions {
            display: flex;
            gap: 14px;
            opacity: 0;
            animation: fadeUp .7s .8s forwards;
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .hero-scroll {
            position: absolute;
            right: 50px;
            bottom: 50px;
            writing-mode: vertical-rl;
            color: rgba(255, 255, 255, .3);
            font-family: var(--font-display);
            font-size: 11px;
            letter-spacing: 3px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-transform: uppercase;
        }

        .hero-scroll::before {
            content: '';
            width: 1px;
            height: 60px;
            background: rgba(255, 255, 255, .2);
            animation: scrollLine 2s ease-in-out infinite;
        }

        @keyframes scrollLine {

            0%,
            100% {
                transform: scaleY(1)
            }

            50% {
                transform: scaleY(.5)
            }
        }

        /* ── SECTION ── */
        .section {
            padding: 80px 40px;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 48px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border);
        }

        .section-title {
            font-size: clamp(40px, 5vw, 64px);
        }

        .section-sub {
            font-size: 13px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 2px;
            font-family: var(--font-display);
            font-weight: 700;
            margin-top: 8px;
            display: block;
        }

        /* ── PRODUCT GRID ── */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 1px;
            background: var(--border);
        }

        .p-card {
            background: var(--white);
            padding: 28px;
            position: relative;
            transition: box-shadow .3s;
        }

        .p-card:hover {
            box-shadow: 0 8px 40px rgba(0, 0, 0, .1);
            z-index: 1;
        }

        .p-card-badge {
            position: absolute;
            top: 16px;
            left: 16px;
            background: var(--accent);
            color: var(--white);
            font-family: var(--font-display);
            font-weight: 900;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 3px 8px;
            border-radius: 2px;
        }

        .p-card-wish {
            position: absolute;
            top: 14px;
            right: 16px;
            width: 34px;
            height: 34px;
            border: 1px solid var(--border);
            border-radius: 50%;
            background: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
            color: var(--muted);
            font-size: 14px;
            transition: .2s;
        }

        .p-card-wish:hover,
        .p-card-wish.active {
            color: var(--accent);
            border-color: var(--accent);
            background: #fff5f5;
        }

        .p-card-img-wrap {
            aspect-ratio: 1;
            overflow: hidden;
            background: #f8f8f8;
            border-radius: 2px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .p-card img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transition: transform .5s ease;
            padding: 12px;
        }

        .p-card:hover img {
            transform: scale(1.04);
        }

        .p-card-brand {
            font-size: 11px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 2px;
            font-family: var(--font-display);
            font-weight: 700;
            margin-bottom: 4px;
        }

        .p-card-name {
            font-family: var(--font-display);
            font-weight: 900;
            font-size: 20px;
            text-transform: uppercase;
            line-height: 1.1;
            margin-bottom: 8px;
            text-decoration: none;
            color: var(--ink);
            display: block;
        }

        .p-card-price {
            font-family: var(--font-display);
            font-weight: 900;
            font-size: 22px;
            color: var(--ink);
            margin-bottom: 16px;
        }

        .p-card-price .original {
            font-size: 14px;
            color: var(--muted);
            text-decoration: line-through;
            margin-left: 8px;
            font-weight: 400;
        }

        .p-card-actions {
            display: flex;
            gap: 8px;
        }

        .p-card-actions .btn-primary {
            font-size: 13px;
            padding: 12px 16px;
        }

        .p-card-actions .btn-detail {
            width: 42px;
            height: auto;
            border: 1.5px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: var(--ink);
            font-size: 15px;
            transition: .2s;
            flex-shrink: 0;
            border-radius: 2px;
        }

        .p-card-actions .btn-detail:hover {
            border-color: var(--ink);
            background: var(--ink);
            color: var(--white);
        }

        /* ── SHOP PAGE ── */
        .shop-header {
            background: var(--ink);
            padding: 60px 40px 50px;
        }

        .shop-header h1 {
            font-size: clamp(56px, 8vw, 100px);
            color: var(--white);
            margin-bottom: 6px;
        }

        .shop-header p {
            color: var(--muted);
            font-size: 14px;
            text-transform: none;
        }

        .shop-toolbar {
            display: flex;
            gap: 12px;
            align-items: center;
            padding: 20px 40px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 18px;
            border: 1.5px solid var(--border);
            background: var(--white);
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            border-radius: 20px;
            transition: .2s;
            text-decoration: none;
            color: var(--ink);
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: var(--ink);
            color: var(--white);
            border-color: var(--ink);
        }

        .sort-select {
            margin-left: auto;
            padding: 8px 14px;
            border: 1.5px solid var(--border);
            background: var(--white);
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            outline: none;
            cursor: pointer;
            border-radius: 2px;
        }

        .results-count {
            font-size: 12px;
            color: var(--muted);
            font-family: var(--font-display);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 8px 0;
        }

        /* ── PRODUCT VIEW ── */
        .product-view {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            min-height: 80vh;
        }

        .product-gallery {
            background: #f0ede8;
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 40px;
            position: sticky;
            top: var(--nav-h);
            height: calc(100vh - var(--nav-h));
            overflow: hidden;
        }

        .gallery-main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--white);
            border-radius: 2px;
            overflow: hidden;
        }

        .gallery-main img {
            width: 85%;
            height: 85%;
            object-fit: contain;
            transition: transform .4s ease;
        }

        .gallery-main img:hover {
            transform: scale(1.05);
        }

        .gallery-thumbs {
            display: flex;
            gap: 8px;
        }

        .gallery-thumb {
            width: 64px;
            height: 64px;
            background: var(--white);
            border: 2px solid transparent;
            border-radius: 2px;
            cursor: pointer;
            object-fit: contain;
            padding: 4px;
            transition: .2s;
        }

        .gallery-thumb.active,
        .gallery-thumb:hover {
            border-color: var(--ink);
        }

        .product-info {
            padding: 60px 50px;
            overflow-y: auto;
        }

        .product-info .brand {
            font-size: 11px;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 3px;
            font-family: var(--font-display);
            font-weight: 700;
            margin-bottom: 10px;
        }

        .product-info h1 {
            font-size: clamp(36px, 4vw, 60px);
            margin-bottom: 16px;
            line-height: .95;
        }

        .product-info .price {
            font-family: var(--font-display);
            font-weight: 900;
            font-size: 40px;
            color: var(--ink);
            margin-bottom: 30px;
        }

        .product-desc {
            font-size: 15px;
            line-height: 1.75;
            color: #555;
            margin-bottom: 36px;
            text-transform: none;
            border-top: 1px solid var(--border);
            padding-top: 24px;
        }

        .spec-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1px;
            background: var(--border);
            margin-bottom: 36px;
            border: 1px solid var(--border);
        }

        .spec-item {
            background: var(--white);
            padding: 14px 18px;
        }

        .spec-item dt {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--muted);
            font-family: var(--font-display);
            font-weight: 700;
            margin-bottom: 3px;
        }

        .spec-item dd {
            font-weight: 500;
            font-size: 14px;
        }

        .product-cta {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .product-cta .btn-accent {
            flex: 1;
            font-size: 17px;
            padding: 18px;
        }

        .product-cta .btn-ghost {
            padding: 18px 20px;
        }

        .product-meta {
            display: flex;
            gap: 20px;
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-family: var(--font-display);
            font-weight: 700;
        }

        .product-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ── AUTH PAGE ── */
        .auth-wrap {
            min-height: calc(100vh - var(--nav-h));
            display: flex;
        }

        .auth-visual {
            flex: 1;
            background: var(--ink) url('https://plus.unsplash.com/premium_photo-1680985551009-05107cd2752c?fm=jpg&q=60&w=3000&auto=format&fit=crop&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxzZWFyY2h8NXx8dCVDMyVBOWwlQzMlQTlwaG9uZSUyMHBvcnRhYmxlfGVufDB8fDB8fHww') center/cover;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 60px;
            position: relative;
            overflow: hidden;
        }

        .auth-visual::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0, 0, 0, .8), transparent);
        }

        .auth-visual-text {
            position: relative;
            z-index: 1;
            color: var(--white);
        }

        .auth-visual-text h2 {
            font-size: clamp(40px, 5vw, 72px);
            color: var(--white);
        }

        .auth-form {
            width: 480px;
            flex-shrink: 0;
            background: var(--white);
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .auth-form h1 {
            font-size: 48px;
            margin-bottom: 8px;
        }

        .auth-form p {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 36px;
            text-transform: none;
        }

        .auth-toggle {
            font-size: 13px;
            color: var(--muted);
            margin-top: 24px;
        }

        .auth-toggle a {
            color: var(--accent);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .error-msg {
            background: #fff5f5;
            border: 1px solid #fdb;
            border-left: 3px solid var(--accent);
            color: var(--accent);
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 20px;
            border-radius: 2px;
            text-transform: none;
        }

        /* ── DASHBOARD ── */
        .dashboard {
            background: var(--ink);
            min-height: calc(100vh - var(--nav-h));
            color: var(--white);
        }

        .dashboard-hero {
            padding: 80px 40px 60px;
            border-bottom: 1px solid #1a1a1a;
        }

        .dashboard-greeting {
            font-size: 12px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 3px;
            font-family: var(--font-display);
            margin-bottom: 12px;
        }

        .dashboard-name {
            font-size: clamp(48px, 7vw, 90px);
            color: var(--white);
        }

        .dashboard-name span {
            color: var(--accent);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1px;
            background: #1a1a1a;
            margin: 40px 0 0;
        }

        .stat-card {
            background: var(--ink);
            padding: 36px 28px;
        }

        .stat-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 3px;
            color: var(--muted);
            font-family: var(--font-display);
            font-weight: 700;
            margin-bottom: 10px;
        }

        .stat-value {
            font-family: var(--font-display);
            font-weight: 900;
            font-size: 52px;
            line-height: 1;
            color: var(--white);
        }

        .stat-value.accent {
            color: var(--accent);
        }

        .dashboard-nav {
            display: flex;
            gap: 1px;
            background: #111;
            padding: 0 40px;
            border-bottom: 1px solid #1a1a1a;
        }

        .dashboard-nav a {
            padding: 18px 24px;
            color: #555;
            text-decoration: none;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 2px solid transparent;
            transition: .2s;
        }

        .dashboard-nav a:hover,
        .dashboard-nav a.active {
            color: var(--white);
            border-bottom-color: var(--accent);
        }

        .dashboard-section {
            padding: 40px;
        }

        /* ── ORDERS ── */
        .orders-table-wrap {
            overflow-x: auto;
        }

        .orders-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .orders-table th {
            text-align: left;
            padding: 12px 16px;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--muted);
            border-bottom: 1px solid #1a1a1a;
        }

        .orders-table td {
            padding: 16px;
            border-bottom: 1px solid #111;
            color: rgba(255, 255, 255, .8);
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-family: var(--font-display);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .status-badge.processing {
            background: rgba(200, 56, 43, .15);
            color: var(--accent);
            border: 1px solid rgba(200, 56, 43, .3);
        }

        .status-badge.delivered {
            background: rgba(45, 122, 79, .15);
            color: #5ecb8e;
            border: 1px solid rgba(45, 122, 79, .3);
        }

        .status-badge.shipped {
            background: rgba(26, 92, 255, .15);
            color: #7da5ff;
            border: 1px solid rgba(26, 92, 255, .3);
        }

        /* ── WISHLIST ── */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 100px 40px;
            text-align: center;
            gap: 16px;
        }

        .empty-state i {
            font-size: 48px;
            color: var(--border);
        }

        .empty-state h3 {
            font-size: 32px;
            color: var(--muted);
        }

        .empty-state p {
            font-size: 14px;
            color: var(--muted);
            text-transform: none;
        }

        /* ── RELATED PRODUCTS ── */
        .related {
            padding: 60px 40px;
            background: var(--paper);
        }

        /* ── FEATURES STRIP ── */
        .features-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            background: var(--ink);
        }

        .feature-item {
            padding: 36px 30px;
            border-right: 1px solid #1a1a1a;
            color: var(--white);
        }

        .feature-item:last-child {
            border: none;
        }

        .feature-item i {
            font-size: 22px;
            color: var(--accent);
            margin-bottom: 12px;
            display: block;
        }

        .feature-item h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .feature-item p {
            font-size: 12px;
            color: #555;
            text-transform: none;
        }

        /* ── FOOTER ── */
        footer {
            background: #050505;
            color: var(--white);
            padding: 70px 40px 40px;
        }

        .footer-top {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 60px;
            margin-bottom: 60px;
        }

        .footer-brand .logo {
            font-size: 32px;
        }

        .footer-brand p {
            font-size: 13px;
            color: #444;
            margin-top: 16px;
            line-height: 1.7;
            text-transform: none;
        }

        .footer-col h4 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 3px;
            color: var(--muted);
            font-family: var(--font-display);
            font-weight: 700;
            margin-bottom: 20px;
        }

        .footer-col a {
            display: block;
            color: #444;
            text-decoration: none;
            font-size: 14px;
            text-transform: none;
            margin-bottom: 10px;
            transition: .2s;
        }

        .footer-col a:hover {
            color: var(--white);
        }

        .footer-bottom {
            border-top: 1px solid #111;
            padding-top: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footer-bottom p {
            font-size: 12px;
            color: #333;
            text-transform: none;
        }

        .footer-socials {
            display: flex;
            gap: 14px;
        }

        .footer-socials a {
            width: 34px;
            height: 34px;
            border: 1px solid #1a1a1a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #444;
            font-size: 13px;
            transition: .2s;
            text-decoration: none;
        }

        .footer-socials a:hover {
            border-color: var(--white);
            color: var(--white);
        }

        /* ── SUCCESS TOAST ── */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: var(--success);
            color: var(--white);
            padding: 14px 22px;
            border-radius: 2px;
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 9999;
            transform: translateX(200%);
            transition: .4s cubic-bezier(.77, 0, .18, 1);
            box-shadow: 0 4px 20px rgba(0, 0, 0, .15);
        }

        .toast.show {
            transform: translateX(0);
        }

        /* ── BREADCRUMB ── */
        .breadcrumb {
            padding: 16px 40px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            font-size: 12px;
            color: var(--muted);
            font-family: var(--font-display);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .breadcrumb a {
            color: var(--muted);
            text-decoration: none;
            transition: .2s;
        }

        .breadcrumb a:hover {
            color: var(--ink);
        }

        .breadcrumb span {
            margin: 0 8px;
        }

        /* ── UTILITIES ── */
        .tag {
            display: inline-block;
            padding: 3px 10px;
            border: 1px solid var(--border);
            border-radius: 20px;
            font-size: 11px;
            font-family: var(--font-display);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--muted);
        }

        .divider {
            height: 1px;
            background: var(--border);
            margin: 30px 0;
        }

        .text-muted {
            color: var(--muted);
        }

        .text-accent {
            color: var(--accent);
        }

        .mt-auto {
            margin-top: auto;
        }

        @media(max-width:900px) {
            .product-view {
                grid-template-columns: 1fr;
            }

            .product-gallery {
                position: static;
                height: auto;
                min-height: 360px;
            }

            .auth-wrap {
                flex-direction: column;
            }

            .auth-visual {
                min-height: 300px;
            }

            .auth-form {
                width: 100%;
            }

            .footer-top {
                grid-template-columns: 1fr 1fr;
            }

            .features-strip {
                grid-template-columns: 1fr 1fr;
            }

            nav {
                padding: 0 20px;
            }

            .nav-links {
                gap: 14px;
            }

            .search-form {
                display: none;
            }
        }

        @media(max-width:600px) {
            .hero-content {
                padding: 0 24px 50px;
            }

            .footer-top {
                grid-template-columns: 1fr;
            }

            .features-strip {
                grid-template-columns: 1fr;
            }

            .section {
                padding: 50px 20px;
            }
        }
    </style>
</head>

<body>

    <!-- OVERLAY -->
    <div class="overlay <?= $open_cart ? 'active' : '' ?>" id="overlay" onclick="closeCart()"></div>

    <!-- CART SIDEBAR -->
    <div class="sidebar <?= $open_cart ? 'open' : '' ?>" id="cartSidebar">
        <div class="sidebar-head">
            <h2>YOUR BAG <span style="color:var(--muted);font-size:18px;">(<?= $total_qty ?>)</span></h2>
            <button class="sidebar-close" onclick="closeCart()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="sidebar-body">
            <?php if (empty($cart_items)): ?>
                <div class="empty-state" style="padding:60px 20px;">
                    <i class="fa-regular fa-bag-shopping" style="font-size:40px;color:#ddd;"></i>
                    <h3 style="font-size:24px;">Your bag is empty</h3>
                    <p>Add items from the collection to get started.</p>
                    <a href="?page=shop" class="btn-ghost" style="margin-top:8px;" onclick="closeCart()">Shop Collection</a>
                </div>
            <?php else: ?>
                <?php foreach ($cart_items as $item): ?>
                    <div class="cart-item">
                        <img class="cart-item-img" src="<?= htmlspecialchars($item['image']) ?>" alt="">
                        <div class="cart-item-info">
                            <div class="cart-item-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="cart-item-price">$<?= number_format($item['price'], 2) ?> each</div>
                            <div class="qty-control">
                                <a href="?action=update_qty&id=<?= $item['product_id'] ?>&qty=<?= max(1, $item['quantity'] - 1) ?>&page=<?= $page ?><?= $view_id && $page == 'product_view' ? "&id=$view_id" : '' ?>&open_cart=1"
                                    class="qty-btn"><i class="fa-solid fa-minus" style="font-size:10px;"></i></a>
                                <span class="qty-num"><?= $item['quantity'] ?></span>
                                <a href="?action=update_qty&id=<?= $item['product_id'] ?>&qty=<?= $item['quantity'] + 1 ?>&page=<?= $page ?><?= $view_id && $page == 'product_view' ? "&id=$view_id" : '' ?>&open_cart=1"
                                    class="qty-btn"><i class="fa-solid fa-plus" style="font-size:10px;"></i></a>
                            </div>
                        </div>
                        <a href="?action=remove&id=<?= $item['product_id'] ?>&page=<?= $page ?><?= $view_id && $page == 'product_view' ? "&id=$view_id" : '' ?>&open_cart=1"
                            class="cart-item-del" title="Remove"><i class="fa-regular fa-trash-can"></i></a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($cart_items)): ?>
            <div class="sidebar-foot">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                    <span
                        style="font-family:var(--font-display);font-size:11px;text-transform:uppercase;letter-spacing:2px;color:var(--muted);">Subtotal</span>
                    <span
                        style="font-family:var(--font-display);font-weight:900;font-size:30px;">$<?= number_format($total_price, 2) ?></span>
                </div>
                <p style="font-size:12px;color:var(--muted);text-transform:none;margin-bottom:16px;">Taxes & shipping
                    calculated at checkout.</p>
                <form method="POST">
                    <button type="submit" name="checkout_submit" class="btn-accent"
                        style="font-size:17px;padding:18px;">Proceed to Checkout</button>
                </form>
                <a href="?page=shop" class="btn-ghost" style="margin-top:10px;justify-content:center;"
                    onclick="closeCart()">Continue Shopping</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- NAV -->
    <nav>
        <a href="?page=home" class="logo">PHONO <span>//</span></a>
        <div class="nav-links">
            <a href="?page=home" <?= $page == 'home' ? 'class="active"' : '' ?>>Home</a>
            <a href="?page=shop" <?= $page == 'shop' ? 'class="active"' : '' ?>>Collection</a>
            <?php if ($user_id): ?>
                <a href="?page=dashboard" <?= $page == 'dashboard' ? 'class="active"' : '' ?>>Dashboard</a>
                <a href="?page=orders" <?= $page == 'orders' ? 'class="active"' : '' ?>>Orders</a>
                <a href="?page=wishlist" <?= $page == 'wishlist' ? 'class="active"' : '' ?>><i
                        class="fa-regular fa-heart"></i></a>
                <a href="?action=logout">Logout</a>
            <?php else: ?>
                <a href="?page=login" <?= $page == 'login' ? 'class="active"' : '' ?>>Login / Join</a>
            <?php endif; ?>
        </div>
        <div class="nav-right">
            <form class="search-form" action="?page=shop" method="GET">
                <input type="hidden" name="page" value="shop">
                <input type="text" name="q" placeholder="Search phones…" value="<?= htmlspecialchars($search) ?>">
                <button type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
            </form>
            <button class="cart-btn" onclick="openCart()">
                <i class="fa-solid fa-bag-shopping"></i>
                Bag <?php if ($total_qty > 0): ?><span
                        style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 7px;"><?= $total_qty ?></span><?php endif; ?>
            </button>
        </div>
    </nav>

    <!-- TOAST -->
    <div class="toast" id="toast"><i class="fa-solid fa-check"></i> <span id="toast-msg">Added to bag!</span></div>

    <?php /* ═══════════ HOME ═══════════ */ ?>
    <?php if ($page == 'home'): ?>

        <section class="hero">
            <div class="hero-bg"></div>
            <div class="hero-noise"></div>
            <div class="hero-content">
                <div class="hero-eyebrow"><i class="fa-solid fa-bolt"></i> &nbsp;New Season Collection</div>
                <h1 class="hero-title">FUTURE<br><em>PERFORM</em><br>ANCE.</h1>
                <p class="hero-sub">The most powerful smartphones engineered for those who demand more. Precision tech.
                    Uncompromised.</p>
                <div class="hero-actions">
                    <a href="?page=shop" class="btn-accent">Shop Collection</a>
                    <a href="#featured" class="btn-ghost white">View Featured</a>
                </div>
            </div>
            <div class="hero-scroll">Scroll</div>
        </section>

        <div class="features-strip">
            <div class="feature-item">
                <i class="fa-solid fa-truck-fast"></i>
                <h4>Free Shipping</h4>
                <p>On all orders over $100</p>
            </div>
            <div class="feature-item">
                <i class="fa-solid fa-rotate-left"></i>
                <h4>30-Day Returns</h4>
                <p>Hassle-free returns & exchanges</p>
            </div>
            <div class="feature-item">
                <i class="fa-solid fa-shield-halved"></i>
                <h4>2-Year Warranty</h4>
                <p>Manufacturer warranty included</p>
            </div>
            <div class="feature-item">
                <i class="fa-solid fa-headset"></i>
                <h4>24/7 Support</h4>
                <p>Expert support always available</p>
            </div>
        </div>

        <section class="section" id="featured">
            <div class="section-head">
                <div>
                    <h2 class="section-title">Featured Drops</h2>
                    <span class="section-sub">Handpicked for performance</span>
                </div>
                <a href="?page=shop" class="btn-ghost">All Products <i class="fa-solid fa-arrow-right"></i></a>
            </div>
            <div class="product-grid">
                <?php foreach (array_slice($products, 0, 8) as $p): ?>
                    <div class="p-card">
                        <?php if (($p['id'] ?? 0) % 3 == 0): ?>
                            <div class="p-card-badge">New</div><?php endif; ?>
                        <?php if ($user_id): ?>
                            <a href="?action=<?= in_array($p['id'], $wishlist_ids) ? 'wishlist_remove' : 'wishlist_add' ?>&id=<?= $p['id'] ?>&page=home"
                                class="p-card-wish <?= in_array($p['id'], $wishlist_ids) ? 'active' : '' ?>">
                                <i class="fa-<?= in_array($p['id'], $wishlist_ids) ? 'solid' : 'regular' ?> fa-heart"></i>
                            </a>
                        <?php endif; ?>
                        <a href="?page=product_view&id=<?= $p['id'] ?>">
                            <div class="p-card-img-wrap"><img src="<?= htmlspecialchars($p['image']) ?>"
                                    alt="<?= htmlspecialchars($p['name']) ?>"></div>
                        </a>
                        <?php if (!empty($p['brand'])): ?>
                            <div class="p-card-brand"><?= htmlspecialchars($p['brand']) ?></div><?php endif; ?>
                        <a href="?page=product_view&id=<?= $p['id'] ?>"
                            class="p-card-name"><?= htmlspecialchars($p['name']) ?></a>
                        <div class="p-card-price">$<?= number_format($p['price'], 2) ?></div>
                        <div class="p-card-actions">
                            <a href="?action=add&id=<?= $p['id'] ?>&page=home" class="btn-primary"
                                onclick="showToast('<?= addslashes($p['name']) ?> added to bag!')">ADD TO BAG</a>
                            <a href="?page=product_view&id=<?= $p['id'] ?>" class="btn-detail"><i
                                    class="fa-regular fa-eye"></i></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <?php /* ═══════════ SHOP ═══════════ */ ?>
    <?php elseif ($page == 'shop'): ?>

        <div class="shop-header">
            <h1>The Collection</h1>
            <p><?= count($products) ?>
                product<?= count($products) != 1 ? 's' : '' ?><?= $search ? " matching \"" . htmlspecialchars($search) . "\"" : '' ?>
            </p>
        </div>

        <div class="shop-toolbar">
            <a href="?page=shop" class="filter-btn <?= !$category ? 'active' : '' ?>">All</a>
            <?php foreach ($categories as $cat): ?>
                <a href="?page=shop&cat=<?= urlencode($cat) ?><?= $search ? "&q=" . urlencode($search) : '' ?>"
                    class="filter-btn <?= $category == $cat ? 'active' : '' ?>"><?= htmlspecialchars($cat) ?></a>
            <?php endforeach; ?>
            <span class="results-count"><?= count($products) ?> Results</span>
            <form method="GET" style="display:flex;align-items:center;gap:8px;margin-left:auto;">
                <input type="hidden" name="page" value="shop">
                <?php if ($search): ?><input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>"><?php endif; ?>
                <?php if ($category): ?><input type="hidden" name="cat"
                        value="<?= htmlspecialchars($category) ?>"><?php endif; ?>
                <select name="sort" class="sort-select" onchange="this.form.submit()">
                    <option value="default" <?= $sort == 'default' ? 'selected' : '' ?>>Sort: Default</option>
                    <option value="price_asc" <?= $sort == 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= $sort == 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                    <option value="newest" <?= $sort == 'newest' ? 'selected' : '' ?>>Newest First</option>
                </select>
            </form>
        </div>

        <?php if (empty($products)): ?>
            <div class="empty-state" style="padding:120px 40px;">
                <i class="fa-regular fa-face-sad-tear"></i>
                <h3>No products found</h3>
                <p>Try adjusting your search or filters.</p>
                <a href="?page=shop" class="btn-ghost" style="margin-top:8px;">Clear Filters</a>
            </div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $p): ?>
                    <div class="p-card">
                        <?php if ($user_id): ?>
                            <a href="?action=<?= in_array($p['id'], $wishlist_ids) ? 'wishlist_remove' : 'wishlist_add' ?>&id=<?= $p['id'] ?>&page=shop<?= $search ? "&q=" . urlencode($search) : '' ?>"
                                class="p-card-wish <?= in_array($p['id'], $wishlist_ids) ? 'active' : '' ?>">
                                <i class="fa-<?= in_array($p['id'], $wishlist_ids) ? 'solid' : 'regular' ?> fa-heart"></i>
                            </a>
                        <?php endif; ?>
                        <a href="?page=product_view&id=<?= $p['id'] ?>">
                            <div class="p-card-img-wrap"><img src="<?= htmlspecialchars($p['image']) ?>"
                                    alt="<?= htmlspecialchars($p['name']) ?>"></div>
                        </a>
                        <?php if (!empty($p['brand'])): ?>
                            <div class="p-card-brand"><?= htmlspecialchars($p['brand']) ?></div><?php endif; ?>
                        <a href="?page=product_view&id=<?= $p['id'] ?>" class="p-card-name"><?= htmlspecialchars($p['name']) ?></a>
                        <div class="p-card-price">$<?= number_format($p['price'], 2) ?></div>
                        <div class="p-card-actions">
                            <a href="?action=add&id=<?= $p['id'] ?>&page=shop<?= $search ? "&q=" . urlencode($search) : '' ?><?= $category ? "&cat=" . urlencode($category) : '' ?>&sort=<?= $sort ?>"
                                class="btn-primary">ADD TO BAG</a>
                            <a href="?page=product_view&id=<?= $p['id'] ?>" class="btn-detail"><i class="fa-regular fa-eye"></i></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php /* ═══════════ PRODUCT VIEW ═══════════ */ ?>
    <?php elseif ($page == 'product_view' && $product): ?>

        <div class="breadcrumb">
            <a href="?page=home">Home</a><span>/</span>
            <a href="?page=shop">Collection</a><span>/</span>
            <?= htmlspecialchars($product['name']) ?>
        </div>

        <div class="product-view">
            <div class="product-gallery">
                <div class="gallery-main">
                    <img src="<?= htmlspecialchars($product['image']) ?>" id="mainImg"
                        alt="<?= htmlspecialchars($product['name']) ?>">
                </div>
                <div class="gallery-thumbs">
                    <img src="<?= htmlspecialchars($product['image']) ?>" class="gallery-thumb active"
                        onclick="setImg(this.src)">
                    <!-- Additional thumb slots — duplicate the main image for demo -->
                    <img src="<?= htmlspecialchars($product['image']) ?>" class="gallery-thumb" onclick="setImg(this.src)"
                        style="opacity:.6;">
                    <img src="<?= htmlspecialchars($product['image']) ?>" class="gallery-thumb" onclick="setImg(this.src)"
                        style="opacity:.4;">
                </div>
            </div>

            <div class="product-info">
                <?php if (!empty($product['brand'])): ?>
                    <div class="brand"><?= htmlspecialchars($product['brand']) ?></div>
                <?php endif; ?>
                <h1><?= htmlspecialchars($product['name']) ?></h1>
                <div class="price">$<?= number_format($product['price'], 2) ?></div>

                <?php if (!empty($product['description'])): ?>
                    <p class="product-desc"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                <?php else: ?>
                    <p class="product-desc">Experience cutting-edge smartphone technology. Engineered for peak performance with
                        a focus on precision, speed, and long-lasting battery life. Built for those who refuse to compromise.
                    </p>
                <?php endif; ?>

                <dl class="spec-grid">
                    <?php
                    $specs = [
                        'Storage' => $product['storage'] ?? '256GB',
                        'RAM' => $product['ram'] ?? '12GB',
                        'Display' => $product['display'] ?? '6.7"',
                        'Battery' => $product['battery'] ?? '5000mAh',
                        'Camera' => $product['camera'] ?? '200MP',
                        'OS' => $product['os'] ?? 'Android 14',
                    ];
                    foreach ($specs as $k => $v):
                        ?>
                        <div class="spec-item">
                            <dt><?= $k ?></dt>
                            <dd><?= htmlspecialchars($v) ?></dd>
                        </div>
                    <?php endforeach; ?>
                </dl>

                <div class="product-cta">
                    <a href="?action=add&id=<?= $product['id'] ?>&page=product_view&id=<?= $product['id'] ?>"
                        class="btn-accent">
                        <i class="fa-solid fa-bag-shopping"></i> &nbsp;Add to Bag
                    </a>
                    <?php if ($user_id): ?>
                        <a href="?action=<?= in_array($product['id'], $wishlist_ids) ? 'wishlist_remove' : 'wishlist_add' ?>&id=<?= $product['id'] ?>&page=product_view"
                            class="btn-ghost <?= in_array($product['id'], $wishlist_ids) ? 'text-accent' : '' ?>"
                            title="Wishlist">
                            <i class="fa-<?= in_array($product['id'], $wishlist_ids) ? 'solid' : 'regular' ?> fa-heart"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="product-meta">
                    <span><i class="fa-solid fa-circle-check" style="color:var(--success);"></i> In Stock</span>
                    <span><i class="fa-solid fa-truck-fast"></i> Free shipping</span>
                    <span><i class="fa-solid fa-rotate-left"></i> 30-day returns</span>
                </div>

                <?php if (!$user_id): ?>
                    <div
                        style="margin-top:24px;padding:16px;background:#fff8f7;border:1px solid #fde;border-radius:2px;font-size:13px;text-transform:none;">
                        <a href="?page=login" style="color:var(--accent);font-weight:600;">Sign in</a> to save to wishlist and
                        track your orders.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($related)): ?>
            <section class="related">
                <div class="section-head" style="margin-bottom:32px;">
                    <div>
                        <h2 class="section-title">You May Also Like</h2>
                    </div>
                </div>
                <div class="product-grid">
                    <?php foreach ($related as $p): ?>
                        <div class="p-card">
                            <a href="?page=product_view&id=<?= $p['id'] ?>">
                                <div class="p-card-img-wrap"><img src="<?= htmlspecialchars($p['image']) ?>"
                                        alt="<?= htmlspecialchars($p['name']) ?>"></div>
                            </a>
                            <a href="?page=product_view&id=<?= $p['id'] ?>"
                                class="p-card-name"><?= htmlspecialchars($p['name']) ?></a>
                            <div class="p-card-price">$<?= number_format($p['price'], 2) ?></div>
                            <div class="p-card-actions">
                                <a href="?action=add&id=<?= $p['id'] ?>&page=product_view&id=<?= $view_id ?>"
                                    class="btn-primary">ADD TO BAG</a>
                                <a href="?page=product_view&id=<?= $p['id'] ?>" class="btn-detail"><i
                                        class="fa-regular fa-eye"></i></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php /* ═══════════ LOGIN ═══════════ */ ?>
    <?php elseif ($page == 'login'): ?>

        <div class="auth-wrap">
            <div class="auth-visual">
                <div class="auth-visual-text">
                    <h2>Members Get More.</h2>
                    <p
                        style="color:rgba(255,255,255,.5);font-size:15px;margin-top:12px;text-transform:none;line-height:1.7;">
                        Exclusive deals, order tracking, wishlist, and early access to new drops.</p>
                </div>
            </div>
            <div class="auth-form">
                <div id="login-box">
                    <h1>Welcome Back</h1>
                    <p>Sign in to your PHONO account</p>
                    <?php if ($auth_error): ?>
                        <div class="error-msg"><i class="fa-solid fa-circle-exclamation"></i> <?= $auth_error ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="input-group">
                            <label class="input-label">Email</label>
                            <input type="email" name="login_email" class="input" placeholder="your@email.com" required>
                        </div>
                        <div class="input-group">
                            <label class="input-label">Password</label>
                            <input type="password" name="login_pass" class="input" placeholder="••••••••" required>
                        </div>
                        <button type="submit" name="login_submit" class="btn-primary" style="margin-top:8px;">Sign
                            In</button>
                    </form>
                    <p class="auth-toggle">Not a member? <a onclick="toggleAuth()">Create account</a></p>
                </div>

                <div id="register-box" style="display:none;">
                    <h1>Join PHONO</h1>
                    <p>Create your account to get started</p>
                    <?php if ($auth_error): ?>
                        <div class="error-msg"><i class="fa-solid fa-circle-exclamation"></i> <?= $auth_error ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="input-group">
                            <label class="input-label">Full Name</label>
                            <input type="text" name="reg_name" class="input" placeholder="John Doe" required>
                        </div>
                        <div class="input-group">
                            <label class="input-label">Email</label>
                            <input type="email" name="reg_email" class="input" placeholder="your@email.com" required>
                        </div>
                        <div class="input-group">
                            <label class="input-label">Password</label>
                            <input type="password" name="reg_pass" class="input" placeholder="Min. 8 characters" required>
                        </div>
                        <button type="submit" name="register_submit" class="btn-primary" style="margin-top:8px;">Create
                            Account</button>
                    </form>
                    <p class="auth-toggle">Already a member? <a onclick="toggleAuth()">Sign in</a></p>
                </div>
            </div>
        </div>

        <?php /* ═══════════ DASHBOARD ═══════════ */ ?>
    <?php elseif ($page == 'dashboard' && $user_id): ?>

        <div class="dashboard">
            <div class="dashboard-hero">
                <p class="dashboard-greeting"><i class="fa-solid fa-circle-user"></i> &nbsp;Member Portal</p>
                <h1 class="dashboard-name">Hey, <span><?= htmlspecialchars(strtoupper($user_name)) ?>.</span></h1>
                <div class="stats-row">
                    <div class="stat-card">
                        <div class="stat-label">Items in Bag</div>
                        <div class="stat-value accent"><?= $total_qty ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Total Orders</div>
                        <div class="stat-value"><?= $stats['total_orders'] ?? 0 ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Total Spent</div>
                        <div class="stat-value">$<?= number_format($stats['total_spent'] ?? 0, 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Bag Value</div>
                        <div class="stat-value">$<?= number_format($total_price, 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Wishlist Items</div>
                        <div class="stat-value"><?= count($wishlist_ids) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Account Status</div>
                        <div class="stat-value" style="font-size:28px;color:var(--success);">ACTIVE ✓</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-nav">
                <a href="?page=shop" class="dashboard-nav">Shop Collection</a>
                <a href="?page=orders">My Orders</a>
                <a href="?page=wishlist">Wishlist (<?= count($wishlist_ids) ?>)</a>
                <a href="?action=logout" style="margin-left:auto;">Sign Out</a>
            </div>

            <div class="dashboard-section">
                <h3 style="font-size:28px;color:var(--white);margin-bottom:24px;">Quick Actions</h3>
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <a href="?page=shop" class="btn-accent">Browse Collection</a>
                    <a href="?page=orders" class="btn-ghost white">View Orders</a>
                    <a href="?page=wishlist" class="btn-ghost white">My Wishlist</a>
                    <button onclick="openCart()" class="btn-ghost white">View Bag (<?= $total_qty ?>)</button>
                </div>
            </div>
        </div>

        <?php /* ═══════════ ORDERS ═══════════ */ ?>
    <?php elseif ($page == 'orders' && $user_id): ?>

        <div class="dashboard">
            <div class="dashboard-hero">
                <p class="dashboard-greeting"><i class="fa-solid fa-box"></i> &nbsp;Order History</p>
                <h1 class="dashboard-name">Your <span>Orders.</span></h1>
            </div>
            <div class="dashboard-section">
                <?php if (isset($_GET['success'])): ?>
                    <div
                        style="background:rgba(45,122,79,.1);border:1px solid rgba(45,122,79,.3);border-left:3px solid var(--success);color:#5ecb8e;padding:16px;border-radius:2px;margin-bottom:24px;font-size:14px;">
                        <i class="fa-solid fa-circle-check"></i> &nbsp;Order placed successfully! Thank you for shopping with
                        PHONO.
                    </div>
                <?php endif; ?>
                <?php if (empty($orders)): ?>
                    <div class="empty-state" style="padding:80px 20px;">
                        <i class="fa-regular fa-box-open"></i>
                        <h3 style="color:var(--muted);">No orders yet</h3>
                        <p>Your order history will appear here after your first purchase.</p>
                        <a href="?page=shop" class="btn-accent" style="margin-top:8px;display:inline-block;">Shop Now</a>
                    </div>
                <?php else: ?>
                    <div class="orders-table-wrap">
                        <table class="orders-table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $o): ?>
                                    <tr>
                                        <td><span
                                                style="font-family:var(--font-display);font-weight:700;">#<?= str_pad($o['id'], 6, '0', STR_PAD_LEFT) ?></span>
                                        </td>
                                        <td><?= date('M j, Y', strtotime($o['created_at'] ?? 'now')) ?></td>
                                        <td><?= $o['item_count'] ?> item<?= $o['item_count'] != 1 ? 's' : '' ?></td>
                                        <td style="font-weight:700;">$<?= number_format($o['total'], 2) ?></td>
                                        <td>
                                            <span class="status-badge <?= strtolower($o['status'] ?? 'processing') ?>">
                                                <?= htmlspecialchars($o['status'] ?? 'Processing') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php /* ═══════════ WISHLIST ═══════════ */ ?>
    <?php elseif ($page == 'wishlist' && $user_id): ?>

        <div class="breadcrumb"><a href="?page=home">Home</a><span>/</span> Wishlist</div>

        <section class="section">
            <div class="section-head">
                <div>
                    <h2 class="section-title">My Wishlist</h2>
                    <span class="section-sub"><?= count($wishlist_products) ?> saved
                        item<?= count($wishlist_products) != 1 ? 's' : '' ?></span>
                </div>
            </div>
            <?php if (empty($wishlist_products)): ?>
                <div class="empty-state">
                    <i class="fa-regular fa-heart"></i>
                    <h3>Nothing saved yet</h3>
                    <p>Heart products while browsing to save them here.</p>
                    <a href="?page=shop" class="btn-ghost" style="margin-top:8px;">Browse Collection</a>
                </div>
            <?php else: ?>
                <div class="product-grid">
                    <?php foreach ($wishlist_products as $p): ?>
                        <div class="p-card">
                            <a href="?action=wishlist_remove&id=<?= $p['id'] ?>&page=wishlist" class="p-card-wish active"
                                title="Remove from wishlist">
                                <i class="fa-solid fa-heart"></i>
                            </a>
                            <a href="?page=product_view&id=<?= $p['id'] ?>">
                                <div class="p-card-img-wrap"><img src="<?= htmlspecialchars($p['image']) ?>"
                                        alt="<?= htmlspecialchars($p['name']) ?>"></div>
                            </a>
                            <a href="?page=product_view&id=<?= $p['id'] ?>"
                                class="p-card-name"><?= htmlspecialchars($p['name']) ?></a>
                            <div class="p-card-price">$<?= number_format($p['price'], 2) ?></div>
                            <div class="p-card-actions">
                                <a href="?action=add&id=<?= $p['id'] ?>&page=wishlist" class="btn-primary">Add to Bag</a>
                                <a href="?page=product_view&id=<?= $p['id'] ?>" class="btn-detail"><i
                                        class="fa-regular fa-eye"></i></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($user_id === null && in_array($page, ['dashboard', 'orders', 'wishlist'])): ?>
        <div class="empty-state" style="padding:120px 40px;">
            <i class="fa-solid fa-lock" style="color:var(--border);font-size:40px;"></i>
            <h3>Login Required</h3>
            <p>Please sign in to access this page.</p>
            <a href="?page=login" class="btn-accent" style="margin-top:12px;display:inline-block;">Sign In</a>
        </div>
    <?php endif; ?>

    <!-- FOOTER -->
    <footer>
        <div class="footer-top">
            <div class="footer-brand">
                <a href="?page=home" class="logo">PHONO <span style="color:var(--accent)">//</span></a>
                <p>Premium smartphones for those who demand performance. Curated tech for a connected world.</p>
            </div>
            <div class="footer-col">
                <h4>Shop</h4>
                <a href="?page=shop">All Phones</a>
                <a href="?page=shop&cat=Android">Android</a>
                <a href="?page=shop&cat=iPhone">iPhone</a>
                <a href="?page=shop&sort=newest">New Arrivals</a>
            </div>
            <div class="footer-col">
                <h4>Account</h4>
                <?php if ($user_id): ?>
                    <a href="?page=dashboard">Dashboard</a>
                    <a href="?page=orders">Orders</a>
                    <a href="?page=wishlist">Wishlist</a>
                    <a href="?action=logout">Sign Out</a>
                <?php else: ?>
                    <a href="?page=login">Sign In</a>
                    <a href="?page=login">Create Account</a>
                <?php endif; ?>
            </div>
            <div class="footer-col">
                <h4>Support</h4>
                <a href="#">Shipping Info</a>
                <a href="#">Returns Policy</a>
                <a href="#">Warranty</a>
                <a href="#">Contact Us</a>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© 2026 PHONO // PERFORMANCE. All rights reserved.</p>
            <div class="footer-socials">
                <a href="#"><i class="fa-brands fa-instagram"></i></a>
                <a href="#"><i class="fa-brands fa-x-twitter"></i></a>
                <a href="#"><i class="fa-brands fa-tiktok"></i></a>
            </div>
        </div>
    </footer>

    <script>
        function openCart() {
            document.getElementById('cartSidebar').classList.add('open');
            document.getElementById('overlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeCart() {
            document.getElementById('cartSidebar').classList.remove('open');
            document.getElementById('overlay').classList.remove('active');
            document.body.style.overflow = '';
        }
        function toggleAuth() {
            const l = document.getElementById('login-box');
            const r = document.getElementById('register-box');
            if (l.style.display === 'none') { l.style.display = 'block'; r.style.display = 'none'; }
            else { l.style.display = 'none'; r.style.display = 'block'; }
        }
        function setImg(src) {
            document.getElementById('mainImg').src = src;
            document.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('active'));
            event.target.classList.add('active');
        }
        function showToast(msg) {
            const t = document.getElementById('toast');
            document.getElementById('toast-msg').textContent = msg || 'Added to bag!';
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 3000);
        }
        // Auto-show cart if URL has open_cart
        <?php if ($open_cart): ?>
            document.addEventListener('DOMContentLoaded', () => {
                document.getElementById('cartSidebar').classList.add('open');
                document.getElementById('overlay').classList.add('active');
            });
        <?php endif; ?>
    </script>
</body>

</html>
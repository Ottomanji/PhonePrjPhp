<?php
session_start();

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

$msg = "";
$page = $_GET['page'] ?? 'home';
$user_id = $_SESSION['user_id'] ?? null;

if (isset($_GET['action']) && $_GET['action'] == 'add_to_cart') {
    if (!$user_id) {
        header("Location: ?page=login");
        exit;
    }
    $p_id = $_GET['id'];
    $check_cart = $pdo->prepare("SELECT id FROM cart WHERE user_id = ? AND product_id = ?");
    $check_cart->execute([$user_id, $p_id]);
    if ($check_cart->fetch()) {
        $pdo->prepare("UPDATE cart SET quantity = quantity + 1 WHERE user_id = ? AND product_id = ?")->execute([$user_id, $p_id]);
    } else {
        $pdo->prepare("INSERT INTO cart (user_id, product_id) VALUES (?, ?)")->execute([$user_id, $p_id]);
    }
    header("Location: ?page=cart");
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'remove_cart') {
    if ($user_id) {
        $pdo->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?")->execute([$_GET['id'], $user_id]);
    }
    header("Location: ?page=cart");
    exit;
}

if (isset($_POST['register'])) {
    $p = password_hash($_POST['password'], PASSWORD_DEFAULT);
    try {
        $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)")->execute([$_POST['username'], $_POST['email'], $p]);
        header("Location: ?page=login&reg=success");
        exit;
    } catch (Exception $e) {
        $msg = "Email already exists.";
    }
}

if (isset($_POST['login'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$_POST['email']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        header("Location: ?page=home");
        exit;
    } else {
        $msg = "Invalid credentials.";
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    session_destroy();
    header("Location: ?page=home");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHONO | Modern Tech Store</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #000000;
            --accent: #666666;
            --bg: #ffffff;
            --light: #f5f5f7;
            --border: #e5e5e7;
            --transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--primary);
            -webkit-font-smoothing: antialiased;
        }

        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 8%;
            position: sticky;
            top: 0;
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: saturate(180%) blur(20px);
            z-index: 1000;
            border-bottom: 1px solid var(--border);
        }

        .logo {
            font-weight: 800;
            font-size: 1.6rem;
            letter-spacing: -1.5px;
            text-decoration: none;
            color: var(--primary);
        }

        .nav-links {
            display: flex;
            gap: 35px;
            align-items: center;
            list-style: none;
        }

        .nav-links a {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            text-decoration: none;
            color: var(--accent);
            letter-spacing: 1px;
            transition: var(--transition);
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .container {
            padding: 80px 8%;
            max-width: 1400px;
            margin: 0 auto;
            min-height: 70vh;
        }

        .hero {
            height: 70vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .hero h1 {
            font-size: clamp(3rem, 8vw, 6rem);
            font-weight: 800;
            letter-spacing: -3px;
            line-height: 0.9;
            margin-bottom: 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 40px;
        }

        .product-card {
            background: var(--light);
            padding: 30px;
            border-radius: 24px;
            transition: var(--transition);
            text-align: center;
        }

        .product-card:hover {
            transform: translateY(-10px);
            background: #fff;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.05);
        }

        .product-card img {
            width: 100%;
            height: 300px;
            object-fit: contain;
            margin-bottom: 20px;
        }

        .product-card h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        .price {
            color: var(--accent);
            font-weight: 400;
            margin-bottom: 20px;
            display: block;
        }

        .btn-black {
            background: var(--primary);
            color: #fff;
            padding: 14px 28px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.85rem;
            border: 1px solid var(--primary);
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-block;
        }

        .btn-black:hover {
            background: transparent;
            color: var(--primary);
        }

        .cart-wrapper {
            background: #fff;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid var(--border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: var(--light);
            padding: 20px;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--accent);
        }

        td {
            padding: 25px 20px;
            border-bottom: 1px solid var(--border);
        }

        .auth-card {
            max-width: 400px;
            margin: 60px auto;
            padding: 40px;
            border-radius: 24px;
            border: 1px solid var(--border);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
            color: var(--accent);
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            outline: none;
            font-family: inherit;
        }

        footer {
            padding: 60px 8%;
            text-align: center;
            border-top: 1px solid var(--border);
            font-size: 0.75rem;
            color: var(--accent);
        }
    </style>
</head>

<body>

    <nav>
        <a href="?page=home" class="logo">PHONO.</a>
        <ul class="nav-links">
            <li><a href="?page=home">Home</a></li>
            <li><a href="?page=shop">Shop</a></li>
            <?php if ($user_id): ?>
                <?php if ($_SESSION['role'] == 'admin'): ?>
                    <li><a href="?page=admin">Admin</a></li>
                <?php endif; ?>
                <li><a href="?page=cart">Cart <i class="fa-solid fa-bag-shopping"></i></a></li>
                <li><a href="?action=logout">Logout</a></li>
            <?php else: ?>
                <li><a href="?page=login">Sign In</a></li>
            <?php endif; ?>
        </ul>
    </nav>

    <div class="container">
        <?php if ($page == 'home'): ?>
            <section class="hero">
                <h1>Hardware <br> for Humans.</h1>
                <p style="margin-bottom: 30px; color: var(--accent);">Experience the next generation of mobile architecture.
                </p>
                <a href="?page=shop" class="btn-black">View Collection</a>
            </section>

        <?php elseif ($page == 'shop'): ?>
            <h2 style="font-size: 2.5rem; margin-bottom: 40px; letter-spacing: -1.5px;">Collection</h2>
            <div class="grid">
                <?php
                $products = $pdo->query("SELECT * FROM products")->fetchAll();
                foreach ($products as $p): ?>
                    <div class="product-card">
                        <img src="<?= $p['image'] ?>" alt="<?= $p['name'] ?>">
                        <h3><?= $p['name'] ?></h3>
                        <span class="price">$<?= number_format($p['price'], 2) ?></span>
                        <a href="?action=add_to_cart&id=<?= $p['id'] ?>" class="btn-black">Add to Bag</a>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($page == 'cart'): ?>
            <h2 style="font-size: 2.5rem; margin-bottom: 40px; letter-spacing: -1.5px;">Your Bag</h2>
            <?php if ($user_id):
                $stmt = $pdo->prepare("SELECT cart.id, products.name, products.price, cart.quantity FROM cart JOIN products ON cart.product_id = products.id WHERE cart.user_id = ?");
                $stmt->execute([$user_id]);
                $items = $stmt->fetchAll();
                if ($items): ?>
                    <div class="cart-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Quantity</th>
                                    <th>Subtotal</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $total = 0;
                                foreach ($items as $i):
                                    $total += ($i['price'] * $i['quantity']); ?>
                                    <tr>
                                        <td><strong><?= $i['name'] ?></strong></td>
                                        <td><?= $i['quantity'] ?></td>
                                        <td>$<?= number_format($i['price'] * $i['quantity'], 2) ?></td>
                                        <td><a href="?action=remove_cart&id=<?= $i['id'] ?>"
                                                style="color:red; text-decoration: none; font-size: 0.8rem;">Remove</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr>
                                    <td colspan="2" style="text-align: right;"><strong>Total</strong></td>
                                    <td colspan="2"><strong>$<?= number_format($total, 2) ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn-black" style="margin-top: 30px; width: auto; padding: 16px 60px;">Proceed to
                        Checkout</button>
                <?php else:
                    echo "<p>Your bag is empty.</p>";
                endif; ?>
            <?php else:
                echo "<p>Please <a href='?page=login'>Login</a> to see your bag.</p>";
            endif; ?>

        <?php elseif ($page == 'login'): ?>
            <div class="auth-card">
                <h2>Sign In.</h2>
                <p style="color:red; margin-bottom: 15px; font-size: 0.8rem;"><?= $msg ?></p>
                <form method="POST">
                    <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                    <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                    <button type="submit" name="login" class="btn-black" style="width: 100%;">Login</button>
                </form>
                <p style="margin-top:20px; font-size:0.8rem; text-align: center;">New here? <a href="?page=register">Create
                        account</a></p>
            </div>

        <?php elseif ($page == 'register'): ?>
            <div class="auth-card">
                <h2>Register.</h2>
                <form method="POST">
                    <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
                    <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                    <button type="submit" name="register" class="btn-black" style="width: 100%;">Create Account</button>
                </form>
            </div>

        <?php elseif ($page == 'admin'): ?>
            <?php if ($_SESSION['role'] !== 'admin') {
                echo "Access Denied";
                exit;
            } ?>
            <h2 style="font-size: 2rem; margin-bottom: 20px;">Admin Dashboard</h2>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 40px;">
                <div style="background: var(--light); padding: 30px; border-radius: 20px;">
                    <h4>Revenue</h4>
                    <p style="font-size: 1.5rem; font-weight: 700;">$12,400</p>
                </div>
                <div style="background: var(--light); padding: 30px; border-radius: 20px;">
                    <h4>Orders</h4>
                    <p style="font-size: 1.5rem; font-weight: 700;">48</p>
                </div>
                <div style="background: var(--light); padding: 30px; border-radius: 20px;">
                    <h4>Users</h4>
                    <p style="font-size: 1.5rem; font-weight: 700;">1,102</p>
                </div>
            </div>
            <canvas id="salesChart" style="max-height: 300px; width: 100%;"></canvas>
            <script>
                new Chart(document.getElementById('salesChart'), {
                    type: 'line',
                    data: { labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'], datasets: [{ label: 'Sales', data: [10, 25, 13, 40, 20, 55], borderColor: '#000', tension: 0.4 }] }
                });
            </script>
        <?php endif; ?>
    </div>

    <footer>
        &copy; 2026 PHONO. Built for the modern web.
    </footer>

</body>

</html>
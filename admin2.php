<?php

include("db.php");
ob_start();
session_start();

/* ---------- Setup and Configuration ---------- */
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}


// Default image for new items or missing images
if (!file_exists($uploadDir . '/default.png')) {
    // Basic placeholder image creation if needed (for safety)
    // You should manually place a file named 'default.png' in the 'uploads' folder
}
$DEFAULT_IMG_PATH = 'uploads/default.png';

$img_path = $employee['image_path'] ?? '';
if (empty($img_path)) {
    $img_path = $DEFAULT_IMG_PATH;
}

// Display
echo '<img src="' . htmlspecialchars($img_path) . '" alt="Image" style="width:60px;height:60px;object-fit:cover;">';


$ITEMS = [];
$result = $conn->query("SELECT id, name, price, category, image_path, stock FROM inventory ORDER BY category ASC, name ASC");
while ($row = $result->fetch_assoc()) {
    $ITEMS[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'price' => $row['price'],
        'cat' => $row['category'],
        'emoji' => '<img src="' . htmlspecialchars($row['image_path']) . '" style="width:80px; height:80px; object-fit:cover; border-radius:8px;">',
        'stock' => $row['stock']
    ];
}

/* ---------- Helper Functions (Updated) ---------- */
function findItem($ITEMS, $id) {
    foreach ($ITEMS as $it) { if ($it['id'] === $id) return $it; }
    return null;
}
function cartTotal() {
    $sum = 0;
    if (!empty($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $it) {
            $qty = isset($it['qty']) ? (int)$it['qty'] : 0;
            $price = isset($it['price']) ? (float)$it['price'] : 0;
            $sum += $price * $qty;
        }
    }
    return $sum;
}
function pushOrder($receipt) {
    global $conn;

    // Insert sa sales_history
    $stmt = $conn->prepare("INSERT INTO sales_history 
        (transaction_no, cashier, time, total, status) 
        VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param(
        "sssds",
        $receipt['trans_no'],
        $receipt['user'],
        $receipt['time'],
        $receipt['total'],
        $receipt['status']
    );
    $stmt->execute();
    $stmt->close();

        // Insert bawat item sa sales_items
    foreach ($receipt['items'] as $item) {
        $stmt = $conn->prepare("INSERT INTO sales_items 
            (transaction_no, item_name, price, qty) 
            VALUES (?, ?, ?, ?)");
        $stmt->bind_param(
            "ssdi",
            $receipt['trans_no'],
            $item['name'],
            $item['price'],
            $item['qty']
        );
        $stmt->execute();
        $stmt->close();
    }

}
function currentLogoUrl() {
    global $uploadDir;
    $exts = ['png', 'jpg', 'gif', 'webp', 'jpeg'];
    foreach ($exts as $ext) {
        if (file_exists("$uploadDir/logo.$ext")) {
            return "uploads/logo.$ext?" . filemtime("$uploadDir/logo.$ext");
        }
    }
    return 'https://via.placeholder.com/56/fde68a/ea580c?text=Logo';
}
function currentLogoForPrint() {
    global $uploadDir;
    $exts = ['png', 'jpg', 'gif', 'webp', 'jpeg'];
    foreach ($exts as $ext) {
        $path = "$uploadDir/logo.$ext";
        if (file_exists($path)) {
            $data = base64_encode(file_get_contents($path));
            $type = mime_content_type($path);
            return 'data:' . $type . ';base64,' . $data;
        }
    }
    return 'https://via.placeholder.com/56/fde68a/ea580c?text=Logo';
}


function logoUploadHandler() {
    global $uploadDir;
    $flash = '';
    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['logo']['tmp_name'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $tmp);
        finfo_close($finfo);
        $allowedMap = ['image/png' => 'png', 'image/jpeg' => 'jpeg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (isset($allowedMap[$mime])) {
            foreach ($allowedMap as $m => $ext) {
                $old = $uploadDir . "/logo.$ext";
                if (file_exists($old)) @unlink($old);
            }
            $ext = $allowedMap[$mime];
            $dest = $uploadDir . "/logo.$ext";
            if (@move_uploaded_file($tmp, $dest)) {
                $flash = "Logo updated.";
            } else {
                $flash = "Failed to save the uploaded file.";
            }
        } else {
            $flash = "Invalid image type. Please upload PNG/JPG/GIF/WEBP.";
        }
    } else {
        $flash = "No image selected or upload error.";
    }
    return $flash;
}
function totalSales() {
    $sales = 0;
    if (!empty($_SESSION['orders'])) {
        foreach ($_SESSION['orders'] as $ord) {
            $sales += $ord['total'];
        }
    }
    return $sales;
}
function todaySales() {
    $sales = 0;
    $today = date('Y-m-d');
    if (!empty($_SESSION['orders'])) {
        foreach ($_SESSION['orders'] as $ord) {
            if (strpos($ord['time'], $today) === 0) {
                $sales += $ord['total'];
            }
        }
    }
    return $sales;
}

function getTotalSales($conn) {
    $sql = "SELECT SUM(total_amount) as total FROM orders WHERE status = 'Completed'";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return $row['total'] ?? 0;
}

function getTodaysSales($conn) {
    $sql = "SELECT SUM(total_amount) as total FROM orders 
            WHERE status = 'Completed' AND DATE(completed_at) = CURDATE()";
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    return $row['total'] ?? 0;
}


// Initialize sessions
if (!isset($_SESSION['users'])) $_SESSION['users'] = [];
if (!isset($_SESSION['users']['cashier_1'])) $_SESSION['users']['cashier_1'] = '1234';
if (!isset($_SESSION['users']['cashier_2'])) $_SESSION['users']['cashier_2'] = '1234';
if (!isset($_SESSION['users']['admin'])) $_SESSION['users']['admin'] = 'adminpass';




if (!isset($_SESSION['orders']) || empty($_SESSION['orders'])) {
    $_SESSION['orders'] = []; // walang laman by default
}

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];


// Determine which page to load
$view = $_GET['view'] ?? 'dashboard';
$loggedIn = !empty($_SESSION['loggedin']);
$isAdmin = ($loggedIn && $_SESSION['username'] === 'admin');
$flash = '';

// Authentication Logic
if (!$loggedIn) {
    if (isset($_POST['login'])) {
        $u = $_POST['username'] ?? '';
        $p = $_POST['password'] ?? '';

        // -------------------------
        // 1️⃣  Check muna sa ADMIN table
        // -------------------------
        $sql = "SELECT * FROM admins WHERE username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $u);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (password_verify($p, $row['password'])) {
                $_SESSION['loggedin'] = true;
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = 'admin';
                header("Location: ?view=dashboard");
                exit;
            } else {
                $flash = "❌ Mali ang password ng admin account.";
            }
        } else {

            // -------------------------
            // 2️⃣  Kung walang admin, check sa USER table
            // -------------------------
            $sql2 = "SELECT * FROM users WHERE username = ?";
            $stmt2 = $conn->prepare($sql2);
            $stmt2->bind_param("s", $u);
            $stmt2->execute();
            $result2 = $stmt2->get_result();

            if ($row2 = $result2->fetch_assoc()) {
                if (password_verify($p, $row2['password'])) {
                    $_SESSION['loggedin'] = true;
                    $_SESSION['username'] = $row2['username'];
                    $_SESSION['role'] = 'cashier';
                    header("Location: ?view=dashboard");
                    exit;
                } else {
                    $flash = "❌ Mali ang password ng user account.";
                }
            } else {
                $flash = "❌ Walang nahanap na account.";
            }
            $stmt2->close();
        }
        $stmt->close();
    }
}

   // SIGNUP
    elseif (isset($_POST['signup'])) {
        $u = trim($_POST['new_username'] ?? '');
        $p = trim($_POST['new_password'] ?? '');
        $role = trim($_POST['role'] ?? 'cashier'); // default cashier kung walang role

        if ($u === '' || $p === '') {
            $flash = "⚠️ Punan ang lahat ng fields.";
        } else {
            // check kung existing na
            $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $check->bind_param("s", $u);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $flash = "⚠️ Username already exists.";
            } else {
                $hashed = password_hash($p, PASSWORD_DEFAULT);
                $sql = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sss", $u, $hashed, $role);

                if ($stmt->execute()) {
                    $flash = "✅ Account created! Please login.";
                } else {
                    $flash = "❌ Error: " . $stmt->error;
                }
                $stmt->close();
            }
            $check->close();
        }
    }


// Main App Logic (Ordering & Dashboard)
if ($loggedIn) {
    if (isset($_POST['upload_logo'])) {
        $flash = logoUploadHandler();
    }
    if (isset($_GET['logout'])) {
        session_unset();
        session_destroy();
        header("Location: ?");
        exit;
    }

    
    
    if ($view === 'ordering') {
        if (isset($_POST['add_to_cart'])) {
            $id = $_POST['id'] ?? '';
            $qty = max(1, (int)($_POST['qty'] ?? 1));
   // Ordering Actions

            $item = findItem($ITEMS, $id);
            if ($item) {
                if (!isset($_SESSION['cart'][$id])) {
                    $_SESSION['cart'][$id] = [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'price' => (float)$item['price'],
                        'qty' => $qty,
                        'cat' => $item['cat'],
                        'emoji' => $item['emoji'],
                    ];
                } else {
                    $_SESSION['cart'][$id]['qty'] += $qty;
                }
            }
        }
        if (isset($_POST['qty_delta'])) {
            $id = $_POST['id'] ?? '';
            $delta = (int)$_POST['qty_delta'];
            if (isset($_SESSION['cart'][$id])) {
                $_SESSION['cart'][$id]['qty'] += $delta;
                if ($_SESSION['cart'][$id]['qty'] <= 0) unset($_SESSION['cart'][$id]);
            }
        }
        if (isset($_POST['remove_item'])) {
            $id = $_POST['id'] ?? '';
            if (isset($_SESSION['cart'][$id])) unset($_SESSION['cart'][$id]);
        }
        if (isset($_POST['clear_cart'])) {
            $_SESSION['cart'] = [];
        }
        if (isset($_POST['manual_qty']) && isset($_POST['id'])) {
        $id = $_POST['id'];
        $newQty = max(1, intval($_POST['manual_qty']));
        if (isset($_SESSION['cart'][$id])) {
            $_SESSION['cart'][$id]['qty'] = $newQty;
        }
    }

        if (isset($_POST['checkout'])) {
        $cash = isset($_POST['cash']) ? (float)$_POST['cash'] : 0;
        $total = cartTotal();

        // 🧮 Auto-deduct stock per item
foreach ($_SESSION['cart'] as $cartItem) {
    $item_id = $cartItem['id'];
    $qty_bought = $cartItem['qty'];

    // Kunin current stock mula sa database
    $stmt = $conn->prepare("SELECT stock FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $new_stock = max(0, $row['stock'] - $qty_bought); // para di maging negative
        $update = $conn->prepare("UPDATE inventory SET stock = ? WHERE id = ?");
        $update->bind_param("ii", $new_stock, $item_id);
        $update->execute();
        $update->close();
    }

    $stmt->close();
}


    if ($total <= 0) {
        $flash = "Cart is empty.";
    } elseif ($cash >= $total) {
        $receipt = [
            'trans_no' => 'ORD-' . date('Ymd-His') . '-' . rand(1000, 9999),
            'user' => $_SESSION['username'] ?? '—',
            'time' => date('Y-m-d H:i:s'),
            'items' => array_values($_SESSION['cart']),
            'total' => $total,
            'cash' => $cash,
            'change' => $cash - $total,
            'status' => 'New'
        ];
        $_SESSION['last_receipt'] = $receipt;
        $_SESSION['orders'][] = $receipt;
        $_SESSION['cart'] = [];

        // 👉 Flash message with bayad + sukli
        $flash = "Order placed: " . $receipt['trans_no'] .
                 " | Payment: ₱" . number_format($cash, 2) .
                 " | Change: ₱" . number_format($receipt['change'], 2);
    } else {
        $kulang = $total - $cash;
        $flash = "Insufficient cash. Kulang: ₱" . number_format($kulang, 2);
    }


}

    }
   // Order Board Actions
if ($view === 'order_board') {
    if (isset($_POST['update_status'])) {
        $trans_no = $_POST['trans_no'] ?? '';
        $new_status = $_POST['new_status'] ?? '';

        foreach ($_SESSION['orders'] as $i => $order) {
        if ($order['trans_no'] === $trans_no) {
            $_SESSION['orders'][$i]['status'] = $new_status;

                // ✅ kapag Completed, saka lang isulat sa DB
                if ($new_status === 'Completed') {
                    // Sales history insert
                    $stmt = $conn->prepare("INSERT INTO sales_history (transaction_no, cashier, time, total, status) 
                                            VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssis", $order['trans_no'], $order['user'], $order['time'], $order['total'], $order['status']);
                    $stmt->execute();
                    $stmt->close();

                    // Sales items insert
                    $stmtItem = $conn->prepare("INSERT INTO sales_items (transaction_no, item_name, price, qty) VALUES (?, ?, ?, ?)");
                    foreach ($order['items'] as $it) {
                        $stmtItem->bind_param("ssdi", $order['trans_no'], $it['name'], $it['price'], $it['qty']);
                        $stmtItem->execute();
                    }
                    $stmtItem->close();

                    // ✅ Orders insert 
                    $stmtOrder = $conn->prepare("INSERT INTO orders (trans_no, user, status, total_amount, completed_at) 
                                                VALUES (?, ?, 'Completed', ?, NOW())");
                    
                    $stmtOrder->bind_param("ssd", $order['trans_no'], $order['user'], $order['total']);
                    $stmtOrder->execute();
                    $stmtOrder->close();
                }

                // ❌ kapag Cancelled → hindi isusulat sa DB
                if ($new_status === 'Cancelled') {
                    // Optional: pwede mong idagdag sa separate cancelled_orders session kung gusto mo
                     $_SESSION['cancelled'][] = $order;
                }

                $flash = "Order $trans_no status updated to $new_status.";
                break;
            }
        }
    }
}


    // History Actions
    if ($view === 'history') {
        if (isset($_POST['delete_order'])) {
            $trans_no_to_delete = $_POST['trans_no'] ?? '';
            $_SESSION['orders'] = array_filter($_SESSION['orders'], function($order) use ($trans_no_to_delete) {
                return $order['trans_no'] !== $trans_no_to_delete;
            });
            $_SESSION['orders'] = array_values($_SESSION['orders']); // Re-index array
            $flash = "Order $trans_no_to_delete has been deleted.";
        }
    }

}
    
    // ➕ INVENTORY MANAGEMENT ACTIONS
    if ($view === 'inventory' && $isAdmin) {
        if (isset($_POST['add_item']) || isset($_POST['edit_item'])) {
            $isEdit = isset($_POST['edit_item']);
            $id = $isEdit ? trim($_POST['item_id']) : strtoupper('NEW-' . rand(100, 999));
            $name = trim($_POST['name'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $cat = trim($_POST['category'] ?? '');
            
                            // Default image path (can be from anywhere)
                $DEFAULT_IMG_PATH = 'uploads/default.png';

                // Current or old image (kung edit mode)
                $oldImagePath = $DEFAULT_IMG_PATH;
                if ($isEdit && isset($_SESSION['inventory'][$id])) {
                    $oldImagePath = $_SESSION['inventory'][$id]['image_path'] ?? $DEFAULT_IMG_PATH;
                }

                $imagePath = $oldImagePath;

                // Check kung may bagong image
                if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {

                    // Pwede kahit anong directory o URL base name
                    $originalName = basename($_FILES['image']['name']);
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                    // Allowed file types for security
                    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (!in_array($ext, $allowed_ext)) {
                        
                        goto end_inventory_action;
                    }

                    // Upload directory (auto-create kung wala)
                    $upload_dir = 'uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    // Generate unique filename
                    $newFileName = $upload_dir . 'item_' . time() . '_' . rand(100, 999) . '.' . $ext;

                    // Move uploaded file
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $newFileName)) {
                        $imagePath = $newFileName;

                        // Delete old image if local and not the default
                        if (
                            $isEdit &&
                            $oldImagePath !== $DEFAULT_IMG_PATH &&
                            file_exists($oldImagePath) &&
                            strpos($oldImagePath, 'http') !== 0
                        ) {
                            @unlink($oldImagePath);
                        }

                    } else {
                        $flash = "❌ Failed to upload image.";
                        goto end_inventory_action;
                    }
                }

$CATS = [];

$result = $conn->query("SELECT DISTINCT category FROM inventory");
while ($row = $result->fetch_assoc()) {
    $CATS[] = $row['category'];
}

            if ($name && $price > 0 && in_array($cat, $CATS)) {
                $emoji_tag = '<img src="' . htmlspecialchars($imagePath) . '" alt="' . htmlspecialchars($name) . '" style="width:195px;height:110px; border-radius:10px; object-fit: cover;">';
                
                if ($isEdit && isset($_SESSION['inventory'][$id])) {
                    $_SESSION['inventory'][$id]['name'] = $name;
                    $_SESSION['inventory'][$id]['price'] = $price;
                    $_SESSION['inventory'][$id]['cat'] = $cat;
                    $_SESSION['inventory'][$id]['image_path'] = $imagePath;
                    $_SESSION['inventory'][$id]['emoji'] = $emoji_tag;
                    $flash = "✅ Item $name** updated successfully!";
                } elseif (!$isEdit) {
                    $_SESSION['inventory'][$id] = [
                        'id' => $id,
                        'name' => $name,
                        'price' => $price,
                        'cat' => $cat,
                        'emoji' => $emoji_tag,
                        'image_path' => $imagePath,
                    ];
                    $flash = "✅ Item **$name** added successfully! ID: $id";
                }
            } else {
                $flash = "⚠️ All fields are required and Price must be greater than zero.";
            }
        }
        
        end_inventory_action:

        if (isset($_POST['delete_item'])) {
            $id = trim($_POST['item_id'] ?? '');
            if (isset($_SESSION['inventory'][$id])) {
                $name = $_SESSION['inventory'][$id]['name'];
                $imagePath = $_SESSION['inventory'][$id]['image_path'];
                
                if ($imagePath !== $DEFAULT_IMG_PATH && file_exists($imagePath)) {
                    @unlink($imagePath);
                }
                
                unset($_SESSION['inventory'][$id]);
                $flash = "✅ Item **$name** deleted successfully!";
            }
        }

        
    }
    
    // Supplier Actions
    if ($view === 'suppliers' && $isAdmin) {
        if (isset($_POST['add_supplier'])) {
            $name = trim($_POST['supplier_name'] ?? '');
            $company = trim($_POST['company_name'] ?? '');
            $email = trim($_POST['supplier_email'] ?? '');
            $phone = trim($_POST['supplier_phone'] ?? '');
            $address = trim($_POST['supplier_address'] ?? '');

            if ($name && $company) {
                // Generate simple unique ID
                $newId = count($_SESSION['suppliers']) > 0 ? max(array_keys($_SESSION['suppliers'])) + 1 : 1;

                $_SESSION['suppliers'][$newId] = [
                    'id' => $newId,
                    'name' => $name,
                    'company_name' => $company,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                ];
                $flash = "✅ Supplier **$name** added successfully!";
            } else {
                $flash = "⚠️ Supplier Name and Company Name are required.";
            }
        }

        if (isset($_POST['delete_supplier'])) {
            $id = (int)$_POST['supplier_id'] ?? 0;
            if ($id && isset($_SESSION['suppliers'][$id])) {
                $name = $_SESSION['suppliers'][$id]['name'];
                unset($_SESSION['suppliers'][$id]);
                $flash = "✅ Supplier **$name** deleted successfully!";
            }
        }
    }

include("config.php"); // connection file mo sa database

// ====== ADD ITEM ======
if (isset($_POST['add_item'])) {
    $name = trim($_POST['name']);
    $price = (float)$_POST['price'];
    $stock = (int)($_POST['stock'] ?? 0);
    $category = $_POST['category'] ?? '';

    // 🔽 Add this block here
    if ($category === '__new__' && !empty($_POST['newCategory'])) {
        $newCat = trim($_POST['newCategory']);
        $stmt = $conn->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $newCat);
        $stmt->execute();
        $stmt->close();
        $category = $newCat;
    }
    // 🔼 Until here

     $image_path = '';
    if (!empty($_FILES['image']['name'])) {
        $target_dir = "uploads/";
        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
        $target_file = $target_dir . basename($_FILES["image"]["name"]);
        move_uploaded_file($_FILES["image"]["tmp_name"], $target_file);
        $image_path = $target_file;
    }

    $stmt = $conn->prepare("INSERT INTO inventory (name, price, category, image_path) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sdss", $name, $price, $category, $image_path);
    $stmt->execute();
    $stmt->close();

    echo "<script>alert('✅ Item added successfully!'); window.location='?view=inventory';</script>";
}


// ====== EDIT ITEM ======
if (isset($_POST['edit_item'])) {
    $id = (int)$_POST['item_id'];
    $name = trim($_POST['name']);
    $price = (float)$_POST['price'];
    $stock = (int)($_POST['stock'] ?? 0);
    $category = $_POST['category'] ?? '';

    if ($category === '__new__' && !empty($_POST['newCategory'])) {
        $newCat = trim($_POST['newCategory']);
        $stmt = $conn->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $newCat);
        $stmt->execute();
        $stmt->close();
        $category = $newCat;
    }

    // handle image change
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $fileName = uniqid() . '_' . basename($_FILES['image']['name']);
        $dest = $uploadDir . $fileName;
        move_uploaded_file($_FILES['image']['tmp_name'], $dest);

        $stmt = $conn->prepare("UPDATE inventory SET name=?, price=?, stock=?, category=?, image_path=? WHERE id=?");
        $stmt->bind_param("sdissi", $name, $price, $stock, $category, $dest, $id);
    } else {
        $stmt = $conn->prepare("UPDATE inventory SET name=?, price=?, stock=?, category=? WHERE id=?");
        $stmt->bind_param("sdisi", $name, $price, $stock, $category, $id);
    }
    $stmt->execute();
    $stmt->close();

    header("Location: ?view=inventory");
    exit;
}

// ====== DELETE ITEM ======
if (isset($_POST['delete_item'])) {
    $id = (int)$_POST['item_id'];
    $stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    header("Location: ?view=inventory");
    exit;
}


// Clear output buffer
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="logo.jpeg">
    <title>BeePOS — <?php echo ucfirst(str_replace('_', ' ', $view)); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* General Styles */
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; margin: 0;  background-image: url(milktea.jpg); background-repeat: no-repeat; background-size: cover; color: #000000ff; }
        .app { display: flex; min-height: 100vh; }
        .container { padding: 20px; max-width: 1200px; width: 100%; margin: 0 auto; }

        /* Sidebar */
        .sidebar { width: 250px; background-color: #07223bff; color: #ecf0f1; padding: 20px 0; display: flex; flex-direction: column; gap: 12px; position: sticky; top: 0; min-height: 100vh; }
        .side-brand { display: flex; align-items: center; gap: 10px; padding: 0 20px; border-bottom: 1px solid #34495e; padding-bottom: 20px; }
        .logo-circle { width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 3px solid #f39c12; }
        .brand-title { font-size: 1.2em; font-weight: bold; }
        .logo-tools-inline { display: inline-block; }

        /* Navigation */
        .nav { display: flex; flex-direction: column; flex-grow: 1; }
        .nav a { padding: 10px 20px; color: #bdc3c7; text-decoration: none; border-left: 5px solid transparent; transition: background-color 0.2s, border-left-color 0.2s; }
        .nav a:hover { background-color: #34495e; color: #fff; }
        .nav a.active { background-color: #34495e; border-left-color: #f39c12; color: #fff; font-weight: bold; }
        .logout-btn { padding: 20px; border-top: 1px solid #34495e; }

        /* Main Content */
        .main { flex-grow: 1; padding: 20px; }
        .head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #ddd; }
        .head .title { font-size: 2em; font-weight: 300; }
        .small { font-size: 0.8em; color: #7f8c8d; }
        
        /* Cards and Grid */
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-grid .card { padding: 15px; text-align: center; }

        /* Buttons and Forms */
        .btn { padding: 8px 12px; border-radius: 4px; border: none; cursor: pointer; text-decoration: none; display: inline-block; text-align: center; transition: background-color 0.2s; font-size: 1em; }
        .btn-primary { background-color: #f39c12; color: #fff; }
        .btn-primary:hover { background-color: #e67e22; }
        .btn-success { background-color: #2ecc71; color: #fff; }
        .btn-success:hover { background-color: #27ae60; }
        .btn-danger { background-color: #e74c3c; color: #fff; }
        .btn-danger:hover { background-color: #c0392b; }
        .btn-info { background-color: #3498db; color: #fff; }
        .btn-info:hover { background-color: #2980b9; }
        .btn-ghost { background: none; color: #bdc3c7; padding: 5px 10px; }
        .btn-ghost:hover { color: #fff; background-color: #34495e; }
        .btn-sm { padding: 4px 8px; font-size: 0.8em; }
        
        .input { padding: 10px; border: 1px solid #ccc; border-radius: 4px; width: 100%; box-sizing: border-box; }
        .input:focus { border-color: #f39c12; outline: none; }

        /* Auth Screen */
        .auth-wrap { max-width: 350px; margin: 100px auto; padding: 30px; background: #fff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .auth-head { text-align: center; margin-bottom: 20px; }
        .auth-head .brand { font-size: 2em; font-weight: 700; color: #f39c12; }

        /* Notices */
        .notice { padding: 10px; border-radius: 4px; margin-bottom: 15px; font-weight: bold; }
        .notice-ok { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .notice-bad { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }

        /* Ordering/Menu */
        .menu-tabs { display: flex; flex-wrap: wrap; margin-bottom: 10px; border-bottom: 2px solid #ddd; }
        .menu-tabs a { padding: 10px 15px; text-decoration: none; color: #333; border-bottom: 2px solid transparent; margin-bottom: -2px; transition: border-color 0.2s; }
        .menu-tabs a.active { border-bottom-color: #f39c12; font-weight: bold; color: #f39c12; }
        .menu-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        .menu-item { background: #fff; border: 1px solid #eee; border-radius: 8px; overflow: hidden; text-align: center; cursor: pointer; transition: transform 0.1s, box-shadow 0.1s; position: relative; }
        .menu-item:hover { transform: translateY(-3px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .menu-item img { display: block; width: 100%; height: 110px; object-fit: cover; border-radius: 8px 8px 0 0; }
        .item-info { padding: 10px; }
        .item-info strong { display: block; margin-bottom: 5px; font-size: 1.1em; }
        .item-info span { color: #f39c12; font-weight: bold; }

        /* Cart */
        .cart { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .cart-item { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #eee; }
        .cart-qty-controls { display: flex; align-items: center; gap: 5px; }
        .cart-total { font-size: 1.5em; font-weight: bold; color: #2ecc71; margin-top: 10px; }

        /* Data Tables (General) */
        .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .data-table th, .data-table td { border: 1px solid #eee; padding: 12px; text-align: left; }
        .data-table th { background-color: #f8f8f8; font-weight: bold; }
        .data-table tr:nth-child(even) { background-color: #f9f9f9; }
        .data-table tr:hover { background-color: #f0f0f0; }

        /* Order Board Specific */
        .order-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .order-card { border: 1px solid #ddd; padding: 15px; border-radius: 8px; background-color: #fff; }
        .order-card h4 { margin-top: 0; border-bottom: 1px dashed #ccc; padding-bottom: 5px; }
        .order-card .status-new { color: #f39c12; font-weight: bold; }
        .order-card .status-preparing { color: #3498db; font-weight: bold; }
        .order-card .status-completed { color: #2ecc71; font-weight: bold; }
        .order-card ul { list-style: none; padding: 0; margin: 5px 0 10px 0; }
        .order-card ul li { font-size: 0.9em; margin-bottom: 2px; }

        /* Modal Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 100; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
        }
        .modal-content {
            background-color: #fefefe;
            margin: 10% auto; /* 10% from the top and centered */
            padding: 20px;
            border: 1px solid #888;
            width: 80%; /* Could be more or less, depending on screen size */
            max-width: 600px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .close-btn {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-btn:hover, .close-btn:focus {
            color: #000;
            text-decoration: none;
            cursor: pointer;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .app { flex-direction: column; }
            .sidebar { width: 100%; min-height: auto; position: relative; }
            .nav { flex-direction: row; flex-wrap: wrap; justify-content: center; }
            .nav a { border-left: none; border-bottom: 3px solid transparent; }
            .nav a.active { border-left: none; border-bottom-color: #f39c12; }
            .side-brand, .logout-btn { padding: 10px; border-bottom: none; }
            .logout-btn { border-top: none; }
            .head { flex-direction: column; align-items: flex-start; }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Function to open a modal
            window.openModal = function(id) {
                document.getElementById(id).style.display = 'block';
            }

            // Function to close a modal
            window.closeModal = function(id) {
                document.getElementById(id).style.display = 'none';
            }

            // Close modal when clicking outside
            window.onclick = function(event) {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (event.target == modal) {
                        modal.style.display = 'none';
                    }
                });
            }

            // Set data for Edit Item Modal
            window.editItem = function(id, name, price, cat, image_path) {
                document.getElementById('edit_item_id').value = id;
                document.getElementById('edit_name').value = name;
                document.getElementById('edit_price').value = price;
                document.getElementById('edit_category').value = cat;
                document.getElementById('current_image_preview').src = image_path;
                openModal('editItemModal');
            }
            
            // Set data for Delete Item Modal
            window.deleteItemConfirm = function(id, name) {
                document.getElementById('delete_item_id_input').value = id;
                document.getElementById('delete_item_name_span').textContent = name;
                openModal('deleteItemModal');
            }

            // Set data for Delete Supplier Modal
            window.deleteSupplierConfirm = function(id, name) {
                document.getElementById('delete_supplier_id_input').value = id;
                document.getElementById('delete_supplier_name_span').textContent = name;
                openModal('deleteSupplierModal');
            }
        });
        
        // Function to handle Add to Cart
        function addToCart(id) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '?view=ordering';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            
            const qtyInput = document.createElement('input');
            qtyInput.type = 'hidden';
            qtyInput.name = 'qty';
            qtyInput.value = 1; // Default to 1
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'add_to_cart';
            actionInput.value = '1';
            
            form.appendChild(idInput);
            form.appendChild(qtyInput);
            form.appendChild(actionInput);
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</head>
<body>

<?php if ($loggedIn): ?>
<div class="app">
    <div class="sidebar">
        <div class="side-brand">
            <img src="<?php echo currentLogoUrl(); ?>" alt="Logo" class="logo-circle">
            <span class="brand-title">KAT-FFEINATED</span>
        </div>
        
        <div class="nav">
            <a href="?view=dashboard" class="block py-2 px-4 rounded transition duration-200 <?php echo $view === 'dashboard' ? 'bg-indigo-700' : 'hover:bg-gray-700'; ?>">📊 Dashboard</a>
            <a href="?view=ordering" class="block py-2 px-4 rounded transition duration-200 <?php echo $view === 'ordering' ? 'bg-indigo-700' : 'hover:bg-gray-700'; ?>">🛒 Ordering / POS</a>
            <a href="?view=order_board" class="block py-2 px-4 rounded transition duration-200 <?php echo $view === 'order_board' ? 'bg-indigo-700' : 'hover:bg-gray-700'; ?>">📋 Order Board</a>
            <a href="?view=history" class="block py-2 px-4 rounded transition duration-200 <?php echo $view === 'history' ? 'bg-indigo-700' : 'hover:bg-gray-700'; ?>">📜 Sales History</a>
            
            <?php if ($isAdmin): ?>
            <p class="text-xs text-gray-400 uppercase mb-2">Admin Tools</p>
            <a href="?view=inventory" class="block py-2 px-4 rounded transition duration-200 <?php echo $view === 'inventory' ? 'bg-indigo-700' : 'hover:bg-gray-700'; ?>">📦 Inventory</a>
            <a href="?view=suppliers" class="block py-2 px-4 rounded transition duration-200 <?php echo $view === 'suppliers' ? 'bg-indigo-700' : 'hover:bg-gray-700'; ?>">🚚 Suppliers</a>
            <a href="?view=settings" class="block py-2 px-4 rounded transition duration-200 <?php echo $view === 'settings' ? 'bg-indigo-700' : 'hover:bg-gray-700'; ?>">⚙️ Settings</a>
            <?php endif; ?>
        </div>
        
        <div class="logout-btn">
            <p>Logged in as: <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'Guest'); ?></strong></p>
            <a href="?logout=1" class="btn btn-danger" style="width: 90%;">Logout</a>
        </div>
    </div>
    
    <div class="main">
        <div class="head">
            <h1 class="title"><?php echo ucfirst(str_replace('_', ' ', $view)); ?></h1>
            <span class="small"><?php echo date('F j, Y'); ?></span>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?php echo (strpos($flash, '✅') !== false || strpos($flash, 'Order placed:') !== false) ? 'notice-ok' : 'notice-bad'; ?>">
                <?php echo $flash; ?>
            </div>
        <?php endif; ?>
        
        <div class="content">
            <?php
            // --- View Logic ---
            if ($view === 'dashboard') {
                ?>
                <div class="stat-grid">
                    <div class="card">
                        <h3>Total Sales</h3>
                        <p style="font-size: 2em; color: #2ecc71;">₱ <?php echo number_format(getTotalSales($conn), 2); ?></p>
                    </div>
                    <div class="card">
                        <h3>Today's Sales</h3>
                        <p style="font-size: 2em; color: #f39c12;">₱ <?php echo number_format(getTodaysSales($conn), 2); ?></p>
                    </div>
                    <div class="card">
                        <h3>Total Items</h3>
                        <?php
                            $result = $conn->query("SELECT COUNT(*) AS total_items FROM inventory");
                            $totalItems = $result->fetch_assoc()['total_items'] ?? 0;
                        ?>
                        <p style="font-size: 2em;"><?php echo $totalItems; ?></p>
                    </div>
                    <div class="card">
                        <h3>Orders in Queue</h3>
                        <p style="font-size: 2em;"><?php echo count(array_filter($_SESSION['orders'] ?? [], fn($o) => $o['status'] !== 'Completed')); ?></p>
                    </div>
                </div>

                <div class="card">
                    <h2>Recent Orders</h2>
                    <?php if (empty($_SESSION['orders'])): ?>
                        <p>No orders yet.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Trans No.</th>
                                    <th>Time</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Cashier</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $recent_orders = array_slice(array_reverse($_SESSION['orders']), 0, 5);
                                foreach ($recent_orders as $order):
                                    $status_class = strtolower(str_replace(' ', '-', $order['status']));
                                ?>
                                <tr>
                                    <td><?php echo $order['trans_no']; ?></td>
                                    <td><?php echo date('h:i A', strtotime($order['time'])); ?></td>
                                    <td>₱<?php echo number_format($order['total'], 2); ?></td>
                                    <td class="status-<?php echo $status_class; ?>"><?php echo $order['status']; ?></td>
                                    <td><?php echo $order['user']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <?php
            } elseif ($view === 'ordering') {
                ?>
                <style>
/* 🌈 Custom Scrollbar for Menu Container */
.menu-container::-webkit-scrollbar {
    width: 10px;
}
.menu-container::-webkit-scrollbar-thumb {
    background: #ccc;
    border-radius: 10px;
}
.menu-container::-webkit-scrollbar-thumb:hover {
    background: #999;
}
.menu-container {
    scrollbar-width: thin;
    scrollbar-color: #ccc #f9f9fb;
}
</style>

<!-- 🧭 Layout wrapper -->
<div style="display: flex; gap: 25px; align-items: flex-start;">
    
    <!-- 🧩 LEFT SIDE: MENU ITEMS -->
    <div style="flex: 1.4;"> 
        <h2 style="margin-bottom: 15px;">Menu Items</h2>

        <!-- 🔍 Unified Search Bar -->
        <div style="margin-bottom: 15px;">
            <input type="text" id="searchInput" placeholder="Search by name or category..."
                style="padding: 10px; width: 60%; border: 1px solid #ccc; border-radius: 6px;">
        </div>

        <!-- 📦 Scrollable White Container -->
        <div class="menu-container" style="
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            height: 520px;
            overflow-y: auto;
            overflow-x: hidden;
        ">
            <!-- 🧱 Product Grid -->
            <div class="menu-grid" id="menuGrid"
                style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px;">
                <?php
                $result = $conn->query("SELECT * FROM inventory ORDER BY name ASC");
                while ($item = $result->fetch_assoc()) {
                ?>
                    <div class="menu-item"
                        data-name="<?php echo strtolower($item['name']); ?>"
                        data-category="<?php echo strtolower($item['category']); ?>"
                        onclick="addToCart('<?php echo $item['id']; ?>')"
                        style="
                            cursor: pointer;
                            border-radius: 15px;
                            padding: 10px;
                            background: #f9f9fb;
                            text-align: center;
                            transition: all 0.2s ease-in-out;
                            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
                        "
                        onmouseover="this.style.transform='scale(1.03)'"
                        onmouseout="this.style.transform='scale(1)'"
                    >
                        <img src="<?php echo htmlspecialchars($item['image_path']); ?>"
                            alt="<?php echo $item['name']; ?>"
                            style="width: 100%; height: 100px; object-fit: cover; border-radius: 10px;">

                        <div class="item-info" style="margin-top: 8px;">
                            <strong style="display: block; font-size: 15px;"><?php echo htmlspecialchars($item['name']); ?></strong>
                            <span style="color: #007bff; font-weight: bold;">₱<?php echo number_format($item['price'], 2); ?></span><br>
                            <small style="color: gray;"><?php echo htmlspecialchars($item['category']); ?></small><br>
                            <small style="color: <?php echo ($item['stock'] <= 0) ? 'red' : '#666'; ?>;">
                                Stock: <?php echo $item['stock']; ?>
                            </small>
                        </div>
                    </div>
                <?php } ?>
            </div>

            <p id="noResults" style="display: none; text-align: center; margin-top: 20px; font-style: italic; color: black;">
                No products found.
            </p>
        </div>
    </div>

                  

                <!-- 🔍 Smart Search Script -->
                <script>
                const searchInput = document.getElementById('searchInput');
                const items = document.querySelectorAll('.menu-item');
                const noResults = document.getElementById('noResults');

                searchInput.addEventListener('input', () => {
                    const searchValue = searchInput.value.toLowerCase();
                    let visibleCount = 0;

                    items.forEach(item => {
                        const name = item.dataset.name;
                        const category = item.dataset.category;

                        if (name.includes(searchValue) || category.includes(searchValue)) {
                            item.style.display = 'block';
                            visibleCount++;
                        } else {
                            item.style.display = 'none';
                        }
                    });

                    // Show or hide "No products found" message
                    noResults.style.display = (visibleCount === 0) ? 'block' : 'none';
                });
                </script>
                                
                    <div style="flex: 1;">
                        <div class="cart">
                            <h3 style="margin-top: 0;">Cart (<?php echo count($_SESSION['cart']); ?> items)</h3>
                            <div style="max-height: 400px; overflow-y: auto; margin-bottom: 10px;">
                                <?php if (empty($_SESSION['cart'])): ?>
                                    <p style="color: #7f8c8d;">Cart is empty. Click an item to add.</p>
                                <?php else: ?>
                                    <?php foreach ($_SESSION['cart'] as $item): ?>
                                        <div class="cart-item">
                                            <div>
                                                **<?php echo htmlspecialchars($item['name']); ?>** <br>
                                                <span class="small">₱<?php echo number_format($item['price'], 2); ?> each</span>
                                            </div>
                                            <div class="cart-qty-controls">
                                                <form method="POST" style="display: inline-block;">
                                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="qty_delta" value="-1">
                                                    <button type="submit" class="btn btn-danger btn-sm">-</button>
                                                </form>
                                                <input type="number" name="manual_qty" value="<?php echo $item['qty']; ?>" min="1" style="width:50px; text-align:center; border:1px solid #ccc; border-radius:5px;" onchange="updateManualQty('<?php echo $item['id']; ?>', this.value)">

                                                <form method="POST" style="display: inline-block;">
                                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                    <input type="hidden" name="qty_delta" value="1">
                                                    <button type="submit" class="btn btn-success btn-sm">+</button>
                                                </form>
                                                <form method="POST" style="display: inline-block;">
                                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                    <button type="submit" name="remove_item" class="btn btn-ghost btn-sm" title="Remove">❌</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div class="cart-total">Total: ₱<?php echo number_format(cartTotal(), 2); ?></div>
                            <hr>

                            <form method="POST">
                                <label for="cash">Cash Tendered:</label>
                                <input type="number" step="0.01" min="<?php echo cartTotal(); ?>" id="cash" name="cash" class="input" required value="<?php echo number_format(cartTotal(), 2, '.', ''); ?>" style="margin-bottom: 10px;">
                                <input type="hidden" name="checkout" value="1">
                                <button type="submit" class="btn btn-primary" style="width: 100%; margin-bottom: 10px;">Checkout (Pay)</button>
                            </form>
                            <form method="POST" style="display: inline-block; width: 49%;">
                                <input type="hidden" name="clear_cart" value="1">
                                <button type="submit" class="btn btn-danger" style="width: 100%;">Clear Cart</button>
                            </form>
                            <button class="btn btn-info" style="width: 49%;" onclick="window.printReceipt()">Print Receipt</button>
                        </div>
                    </div>
                </div>
                <script>
                    function updateManualQty(id, newQty) {
                        const formData = new FormData();
                        formData.append('id', id);
                        formData.append('manual_qty', newQty);

                        fetch('', { // same page
                            method: 'POST',
                            body: formData
                        }).then(() => location.reload());
                    }
                </script>
                  <script>
                        window.printReceipt = function() {
                            const receipt = <?php echo json_encode($_SESSION['last_receipt'] ?? null); ?>;
                            if (!receipt) {
                                alert("Please complete an order first.");
                                return;
                            }

                            const logoBase64 = '<?php echo currentLogoForPrint(); ?>';
                            let receiptHTML = `
                                <div style="font-family: monospace; font-size: 10px; width: 220px; margin: 0 auto; text-align: center;">
                                    <!-- Business Logo -->
                                    <img src="${logoBase64}" style="width: 60px; height: 60px; margin-bottom: 5px; border-radius: 50%;">

                                    <!-- Business Name -->
                                    <h4 style="margin: 0; font-size: 12px;">KAT-FEINATED</h4>
                                    <p style="margin: 0; font-size: 10px;">
                                        112 Omega St. corner Rolex, Brgy. Fairview, Quezon City <br>
                                        Contact: 0993-655-7331
                                    </p>

                                    <p style="border-top: 1px dashed #000; margin: 5px 0;"></p>

                                    <!-- Transaction Info -->
                                    <p style="text-align: left; font-size: 10px;">
                                        Trans No: ${receipt.trans_no}<br>
                                        Time: ${receipt.time}<br>
                                        Cashier: ${receipt.user}
                                    </p>

                                    <!-- Order Items -->
                                    <table style="width: 100%; border-collapse: collapse; font-size: 10px; margin-bottom: 5px;">
                                        <thead>
                                            <tr>
                                                <th style="text-align: left;">Item</th>
                                                <th>Qty</th>
                                                <th style="text-align: right;">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${receipt.items.map(item => `
                                                <tr>
                                                    <td style="text-align: left;">${item.name}</td>
                                                    <td style="text-align: center;">${item.qty}</td>
                                                    <td style="text-align: right;">${(item.price * item.qty).toFixed(2)}</td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>

                                    <!-- Totals -->
                                    <p style="border-top: 1px dashed #000; font-size: 10px; text-align: right;">
                                        Total: <strong>₱${receipt.total.toFixed(2)}</strong><br>
                                        Cash: ₱${receipt.cash.toFixed(2)}<br>
                                        Change: <strong>₱${receipt.change.toFixed(2)}</strong>
                                    </p>

                                    <!-- Thank You Message -->
                                    <p style="margin-top: 10px; font-size: 10px;">
                                        “Thank you for supporting Kat-Feinated! God Bless!”
                                    </p>

                                    <p style="font-size: 9px; margin-top: 5px;">
                                        ${receipt.time}
                                    </p>
                                </div>
                            `;

                            const printWindow = window.open('', '', 'height=600,width=300');
                            printWindow.document.write('<html><head><title>Receipt</title>');
                            printWindow.document.write('<style>@media print { body{margin:0;} }</style></head><body>');
                            printWindow.document.write(receiptHTML);
                            printWindow.document.write('</body></html>');
                            printWindow.document.close();
                            printWindow.print();
                        }
                    </script>
                <?php
            } elseif ($view === 'order_board') {
                $new_orders = array_filter($_SESSION['orders'] ?? [], fn($o) => $o['status'] === 'New');
                $preparing_orders = array_filter($_SESSION['orders'] ?? [], fn($o) => $o['status'] === 'Preparing');
                $completed_orders = array_filter($_SESSION['orders'] ?? [], fn($o) => $o['status'] === 'Completed');
                $cancelled_orders = array_filter($_SESSION['orders'] ?? [], fn($o) => $o['status'] === 'Cancelled');
                
                ?>
                <div class="order-grid">
                    <div class="card" style="border-left: 5px solid #f39c12;">
                        <h3>New Orders (<?php echo count($new_orders); ?>)</h3>
                        <?php foreach ($new_orders as $order): ?>
                            <div class="order-card">
                                <h4><?php echo $order['trans_no']; ?> - ₱<?php echo number_format($order['total'], 2); ?></h4>
                                <span class="small"><?php echo date('h:i:s A', strtotime($order['time'])); ?> | Cashier: <?php echo $order['user']; ?></span>
                                <ul>
                                    <?php foreach ($order['items'] as $item): ?>
                                        <li><?php echo $item['qty']; ?>x <?php echo $item['name']; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <form method="POST" style="display: inline-block;">
                                    <input type="hidden" name="trans_no" value="<?php echo $order['trans_no']; ?>">
                                    <input type="hidden" name="new_status" value="Preparing">
                                    <button type="submit" name="update_status" class="btn btn-info btn-sm">Start Preparing</button>
                                </form>
                                <form method="POST" style="display: inline-block;">
                                    <input type="hidden" name="trans_no" value="<?php echo $order['trans_no']; ?>">
                                    <input type="hidden" name="new_status" value="Completed">
                                    <button type="submit" name="update_status" class="btn btn-success btn-sm">Complete Now</button>
                                </form>
                                <form method="POST" style="display: inline-block;">
                                    <input type="hidden" name="trans_no" value="<?php echo $order['trans_no']; ?>">
                                    <input type="hidden" name="new_status" value="Cancelled">
                                    <button type="submit" name="update_status" class="btn btn-danger btn-sm">Cancel</button>
                                </form>

                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($new_orders)) echo '<p class="small">No new orders.</p>'; ?>
                    </div>

                    <div class="card" style="border-left: 5px solid #3498db;">
                        <h3>Preparing Orders (<?php echo count($preparing_orders); ?>)</h3>
                        <?php foreach ($preparing_orders as $order): ?>
                            <div class="order-card">
                                <h4><?php echo $order['trans_no']; ?> - ₱<?php echo number_format($order['total'], 2); ?></h4>
                                <span class="small"><?php echo date('h:i:s A', strtotime($order['time'])); ?> | Cashier: <?php echo $order['user']; ?></span>
                                <ul>
                                    <?php foreach ($order['items'] as $item): ?>
                                        <li><?php echo $item['qty']; ?>x <?php echo $item['name']; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                                <form method="POST">
                                    <input type="hidden" name="trans_no" value="<?php echo $order['trans_no']; ?>">
                                    <input type="hidden" name="new_status" value="Completed">
                                    <button type="submit" name="update_status" class="btn btn-success btn-sm">Mark Completed</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($preparing_orders)) echo '<p class="small">No orders currently preparing.</p>'; ?>
                    </div>

                    <div class="card" style="border-left: 5px solid #2ecc71;">
                        <h3>Recently Completed (<?php echo count($completed_orders); ?> total)</h3>
                        <?php
                        $recent_completed = array_slice(array_reverse($completed_orders), 0, 5);
                        foreach ($recent_completed as $order):
                        ?>
                            <div class="order-card" style="opacity: 0.7; border-color: #c3e6cb;">
                                <h4><?php echo $order['trans_no']; ?> - ₱<?php echo number_format($order['total'], 2); ?></h4>
                                <span class="small status-completed">Completed</span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($completed_orders)) echo '<p class="small">No completed orders yet.</p>'; ?>
                    </div>

                    <div class="card" style="border-left: 5px solid #e74c3c;">
                        <h3>Cancelled Orders (<?php echo count($cancelled_orders); ?> total)</h3>
                        <?php
    
                        $recent_cancelled = array_slice(array_reverse($cancelled_orders), 0, 5);
                        foreach ($recent_cancelled as $order):
                        ?>
                            <div class="order-card" style="opacity: 0.7; border-color: #f5b7b1;">
                                <h4><?php echo $order['trans_no']; ?> - ₱<?php echo number_format($order['total'], 2); ?></h4>
                                <span class="small status-cancelled" style="color: #e74c3c;">Cancelled</span>
                            </div>
                        <?php endforeach; ?>

                        <?php if (empty($cancelled_orders)) echo '<p class="small">No cancelled orders yet.</p>'; ?>
                    </div>
                                       
                </div>

                <?php
            } elseif ($view === 'history') {
                ?>
                <div class="card">
                    <h2>Sales History</h2>
                    <?php if (empty($_SESSION['orders'])): ?>
                        <p>No orders recorded yet.</p>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Trans No.</th>
                                    <th>Time</th>
                                    <th>Total</th>
                                    <th>Cash Tendered</th>
                                    <th>Change</th>
                                    <th>Status</th>
                                    <th>Cashier</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($_SESSION['orders']) as $order): 
                                    $status_class = strtolower(str_replace(' ', '-', $order['status']));
                                ?>
                                <tr>
                                    <td><?php echo $order['trans_no']; ?></td>
                                    <td><?php echo $order['time']; ?></td>
                                    <td>₱<?php echo number_format($order['total'], 2); ?></td>
                                    <td>₱<?php echo number_format($order['cash'], 2); ?></td>
                                    <td>₱<?php echo number_format($order['change'], 2); ?></td>
                                    <td class="status-<?php echo $status_class; ?>"><?php echo $order['status']; ?></td>
                                    <td><?php echo $order['user']; ?></td>
                                    <td>
                                        <?php if ($isAdmin): ?>
                                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this order?');" style="display: inline;">
                                                <input type="hidden" name="trans_no" value="<?php echo $order['trans_no']; ?>">
                                                <button type="submit" name="delete_order" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <?php
            } elseif ($view === 'inventory' && $isAdmin) {
    // fetch items & categories for display
    $itemsRes = $conn->query("SELECT * FROM inventory ORDER BY id DESC");
    $items = $itemsRes->fetch_all(MYSQLI_ASSOC);

    $catRes = $conn->query("SELECT name FROM categories ORDER BY name ASC");
    $cats = [];
    while ($r = $catRes->fetch_assoc()) $cats[] = $r['name'];
    ?>
    <button class="btn btn-success" onclick="openModal('addItemModal')" style="margin-bottom: 20px;">+ Add New Item</button>

    <div class="card">
        <h2>Inventory</h2>

        <!-- search + filter -->
        <div style="display:flex; gap:10px; margin:12px 0;">
            <input id="searchInput" placeholder="Search by name..." style="flex:1; padding:8px; border-radius:6px; border:1px solid #ccc;">
            <select id="filterCategory" style="padding:8px; border-radius:6px; border:1px solid #ccc;">
                <option value="">All Categories</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <table class="data-table" style="width:100%; border-collapse:collapse;">
            <thead>
                <tr style="background:#f4f4f4; text-align:left;">
                    <th style="padding:10px;">Image</th>
                    <th style="padding:10px;">Name</th>
                    <th style="padding:10px;">Price</th>
                    <th style="padding:10px;">Stock</th>
                    <th style="padding:10px;">Category</th>
                    <th style="padding:10px;">Date Added</th>
                    <th style="padding:10px;">Actions</th>
                </tr>
            </thead>
            <tbody id="inventoryTbody">
                <?php if (count($items) === 0): ?>
                    <tr><td colspan="7" style="text-align:center; padding:20px;">No items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr data-name="<?php echo htmlspecialchars(strtolower($item['name'])); ?>" data-cat="<?php echo htmlspecialchars($item['category']); ?>">
                            <td style="padding:10px;"><img src="<?php echo htmlspecialchars($item['image_path'] ?: 'no-image.png'); ?>" style="width:60px;height:60px;object-fit:cover;border-radius:8px;"></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($item['name']); ?></td>
                            <td style="padding:10px;">₱<?php echo number_format($item['price'],2); ?></td>
                            <td style="padding:10px;"><?php echo (int)($item['stock'] ?? 0); ?></td>
                            <td style="padding:10px;"><?php echo htmlspecialchars($item['category']); ?></td>
                            <td style="padding:10px;color:gray;"><?php echo date("Y-m-d H:i", strtotime($item['created_at'])); ?></td>
                            <td style="padding:10px;">
                                <button class="btn btn-info btn-sm"
                                    onclick="editItem('<?php echo $item['id']; ?>','<?php echo htmlspecialchars(addslashes($item['name'])); ?>',<?php echo $item['price']; ?>,'<?php echo htmlspecialchars(addslashes($item['category'])); ?>','<?php echo htmlspecialchars($item['image_path']); ?>',<?php echo (int)($item['stock'] ?? 0); ?>)">Edit</button>
                                <button class="btn btn-danger btn-sm" onclick="deleteItemConfirm('<?php echo $item['id']; ?>','<?php echo htmlspecialchars(addslashes($item['name'])); ?>')">Delete</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ADD Modal (same as provided earlier but with stock & dynamic categories) -->
    <div id="addItemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add New Product</h2>
                <span class="close-btn" onclick="closeModal('addItemModal')">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data" style="padding:10px;">
                <input type="text" name="name" placeholder="Name" required style="width:100%;margin-bottom:8px;padding:8px;">
                <input type="number" step="0.01" name="price" placeholder="Price (₱)" required style="width:150px;margin-bottom:8px;padding:8px;">
                <input type="number" name="stock" placeholder="Stock (Qty)" value="0" required style="width:150px;margin-bottom:8px;padding:8px;">
                <select id="add_cat" name="category" required style="width:100%;margin-bottom:8px;padding:8px;">
                    <option value="">-- Select Category --</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                    <option value="__new__">+ Add New Category</option>
                </select>
                <div id="addNewCatDiv" style="display:none;margin-bottom:8px;">
                    <input type="text" name="newCategory" placeholder="New category name" style="width:100%;padding:8px;">
                </div>
                <input type="file" name="image" accept="image/*" style="margin-bottom:8px;">
                <button type="submit" name="add_item" class="btn btn-success">Add Item</button>
            </form>
        </div>
    </div>

    <!-- EDIT Modal (fields filled by JS) -->
    <div id="editItemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Product</h2>
                <span class="close-btn" onclick="closeModal('editItemModal')">&times;</span>
            </div>
            <form method="POST" enctype="multipart/form-data" style="padding:10px;">
                <input type="hidden" name="item_id" id="edit_item_id">
                <div>
                    <label>Current Image:</label><br>
                    <img id="current_image_preview" style="width:100px;height:100px;object-fit:cover;border-radius:8px;margin-bottom:8px;">
                </div>
                <input type="text" id="edit_name" name="name" required style="width:100%;margin-bottom:8px;padding:8px;">
                <input type="number" id="edit_price" name="price" step="0.01" required style="width:150px;margin-bottom:8px;padding:8px;">
                <input type="number" id="edit_stock" name="stock" min="0" required style="width:150px;margin-bottom:8px;padding:8px;">
                <select id="edit_category" name="category" required style="width:100%;margin-bottom:8px;padding:8px;">
                    <option value="">-- Select Category --</option>
                    <?php foreach ($cats as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                    <?php endforeach; ?>
                    <option value="__new__">+ Add New Category</option>
                </select>
                <div id="editNewCatDiv" style="display:none;margin-bottom:8px;">
                    <input type="text" name="newCategory" placeholder="New category name" style="width:100%;padding:8px;">
                </div>
                <input type="file" id="edit_image" name="image" accept="image/*" style="margin-bottom:8px;">
                <button type="submit" name="edit_item" class="btn btn-info">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- DELETE Modal -->
    <div id="deleteItemModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Confirm Delete</h2>
                <span class="close-btn" onclick="closeModal('deleteItemModal')">&times;</span>
            </div>
            <p>Delete <strong><span id="delete_item_name_span"></span></strong> ?</p>
            <form method="POST" style="padding:10px;">
                <input type="hidden" name="item_id" id="delete_item_id_input">
                <button type="submit" name="delete_item" class="btn btn-danger">Yes, Delete</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('deleteItemModal')">Cancel</button>
            </form>
        </div>
    </div>

    <!-- JS: modal control, editItem, deleteItemConfirm, search/filter -->
    <script>
    function openModal(id){ document.getElementById(id).style.display='block'; }
    function closeModal(id){ document.getElementById(id).style.display='none'; }

    // show/hide new category inputs
    document.getElementById('add_cat').addEventListener('change', function(){
        document.getElementById('addNewCatDiv').style.display = (this.value==='__new__') ? 'block' : 'none';
    });
    document.getElementById('edit_category').addEventListener('change', function(){
        document.getElementById('editNewCatDiv').style.display = (this.value==='__new__') ? 'block' : 'none';
    });

    function editItem(id,name,price,category,image,stock){
        openModal('editItemModal');
        document.getElementById('edit_item_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_price').value = price;
        document.getElementById('edit_stock').value = stock;
        document.getElementById('edit_category').value = category;
        document.getElementById('current_image_preview').src = image || 'no-image.png';
    }

    function deleteItemConfirm(id,name){
        openModal('deleteItemModal');
        document.getElementById('delete_item_id_input').value = id;
        document.getElementById('delete_item_name_span').innerText = name;
    }

    // search + filter
    document.getElementById('searchInput').addEventListener('input', function(){
        applyFilter();
    });
    document.getElementById('filterCategory').addEventListener('change', function(){
        applyFilter();
    });

    function applyFilter(){
        const q = document.getElementById('searchInput').value.trim().toLowerCase();
        const cat = document.getElementById('filterCategory').value;
        document.querySelectorAll('#inventoryTbody tr').forEach(row=>{
            const name = row.getAttribute('data-name') || '';
            const rcat = row.getAttribute('data-cat') || '';
            const matches = (!q || name.includes(q)) && (!cat || rcat===cat);
            row.style.display = matches ? '' : 'none';
        });
    }
    </script>
    <?php


                } elseif ($view === 'suppliers' && $isAdmin) {

                    include("db.php");

                    // --- ADD SUPPLIER ---
                    if (isset($_POST['add_supplier'])) {
                        $name = $_POST['supplier_name'];
                        $company_name = $_POST['company_name'];
                        $phone = $_POST['supplier_phone'];
                        $email = $_POST['supplier_email'];
                        $address = $_POST['supplier_address'];

                        $stmt = $conn->prepare("INSERT INTO suppliers (contact_name, company_name, phone, email, address) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssss", $name, $company_name, $phone, $email, $address);
                        $stmt->execute();
                        $stmt->close();

                        echo "<script>alert('Supplier added successfully!'); window.location.href='?view=suppliers';</script>";
                        exit;
                    }

                    // --- EDIT SUPPLIER ---
                    if (isset($_POST['edit_supplier'])) {
                        $id = $_POST['edit_supplier_id'];
                        $name = $_POST['edit_supplier_name'];
                        $company_name = $_POST['edit_company_name'];
                        $phone = $_POST['edit_supplier_phone'];
                        $email = $_POST['edit_supplier_email'];
                        $address = $_POST['edit_supplier_address'];

                        $stmt = $conn->prepare("UPDATE suppliers SET contact_name=?, company_name=?, phone=?, email=?, address=? WHERE id=?");
                        $stmt->bind_param("sssssi", $name, $company_name, $phone, $email, $address, $id);
                        $stmt->execute();
                        $stmt->close();

                        echo "<script>alert('Supplier updated successfully!'); window.location.href='?view=suppliers';</script>";
                        exit;
                    }

                    // --- DELETE SUPPLIER ---
                    if (isset($_POST['delete_supplier'])) {
                        $supplier_id = $_POST['supplier_id'];
                        $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
                        $stmt->bind_param("i", $supplier_id);
                        $stmt->execute();
                        $stmt->close();

                        echo "<script>alert('Supplier deleted successfully!'); window.location.href='?view=suppliers';</script>";
                        exit;
                    }

                    // --- FETCH SUPPLIERS ---
                    $suppliers = [];
                    $result = $conn->query("SELECT * FROM suppliers ORDER BY id DESC");
                    if ($result && $result->num_rows > 0) {
                        while ($row = $result->fetch_assoc()) {
                            $suppliers[] = $row;
                        }
                    }
                ?>
                    <button class="btn btn-success" onclick="openModal('addSupplierModal')" style="margin-bottom: 20px;">+ Add New Supplier</button>

                    <div class="card">
                        <h2>Supplier List (<?php echo count($suppliers); ?>)</h2>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Contact Name</th>
                                    <th>Company Name</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <td><?php echo $supplier['id']; ?></td>
                                    <td><?php echo htmlspecialchars($supplier['contact_name']); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['company_name']); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['phone']); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['email']); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['address']); ?></td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" 
                                            onclick="editSupplier(<?php echo $supplier['id']; ?>, 
                                                '<?php echo htmlspecialchars(addslashes($supplier['contact_name'])); ?>',
                                                '<?php echo htmlspecialchars(addslashes($supplier['company_name'])); ?>',
                                                '<?php echo htmlspecialchars(addslashes($supplier['phone'])); ?>',
                                                '<?php echo htmlspecialchars(addslashes($supplier['email'])); ?>',
                                                '<?php echo htmlspecialchars(addslashes($supplier['address'])); ?>')">
                                            Edit
                                        </button>

                                        <button class="btn btn-danger btn-sm" 
                                            onclick="deleteSupplierConfirm(<?php echo $supplier['id']; ?>, '<?php echo htmlspecialchars(addslashes($supplier['contact_name'])); ?>')">
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (empty($suppliers)) echo '<p class="small">No suppliers yet.</p>'; ?>
                    </div>

                    <!-- Add Supplier Modal -->
                    <div id="addSupplierModal" class="modal">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2>Add New Supplier</h2>
                                <span class="close-btn" onclick="closeModal('addSupplierModal')">&times;</span>
                            </div>
                            <form method="POST">
                                <label>Contact Name:</label>
                                <input type="text" name="supplier_name" class="input" required style="margin-bottom: 10px;">
                                
                                <label>Company Name:</label>
                                <input type="text" name="company_name" class="input" required style="margin-bottom: 10px;">
                                
                                <label>Phone:</label>
                                <input type="text" name="supplier_phone" class="input" style="margin-bottom: 10px;">
                                
                                <label>Email:</label>
                                <input type="email" name="supplier_email" class="input" style="margin-bottom: 10px;">

                                <label>Address:</label>
                                <input type="text" name="supplier_address" class="input" style="margin-bottom: 20px;">

                                <button type="submit" name="add_supplier" class="btn btn-success">Add Supplier</button>
                            </form>
                        </div>
                    </div>

                    <!-- Edit Supplier Modal -->
                    <div id="editSupplierModal" class="modal">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2>Edit Supplier</h2>
                                <span class="close-btn" onclick="closeModal('editSupplierModal')">&times;</span>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="edit_supplier_id" id="edit_supplier_id">

                                <label>Contact Name:</label>
                                <input type="text" name="edit_supplier_name" id="edit_supplier_name" class="input" required style="margin-bottom: 10px;">
                                
                                <label>Company Name:</label>
                                <input type="text" name="edit_company_name" id="edit_company_name" class="input" required style="margin-bottom: 10px;">
                                
                                <label>Phone:</label>
                                <input type="text" name="edit_supplier_phone" id="edit_supplier_phone" class="input" style="margin-bottom: 10px;">
                                
                                <label>Email:</label>
                                <input type="email" name="edit_supplier_email" id="edit_supplier_email" class="input" style="margin-bottom: 10px;">

                                <label>Address:</label>
                                <input type="text" name="edit_supplier_address" id="edit_supplier_address" class="input" style="margin-bottom: 20px;">

                                <button type="submit" name="edit_supplier" class="btn btn-primary">Save Changes</button>
                            </form>
                        </div>
                    </div>

                    <!-- Delete Supplier Modal -->
                    <div id="deleteSupplierModal" class="modal">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h2>Confirm Delete Supplier</h2>
                                <span class="close-btn" onclick="closeModal('deleteSupplierModal')">&times;</span>
                            </div>
                            <p>Are you sure you want to delete supplier <strong><span id="delete_supplier_name_span"></span></strong>? This action cannot be undone.</p>
                            <form method="POST">
                                <input type="hidden" name="supplier_id" id="delete_supplier_id_input">
                                <button type="submit" name="delete_supplier" class="btn btn-danger">Yes, Delete Supplier</button>
                                <button type="button" class="btn btn-ghost" onclick="closeModal('deleteSupplierModal')">Cancel</button>
                            </form>
                        </div>
                    </div>

                    <script>
                    // Open edit modal and fill with supplier info
                    function editSupplier(id, name, company, phone, email, address) {
                        document.getElementById('edit_supplier_id').value = id;
                        document.getElementById('edit_supplier_name').value = name;
                        document.getElementById('edit_company_name').value = company;
                        document.getElementById('edit_supplier_phone').value = phone;
                        document.getElementById('edit_supplier_email').value = email;
                        document.getElementById('edit_supplier_address').value = address;
                        openModal('editSupplierModal');
                    }

                    // Delete supplier confirmation
                    function deleteSupplierConfirm(id, name) {
                        document.getElementById('delete_supplier_id_input').value = id;
                        document.getElementById('delete_supplier_name_span').innerText = name;
                        openModal('deleteSupplierModal');
                    }
                    </script>
                <?php

            } elseif ($view === 'settings' && $isAdmin) {
                ?>
                <div class="card">
                    <h2>Logo Upload</h2>
                    <p>Upload a new logo for your POS system.</p>
                    <img src="<?php echo currentLogoUrl(); ?>" alt="Current Logo" style="width: 100px; height: 100px; border-radius: 4px; object-fit: cover; margin-bottom: 15px;">
                    <form method="POST" enctype="multipart/form-data">
                        <label for="logo">Select new logo image (PNG, JPG, GIF, WEBP):</label>
                        <input type="file" name="logo" id="logo" class="input" required style="margin-bottom: 10px;">
                        <button type="submit" name="upload_logo" class="btn btn-primary">Upload Logo</button>
                    </form>
                </div>
                <?php
            } else {
                echo '<div class="card"><p>Page not found or access denied.</p></div>';
            }
            ?>
        </div>
    </div>
</div>

<?php else: ?>
<div class="auth-wrap">
    <div class="auth-head">
        <div class="brand">KAT-FFEINATED</div>
        <div class="small">POS</div>
        <h2 style="margin-top: 10px;">Login</h2>
    </div>

    <?php if ($flash): ?>
    <div class="notice notice-bad"><?php echo $flash; ?></div>
    <?php endif; ?>

    <form method="POST">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" class="input" required style="margin-bottom: 15px;">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" class="input" required style="margin-bottom: 20px;">
        
        <div style="display: flex; gap: 10px; align-items: center;">
            <button type="submit" name="login" class="btn btn-primary">Login</button>
            </div>
    </form>
</div>
<?php endif; ?>
</body>
</html>
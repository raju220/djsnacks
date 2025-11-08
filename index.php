<?php
// Database connection configuration
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "djfastfood";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set proper charset for UTF-8 support
$conn->set_charset('utf8mb4');

// Handle AJAX request for order details
if (isset($_GET['action']) && $_GET['action'] == 'getOrderDetails' && isset($_GET['orderId'])) {
    $orderId = (int)$_GET['orderId'];
    $sql = "SELECT oi.*, i.name as item_name 
            FROM order_items oi 
            JOIN items i ON oi.item_id = i.id 
            WHERE oi.order_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => 'Database prepare error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param("i", $orderId);
    if (!$stmt->execute()) {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['error' => 'Database execute error: ' . $stmt->error]);
        $stmt->close();
        exit;
    }

    $items = [];

    // Prefer get_result (requires mysqlnd), otherwise fallback to bind_result
    if (method_exists($stmt, 'get_result')) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    } else {
        // Fallback when get_result is not available
        $meta = $stmt->result_metadata();
        if ($meta) {
            $fields = [];
            $row = [];
            $bindVars = [];
            while ($field = $meta->fetch_field()) {
                $fields[] = $field->name;
                $row[$field->name] = null;
                $bindVars[] = &$row[$field->name];
            }
            call_user_func_array([$stmt, 'bind_result'], $bindVars);
            while ($stmt->fetch()) {
                $record = [];
                foreach ($fields as $name) {
                    $record[$name] = $row[$name];
                }
                $items[] = $record;
            }
            $meta->free();
        }
    }

    header('Content-Type: application/json');
    echo json_encode($items);
    $stmt->close();
    exit;
}

// Define prices array
$prices = [5, 10, 7, 15, 30, 30, 10, 10, 70];

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $items = [];
    $quantities = [];
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    $amount_paid = floatval($_POST['amount_paid'] ?? 0);

    // Validate input
    if ($total_amount < 0) {
        $error_message = "Invalid total amount!";
    } elseif ($amount_paid < 0) {
        $error_message = "Invalid amount paid!";
    } else {
        // Get all items and their quantities
        for ($i = 1; $i <= 9; $i++) {
            $qty = intval($_POST["qty-$i"] ?? 0);
            if ($qty > 0 && $qty <= 1000) { // Add reasonable upper limit
                $items[] = $i;
                $quantities[] = $qty;
            }
        }

        if (!empty($items) && $total_amount > 0) {
            // Insert order into orders table
            $sql = "INSERT INTO orders (total_amount, amount_paid, order_date) VALUES (?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                $error_message = "Database error: " . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8');
            } else {
                $stmt->bind_param("dd", $total_amount, $amount_paid);
                
                if ($stmt->execute()) {
                    $order_id = $conn->insert_id;
                    
                    // Insert order items
                    $sql = "INSERT INTO order_items (order_id, item_id, quantity, price) VALUES (?, ?, ?, ?)";
                    $stmt_items = $conn->prepare($sql);
                    if ($stmt_items) {
                        $hasError = false;
                        for ($i = 0; $i < count($items); $i++) {
                            $item_id = $items[$i];
                            $quantity = $quantities[$i];
                            $price = $prices[$item_id - 1];
                            
                            if (!$stmt_items->bind_param("iiid", $order_id, $item_id, $quantity, $price)) {
                                $hasError = true;
                                $error_message = "Error binding parameters: " . htmlspecialchars($stmt_items->error, ENT_QUOTES, 'UTF-8');
                                break;
                            }
                            
                            if (!$stmt_items->execute()) {
                                $hasError = true;
                                $error_message = "Error executing insert: " . htmlspecialchars($stmt_items->error, ENT_QUOTES, 'UTF-8');
                                break;
                            }
                        }
                        $stmt_items->close();
                        
                        if (!$hasError) {
                            $success_message = "Order saved successfully!";
                        }
                    } else {
                        $error_message = "Error preparing order items insert: " . htmlspecialchars($conn->error, ENT_QUOTES, 'UTF-8');
                    }
                } else {
                    $error_message = "Error saving order: " . htmlspecialchars($stmt->error, ENT_QUOTES, 'UTF-8');
                }
                $stmt->close();
            }
        } else {
            $error_message = "Please add items to the order!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ta">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="theme-color" content="#667eea">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="DJ Snack">
  <meta name="mobile-web-app-capable" content="yes">
  <title>DJ SNACK CORNER</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
  <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="manifest" href="manifest.json">
  <link rel="apple-touch-icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 192 192'%3E%3Cdefs%3E%3ClinearGradient id='grad' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' style='stop-color:%23667eea;stop-opacity:1' /%3E%3Cstop offset='100%25' style='stop-color:%23764ba2;stop-opacity:1' /%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='192' height='192' fill='url(%23grad)' rx='40'/%3E%3Ctext x='50%25' y='50%25' font-family='Arial' font-size='120' font-weight='bold' fill='white' text-anchor='middle' dominant-baseline='middle'%3EDJ%3C/text%3E%3C/svg%3E">
  
  <style>
    * {
      box-sizing: border-box;
      -webkit-tap-highlight-color: transparent;
    }

    :root {
      --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      --primary-color: #667eea;
      --secondary-color: #764ba2;
      --success-color: #10b981;
      --danger-color: #ef4444;
      --warning-color: #f59e0b;
      --shadow-sm: 0 2px 4px rgba(0,0,0,0.1);
      --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
      --shadow-lg: 0 10px 25px rgba(0,0,0,0.15);
      --shadow-xl: 0 20px 40px rgba(0,0,0,0.2);
    }

    body {
      background: var(--primary-gradient);
      font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      min-height: 100vh;
      min-height: -webkit-fill-available;
      padding: 0;
      margin: 0;
      padding-bottom: env(safe-area-inset-bottom, 20px);
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      overscroll-behavior-y: none;
    }

    /* Mobile-first navbar */
    .navbar {
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      box-shadow: var(--shadow-md);
      padding: 0.75rem 1rem;
      position: sticky;
      top: 0;
      z-index: 1000;
      border-bottom: 1px solid rgba(0,0,0,0.05);
    }

    .navbar-brand {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .sna {
      font-family: 'Poppins', sans-serif;
      font-weight: 700;
      font-size: clamp(1.1rem, 4vw, 1.5rem);
      background: var(--primary-gradient);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin: 0;
      letter-spacing: -0.5px;
    }

    .navbar-search {
      margin-top: 0.75rem;
      width: 100%;
    }

    .navbar-search input {
      border-radius: 12px;
      border: 2px solid #e5e7eb;
      padding: 0.6rem 1rem;
      font-size: 0.9rem;
      transition: all 0.3s ease;
    }

    .navbar-search input:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .navbar-search .btn {
      border-radius: 12px;
      padding: 0.6rem 1.2rem;
      font-weight: 600;
    }

    /* Modern card design */
    .card {
      border-radius: 24px;
      box-shadow: var(--shadow-lg);
      border: none;
      background: #ffffff;
      margin-bottom: 1.5rem;
      overflow: hidden;
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .card:active {
      transform: scale(0.98);
    }

    .card-header {
      background: var(--primary-gradient);
      color: white;
      font-weight: 600;
      font-size: clamp(1rem, 3vw, 1.2rem);
      border: none;
      text-align: center;
      padding: 1.25rem 1rem;
      position: relative;
      overflow: hidden;
    }

    .card-header::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      transition: left 0.5s;
    }

    .card-header:hover::before {
      left: 100%;
    }

    /* Mobile-optimized table */
    .table-responsive {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
    }

    .table-responsive::-webkit-scrollbar {
      height: 6px;
    }

    .table-responsive::-webkit-scrollbar-thumb {
      background: var(--primary-color);
      border-radius: 3px;
    }

    .table {
      margin-bottom: 0;
      width: 100%;
    }

    .table thead th {
      background: linear-gradient(180deg, #f8f9fa 0%, #e9ecef 100%);
      text-align: center;
      font-weight: 700;
      font-size: clamp(0.75rem, 2.5vw, 0.9rem);
      white-space: nowrap;
      padding: 1rem 0.5rem;
      border-bottom: 2px solid #dee2e6;
      color: #495057;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-size: 0.75rem;
    }

    .table tbody td {
      vertical-align: middle;
      text-align: center;
      font-size: clamp(0.8rem, 3vw, 0.95rem);
      padding: 1rem 0.5rem;
      border-bottom: 1px solid #f0f0f0;
    }

    .table tbody tr {
      transition: all 0.2s ease;
    }

    .table tbody tr:hover {
      background-color: #f8f9ff;
      transform: scale(1.01);
    }

    /* Mobile input styling */
    .table input.form-control {
      border: 2px solid #e5e7eb;
      border-radius: 12px;
      padding: 0.75rem;
      font-size: clamp(0.9rem, 3vw, 1rem);
      text-align: center;
      transition: all 0.3s ease;
      min-width: 60px;
      -webkit-appearance: none;
      appearance: none;
    }

    .table input.form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
      outline: none;
      transform: scale(1.05);
    }

    .form-control {
      border-radius: 12px;
      border: 2px solid #e5e7eb;
      padding: 0.75rem 1rem;
      transition: all 0.3s ease;
    }

    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
      outline: none;
    }

    /* Totals styling */
    #grand-total {
      color: var(--primary-color);
      font-weight: 700;
      font-size: clamp(1.1rem, 4vw, 1.3rem);
    }

    #grand-total-1 {
      font-size: clamp(1.2rem, 4.5vw, 1.5rem);
      font-weight: 700;
      color: var(--primary-color);
    }
    
    #balance {
      font-size: clamp(1.2rem, 4.5vw, 1.5rem);
      font-weight: 700;
      transition: all 0.3s ease;
    }

    /* Modern balance section */
    .balance-section {
      background: linear-gradient(135deg, #ffffff 0%, #f8f9ff 100%);
      border-radius: 24px;
      box-shadow: var(--shadow-xl);
      padding: clamp(1.25rem, 4vw, 2rem);
      text-align: center;
      border: 2px solid rgba(102, 126, 234, 0.1);
      position: sticky;
      top: 80px;
    }

    .balance-section h5 {
      color: var(--primary-color);
      font-weight: 700;
      margin-bottom: 1.5rem;
      font-size: clamp(1.1rem, 4vw, 1.3rem);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
    }

    /* Modern button styles */
    .btn {
      border-radius: 14px;
      padding: 0.75rem 1.5rem;
      font-weight: 600;
      font-size: clamp(0.9rem, 3vw, 1rem);
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      border: none;
      cursor: pointer;
      position: relative;
      overflow: hidden;
      touch-action: manipulation;
      -webkit-tap-highlight-color: transparent;
    }

    .btn::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 0;
      height: 0;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.3);
      transform: translate(-50%, -50%);
      transition: width 0.6s, height 0.6s;
    }

    .btn:active::before {
      width: 300px;
      height: 300px;
    }

    .btn-primary {
      background: var(--primary-gradient);
      color: white;
      box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
    }

    .btn-primary:hover, .btn-primary:focus {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(102, 126, 234, 0.5);
    }

    .btn-primary:active {
      transform: translateY(0);
    }

    .btn-danger {
      background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
      color: white;
      box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
    }

    .btn-danger:hover, .btn-danger:focus {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(239, 68, 68, 0.5);
    }

    .btn-danger:active {
      transform: translateY(0);
    }

    .btn-info {
      background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
      color: white;
      border: none;
      box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
    }

    .btn-info:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    }

    /* Footer buttons */
    .footer-buttons {
      background: linear-gradient(180deg, #f8f9fa 0%, #ffffff 100%);
      border-top: 2px solid #e9ecef;
      padding: 1.25rem;
      display: flex;
      gap: 0.75rem;
      justify-content: flex-end;
    }

    /* Mobile optimizations */
    @media (max-width: 768px) {
      body {
        padding-bottom: calc(20px + env(safe-area-inset-bottom));
      }

      .container {
        padding-left: 0.75rem;
        padding-right: 0.75rem;
        max-width: 100%;
      }

      .navbar {
        padding: 0.75rem 1rem;
      }

      .navbar-search {
        margin-top: 0.75rem;
      }

      .card {
        border-radius: 20px;
        margin-bottom: 1.25rem;
      }

      .card-header {
        padding: 1rem;
        font-size: 1rem;
      }

      .table thead th {
        padding: 0.75rem 0.4rem;
        font-size: 0.7rem;
      }

      .table tbody td {
        padding: 0.75rem 0.4rem;
        font-size: 0.85rem;
      }

      .table input.form-control {
        padding: 0.6rem;
        font-size: 0.9rem;
        min-width: 50px;
      }

      .balance-section {
        border-radius: 20px;
        padding: 1.5rem;
        position: relative;
        top: 0;
        margin-bottom: 1.5rem;
      }

      .footer-buttons {
        flex-direction: column;
        gap: 0.75rem;
      }

      .footer-buttons .btn {
        width: 100%;
        padding: 1rem;
        font-size: 1rem;
      }

      .row {
        margin-left: -0.5rem;
        margin-right: -0.5rem;
      }

      .row > * {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
      }

      /* Stack columns on mobile */
      .col-lg-8, .col-lg-4 {
        margin-bottom: 1rem;
      }
    }

    @media (max-width: 576px) {
      .navbar-search input {
        font-size: 16px; /* Prevents zoom on iOS */
      }

      .table input.form-control {
        font-size: 16px; /* Prevents zoom on iOS */
      }

      .form-control {
        font-size: 16px; /* Prevents zoom on iOS */
      }

      .card-header {
        font-size: 0.95rem;
      }

      .sna {
        font-size: 1.1rem;
      }
    }

    /* DataTable mobile adjustments */
    @media (max-width: 768px) {
      .dataTables_wrapper {
        font-size: 0.85rem;
      }

      .dataTables_wrapper .dataTables_length,
      .dataTables_wrapper .dataTables_filter {
        text-align: center;
        margin-bottom: 1rem;
      }

      .dataTables_wrapper .dataTables_length select,
      .dataTables_wrapper .dataTables_filter input {
        border-radius: 8px;
        padding: 0.5rem;
        font-size: 0.9rem;
      }

      .dataTables_wrapper .dataTables_info,
      .dataTables_wrapper .dataTables_paginate {
        text-align: center;
        margin-top: 1rem;
      }

      .dataTables_wrapper .dataTables_paginate .paginate_button {
        padding: 0.4rem 0.6rem;
        margin: 0 0.2rem;
        border-radius: 8px;
      }
    }

    /* Modern alert styling */
    .alert {
      border-radius: 16px;
      border: none;
      box-shadow: var(--shadow-md);
      padding: 1rem 1.25rem;
      margin-bottom: 1.5rem;
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    .alert-success {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(16, 185, 129, 0.05) 100%);
      color: #065f46;
      border-left: 4px solid var(--success-color);
    }

    .alert-danger {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(239, 68, 68, 0.05) 100%);
      color: #991b1b;
      border-left: 4px solid var(--danger-color);
    }

    /* Smooth scrolling */
    html {
      scroll-behavior: smooth;
      -webkit-text-size-adjust: 100%;
    }

    /* Loading animation */
    .spinner-border {
      width: 2rem;
      height: 2rem;
      border-width: 0.2em;
    }

    /* Touch-friendly elements */
    @media (hover: none) and (pointer: coarse) {
      .btn, .form-control, input, select, textarea {
        min-height: 44px; /* Apple's recommended touch target */
      }
    }

    /* Safe area support for notched devices */
    @supports (padding: max(0px)) {
      body {
        padding-left: max(1rem, env(safe-area-inset-left));
        padding-right: max(1rem, env(safe-area-inset-right));
      }

      .navbar {
        padding-left: max(1rem, env(safe-area-inset-left));
        padding-right: max(1rem, env(safe-area-inset-right));
      }
    }

    /* Card body padding */
    .card-body {
      padding: 1.5rem;
    }

    @media (max-width: 768px) {
      .card-body {
        padding: 1rem;
      }
    }

    /* Modal improvements for mobile */
    .modal-dialog {
      margin: 1rem;
    }

    .modal-content {
      border-radius: 24px;
      border: none;
      box-shadow: var(--shadow-xl);
    }

    .modal-header {
      border-bottom: 2px solid #f0f0f0;
      padding: 1.5rem;
      border-radius: 24px 24px 0 0;
    }

    .modal-body {
      padding: 1.5rem;
    }

    @media (max-width: 576px) {
      .modal-dialog {
        margin: 0.5rem;
      }

      .modal-header, .modal-body {
        padding: 1rem;
      }
    }

    /* Text color utilities */
    .text-success {
      color: var(--success-color) !important;
    }

    .text-danger {
      color: var(--danger-color) !important;
    }

    .text-warning {
      color: var(--warning-color) !important;
    }

    /* Enhanced table row styling */
    .table-striped tbody tr:nth-of-type(odd) {
      background-color: rgba(102, 126, 234, 0.02);
    }

    /* Focus visible for accessibility */
    *:focus-visible {
      outline: 3px solid var(--primary-color);
      outline-offset: 2px;
    }

    /* Mobile-specific table improvements */
    @media (max-width: 576px) {
      /* Keep menu table as regular table */
      #orderForm .table {
        font-size: 0.85rem;
      }

      #orderForm .table thead th {
        padding: 0.5rem 0.25rem;
        font-size: 0.7rem;
      }

      #orderForm .table tbody td {
        padding: 0.5rem 0.25rem;
      }

      /* DataTables responsive child rows styling */
      #ordersTable tbody tr.child {
        background: #f8f9fa;
        padding: 1rem;
      }

      #ordersTable tbody tr.child ul {
        list-style: none;
        padding: 0;
        margin: 0;
      }

      #ordersTable tbody tr.child li {
        padding: 0.5rem 0;
        border-bottom: 1px solid #e5e7eb;
      }

      #ordersTable tbody tr.child li:last-child {
        border-bottom: none;
      }

      #ordersTable tbody tr.child .dtr-title {
        font-weight: 700;
        color: #667eea;
        min-width: 120px;
        display: inline-block;
      }

      /* Improve DataTables responsive button */
      table.dataTable.dtr-inline.collapsed > tbody > tr[role="row"] > td:first-child:before,
      table.dataTable.dtr-inline.collapsed > tbody > tr[role="row"] > th:first-child:before {
        background-color: var(--primary-color);
        border: 2px solid white;
        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        top: 50%;
        transform: translateY(-50%);
      }
    }

    /* Improved button spacing on mobile */
    @media (max-width: 576px) {
      .footer-buttons {
        position: sticky;
        bottom: 0;
        background: white;
        box-shadow: 0 -4px 6px rgba(0,0,0,0.1);
        z-index: 100;
        padding: 1rem;
        margin: 0 -0.75rem -1rem;
        border-radius: 0;
      }
    }

    /* Loading state improvements */
    .spinner-border {
      border: 0.25em solid currentColor;
      border-right-color: transparent;
    }
  </style>
</head>

<body>

  <!-- Main Container -->
  <div class="container mt-3 mt-md-4 px-2 px-md-3">
    
    <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
      <strong>Success!</strong> <?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
      <strong>Error!</strong> <?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div class="row g-4">

      <!-- Menu Card -->
      <div class="col-lg-8">
        <div class="card">
          <form method="POST" id="orderForm">
            <div class="card-header">🍴 பொருள் விவரம்</div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th>பொருள் பெயர்</th>
                      <th>விலை</th>
                      <th>அளவு</th>
                      <th>மொத்தம்</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr><td>சுக்கு காபி</td><td>₹ 5</td><td><input type="number" min="0" class="form-control" name="qty-1" id="tds-1"></td><td id="total-1">₹ 0</td></tr>
                    <tr><td>பானிபூரி</td><td>₹ 10</td><td><input type="number" min="0" class="form-control" name="qty-2" id="tds-2"></td><td id="total-2">₹ 0</td></tr>
                    <tr><td>சாமோசா</td><td>₹ 7</td><td><input type="number" min="0" class="form-control" name="qty-3" id="tds-3"></td><td id="total-3">₹ 0</td></tr>
                    <tr><td>சிக்கன் சாமோசா</td><td>₹ 15</td><td><input type="number" min="0" class="form-control" name="qty-4" id="tds-4"></td><td id="total-4">₹ 0</td></tr>
                    <tr><td>காளான்</td><td>₹ 30</td><td><input type="number" min="0" class="form-control" name="qty-5" id="tds-5"></td><td id="total-5">₹ 0</td></tr>
                    <tr><td>பிரட் ஆம்லெட்</td><td>₹ 30</td><td><input type="number" min="0" class="form-control" name="qty-6" id="tds-6"></td><td id="total-6">₹ 0</td></tr>
                    <tr><td>போலி</td><td>₹ 10</td><td><input type="number" min="0" class="form-control" name="qty-7" id="tds-7"></td><td id="total-7">₹ 0</td></tr>
                    <tr><td>மோமோஸ்</td><td>₹ 10</td><td><input type="number" min="0" class="form-control" name="qty-8" id="tds-8"></td><td id="total-8">₹ 0</td></tr>
                    <tr><td>சாண்ட்விச்</td><td>₹ 70</td><td><input type="number" min="0" class="form-control" name="qty-9" id="tds-9"></td><td id="total-9">₹ 0</td></tr>
                    <tr class="fw-bold bg-light">
                      <td colspan="3" class="text-end">மொத்தம்:</td>
                      <td id="grand-total">₹ 0</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="footer-buttons">
              <input type="hidden" name="total_amount" id="total_amount" value="0">
              <button type="submit" class="btn btn-primary w-100 w-md-auto">
                <span>✓ Submit Order</span>
              </button>
              <button type="button" class="btn btn-danger w-100 w-md-auto" id="clear-button">
                <span>🗑️ Clear All</span>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Balance Section -->
      <div class="col-lg-4">
        <div class="balance-section">
          <h5 class="mb-3">💰 பில் விவரம்</h5>
          <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded">
            <span class="fw-semibold">மொத்தம்:</span>
            <span id="grand-total-1" class="fw-bold">₹ 0</span>
          </div>
          <div class="mb-3">
            <label for="amount-input" class="form-label fw-semibold mb-2">Amount Paid</label>
            <input type="number" min="0" step="0.01" class="form-control text-center" form="orderForm" name="amount_paid" placeholder="Enter amount" id="amount-input" inputmode="decimal">
          </div>
          <div class="d-flex justify-content-between align-items-center p-3 rounded" style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);">
            <span class="fw-semibold">Balance:</span>
            <span id="balance" class="fw-bold">₹ 0.00</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Orders Table Section -->
     <div class="nxtpage" id="ordersSection">
        <div class="card mt-4">
      <div class="card-header">📋 Orders History</div>
      <div class="card-body">
        <div class="table-responsive">
          <table id="ordersTable" class="table table-striped table-bordered">
            <thead>
              <tr>
                <th>Order ID</th>
                <th>Total Amount</th>
                <th>Amount Paid</th>
                <th>Balance</th>
                <th>Order Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              // Use prepared statement for security
              $sql = "SELECT * FROM orders ORDER BY order_date DESC";
              $stmt = $conn->prepare($sql);
              
              if ($stmt && $stmt->execute()) {
                  $result = $stmt->get_result();
                  if ($result && $result->num_rows > 0) {
                      while($row = $result->fetch_assoc()) {
                          $balance = $row['amount_paid'] - $row['total_amount'];
                          $balanceFormatted = number_format($balance, 2);
                          $balanceClass = $balance < 0 ? 'text-danger' : ($balance > 0 ? 'text-success' : '');
                          
                          echo "<tr>";
                          echo "<td data-label='Order ID'>" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "</td>";
                          echo "<td data-label='Total Amount'>₹ " . number_format($row['total_amount'], 2) . "</td>";
                          echo "<td data-label='Amount Paid'>₹ " . number_format($row['amount_paid'], 2) . "</td>";
                          echo "<td data-label='Balance' class='" . $balanceClass . "'>₹ " . $balanceFormatted . "</td>";
                          echo "<td data-label='Order Date'>" . htmlspecialchars(date('Y-m-d H:i:s', strtotime($row['order_date'])), ENT_QUOTES, 'UTF-8') . "</td>";
                          echo "<td data-label='Actions'><button class='btn btn-sm btn-info view-details' data-order-id='" . htmlspecialchars($row['id'], ENT_QUOTES, 'UTF-8') . "'>View Details</button></td>";
                          echo "</tr>";
                      }
                  } else {
                      echo "<tr><td colspan='6' class='text-center'>No orders found</td></tr>";
                  }
                  $stmt->close();
              } else {
                  echo "<tr><td colspan='6' class='text-center text-danger'>Error loading orders</td></tr>";
              }
              $conn->close();
              ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
     </div>
    
  </div>

  <!-- Order Details Modal -->
  <div class="modal fade" id="orderDetailsModal" tabindex="-1" aria-labelledby="orderDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="orderDetailsModalLabel">Order Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="orderItemsTable"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Scripts -->
  <script src="https://code.jquery.com/jquery-3.7.0.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
  <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

  <script>
    const prices = [5, 10, 7, 15, 30, 30, 10, 10, 70];

    function calculateTotals() {
      let grandTotal = 0;
      for (let i = 0; i < prices.length; i++) {
        const qty = parseInt(document.getElementById(`tds-${i + 1}`).value) || 0;
        const total = prices[i] * qty;
        document.getElementById(`total-${i + 1}`).innerText = `₹ ${total}`;
        grandTotal += total;
      }
      document.getElementById('grand-total').innerText = `₹ ${grandTotal}`;
      document.getElementById('grand-total-1').innerText = `₹ ${grandTotal}`;
      document.getElementById('total_amount').value = grandTotal;
      calculateBalance();
    }

    function calculateBalance() {
      const grandTotalText = document.getElementById('grand-total-1').innerText.replace(/₹\s/g, '').replace(/,/g, '');
      const grandTotal = parseFloat(grandTotalText) || 0;
      const amountPaid = parseFloat(document.getElementById('amount-input').value) || 0;
      const balance = amountPaid - grandTotal; // Positive = overpaid, Negative = owes more
      const balanceElement = document.getElementById('balance');
      
      // Add color coding for balance
      balanceElement.classList.remove('text-success', 'text-danger', 'text-warning');
      if (balance > 0) {
        balanceElement.innerText = `₹ ${balance.toFixed(2)} (Overpaid)`;
        balanceElement.classList.add('text-success');
      } else if (balance < 0) {
        balanceElement.innerText = `₹ ${Math.abs(balance).toFixed(2)} (Due)`;
        balanceElement.classList.add('text-danger');
      } else {
        balanceElement.innerText = `₹ 0.00`;
        balanceElement.classList.add('text-warning');
      }
    }

    // Add event listeners
    for (let i = 0; i < prices.length; i++) {
      document.getElementById(`tds-${i + 1}`).addEventListener('input', calculateTotals);
    }
    document.getElementById('amount-input').addEventListener('input', calculateBalance);

    // Clear button functionality
    document.getElementById('clear-button').addEventListener('click', () => {
      for (let i = 1; i <= 9; i++) {
        document.getElementById(`tds-${i}`).value = '';
        document.getElementById(`total-${i}`).innerText = '₹ 0';
      }
      document.getElementById('grand-total').innerText = '₹ 0';
      document.getElementById('grand-total-1').innerText = '₹ 0';
      document.getElementById('amount-input').value = '';
      document.getElementById('total_amount').value = '0';
      
      // Reset balance with proper formatting
      const balanceElement = document.getElementById('balance');
      balanceElement.innerText = '₹ 0.00';
      balanceElement.classList.remove('text-success', 'text-danger', 'text-warning');
      balanceElement.classList.add('text-warning');
    });

    // Form validation
    document.getElementById('orderForm').addEventListener('submit', function(e) {
      const totalAmount = parseFloat(document.getElementById('total_amount').value);
      const amountPaid = parseFloat(document.getElementById('amount-input').value) || 0;
      
      if (totalAmount <= 0) {
        e.preventDefault();
        alert('Please add items to the order!');
        return false;
      }
      
      if (amountPaid < 0) {
        e.preventDefault();
        alert('Amount paid cannot be negative!');
        return false;
      }
      
      // Validate quantity inputs
      let hasValidQuantity = false;
      for (let i = 1; i <= 9; i++) {
        const qty = parseInt(document.getElementById(`tds-${i}`).value) || 0;
        if (qty > 0) {
          if (qty > 1000) {
            e.preventDefault();
            alert(`Quantity for item ${i} is too large! Maximum is 1000.`);
            return false;
          }
          hasValidQuantity = true;
        }
      }
      
      if (!hasValidQuantity) {
        e.preventDefault();
        alert('Please add at least one item to the order!');
        return false;
      }
    });

    // Initialize DataTable
    $(document).ready(function() {
      $('#ordersTable').DataTable({
        responsive: {
          details: {
            type: 'column',
            target: 'tr'
          }
        },
        order: [[0, 'desc']],
        pageLength: 10,
        language: {
          search: "Search:",
          lengthMenu: "Show _MENU_ entries",
          info: "Showing _START_ to _END_ of _TOTAL_ orders",
          infoEmpty: "No orders found",
          infoFiltered: "(filtered from _MAX_ total orders)",
          paginate: {
            first: "First",
            last: "Last",
            next: "Next",
            previous: "Previous"
          }
        },
        columnDefs: [
          { responsivePriority: 1, targets: 0 },
          { responsivePriority: 2, targets: 4 },
          { responsivePriority: 3, targets: 5 }
        ]
      });

      // Handle View Details button click
      $(document).on('click', '.view-details', function() {
        const orderId = $(this).data('order-id');
        if (!orderId || isNaN(orderId)) {
          alert('Invalid order ID');
          return;
        }
        
        $('#orderItemsTable').html('<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Loading...</span></div></div>');
        $('#orderDetailsModal').modal('show');
        
        $.get('index.php', { action: 'getOrderDetails', orderId: orderId })
          .done(function(data) {
            if (typeof data === 'string') {
              try {
                data = JSON.parse(data);
              } catch(e) {
                $('#orderItemsTable').html('<div class="alert alert-danger">Error parsing response. Please try again.</div>');
                return;
              }
            }
            
            let html = '<div class="table-responsive"><table class="table table-bordered table-hover">';
            html += '<thead class="table-light"><tr><th>Item Name</th><th>Quantity</th><th>Price</th><th>Total</th></tr></thead><tbody>';
            
            if (data.length > 0 && !data.error) {
              let orderTotal = 0;
              data.forEach(item => {
                const itemPrice = parseFloat(item.price) || 0;
                const itemQuantity = parseInt(item.quantity) || 0;
                const total = itemPrice * itemQuantity;
                orderTotal += total;
                
                // Escape HTML to prevent XSS
                const itemName = $('<div>').text(item.item_name || '').html();
                html += `<tr>
                  <td>${itemName}</td>
                  <td>${itemQuantity}</td>
                  <td>₹ ${itemPrice.toFixed(2)}</td>
                  <td>₹ ${total.toFixed(2)}</td>
                </tr>`;
              });
              html += `<tr class="table-info fw-bold">
                <td colspan="3" class="text-end">Grand Total:</td>
                <td>₹ ${orderTotal.toFixed(2)}</td>
              </tr>`;
            } else {
              const errorMsg = data.error || 'No items found';
              html += `<tr><td colspan="4" class="text-center">${$('<div>').text(errorMsg).html()}</td></tr>`;
            }
            
            html += '</tbody></table></div>';
            $('#orderItemsTable').html(html);
          })
          .fail(function(xhr, status, error) {
            $('#orderItemsTable').html('<div class="alert alert-danger">Error loading order details. Please try again.<br><small>' + $('<div>').text(error).html() + '</small></div>');
          });
      });
      
      // Order search functionality
      $('#searchBtn').on('click', function() {
        const searchTerm = $('#orderSearch').val();
        if (searchTerm) {
          $('#ordersTable').DataTable().search(searchTerm).draw();
        } else {
          $('#ordersTable').DataTable().search('').draw();
        }
      });

      $('#orderSearch').on('keypress', function(e) {
        if (e.which === 13) {
          e.preventDefault();
          $('#searchBtn').click();
        }
      });

      // Clear search on empty input
      $('#orderSearch').on('input', function() {
        if (!$(this).val()) {
          $('#ordersTable').DataTable().search('').draw();
        }
      });
    });

    // PWA Service Worker Registration
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
          .then((registration) => {
            console.log('Service Worker registered:', registration);
          })
          .catch((error) => {
            console.log('Service Worker registration failed:', error);
          });
      });
    }

    // PWA Install Prompt
    let deferredPrompt;
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredPrompt = e;
      // You can show a custom install button here
    });

    // Prevent iOS bounce scrolling
    document.addEventListener('touchmove', function(e) {
      if (e.target.closest('.table-responsive, .modal-body')) {
        return;
      }
    }, { passive: true });

    // Add haptic feedback for mobile buttons
    if ('vibrate' in navigator) {
      document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('touchstart', function() {
          navigator.vibrate(10);
        });
      });
    }

    // Prevent zoom on double tap (iOS)
    let lastTouchEnd = 0;
    document.addEventListener('touchend', function(event) {
      const now = Date.now();
      if (now - lastTouchEnd <= 300) {
        event.preventDefault();
      }
      lastTouchEnd = now;
    }, false);
  </script>

</body>
</html>
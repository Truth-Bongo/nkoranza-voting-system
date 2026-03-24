<?php
if (!isset($pageTitle)) {
    $pageTitle = "Page Not Found";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; text-align: center; padding: 50px; }
        h1 { font-size: 50px; margin-bottom: 10px; }
        p { font-size: 18px; color: #555; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <h1>404</h1>
    <p>Oops! The page you are looking for could not be found.</p>
    <p><a href="<?= BASE_URL ?>">Go back to Home</a></p>
</body>
</html>

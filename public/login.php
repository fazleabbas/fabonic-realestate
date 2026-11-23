<?php
/**
 * Fabonic Real Estate — Tenant Login Redirector
 * Each tenant logs in at their own subdomain (e.g. acme.real.co.tz).
 * This little page asks for the subdomain and forwards them there.
 */
$slug = strtolower(preg_replace('/[^a-z0-9]/', '', $_GET['s'] ?? ''));
if ($slug !== '' && strlen($slug) >= 3) {
    header('Location: https://' . $slug . '.real.co.tz/modules/auth/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Fabonic Real Estate</title>
    <link rel="icon" type="image/png" href="assets/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
               background: linear-gradient(135deg, #003b7a 0%, #002a57 100%);
               min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
        .card-login { background: #fff; border-radius: 18px; box-shadow: 0 24px 60px rgba(0,0,0,.25);
                      padding: 2.5rem; max-width: 420px; width: 92%; text-align: center; }
        .card-login img { height: 40px; margin-bottom: 1.25rem; }
        .card-login h1 { font-size: 1.4rem; font-weight: 800; color: #1a2233; margin-bottom: .3rem; }
        .card-login p { color: #5a6473; font-size: .95rem; }
        .input-group-text { background: #f6f8fb; font-size: .85rem; color: #5a6473; }
        .form-control { border-radius: 10px; }
        .form-control:focus { border-color: #003b7a; box-shadow: 0 0 0 .2rem rgba(0,59,122,.15); }
        .btn-go { background: #003b7a; color: #fff; border: none; border-radius: 10px;
                  padding: .7rem; font-weight: 600; width: 100%; }
        .btn-go:hover { background: #002a57; color: #fff; }
        a.small-link { color: #003b7a; font-size: .85rem; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card-login">
        <img src="assets/logo.png" alt="Fabonic Real Estate">
        <h1>Log in to your account</h1>
        <p>Enter your company's subdomain to continue.</p>
        <form method="get" action="login.php" class="mt-4">
            <div class="input-group mb-2">
                <input type="text" name="s" class="form-control" placeholder="yourcompany" autofocus
                       pattern="[a-zA-Z0-9]+" required>
                <span class="input-group-text">.real.co.tz</span>
            </div>
            <button type="submit" class="btn-go mt-2"><i class="bi bi-box-arrow-in-right me-1"></i> Continue to Login</button>
        </form>
        <p class="mt-4 mb-0"><a href="index.php" class="small-link">&larr; Back to home</a>
           &nbsp;·&nbsp; <a href="index.php#signup" class="small-link">Create an account</a></p>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation complete — Pépite</title>
<link rel="stylesheet" href="<?= base_url('vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= base_url('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>">
<style>
    :root { --bs-body-bg: #fbfaf7; --bs-body-color: #1c1a17; --bs-primary: #a8791f; }
    @media (prefers-color-scheme: dark) {
        :root { --bs-body-bg: #14171c; --bs-body-color: #e6e9ee; --bs-primary: #5b9cf6; }
    }
</style>
</head>
<body>
<main class="container py-5 text-center" style="max-width: 32rem;">
    <i class="bi bi-check-circle-fill text-success" style="font-size: 3rem;"></i>
    <h1 class="h3 mt-3">Pépite is installed</h1>
    <p class="text-body-secondary">The administrator account has been created. This installer is now locked.</p>
    <a class="btn btn-primary" href="<?= site_url('login') ?>">Log in</a>
</main>
</body>
</html>

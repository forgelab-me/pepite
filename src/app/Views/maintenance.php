<?php
/**
 * Rendered by ci4-updater's maintenance filter (Forgelabme\Ci4Updater\Filters\Maintenance)
 * while a release is being applied — the panel and `updater:apply` alike, and
 * for as long as the write actually takes rather than a manually toggled flag.
 *
 * A NuGet client under feeds/* gets the JSON body App\Filters\Maintenance
 * used to return before this superseded it; everything else — the web
 * console, a browser — gets a plain page. $retryAfter and $state come from
 * the filter; see Config\Updater::$maintenanceView.
 */

$path = ltrim((string) service('request')->getUri()->getPath(), '/');

if (str_starts_with($path, 'feeds/')) {
    service('response')->setContentType('application/json');

    echo json_encode(
        ['error' => 'A release is being applied. Try again shortly.'],
        JSON_UNESCAPED_SLASHES,
    );

    return;
}
?>
<!doctype html>
<meta charset="utf-8">
<title>Updating — Pépite</title>
<div style="font:16px/1.5 system-ui,-apple-system,sans-serif;max-width:32rem;margin:20vh auto;text-align:center;padding:0 1rem;">
    <h1 style="font-size:1.25rem;">Updating</h1>
    <p>Pépite is applying an update and will be back shortly.</p>
</div>

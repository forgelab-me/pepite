# Vendored assets

Committed here rather than loaded from a CDN, so the admin console and public
pages render with no third-party network request — including on a host with
no outbound internet access at all.

| | Version | License | Source |
|---|---|---|---|
| Bootstrap | 5.3.3 | MIT | https://getbootstrap.com |
| Bootstrap Icons | 1.11.3 | MIT | https://icons.getbootstrap.com |

To update either: download the release's `-dist` (Bootstrap) or font package
(Bootstrap Icons) archive and replace the files here — `bootstrap.min.css`,
`bootstrap.bundle.min.js`, `bootstrap-icons.min.css` and `fonts/`. Nothing
else in this project reads these files by a versioned path.

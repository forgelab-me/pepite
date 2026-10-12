<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\AuditLogModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Read-only view of the audit log. Nothing here edits or removes an entry —
 * see AuditLogModel for why.
 */
final class Audit extends Controller
{
    private const PER_PAGE = 50;

    public function index(): ResponseInterface
    {
        $page = max(1, (int) $this->request->getGet('page'));

        // One more than a page, only to know whether an older page exists
        // without a second COUNT over a table that only ever grows.
        $rows    = model(AuditLogModel::class)->page($page, self::PER_PAGE, peek: 1);
        $hasMore = count($rows) > self::PER_PAGE;

        return $this->response->setBody(view('admin/audit/index', [
            'entries' => array_slice($rows, 0, self::PER_PAGE),
            'page'    => $page,
            'hasMore' => $hasMore,
        ]));
    }
}

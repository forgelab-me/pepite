<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Models\AuditLogModel;
use CodeIgniter\HTTP\CLIRequest;
use Throwable;

/**
 * Records who did what, once it has been done.
 *
 * Callers say the action and its target; who and how are worked out here, so
 * that the web console, the NuGet API and the CLI all leave the same kind of
 * entry without each repeating the lookup.
 *
 * It never throws. The action has already happened by the time it is logged,
 * and failing the request over a log line would leave the operator with the
 * deletion done and an error page claiming otherwise.
 */
final class Audit
{
    public function __construct(private readonly AuditLogModel $log)
    {
    }

    /**
     * @param array<string, mixed> $detail
     */
    public function record(string $action, ?string $target = null, array $detail = []): void
    {
        try {
            [$via, $userId, $label] = $this->actor();

            $this->log->insert([
                'created_at'    => date('Y-m-d H:i:s'),
                'actor_user_id' => $userId,
                'actor_label'   => $label,
                'via'           => $via,
                'action'        => $action,
                'target'        => $target === null ? null : mb_substr($target, 0, 255),
                'detail'        => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip'            => $this->ip(),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Audit entry "{action}" was not recorded: {message}', [
                'action'  => $action,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{string, int|null, string|null} [via, user id, label]
     */
    private function actor(): array
    {
        if ($this->isCli()) {
            return ['cli', null, null];
        }

        // A browser session and a NuGet key are different authenticators; the
        // API filter logs the key's owner into the tokens one.
        $session = auth('session')->user();

        if ($session !== null) {
            return ['web', (int) $session->id, $this->label($session)];
        }

        $token = auth('tokens')->user();

        if ($token !== null) {
            return ['api', (int) $token->id, $this->label($token)];
        }

        return ['system', null, null];
    }

    /**
     * Not is_cli(): PHPUnit is a CLI process too, and its feature tests
     * stand in for real web requests. What distinguishes the two is the
     * request object the framework built for them.
     */
    private function isCli(): bool
    {
        return service('request') instanceof CLIRequest;
    }

    private function label(object $user): string
    {
        $email = $user->email ?? null;

        return (string) ($user->username ?? $email ?? ('#' . $user->id));
    }

    private function ip(): ?string
    {
        if ($this->isCli()) {
            return null;
        }

        $ip = service('request')->getIPAddress();

        return $ip === '' ? null : $ip;
    }
}

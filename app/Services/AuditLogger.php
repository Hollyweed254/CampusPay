<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;

final class AuditLogger
{
    public static function log(
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        array $metadata = [],
        string $actorType = 'administrator',
        ?int $actorId = null
    ): void {
        if ($actorType === 'administrator' && $actorId === null) {
            $actorId = Auth::admin()['id'] ?? null;
        }
        q(
            'INSERT INTO audit_logs (actor_type, actor_id, action, target_type, target_id, metadata_json, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $actorType,
                $actorId,
                $action,
                $targetType,
                $targetId,
                $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                request_ip(),
            ]
        );
    }
}

<?php

namespace App\Http\Resources;

/**
 * Format commun des auteurs des notes, liens et documents.
 *
 * @internal
 */
final class AttachmentResources
{
    public static function author($user): ?array
    {
        return $user ? ['id' => $user->id, 'full_name' => $user->full_name] : null;
    }
}

<?php

namespace App\Http\Controllers\Concerns;

trait ReleasesEmails
{
    // Builds the parked form of a deleted account's address, e.g.
    // "deleted+12+ary@gmail.com". The id keeps it unique and the original
    // address stays readable for support and audit purposes.
    protected function releasedEmail(int $id, string $email): string
    {
        $prefix = "deleted+{$id}+";

        if (str_starts_with($email, 'deleted+')) {
            return $email;
        }

        return substr($prefix.$email, 0, 255);
    }
}

<?php

namespace App\Services\SocialIdentity;

// A verified person, as described by the provider that vouched for them.
class SocialIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
    ) {}

    // The only address we will link an account by. An unverified address is
    // worth nothing: anyone can type someone else's email into a provider
    // that doesn't check it and would otherwise walk into their account.
    public function trustedEmail(): ?string
    {
        return $this->emailVerified ? $this->email : null;
    }
}

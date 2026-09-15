<?php

namespace App\Services\SocialIdentity;

use RuntimeException;

// The token did not check out. The message is for our logs, never for the
// client: telling a caller which claim failed just helps them forge a
// better one next time.
class InvalidIdTokenException extends RuntimeException {}

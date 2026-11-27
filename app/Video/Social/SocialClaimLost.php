<?php

declare(strict_types=1);

namespace App\Video\Social;

use RuntimeException;

/**
 * A worker no longer holds the posting it was working on: its claim was taken over, or released. It stops where it is —
 * no request to a platform after this, and nothing more written on the posting, which is now another process's.
 */
final class SocialClaimLost extends RuntimeException {}

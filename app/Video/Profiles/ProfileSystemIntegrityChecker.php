<?php

declare(strict_types=1);

namespace App\Video\Profiles;

use App\Video\Profiles\Validation\CategorySemanticValidatorRegistry;
use RuntimeException;

final class ProfileSystemIntegrityChecker
{
    public function __construct(
        private readonly CategoryCreativeProfileRegistry $profiles,
        private readonly CategorySemanticValidatorRegistry $validators,
    ) {}

    public function assertValid(): void
    {
        foreach ($this->profiles->all() as $profile) {
            if (! $this->validators->has($profile->key)) {
                throw new RuntimeException(
                    sprintf(
                        'Profile "%s@%s" has no registered semantic validator.',
                        $profile->key,
                        $profile->version,
                    )
                );
            }
        }
    }
}

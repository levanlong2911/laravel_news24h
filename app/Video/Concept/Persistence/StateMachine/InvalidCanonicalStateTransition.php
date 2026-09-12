<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\StateMachine;

use LogicException;

final class InvalidCanonicalStateTransition extends LogicException {}

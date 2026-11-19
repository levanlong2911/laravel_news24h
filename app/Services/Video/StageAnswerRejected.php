<?php

declare(strict_types=1);

namespace App\Services\Video;

use RuntimeException;

/**
 * An answer a model was paid for that the step cannot turn into its record: the run is stored as failed with this reason, the answer and its usage are kept, and no model is called again.
 */
final class StageAnswerRejected extends RuntimeException {}

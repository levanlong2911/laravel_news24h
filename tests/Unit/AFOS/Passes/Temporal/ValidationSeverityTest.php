<?php

namespace Tests\Unit\AFOS\Passes\Temporal;

use App\Services\AI\AFOS\Ir\Temporal\RelationType;
use App\Services\AI\AFOS\Ir\Temporal\Validation\DuplicateIdError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\LayerConflictError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\MissingReferenceError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\TemporalConstraintError;
use App\Services\AI\AFOS\Ir\Temporal\Validation\TimelineValidationResult;
use App\Services\AI\AFOS\Ir\Temporal\Validation\ValidationSeverity;
use PHPUnit\Framework\TestCase;

final class ValidationSeverityTest extends TestCase
{
    // ── ValidationSeverity enum ───────────────────────────────────────────────

    public function test_severity_ordering(): void
    {
        $this->assertTrue(ValidationSeverity::Error->isAtLeast(ValidationSeverity::Error));
        $this->assertTrue(ValidationSeverity::Error->isAtLeast(ValidationSeverity::Warning));
        $this->assertTrue(ValidationSeverity::Error->isAtLeast(ValidationSeverity::Info));
        $this->assertTrue(ValidationSeverity::Warning->isAtLeast(ValidationSeverity::Info));
        $this->assertTrue(ValidationSeverity::Warning->isAtLeast(ValidationSeverity::Warning));
        $this->assertFalse(ValidationSeverity::Warning->isAtLeast(ValidationSeverity::Error));
        $this->assertFalse(ValidationSeverity::Info->isAtLeast(ValidationSeverity::Warning));
        $this->assertFalse(ValidationSeverity::Info->isAtLeast(ValidationSeverity::Error));
    }

    public function test_severity_labels(): void
    {
        $this->assertSame('INFO',    ValidationSeverity::Info->label());
        $this->assertSame('WARNING', ValidationSeverity::Warning->label());
        $this->assertSame('ERROR',   ValidationSeverity::Error->label());
    }

    // ── Error class severities ─────────────────────────────────────────────────

    public function test_hard_temporal_constraint_is_error(): void
    {
        $err = new TemporalConstraintError('a', 'b', RelationType::Hard, 2.0, 3.0);
        $this->assertSame(ValidationSeverity::Error, $err->severity());
    }

    public function test_follows_temporal_constraint_is_warning(): void
    {
        $err = new TemporalConstraintError('a', 'b', RelationType::Follows, 2.0, 3.0);
        $this->assertSame(ValidationSeverity::Warning, $err->severity());
    }

    public function test_layer_conflict_is_warning(): void
    {
        $err = new LayerConflictError('a', 'b', 'body', 1.0, 2.0);
        $this->assertSame(ValidationSeverity::Warning, $err->severity());
    }

    public function test_duplicate_id_is_error(): void
    {
        $err = new DuplicateIdError('dup_id');
        $this->assertSame(ValidationSeverity::Error, $err->severity());
    }

    public function test_missing_reference_is_error(): void
    {
        $err = new MissingReferenceError('source_id', 'missing_id', RelationType::Follows);
        $this->assertSame(ValidationSeverity::Error, $err->severity());
    }

    // ── TimelineValidationResult severity-aware methods ───────────────────────

    public function test_is_valid_is_true_when_only_warnings_present(): void
    {
        $result = new TimelineValidationResult([
            new LayerConflictError('a', 'b', 'body', 1.0, 2.0),
        ]);
        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isClean());
    }

    public function test_is_valid_is_false_when_error_present(): void
    {
        $result = new TimelineValidationResult([
            new DuplicateIdError('x'),
        ]);
        $this->assertFalse($result->isValid());
    }

    public function test_is_clean_is_true_only_when_no_issues(): void
    {
        $this->assertTrue(TimelineValidationResult::ok()->isClean());
        $this->assertTrue(TimelineValidationResult::ok()->isValid());
    }

    public function test_errors_of_severity_filters_correctly(): void
    {
        $warning = new LayerConflictError('a', 'b', 'body', 1.0, 2.0);
        $error   = new DuplicateIdError('x');

        $result = new TimelineValidationResult([$warning, $error]);

        $this->assertCount(1, $result->errorsOfSeverity(ValidationSeverity::Error));
        $this->assertCount(1, $result->errorsOfSeverity(ValidationSeverity::Warning));
        $this->assertCount(0, $result->errorsOfSeverity(ValidationSeverity::Info));
    }

    public function test_issues_at_or_above_returns_errors_and_above(): void
    {
        $warning = new LayerConflictError('a', 'b', 'body', 1.0, 2.0);
        $error   = new DuplicateIdError('x');

        $result = new TimelineValidationResult([$warning, $error]);

        $this->assertCount(2, $result->issuesAtOrAbove(ValidationSeverity::Warning));
        $this->assertCount(1, $result->issuesAtOrAbove(ValidationSeverity::Error));
        $this->assertCount(2, $result->issuesAtOrAbove(ValidationSeverity::Info));
    }

    public function test_errors_of_type_filters_by_class(): void
    {
        $warning = new LayerConflictError('a', 'b', 'body', 1.0, 2.0);
        $error   = new DuplicateIdError('x');

        $result = new TimelineValidationResult([$warning, $error]);

        $this->assertCount(1, $result->errorsOfType(DuplicateIdError::class));
        $this->assertCount(1, $result->errorsOfType(LayerConflictError::class));
        $this->assertCount(0, $result->errorsOfType(MissingReferenceError::class));
    }

    public function test_merge_combines_issues(): void
    {
        $r1 = new TimelineValidationResult([new DuplicateIdError('x')]);
        $r2 = new TimelineValidationResult([new LayerConflictError('a', 'b', 'body', 1.0, 2.0)]);

        $merged = $r1->merge($r2);

        $this->assertCount(2, $merged->errors());
        $this->assertFalse($merged->isValid());
        $this->assertFalse($merged->isClean());
    }
}

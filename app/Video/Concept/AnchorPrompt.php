<?php

namespace App\Video\Concept;

use App\Video\Inspiration\CategoryCreativeProfile;

final class AnchorPrompt
{
    public function build(CreativeConcept $concept, CategoryCreativeProfile $profile, Viewpoint $viewpoint): string
    {
        $profile->assertConceptReady();

        $lines = [$concept->designThesis, ''];

        foreach ($concept->designIdentity as $slot => $value) {
            $lines[] = '- '.str_replace('_', ' ', $slot).': '.$value;
        }

        foreach ($concept->signatureFeatures as $feature) {
            if ($feature->isVisibleFrom($viewpoint)) {
                $lines[] = '- '.$feature->description;
            }
        }

        $lines[] = '- '.$concept->formRelationships->governingLine;
        $lines[] = '- '.$concept->formRelationships->massingRhythm;
        $lines[] = '- '.$concept->formRelationships->featureIntegration;
        $lines[] = '- '.$profile->viewpointGuidance[$viewpoint->value];

        return implode("\n", $lines);
    }
}

<?php

namespace App\Services\AI\Artifact;

interface ArtifactStorageInterface
{
    /**
     * Download the video at $cdnUrl and persist it to the configured storage disk.
     *
     * @param  string $taskId        Provider task identifier — used to build the storage path.
     * @param  string $cdnUrl        Provider CDN URL of the rendered video.
     * @param  string $pipelineRunId Pipeline run identifier — used to namespace the path.
     * @return StoredArtifact        Metadata about the stored file.
     * @throws \RuntimeException     On download failure or storage error.
     */
    public function store(string $taskId, string $cdnUrl, string $pipelineRunId): StoredArtifact;
}

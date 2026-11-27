<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * A file of a local disk served with byte ranges (RFC 9110 §14) by BinaryFileResponse, with the two edges it answers wrongly put right.
 */
final class RangeFileResponse extends BinaryFileResponse
{
    /**
     * @param  Request  $request
     * @return static the response BinaryFileResponse prepares, except that a suffix range longer than the file asks for the whole file (RFC 9110 §14.1.2) and is answered 200 with all of it, and that an unsatisfiable range is answered 416 with Content-Length 0, the length of the body it sends
     */
    public function prepare(Request $request): static
    {
        $size = $this->file->getSize();
        $range = $request->headers->get('Range');

        if (is_int($size) && is_string($range) && preg_match('/^bytes=-(\d+)$/', $range, $suffix) === 1 && (int) $suffix[1] > $size) {
            $request = $request->duplicate();
            $request->headers->remove('Range');
        }

        parent::prepare($request);

        if ($this->getStatusCode() === 416) {
            $this->headers->set('Content-Length', '0');
        }

        return $this;
    }
}

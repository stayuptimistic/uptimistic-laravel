<?php

namespace Uptimistic\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Uptimistic\Laravel\Report;

class ReportController
{
    public function __invoke(Request $request, Report $report): JsonResponse
    {
        $software = $request->server('SERVER_SOFTWARE');

        return (new JsonResponse($report->build(is_string($software) ? $software : null)))
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex');
    }
}

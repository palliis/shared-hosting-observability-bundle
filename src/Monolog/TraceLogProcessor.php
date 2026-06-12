<?php

namespace Palliis\SharedHostingObservabilityBundle\Monolog;

use Monolog\LogRecord;
use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceRecorder;

final class TraceLogProcessor
{
    public function __construct(
        private TraceRecorder $traceRecorder,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $traceId = $this->traceRecorder->getCurrentTraceId();
        $spanId = $this->traceRecorder->getCurrentSpanId();
        if (!$traceId || !$spanId) {
            return $record;
        }

        $extra = $record->extra;
        $extra['trace_id'] = $traceId;
        $extra['span_id'] = $spanId;

        return $record->with(extra: $extra);
    }
}

<?php

namespace Palliis\SharedHostingObservabilityBundle\EventListener;

use Palliis\SharedHostingObservabilityBundle\Telemetry\TraceRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class TraceRequestListener implements EventSubscriberInterface
{
    public function __construct(
        private TraceRecorder $traceRecorder,
        private bool $enabled = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 110],
            KernelEvents::TERMINATE => ['onKernelTerminate', -110],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $this->traceRecorder->startRequest($event->getRequest());
        } catch (\Throwable) {
            // Observability must never break request handling.
        }
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $this->traceRecorder->completeRequest($event->getRequest(), $event->getResponse());
        } catch (\Throwable) {
            // Observability must never break request handling.
        }
    }
}

<?php

namespace Palliis\SharedHostingObservabilityBundle\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestLoggingListener implements EventSubscriberInterface
{
    private const START_ATTRIBUTE = '_sho_request_start_time';

    /**
     * @param string[] $excludedRoutes
     * @param int[]    $ignoredStatusCodes
     */
    public function __construct(
        private LoggerInterface $logger,
        private string $excludedPathRegex = '#^/(health|metrics|_profiler)#',
        private array $excludedRoutes = [],
        private array $ignoredStatusCodes = [404, 405],
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
            KernelEvents::RESPONSE => ['onKernelResponse', -100],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::START_ATTRIBUTE, microtime(true));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($request->isMethod('OPTIONS') || $request->isMethod('HEAD')) {
            return;
        }

        $path = $request->getPathInfo();
        $route = (string) $request->attributes->get('_route', '');
        if (in_array($route, $this->excludedRoutes, true) || $this->isExcludedPath($path)) {
            return;
        }

        $statusCode = $response->getStatusCode();
        if (in_array($statusCode, $this->ignoredStatusCodes, true)) {
            return;
        }

        $startTime = $request->attributes->get(self::START_ATTRIBUTE, $request->server->get('REQUEST_TIME_FLOAT'));
        $durationMs = null;
        if (is_numeric($startTime)) {
            $durationMs = round((microtime(true) - (float) $startTime) * 1000, 2);
        }

        $responseBytes = null;
        if ($response->headers->has('Content-Length')) {
            $responseBytes = (int) $response->headers->get('Content-Length');
        } elseif (!$response instanceof StreamedResponse && !$response instanceof BinaryFileResponse) {
            $content = $response->getContent();
            if (false !== $content) {
                $responseBytes = strlen($content);
            }
        }

        $level = 'debug';
        if ($statusCode >= 500) {
            $level = 'error';
        } elseif ($statusCode >= 400) {
            $level = 'warning';
        }

        $this->logger->log($level, 'Request completed', [
            'http.request.method' => $request->getMethod(),
            'url.path' => $path,
            'http.route' => $route,
            'http.response.status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'response_bytes' => $responseBytes,
        ]);
    }

    private function isExcludedPath(string $path): bool
    {
        if ('' === $this->excludedPathRegex) {
            return false;
        }

        return 1 === @preg_match($this->excludedPathRegex, $path);
    }
}

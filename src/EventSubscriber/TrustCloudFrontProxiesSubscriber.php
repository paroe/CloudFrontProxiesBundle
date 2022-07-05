<?php

namespace Erichard\CloudfrontProxiesBundle\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TrustCloudFrontProxiesSubscriber implements EventSubscriberInterface
{
    private HttpClientInterface $client;
    private CacheInterface $cache;
    private string $ipRangeDataUrl;
    private int $expire;

    public function __construct(CacheInterface $cache, HttpClientInterface $client, string $ipRangeDataUrl, int $expire)
    {
        $this->cache = $cache;
        $this->client = $client;
        $this->ipRangeDataUrl = $ipRangeDataUrl;
        $this->expire = $expire;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => [
                ['trustProxies', 512], // Higher priority than Symfony\Component\HttpKernel\EventListener\ValidateRequestListener
            ],
        ];
    }

    public function trustProxies(RequestEvent $event)
    {
        if (method_exists($event, 'isMasterRequest') && !$event->isMasterRequest()) {
            return;
        }

        if (method_exists($event, 'isMainRequest') && !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->headers->has('cloudfront-forwarded-proto')) {
            $this->loadTrustedProxies($request);
            $this->setCloudfrontHeaders($request);
        }
    }

    protected function loadTrustedProxies($request)
    {
        // Get the CloudFront IP addresses
        $proxies = $this->cache->get('cloudfront-proxy-ip-addresses', function (ItemInterface $item) {
            $item->expiresAfter($this->expire);

            $response = $this->client->request('GET', $this->ipRangeDataUrl);
            $data = $response->toArray();

            $cloudFrontRanges = array_filter($data['prefixes'], function($item) {
                return 'CLOUDFRONT' === $item['service'];
            });

            return array_map(function($item) {
                return $item['ip_prefix'];
            }, $cloudFrontRanges);
        });

        Request::setTrustedProxies(array_merge(Request::getTrustedProxies(), $proxies), Request::getTrustedHeaderSet());
    }

    protected function setCloudfrontHeaders(Request $request) 
    {
        $request->headers->set('x-forwarded-proto', $request->headers->get('cloudfront-forwarded-proto'));
    }
}

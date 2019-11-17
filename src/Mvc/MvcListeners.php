<?php

namespace Ark\Mvc;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\Filter\StaticFilter;
use Zend\Http\Response;
use Zend\Mvc\Application;
use Zend\Mvc\MvcEvent;
use Zend\Router\Http\RouteMatch;

class MvcListeners extends AbstractListenerAggregate
{
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach(
            MvcEvent::EVENT_ROUTE,
            [$this, 'redirectToResource']
        );
    }

    public function redirectToResource(MvcEvent $event)
    {
        $serviceLocator = $event->getApplication()->getServiceManager();
        $controllerPlugins = $serviceLocator->get('ControllerPluginManager');
        $settings = $serviceLocator->get('Omeka\Settings');
        /** @var \Ark\Mvc\Controller\Plugin\Ark $arkPlugin */
        $arkPlugin = $controllerPlugins->get('ark');

        $routeMatch = $event->getRouteMatch();
        $matchedRouteName = $routeMatch->getMatchedRouteName();
        if (!in_array($matchedRouteName, ['site/ark/default', 'admin/ark/default'], true)) {
            return;
        }

        $naan = $routeMatch->getParam('naan');
        $name = $routeMatch->getParam('name');
        $qualifier = $routeMatch->getParam('qualifier');

        if ($naan != $settings->get('ark_naan')) {
            return $this->triggerDispatchError($event);
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null;
        if (isset($uri) && 0 == substr_compare($uri, '?', -1)) {
            return;
        }

        $resource = $arkPlugin->find([
            'naan' => $naan,
            'name' => $name,
            'qualifier' => $qualifier,
        ]);

        if (empty($resource)) {
            return $this->triggerDispatchError($event);
        }

        if ($resource->resourceName() == 'media' && strpos($qualifier, '.') !== false) {
            $variant = substr($qualifier, strpos($qualifier, '.') + 1);
            $variants = ['large', 'medium', 'square'];
            if ($variant === 'original') {
                $url = $resource->originalUrl();
            } elseif (in_array($variant, $variants)) {
                $url = $resource->thumbnailUrl($variant);
            }
            if (isset($url)) {
                return $this->redirectToUrl($url);
            }
        }

        $isAdmin = $routeMatch->getParam('__ADMIN__');
        $controllerName = $resource->getControllerName();

        if ($isAdmin) {
            $params = [
                '__NAMESPACE__' => 'Omeka\Controller\Admin',
                '__ADMIN__' => true,
                'controller' => 'Omeka\Controller\Admin\\' . StaticFilter::execute($controllerName, 'WordDashToCamelCase'),
                'action' => 'show',
                'id' => $resource->id(),
                '__CONTROLLER__' => $controllerName,
            ];
            $routeName = 'admin/id';
        } else {
            $siteSlug = $routeMatch->getParam('site-slug');
            if ($controllerName === 'item-set') {
                $params = [
                    '__NAMESPACE__' => 'Omeka\Controller\Site',
                    '__SITE__' => true,
                    'site-slug' => $siteSlug,
                    'controller' => 'Omeka\Controller\Site\Item',
                    'action' => 'browse',
                    'item-set-id' => $resource->id(),
                    '__CONTROLLER__' => 'item',
                ];
                $routeName = 'site/item-set';
            } else {
                $params = [
                    '__NAMESPACE__' => 'Omeka\Controller\Site',
                    '__SITE__' => true,
                    'site-slug' => $siteSlug,
                    'controller' => 'Omeka\Controller\Site\\' . StaticFilter::execute($controllerName, 'WordDashToCamelCase'),
                    'action' => 'show',
                    'id' => $resource->id(),
                    '__CONTROLLER__' => $controllerName,
                ];
                $routeName = 'site/resource-id';
            }
        }

        $routeMatch = new RouteMatch($params);
        $routeMatch->setMatchedRouteName($routeName);
        $event->setRouteMatch($routeMatch);

        return $routeMatch;
    }

    protected function triggerDispatchError($event)
    {
        $event->setName(MvcEvent::EVENT_DISPATCH_ERROR);
        $event->setError(Application::ERROR_ROUTER_NO_MATCH);

        $target = $event->getTarget();
        $results = $target->getEventManager()->triggerEvent($event);

        return !empty($results) ? $results->last() : null;
    }

    protected function redirectToUrl($url)
    {
        $response = new Response;
        $response->setStatusCode('302');
        $response->getHeaders()->addHeaderLine('Location', $url);

        return $response;
    }
}

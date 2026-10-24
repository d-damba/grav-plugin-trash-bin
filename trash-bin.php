<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\TrashBin\Api\TrashApiController;
use Grav\Plugin\TrashBin\TrashStore;
use RocketTheme\Toolbox\Event\Event;

/**
 * Trash Bin — deleted pages go to the trash, not away.
 */
class TrashBinPlugin extends Plugin
{
    /**
     * Subscribed statically: the onApi* events only fire in an API context.
     *
     * @return array<string,mixed>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 0],
            'onApiBeforePageDelete' => ['onApiBeforePageDelete', 0],
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems' => ['onApiSidebarItems', 0],
        ];
    }

    public function autoload(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Grav\\Plugin\\TrashBin\\', __DIR__ . '/classes');
        $loader->register();

        return $loader;
    }

    /**
     * Nothing reads permissions.yaml for us: without this the api.trash.*
     * actions cannot be granted to anyone but a super admin.
     */
    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $event->permissions->addActions(PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml"));
    }

    /**
     * Copy the page into the trash; the API deletes it straight after.
     *
     * Never throws. If the copy fails the delete still goes ahead, as it
     * would without this plugin: the failure is logged and shown on the
     * Trash page instead.
     */
    public function onApiBeforePageDelete(Event $event): void
    {
        $page = $event['page'] ?? null;
        if (!$page instanceof PageInterface) {
            return;
        }

        $store = TrashStore::fromGrav($this->grav);
        $user = $this->grav['user'] ?? null;

        try {
            $store->capture($page, $event['lang'] ?? null, (string)($user?->username ?? ''));
        } catch (\Throwable $e) {
            $this->grav['log']->error(sprintf('trash-bin: could not save %s before delete: %s', $page->route(), $e->getMessage()));
            try {
                $store->recordFailure((string)$page->route(), $e->getMessage());
            } catch (\Throwable) {
                // The log line above is the record.
            }
        }
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = TrashApiController::class;

        $routes->group('/trash', static function ($group) use ($controller): void {
            $group->get('', [$controller, 'index']);
            $group->get('/badge', [$controller, 'badge']);
            $group->delete('', [$controller, 'clear']);
            $group->delete('/failure', [$controller, 'dismissFailure']);
            $group->post('/{id}/restore', [$controller, 'restore']);
            $group->post('/{id}/discard', [$controller, 'discard']);
            $group->delete('/{id}', [$controller, 'destroy']);
        });
    }

    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'];

        $items[] = [
            'id' => 'trash-bin',
            'plugin' => 'trash-bin',
            'label' => 'PLUGIN_TRASH_BIN.SIDEBAR',
            'icon' => 'fa-trash-can',
            'route' => '/plugin/trash-bin',
            'priority' => 0,
            'badgeEndpoint' => '/trash/badge',
            'authorize' => 'api.trash.read',
        ];

        $event['items'] = $items;
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin\TrashBin\Api;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\TrashBin\TrashConflict;
use Grav\Plugin\TrashBin\TrashStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Everything the Trash page does, it does through here.
 */
final class TrashApiController extends AbstractApiController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.trash.read');

        $store = TrashStore::fromGrav($this->grav);

        return ApiResponse::create([
            'entries' => $store->all(),
            'last_failure' => $store->lastFailure(),
            'retention_days' => (int)$this->grav['config']->get('plugins.trash-bin.retention_days', 30),
        ]);
    }

    public function badge(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.trash.read');

        return ApiResponse::create(['count' => TrashStore::fromGrav($this->grav)->count()]);
    }

    /**
     * Body, all optional: `node` (which page in the entry, `''` for the top
     * one), `exclude` (nodes below it to leave in the trash), `parent` (route
     * of the page to restore under, `/` for the top level) and `folder` (a new
     * folder name). Without them the whole entry goes back where it was.
     *
     * Restoring writes pages, so it needs the page write permission too.
     */
    public function restore(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.trash.write');
        $this->requirePermission($request, 'api.pages.write');

        $store = TrashStore::fromGrav($this->grav);
        $entry = $this->entryOrFail($store, $request);
        $body = $this->getRequestBody($request);

        $parentRoute = isset($body['parent']) ? trim((string)$body['parent']) : null;
        $folder = isset($body['folder']) ? trim((string)$body['folder']) : null;
        $folder = $folder === '' ? null : $folder;

        if ($folder !== null && (!preg_match('/^[\w.-]+$/u', $folder) || trim($folder, '.') === '')) {
            throw new ValidationException('A folder name is one path segment: letters, numbers, dots, dashes and underscores.');
        }
        if (($parentRoute !== null || $folder !== null) && $entry['kind'] === 'language') {
            throw new ValidationException('A translation can only go back to its own page.');
        }

        $parentDir = null;
        $routePrefix = null;
        if ($parentRoute !== null) {
            if (in_array($parentRoute, ['', '/'], true)) {
                $parentDir = (string)$this->grav['locator']->findResource('page://', true);
                $routePrefix = '';
            } else {
                $parent = $this->resolvePageByRoute($parentRoute);
                if ($parent === null) {
                    throw new ValidationException(sprintf('No page at "%s".', $parentRoute));
                }
                $parentDir = (string)$parent->path();
                $routePrefix = rtrim((string)$parent->route(), '/');
            }
        }

        [$node, $exclude] = $this->nodeFromBody($body);
        $this->authorizeRestore($request, $entry, $node, $parent ?? null, $parentRoute);

        try {
            $path = $store->restore($entry, $parentDir, $folder, $node, $exclude);
        } catch (TrashConflict $e) {
            throw new ApiException(409, 'Conflict', $e->getMessage(), [], $e, $e->reason);
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundException($e->getMessage());
        }

        $pages = $this->grav['pages'];
        method_exists($pages, 'markChanged') ? $pages->markChanged() : $this->grav['cache']->clearCache('standard');

        $route = $routePrefix === null
            ? TrashStore::routeOf($entry, $node)
            : $routePrefix . '/' . TrashStore::slug($folder ?? basename((string)$entry['folder'] . '/' . $node));

        $this->fireEvent('onTrashPageRestored', ['entry' => $entry, 'node' => $node, 'route' => $route, 'path' => $path]);

        return ApiResponse::create(['route' => $route, 'path' => $path]);
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.trash.write');

        $store = TrashStore::fromGrav($this->grav);
        $store->delete($this->entryOrFail($store, $request)['id']);

        return ApiResponse::noContent();
    }

    /** Delete one page of an entry permanently. Body: `node`, optional `exclude`. */
    public function discard(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.trash.write');

        $store = TrashStore::fromGrav($this->grav);
        $entry = $this->entryOrFail($store, $request);
        if ($entry['kind'] === 'language') {
            throw new ValidationException('A translation is deleted as a whole.');
        }

        [$node, $exclude] = $this->nodeFromBody($this->getRequestBody($request));

        try {
            $store->discard($entry, $node, $exclude);
        } catch (\InvalidArgumentException $e) {
            throw new NotFoundException($e->getMessage());
        }

        return ApiResponse::noContent();
    }

    public function clear(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.trash.write');

        TrashStore::fromGrav($this->grav)->emptyTrash();

        return ApiResponse::noContent();
    }

    public function dismissFailure(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.trash.write');

        TrashStore::fromGrav($this->grav)->clearFailure();

        return ApiResponse::noContent();
    }

    /**
     * The page access rules, as the API applies them to a new page: `create`
     * on the parent it goes under, or `update` on the page a translation
     * belongs to. Top-level restores have no parent page to ask.
     */
    private function authorizeRestore(ServerRequestInterface $request, array $entry, string $node, ?PageInterface $parent, ?string $parentRoute): void
    {
        if (!method_exists($this, 'authorizePageAction')) {
            return;
        }

        if ($entry['kind'] === 'language') {
            [$target, $action] = [$this->resolvePageByRoute((string)$entry['route']), 'update'];
        } else {
            $route = $parentRoute ?? dirname(TrashStore::routeOf($entry, $node));
            $target = $parent ?? (in_array($route, ['', '/', '.', '\\'], true) ? null : $this->resolvePageByRoute($route));
            $action = 'create';
        }

        if ($target instanceof PageInterface) {
            $this->authorizePageAction($request, $target, $action, 'api.pages.write');
        }
    }

    /** @return array{0:string,1:list<string>} */
    private function nodeFromBody(array $body): array
    {
        $exclude = $body['exclude'] ?? [];

        return [
            trim((string)($body['node'] ?? ''), '/'),
            is_array($exclude) ? array_values(array_filter($exclude, 'is_string')) : [],
        ];
    }

    private function entryOrFail(TrashStore $store, ServerRequestInterface $request): array
    {
        $entry = $store->get((string)$this->getRouteParam($request, 'id'));
        if ($entry === null) {
            throw new NotFoundException('That item is no longer in the trash.');
        }

        return $entry;
    }
}

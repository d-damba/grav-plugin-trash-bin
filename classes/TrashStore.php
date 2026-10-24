<?php

declare(strict_types=1);

namespace Grav\Plugin\TrashBin;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Yaml;
use InvalidArgumentException;
use RuntimeException;

/**
 * The trash on disk.
 *
 * One folder per entry under `user/data/trash/{id}/`: `entry.json` describes
 * what was deleted and where it lived, `files/` holds the copy. A whole-page
 * delete copies the page folder with everything below it; a delete of one
 * translation copies only that `.md` file, because that is all the API
 * removes.
 *
 * Pages inside a page entry are addressed by "node": their folder path below
 * the entry's top folder, `''` being the top folder itself. Restoring or
 * deleting a node can leave chosen descendants behind; the folders above
 * them stay in the trash without a page file, so the tree keeps its shape.
 */
final class TrashStore
{
    private const ENTRY_FILE = 'entry.json';
    private const FAILURE_FILE = 'last-failure.json';

    public function __construct(
        private readonly string $trashDir,
        private readonly string $pagesDir,
        private readonly int $retentionDays,
    ) {
    }

    public static function fromGrav(Grav $grav): self
    {
        $locator = $grav['locator'];

        return new self(
            self::normalize((string)$locator->findResource('user://data', true)) . '/trash',
            self::normalize((string)$locator->findResource('page://', true)),
            (int)$grav['config']->get('plugins.trash-bin.retention_days', 30),
        );
    }

    /**
     * Copy a page into the trash before the API deletes it.
     *
     * Returns null when there is nothing on disk to save: a batch delete of a
     * parent and its child removes the child with the parent, and the child's
     * own delete then fires for a folder that is already gone.
     */
    public function capture(PageInterface $page, ?string $lang, string $user): ?array
    {
        $source = self::normalize((string)$page->path());
        if (!is_dir($source)) {
            return null;
        }

        $file = null;
        if ($lang !== null && $lang !== '' && count($page->translatedLanguages()) > 1) {
            // A language the page does not have: the API refuses the delete
            // right after this, so there is nothing to keep.
            if (!isset($page->translatedLanguages()[$lang])) {
                return null;
            }
            $file = $this->languageFile($page, $lang);
            if ($file === null) {
                throw new RuntimeException(sprintf('Could not find the %s file of %s to save.', $lang, $page->route()));
            }
        }

        $this->protect();
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $entryDir = $this->trashDir . '/' . $id;
        $folder = basename($source);

        try {
            if ($file !== null) {
                Folder::create($entryDir . '/files');
                if (!@copy($source . '/' . $file, $entryDir . '/files/' . $file)) {
                    throw new RuntimeException(error_get_last()['message'] ?? 'Copy failed');
                }
            } else {
                self::copyTree($source, $entryDir . '/files/' . $folder);
            }

            $entry = [
                'id' => $id,
                'kind' => $file !== null ? 'language' : 'page',
                'title' => (string)$page->title(),
                'route' => (string)$page->route(),
                'path' => $this->relativePath($source),
                'folder' => $folder,
                'file' => $file,
                'lang' => $file !== null ? $lang : null,
                'deleted_at' => time(),
                'deleted_by' => $user,
                'pages' => $file !== null ? 1 : self::countPages($source),
                'size' => $file !== null ? (int)filesize($source . '/' . $file) : self::size($source),
            ];
            if ($file === null) {
                $entry['tree'] = $this->tree($entry, $entryDir . '/files/' . $folder, '');
            }

            self::writeJson($entryDir . '/' . self::ENTRY_FILE, $entry);
        } catch (\Throwable $e) {
            // A half-copied entry would restore as a broken page.
            if (is_dir($entryDir)) {
                Folder::delete($entryDir);
            }
            throw $e;
        }

        // After the copy, never before: a stuck old entry must not cost
        // this page its copy.
        $this->purgeExpired();

        return $entry;
    }

    /** @return list<array<string,mixed>> newest first */
    public function all(): array
    {
        $this->protect();
        $this->purgeExpired();

        $entries = $this->entries();
        foreach ($entries as $i => $entry) {
            // Entries from before the tree was stored with them.
            if ($entry['kind'] === 'page' && !isset($entry['tree'])) {
                $entries[$i]['tree'] = $this->tree($entry, $this->trashDir . '/' . $entry['id'] . '/files/' . $entry['folder'], '');
            }
        }

        usort($entries, static fn (array $a, array $b): int => $b['deleted_at'] <=> $a['deleted_at']);

        return $entries;
    }

    /** Every page in the trash, children included. */
    public function count(): int
    {
        return array_sum(array_map(static fn (array $entry): int => (int)($entry['pages'] ?? 0), $this->entries()));
    }

    /** @return list<array<string,mixed>> every entry as stored, in no order */
    private function entries(): array
    {
        $entries = [];
        foreach (glob($this->trashDir . '/*/' . self::ENTRY_FILE) ?: [] as $file) {
            $entry = json_decode((string)file_get_contents($file), true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function get(string $id): ?array
    {
        if (!preg_match('/^[0-9a-f-]+$/', $id)) {
            return null;
        }

        $file = $this->trashDir . '/' . $id . '/' . self::ENTRY_FILE;
        $entry = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * Put a node back, leaving the $exclude nodes below it in the trash.
     *
     * $parentDir and $folder relocate it; null means "where it was". Returns
     * the restored path relative to the pages folder.
     *
     * @param list<string> $exclude
     * @throws TrashConflict when the location is taken or the parent is gone
     * @throws InvalidArgumentException when the node is not in the entry
     */
    public function restore(array $entry, ?string $parentDir = null, ?string $folder = null, string $node = '', array $exclude = []): string
    {
        $entryDir = $this->trashDir . '/' . $entry['id'];

        if ($entry['kind'] === 'language') {
            $parentDir = self::normalize($parentDir ?? dirname($this->pagesDir . '/' . $entry['path']));
            $target = $parentDir . '/' . $entry['folder'] . '/' . $entry['file'];
            if (!is_dir(dirname($target))) {
                throw new TrashConflict('parent_missing', 'The page this translation belongs to no longer exists.');
            }
            if (file_exists($target)) {
                throw new TrashConflict('location_taken', 'That translation exists again. Delete it first or keep the current one.');
            }
            if (!@rename($entryDir . '/files/' . $entry['file'], $target)) {
                throw new RuntimeException(error_get_last()['message'] ?? 'Restore failed');
            }
            Folder::delete($entryDir);

            return $this->relativePath(dirname($target));
        }

        $source = $this->nodeDir($entry, $node);
        $original = $entry['path'] . ($node === '' ? '' : '/' . $node);
        $parentDir = self::normalize($parentDir ?? dirname($this->pagesDir . '/' . $original));

        if (!is_dir($parentDir)) {
            $parentTrashed = $node === '' ? $this->parentInTrash($entry) : self::isPage(dirname($source));
            throw new TrashConflict(
                $parentTrashed ? 'parent_in_trash' : 'parent_missing',
                'The page this was under no longer exists.',
            );
        }

        $folder ??= basename($original);
        if (self::slugTaken($parentDir, $folder)) {
            throw new TrashConflict('location_taken', 'Another page is using that URL now.');
        }
        $target = $parentDir . '/' . $folder;

        $this->withoutExcluded($entryDir, $source, $node, $exclude, static fn () => Folder::move($source, $target));
        $this->settle($entry);

        return $this->relativePath($target);
    }

    /**
     * Delete a node permanently, leaving the $exclude nodes below it in the
     * trash.
     *
     * @param list<string> $exclude
     * @throws InvalidArgumentException when the node is not in the entry
     */
    public function discard(array $entry, string $node, array $exclude = []): void
    {
        $source = $this->nodeDir($entry, $node);
        $this->withoutExcluded($this->trashDir . '/' . $entry['id'], $source, $node, $exclude, static fn () => Folder::delete($source));
        $this->settle($entry);
    }

    /** The route a node had before it was deleted. */
    public static function routeOf(array $entry, string $node): string
    {
        $route = rtrim((string)$entry['route'], '/');
        foreach ($node === '' ? [] : explode('/', $node) as $folder) {
            $route .= '/' . self::slug($folder);
        }

        return $route === '' ? '/' : $route;
    }

    public function delete(string $id): void
    {
        if ($this->get($id) !== null) {
            Folder::delete($this->trashDir . '/' . $id);
        }
    }

    public function emptyTrash(): void
    {
        foreach ($this->entries() as $entry) {
            $this->delete($entry['id']);
        }
    }

    public function recordFailure(string $route, string $message): void
    {
        Folder::create($this->trashDir);
        self::writeJson($this->trashDir . '/' . self::FAILURE_FILE, [
            'route' => $route,
            'message' => $message,
            'at' => time(),
        ]);
    }

    public function lastFailure(): ?array
    {
        $file = $this->trashDir . '/' . self::FAILURE_FILE;
        $failure = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;

        return is_array($failure) ? $failure : null;
    }

    public function clearFailure(): void
    {
        @unlink($this->trashDir . '/' . self::FAILURE_FILE);
    }

    /** @throws InvalidArgumentException when the node is not in the entry */
    private function nodeDir(array $entry, string $node): string
    {
        $dir = $this->trashDir . '/' . $entry['id'] . '/files/' . $entry['folder'] . ($node === '' ? '' : '/' . $node);
        if (!self::validNode($node) || !is_dir($dir)) {
            throw new InvalidArgumentException('That page is no longer in the trash.');
        }

        return $dir;
    }

    private static function validNode(string $node): bool
    {
        return $node === ''
            || (preg_match('#^[\w.-]+(/[\w.-]+)*$#u', $node) === 1 && array_intersect(['.', '..'], explode('/', $node)) === []);
    }

    /**
     * Park the excluded descendants, run $action on the node, then put them
     * back where they were in the trash, recreating the folders above them.
     *
     * @param list<string> $exclude
     */
    private function withoutExcluded(string $entryDir, string $source, string $node, array $exclude, callable $action): void
    {
        $prefix = $node === '' ? '' : $node . '/';
        $exclude = array_values(array_unique(array_filter(
            $exclude,
            static fn ($ex): bool => is_string($ex) && $ex !== '' && self::validNode($ex)
                && str_starts_with($ex, $prefix) && is_dir($source . '/' . substr($ex, strlen($prefix))),
        )));
        // A node inside another excluded node already goes with it.
        $exclude = array_filter($exclude, static function (string $ex) use ($exclude): bool {
            foreach ($exclude as $other) {
                if (str_starts_with($ex, $other . '/')) {
                    return false;
                }
            }

            return true;
        });

        $hold = $entryDir . '/hold';
        $parked = [];
        try {
            foreach ($exclude as $i => $ex) {
                Folder::create($hold);
                Folder::move($source . '/' . substr($ex, strlen($prefix)), $hold . '/' . $i);
                $parked[$i] = $ex;
            }
            $action();
        } finally {
            foreach ($parked as $i => $ex) {
                $back = $source . '/' . substr($ex, strlen($prefix));
                Folder::create(dirname($back));
                Folder::move($hold . '/' . $i, $back);
            }
            if (is_dir($hold)) {
                Folder::delete($hold);
            }
        }
    }

    /** Drop the entry once no page is left in it; otherwise refresh its totals. */
    private function settle(array $entry): void
    {
        $entryDir = $this->trashDir . '/' . $entry['id'];
        $files = $entryDir . '/files/' . $entry['folder'];
        $pages = is_dir($files) ? self::countPages($files) : 0;

        if ($pages === 0) {
            Folder::delete($entryDir);
            return;
        }

        $stored = $this->get((string)$entry['id']) ?? $entry;
        $stored['pages'] = $pages;
        $stored['size'] = self::size($files);
        $stored['tree'] = $this->tree($stored, $files, '');
        self::writeJson($entryDir . '/' . self::ENTRY_FILE, $stored);
    }

    /**
     * The folders of a trashed page as nested nodes. A folder with no page
     * file (restored already, or never a page) stays in as `page: false`, so
     * what is still in the trash keeps its place.
     */
    private function tree(array $entry, string $dir, string $node): array
    {
        $children = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $child) {
            $name = basename($child);
            $children[] = $this->tree($entry, $child, $node === '' ? $name : $node . '/' . $name);
        }

        $page = self::isPage($dir);

        return [
            'node' => $node,
            'folder' => basename($dir),
            'title' => !$page ? '' : ($node === '' ? (string)$entry['title'] : self::title($dir)),
            'route' => self::routeOf($entry, $node),
            'page' => $page,
            'children' => $children,
        ];
    }

    private static function isPage(string $dir): bool
    {
        return (bool)glob($dir . '/*.md');
    }

    /** Frontmatter title of the folder's first page file, else its slug. */
    private static function title(string $dir): string
    {
        $files = glob($dir . '/*.md') ?: [];
        sort($files);
        $text = isset($files[0]) ? (string)file_get_contents($files[0]) : '';

        if (preg_match('/^---\R(.*?)\R---/s', $text, $m)) {
            try {
                $header = Yaml::parse($m[1]);
                if (is_array($header) && is_string($header['title'] ?? null) && $header['title'] !== '') {
                    return $header['title'];
                }
            } catch (\Throwable) {
                // Fall back to the folder name.
            }
        }

        return self::slug(basename($dir));
    }

    /**
     * Grav's .htaccess serves images, PDFs and the like straight from
     * user/data; trashed media must not be one guessed URL away. Apache and
     * LiteSpeed only: nginx needs its own rule.
     */
    private function protect(): void
    {
        $file = $this->trashDir . '/.htaccess';
        if (is_file($file)) {
            return;
        }
        // Best effort: a folder that can't take the file must not stop a
        // delete or the Trash page. Whoops turns the warning into an
        // exception, hence @ and the catch.
        try {
            Folder::create($this->trashDir);
            $written = @file_put_contents($file, <<<'HTACCESS'
                <IfModule mod_authz_core.c>
                    Require all denied
                </IfModule>
                <IfModule !mod_authz_core.c>
                    Deny from all
                </IfModule>

                HTACCESS);
            if ($written === false) {
                throw new RuntimeException(error_get_last()['message'] ?? 'not writable');
            }
        } catch (\Throwable $e) {
            Grav::instance()['log']->warning(sprintf('trash-bin: could not write %s: %s', $file, $e->getMessage()));
        }
    }

    private function purgeExpired(): void
    {
        // Retention 0 keeps entries until they are emptied by hand.
        $cutoff = $this->retentionDays > 0 ? time() - $this->retentionDays * 86400 : 0;
        foreach ($cutoff === 0 ? [] : (glob($this->trashDir . '/*/' . self::ENTRY_FILE) ?: []) as $file) {
            $entry = json_decode((string)file_get_contents($file), true);
            if (is_array($entry) && ($entry['deleted_at'] ?? 0) < $cutoff) {
                // One entry that cannot be removed (a locked file, a wrong
                // owner) is retried next time; it must not stop the rest,
                // the save that called this, or the list.
                try {
                    Folder::delete(dirname($file));
                } catch (\Throwable $e) {
                    Grav::instance()['log']->warning(sprintf('trash-bin: could not purge %s: %s', basename(dirname($file)), $e->getMessage()));
                }
            }
        }

        // A copy cut off by the time or memory limit never gets its
        // entry.json, and the catch in capture() doesn't run on a fatal.
        foreach (glob($this->trashDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!is_file($dir . '/' . self::ENTRY_FILE) && (int)filemtime($dir) < time() - 3600) {
                try {
                    Folder::delete($dir);
                } catch (\Throwable $e) {
                    Grav::instance()['log']->warning(sprintf('trash-bin: could not purge %s: %s', basename($dir), $e->getMessage()));
                }
            }
        }
    }

    /** Is the missing parent itself sitting in the trash? */
    private function parentInTrash(array $entry): bool
    {
        $parent = dirname((string)$entry['path']);
        foreach ($this->entries() as $other) {
            if ($other['kind'] === 'page' && $other['id'] !== $entry['id']
                && ($parent === $other['path'] || str_starts_with($parent, $other['path'] . '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same logic as the API's deleteLanguageFile(), so the file saved is the
     * file it is about to remove.
     */
    private function languageFile(PageInterface $page, string $lang): ?string
    {
        // The file name is the template's basename: a module's template() is
        // `modular/<name>`, its file `<name>.<lang>.md`.
        $template = basename($page->template());
        $candidates = [$template . '.' . $lang . '.md'];
        if ($lang === Grav::instance()['language']->getDefault()) {
            $candidates[] = $template . '.md';
        }

        foreach ($candidates as $name) {
            if (is_file($page->path() . '/' . $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A folder answers to its name without the order prefix, so `02.blog`
     * takes the URL of a trashed `01.blog`.
     */
    private static function slugTaken(string $parentDir, string $folder): bool
    {
        $slug = self::slug($folder);
        foreach (glob($parentDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (self::slug(basename($dir)) === $slug) {
                return true;
            }
        }

        return false;
    }

    public static function slug(string $folder): string
    {
        return (string)preg_replace('/^\d+\./', '', $folder);
    }

    /** Folder::copy() skips dotfiles; a trash copy must not. */
    private static function copyTree(string $source, string $target): void
    {
        Folder::create($target);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            $dest = $target . '/' . $items->getSubPathname();
            if ($item->isDir()) {
                Folder::create($dest);
            } elseif (!@copy($item->getPathname(), $dest)) {
                throw new RuntimeException(error_get_last()['message'] ?? 'Copy failed: ' . $item->getPathname());
            }
        }
    }

    /** Folders under (and including) this one that hold a page file. */
    private static function countPages(string $dir): int
    {
        $count = glob($dir . '/*.md') ? 1 : 0;
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $child) {
            $count += self::countPages($child);
        }

        return $count;
    }

    private static function size(string $dir): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }

    private function relativePath(string $path): string
    {
        return ltrim(substr(self::normalize($path), strlen($this->pagesDir)), '/');
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function writeJson(string $file, array $data): void
    {
        if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false) {
            throw new RuntimeException('Could not write ' . $file);
        }
    }
}

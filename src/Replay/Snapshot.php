<?php

declare(strict_types=1);

namespace App\Replay;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A captured moment of a real world, read from disk.
 *
 * The project needs a running AzerothCore, a compiled C++ module, a MySQL server and a copy of
 * the 3.3.5a client to do anything at all. That is a reasonable afternoon for someone who wants
 * to play, and an absurd ask of someone who wants to spend five minutes seeing whether the idea
 * is any good.
 *
 * So `bin/console anm:snapshot` writes what the live server answered into `fixtures/world.json`,
 * and the replay services below read it back through the same interfaces the real ones
 * implement. Nothing downstream — not the MCP tools, not the roleplay prompt chain — can tell
 * which it is talking to.
 *
 * The fixture is captured, never written by hand: invented game data would be exactly the kind
 * of confident fiction this whole project exists to avoid.
 */
final class Snapshot
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/world.json')]
        private readonly string $path,
    ) {
    }

    public function exists(): bool
    {
        return is_readable($this->path);
    }

    public function capturedAt(): ?string
    {
        $at = $this->data()['capturedAt'] ?? null;

        return \is_string($at) ? $at : null;
    }

    /**
     * One top-level collection, e.g. `actors` or `quests`.
     *
     * @return array<int|string, mixed>
     */
    public function section(string $name): array
    {
        $section = $this->data()[$name] ?? [];

        return \is_array($section) ? $section : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }

        if (!$this->exists()) {
            throw new \RuntimeException(\sprintf('No world snapshot at "%s". Run `bin/console anm:snapshot` against a live server, or use APP_ENV=dev with a real one.', $this->path));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($this->path), true, flags: \JSON_THROW_ON_ERROR);

        return $this->data = $decoded;
    }
}

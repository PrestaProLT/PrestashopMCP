<?php
/**
 * @author    PrestaPro
 * @license   AFL-3.0
 */

namespace PrestaPro\PpMcp\Skill;

/**
 * Skills are markdown playbooks ("how to analyse a module", "why can't a
 * customer add product X to the cart"…). They ship in modules/ppmcp/skills/
 * and in other modules (mcp/skills/*.md, via PpMcpToolProviderTrait).
 *
 * File format:
 *   ---
 *   name: module_analysis
 *   title: Analyse a PrestaShop module
 *   description: One line: what the playbook is for.
 *   when_to_use: When the user asks how a module works…
 *   ---
 *   # Markdown body
 *
 * Exposed three ways from the same registry: tools (mcp_skill_list /
 * mcp_skill_get — works in every client), prompts (Claude Code slash
 * commands) and resources (ppmcp://skills/{name}).
 */
class SkillRepository
{
    const URI_PREFIX = 'ppmcp://skills/';

    /** @var array|null name => skill */
    private $skills;
    /** @var string */
    private $coreDir;
    /** @var array module => paths */
    private $moduleFiles;

    public function __construct(string $coreDir, array $moduleFiles = [])
    {
        $this->coreDir = rtrim($coreDir, '/') . '/';
        $this->moduleFiles = $moduleFiles;
    }

    /**
     * @return array[] name => [name, title, description, when_to_use, owner, path]
     */
    public function all(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }
        $this->skills = [];
        foreach (glob($this->coreDir . '*.md') ?: [] as $file) {
            $this->add($file, 'ppmcp', '');
        }
        foreach ($this->moduleFiles as $module => $files) {
            foreach ($files as $file) {
                $real = realpath($file);
                // Only files inside that module's own directory are accepted.
                if ($real && strpos($real, realpath(_PS_MODULE_DIR_ . $module) . DIRECTORY_SEPARATOR) === 0) {
                    $this->add($real, $module, $module . '_');
                }
            }
        }
        ksort($this->skills);

        return $this->skills;
    }

    public function get(string $name): ?array
    {
        $all = $this->all();
        if (!isset($all[$name])) {
            return null;
        }
        $skill = $all[$name];
        $skill['content'] = self::body((string) file_get_contents($skill['path']));

        return $skill;
    }

    public static function uri(string $name): string
    {
        return self::URI_PREFIX . $name;
    }

    private function add(string $file, string $owner, string $prefix): void
    {
        $raw = (string) @file_get_contents($file);
        if ($raw === '') {
            return;
        }
        $meta = self::frontMatter($raw);
        $name = isset($meta['name']) ? $meta['name'] : basename($file, '.md');
        $name = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $name));
        if ($prefix && strpos($name, $prefix) !== 0) {
            $name = $prefix . $name;
        }
        if (isset($this->skills[$name])) {
            return;
        }
        $this->skills[$name] = [
            'name' => $name,
            'title' => isset($meta['title']) ? $meta['title'] : $name,
            'description' => isset($meta['description']) ? $meta['description'] : '',
            'when_to_use' => isset($meta['when_to_use']) ? $meta['when_to_use'] : '',
            'owner' => $owner,
            'path' => $file,
        ];
    }

    private static function frontMatter(string $raw): array
    {
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
            return [];
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^([a-z_]+)\s*:\s*(.*)$/i', trim($line), $kv)) {
                $meta[strtolower($kv[1])] = trim($kv[2], " \t\"'");
            }
        }

        return $meta;
    }

    private static function body(string $raw): string
    {
        return trim((string) preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $raw, 1));
    }
}

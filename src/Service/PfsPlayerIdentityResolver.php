<?php

declare (strict_types=1);
namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
final readonly class PfsPlayerIdentityResolver
{
    private array $aliases;
    private array $contextAliases;
    public function __construct(
        private PfsNameNormalizer $normalizer = new PfsNameNormalizer(),
        #[Autowire('%app.pfs_alias_path%')]
        string $aliasPath = ''
    )
    {
        $path = $aliasPath !== '' ? $aliasPath : dirname(__DIR__, 2) . '/config/pfs_player_aliases.yaml';
        $configuration = is_file($path) ? Yaml::parseFile($path) : [];
        $this->aliases = $configuration['aliases'] ?? [];
        $this->contextAliases = $configuration['context_aliases'] ?? [];
    }
    public function index(array $catalog): array
    {
        $index = ['exact' => [], 'normalized' => []];
        foreach ($catalog as $row) {
            $this->add($index, $row);
        }
        return $index;
    }
    public function add(array &$index, array $row): void
    {
        $index['exact'][$row['nameShow']][] = $row;
        $index['normalized'][$this->normalizer->normalizeForMatch($row['nameShow'])][] = $row;
    }
    public function resolve(array $index, string $name, ?string $city = null): ?array
    {
        $target = ($city !== null ? $this->contextAliases[$name][$city] ?? null : null) ?? $this->aliases[$name] ?? $name;
        $rows = $index['exact'][$target] ?? $index['normalized'][$this->normalizer->normalizeForMatch($target)] ?? [];
        // City changes cannot create a second profile or authorize an uncertain merge.
        if (count($rows) > 1) {
            throw new \LogicException('Ambiguous PFS player identity; confirmed mapping required: ' . $name);
        }
        if (!$rows && $target !== $name) {
            throw new \LogicException('Confirmed alias target is missing from organization catalog.');
        }
        return $rows[0] ?? null;
    }
}

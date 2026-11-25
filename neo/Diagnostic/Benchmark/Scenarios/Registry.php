<?php

declare(strict_types=1);

namespace Neo\Diagnostic\Benchmark\Scenarios;

use Neo\Diagnostic\Benchmark\Support\Context;
use Neo\Diagnostic\Benchmark\Support\Scenario;

/** Все сценарии по имени, плюс составные: mix (реалистичная смесь) и heavy (всё тяжёлое сразу). */
final class Registry
{
    /** Состав mix: сценарий => вес. 80% чтение, 20% запись, как у обычного сайта. */
    private const MIX = [
        'db.pk' => 40, 'db.list' => 15, 'db.join' => 5, 'db.comments' => 10, 'db.pk_orm' => 10,
        'db.insert' => 8, 'db.update' => 5, 'db.transaction' => 4, 'db.counter' => 3,
    ];

    /** @var array<string, Scenario> */
    private array $scenarios = [];

    public function __construct()
    {
        foreach ([...PlainScenarios::all(), ...ReadScenarios::all(), ...WriteScenarios::all()] as $scenario) {
            $this->scenarios[$scenario->name] = $scenario;
        }

        $this->scenarios['mix'] = new Scenario('mix', 'mixed', 'Смесь как у сайта: ~80% чтение, ~20% запись. Основной сценарий для wrk', function (Context $c): mixed {
            return $this->scenarios[$this->pick()]->run($c);
        });

        $this->scenarios['heavy'] = new Scenario('heavy', 'mixed', 'Всё тяжёлое сразу: aggregate, like, offset, count', function (Context $c): int {
            foreach (['db.aggregate', 'db.like', 'db.offset', 'db.count'] as $name) {
                $this->scenarios[$name]->run($c);
            }

            return 4;
        }, true);
    }

    public function get(string $name): ?Scenario
    {
        return $this->scenarios[$name] ?? null;
    }

    /**
     * @param list<string> $groups  префиксы групп (plain, db.read, db.write, mixed); пусто = все
     * @param list<string> $only    точные имена; пусто = все
     * @return list<Scenario>
     */
    public function select(array $groups = [], array $only = []): array
    {
        return array_values(array_filter(
            $this->scenarios,
            static fn (Scenario $s): bool => ($only === [] || in_array($s->name, $only, true))
                && ($groups === [] || array_filter($groups, static fn (string $g): bool => str_starts_with($s->group, $g)) !== []),
        ));
    }

    /** @return list<Scenario> */
    public function all(): array
    {
        return array_values($this->scenarios);
    }

    private function pick(): string
    {
        $roll = random_int(1, array_sum(self::MIX));

        foreach (self::MIX as $name => $weight) {
            if (($roll -= $weight) <= 0) {
                return $name;
            }
        }

        return 'db.pk';
    }
}

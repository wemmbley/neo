<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\ORM\Metadata;

use BackedEnum;
use DateTimeInterface;
use Neo\Information\Persistence\ORM\Attributes\Cast;
use Neo\Information\Persistence\ORM\Attributes\Column;
use Neo\Information\Persistence\ORM\Attributes\Config;
use Neo\Information\Persistence\ORM\Attributes\DB;
use Neo\Information\Persistence\ORM\Attributes\Fillable;
use Neo\Information\Persistence\ORM\Attributes\Hidden;
use Neo\Information\Persistence\ORM\Attributes\Immutable;
use Neo\Information\Persistence\ORM\Attributes\ObservedBy;
use Neo\Information\Persistence\ORM\Attributes\PrimaryKey;
use Neo\Information\Persistence\ORM\Attributes\Relations\RelationAttribute;
use Neo\Information\Persistence\ORM\Attributes\Rules;
use Neo\Information\Persistence\ORM\Attributes\UseFactory;
use Neo\Information\Persistence\ORM\Attributes\Watch;
use Neo\Information\Persistence\ORM\Model;
use Neo\Information\Structures\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Разбор класса модели: атрибуты + классические свойства ($table, $fillable, $casts ...).
 * Строится один раз на класс и кешируется.
 */
final class ModelMetadata
{
    /** @var array<class-string, self> */
    private static array $cache = [];

    public readonly string $table;
    public readonly ?string $connection;
    public readonly bool $timestamps;
    public readonly bool $incrementing;
    public readonly string $primaryProperty;
    public readonly string $primaryColumn;
    public readonly ?string $factory;

    /** @var array<string, Field> свойство => поле */
    public array $fields = [];

    /** @var array<string, string> колонка => свойство */
    public array $columns = [];

    /** @var list<class-string> наблюдатели из #[ObservedBy] */
    public array $observers = [];

    /** @var array<string, RelationAttribute> имя => отношение */
    public array $relations = [];

    /** @var array<string, string> классические касты ($casts) для динамических атрибутов */
    public readonly array $classicCasts;

    /** @var list<string> классический $hidden для динамических атрибутов */
    public readonly array $classicHidden;

    /** @param class-string<Model> $class */
    public static function for(string $class): self
    {
        return self::$cache[$class] ??= new self($class);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private function __construct(string $class)
    {
        $ref = new ReflectionClass($class);
        $d = $ref->getDefaultProperties();

        $db = $this->classAttribute($ref, DB::class);

        $this->table = $db?->table ?? ($d['table'] ?? null) ?? Str::plural(Str::snake(Str::basename($class)));
        $this->connection = $db?->connection ?? ($d['connection'] ?? null);
        $this->timestamps = $db?->timestamps ?? (bool) ($d['timestamps'] ?? true);
        $this->classicCasts = (array) ($d['casts'] ?? []);
        $this->classicHidden = (array) ($d['hidden'] ?? []);
        $this->factory = $this->classAttribute($ref, UseFactory::class)?->factory;

        $publicProps = array_filter(
            $ref->getProperties(ReflectionProperty::IS_PUBLIC),
            static fn (ReflectionProperty $p) => !$p->isStatic() && $p->getDeclaringClass()->getName() !== Model::class,
        );

        $pk = (string) ($d['primaryKey'] ?? 'id');
        $autoIncrement = (bool) ($d['incrementing'] ?? true);

        foreach ($publicProps as $p) {
            $a = $p->getAttributes(PrimaryKey::class)[0] ?? null;
            if ($a !== null) {
                $pk = $p->getName();
                $autoIncrement = $a->newInstance()->autoIncrement;
                break;
            }
        }

        $this->primaryProperty = $pk;
        $this->incrementing = $autoIncrement;

        foreach ($publicProps as $p) {
            $field = $this->buildField($p, $d, $pk, $autoIncrement);
            $this->fields[$field->property] = $field;
            $this->columns[$field->column] = $field->property;
        }

        $this->primaryColumn = isset($this->fields[$pk]) ? $this->fields[$pk]->column : $pk;

        foreach ($ref->getAttributes(ObservedBy::class) as $a) {
            array_push($this->observers, ...(array) $a->newInstance()->observers);
        }

        foreach ($ref->getAttributes(RelationAttribute::class, ReflectionAttribute::IS_INSTANCEOF) as $a) {
            $rel = $a->newInstance();
            $rel->name ??= $this->relationName($rel);
            $this->relations[$rel->name] = $rel;
        }
    }

    private function buildField(ReflectionProperty $p, array $d, string $pk, bool $autoIncrement): Field
    {
        $name = $p->getName();

        /** @var Config|null $cfg */
        $cfg = $this->attr($p, Config::class);
        /** @var Cast|null $cast */
        $cast = $this->attr($p, Cast::class);
        /** @var Column|null $col */
        $col = $this->attr($p, Column::class);
        /** @var Fillable|null $fill */
        $fill = $this->attr($p, Fillable::class);
        /** @var Hidden|null $hid */
        $hid = $this->attr($p, Hidden::class);
        /** @var Immutable|null $imm */
        $imm = $this->attr($p, Immutable::class);
        /** @var Rules|null $rules */
        $rules = $this->attr($p, Rules::class);
        /** @var Watch|null $watch */
        $watch = $this->attr($p, Watch::class);

        $type = $p->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $column = $col?->name ?? $cfg?->column ?? $name;

        # Классические массивы: $fillable разрешает только перечисленные, $guarded запрещает.
        $classicFillable = (array) ($d['fillable'] ?? []);
        $guarded = (array) ($d['guarded'] ?? []);
        $fillable = $fill?->value ?? $cfg?->fillable;
        $fillable ??= match (true) {
            $classicFillable !== [] => in_array($name, $classicFillable, true) || in_array($column, $classicFillable, true),
            $guarded !== [] && $guarded !== ['*'] => !in_array($name, $guarded, true) && !in_array($column, $guarded, true),
            default => !($name === $pk && $autoIncrement),
        };

        if ($col !== null && $col->hasDefault()) {
            [$hasDefault, $default] = [true, $col->default];
        } elseif ($cfg !== null && $cfg->default !== Column::UNSET) {
            [$hasDefault, $default] = [true, $cfg->default];
        } elseif ($p->hasDefaultValue()) {
            [$hasDefault, $default] = [true, $p->getDefaultValue()];
        } else {
            [$hasDefault, $default] = [false, null];
        }

        return new Field(
            property: $name,
            column: $column,
            cast: $cast?->type ?? $cfg?->cast ?? ((array) ($d['casts'] ?? []))[$name] ?? $this->inferCast($typeName),
            nullable: $type?->allowsNull() ?? true,
            fillable: $fillable,
            hidden: $hid?->value ?? $cfg?->hidden ?? in_array($name, (array) ($d['hidden'] ?? []), true),
            immutable: $imm?->value ?? $cfg?->immutable ?? false,
            primary: $name === $pk,
            autoIncrement: $name === $pk && $autoIncrement,
            default: $default,
            hasDefault: $hasDefault,
            rules: $rules !== null && $rules->rules !== [] ? $rules->rules : ($cfg?->rules ?? []),
            messages: $rules !== null && $rules->messages !== [] ? $rules->messages : ($cfg?->messages ?? []),
            watch: $watch?->event ?? $cfg?->watch,
        );
    }

    private function inferCast(?string $type): ?string
    {
        return match (true) {
            $type === null => null,
            in_array($type, ['int', 'float', 'string', 'bool', 'array'], true) => $type,
            enum_exists($type) && is_a($type, BackedEnum::class, true) => $type,
            class_exists($type) && is_a($type, DateTimeInterface::class, true) => $type,
            default => null,
        };
    }

    private function relationName(RelationAttribute $rel): string
    {
        $base = Str::camel(Str::basename((string) $rel->related));

        return $rel->isMany() ? Str::plural($base) : $base;
    }

    /** @template T of object @param class-string<T> $attr @return T|null */
    private function attr(ReflectionProperty $p, string $attr): ?object
    {
        return ($p->getAttributes($attr)[0] ?? null)?->newInstance();
    }

    /** @template T of object @param class-string<T> $attr @return T|null */
    private function classAttribute(ReflectionClass $ref, string $attr): ?object
    {
        for ($r = $ref; $r !== false; $r = $r->getParentClass()) {
            $a = $r->getAttributes($attr)[0] ?? null;
            if ($a !== null) {
                return $a->newInstance();
            }
        }

        return null;
    }

    public function fieldByColumn(string $column): ?Field
    {
        return isset($this->columns[$column]) ? $this->fields[$this->columns[$column]] : null;
    }
}

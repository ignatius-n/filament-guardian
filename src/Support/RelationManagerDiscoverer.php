<?php

declare(strict_types=1);

namespace Waguilar\FilamentGuardian\Support;

use Filament\Resources\Pages\Concerns\HasRelationManagers;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use Throwable;

final class RelationManagerDiscoverer
{
    /**
     * Collect relation manager FQNs registered on a Resource — both via
     * Resource::getRelations() and via getAllRelationManagers() overrides on
     * any page that uses Filament's HasRelationManagers concern (ViewRecord,
     * EditRecord, ManageRelatedRecords, and any custom page using the concern).
     *
     * Results are deduplicated by RM class string; insertion order is
     * preserved so getRelations() entries appear before page-specific ones.
     *
     * @param  class-string  $resourceClass
     * @return list<class-string<RelationManager>>
     */
    public static function collectClasses(string $resourceClass): array
    {
        /** @var array<class-string<RelationManager>, true> $classes */
        $classes = [];

        foreach (self::collectFromResourceRelations($resourceClass) as $rmClass) {
            $classes[$rmClass] = true;
        }

        foreach (self::collectFromResourcePages($resourceClass) as $rmClass) {
            $classes[$rmClass] = true;
        }

        return array_keys($classes);
    }

    /**
     * @param  class-string  $resourceClass
     * @return list<class-string<RelationManager>>
     */
    private static function collectFromResourceRelations(string $resourceClass): array
    {
        try {
            /** @var class-string<resource> $resourceClass */
            $relations = $resourceClass::getRelations();
        } catch (Throwable) {
            return [];
        }

        return self::flattenManagers($relations);
    }

    /**
     * @param  class-string  $resourceClass
     * @return list<class-string<RelationManager>>
     */
    private static function collectFromResourcePages(string $resourceClass): array
    {
        try {
            /** @var class-string<resource> $resourceClass */
            $pages = $resourceClass::getPages();
        } catch (Throwable) {
            return [];
        }

        $classes = [];

        foreach ($pages as $registration) {
            try {
                $pageClass = $registration->getPage();
            } catch (Throwable) {
                continue;
            }

            if (! self::pageUsesRelationManagersConcern($pageClass)) {
                continue;
            }

            foreach (self::invokeGetAllRelationManagers($pageClass) as $rmClass) {
                $classes[] = $rmClass;
            }
        }

        return $classes;
    }

    private static function pageUsesRelationManagersConcern(string $pageClass): bool
    {
        if (! class_exists($pageClass)) {
            return false;
        }

        return in_array(HasRelationManagers::class, class_uses_recursive($pageClass), true);
    }

    /**
     * Reflectively invoke the page's getAllRelationManagers() (it's protected
     * on Filament's HasRelationManagers concern). A stub instance is needed
     * because the method isn't static. Pages whose constructor or override
     * depend on runtime state (e.g. $this->record) throw and are skipped.
     *
     * @return list<class-string<RelationManager>>
     */
    private static function invokeGetAllRelationManagers(string $pageClass): array
    {
        try {
            $page = new $pageClass;
            $method = new ReflectionMethod($pageClass, 'getAllRelationManagers');
            $managers = $method->invoke($page);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($managers)) {
            return [];
        }

        /** @var array<class-string<RelationManager>|RelationGroup|RelationManagerConfiguration> $managers */
        return self::flattenManagers($managers);
    }

    /**
     * Flatten a getRelations()-style array (mixing class-string entries,
     * RelationManagerConfiguration instances, and RelationGroup instances)
     * into a flat list of RM FQNs. RelationGroups whose managers are a
     * Closure (resolved with an owner record) are caught and skipped.
     *
     * @param  array<class-string<RelationManager>|RelationGroup|RelationManagerConfiguration>  $managers
     * @return list<class-string<RelationManager>>
     */
    private static function flattenManagers(array $managers): array
    {
        /** @var list<class-string<RelationManager>> $classes */
        $classes = [];

        foreach ($managers as $manager) {
            if (is_string($manager)) {
                /** @var class-string<RelationManager> $manager */
                $classes[] = $manager;

                continue;
            }

            if ($manager instanceof RelationManagerConfiguration) {
                /** @var class-string<RelationManager> $rmClass */
                $rmClass = $manager->relationManager;
                $classes[] = $rmClass;

                continue;
            }

            try {
                foreach ($manager->getManagers() as $grouped) {
                    if (is_string($grouped)) {
                        /** @var class-string<RelationManager> $grouped */
                        $classes[] = $grouped;
                    } elseif ($grouped instanceof RelationManagerConfiguration) {
                        /** @var class-string<RelationManager> $rmClass */
                        $rmClass = $grouped->relationManager;
                        $classes[] = $rmClass;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $classes;
    }

    /**
     * A relation manager is eligible for Guardian's discovery only when
     * Filament hasn't already routed its authorization elsewhere.
     *
     * @param  class-string<RelationManager>  $rmClass
     */
    public static function isEligible(string $rmClass): bool
    {
        try {
            /** @var class-string|null $relatedResource */
            $relatedResource = $rmClass::getRelatedResource();
        } catch (Throwable) {
            $relatedResource = null;
        }

        if ($relatedResource !== null) {
            return false;
        }

        try {
            if ($rmClass::shouldSkipAuthorization()) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Resolve the related model class for a relation manager without a DB
     * call. Mirrors Filament's own resolution path.
     *
     * @param  class-string<RelationManager>  $rmClass
     * @param  class-string  $resourceClass
     * @return class-string|null
     */
    public static function resolveRelatedModel(string $rmClass, string $resourceClass): ?string
    {
        try {
            $relationshipName = $rmClass::getRelationshipName();
        } catch (Throwable) {
            return null;
        }

        if ($relationshipName === '') {
            return null;
        }

        try {
            /** @var class-string<resource> $resourceClass */
            /** @var class-string $parentModelClass */
            $parentModelClass = $resourceClass::getModel();

            if (! class_exists($parentModelClass)) {
                return null;
            }

            $owner = new $parentModelClass;

            if (! method_exists($owner, $relationshipName)) {
                return null;
            }

            $relation = $owner->{$relationshipName}();

            if (! $relation instanceof Relation) {
                return null;
            }

            /** @var class-string $modelClass */
            $modelClass = $relation->getQuery()->getModel()::class;

            return $modelClass;
        } catch (Throwable) {
            return null;
        }
    }
}
